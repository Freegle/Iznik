#!/bin/bash
# Keep role values out of the code as strings: a role is read from a constant.
#
# The seven values are the system roles User, Moderator, Support, Admin
# (users.systemrole) and the membership roles Member, Moderator, Owner
# (memberships.role). A literal that is wrong or misspelt gives no error: it
# matches nobody, and the wrong people see, or do not see, member data. The
# constants make a misspelling fail to build.
#
# The constants are:
#   Go        utils.ROLE_MEMBER / ROLE_MODERATOR / ROLE_OWNER and
#             utils.SYSTEMROLE_USER / SYSTEMROLE_MODERATOR / SYSTEMROLE_SUPPORT / SYSTEMROLE_ADMIN
#   PHP       Membership::ROLE_*  and  User::SYSTEMROLE_*
#   Frontend  ROLE_* and SYSTEMROLE_* in iznik-nuxt3/constants.js
# "Moderator" is two constants: take the one for the column it is compared with.
# In SQL the role is a bound parameter fed from the constant, never inside the string.
#
# WHAT IT LOOKS AT: role CONTEXTS, never the words. "Moderator" in a heading,
# a label or a mail line is fine; so is a log type "User". A guard that fires on
# copy gets switched off, so it fires only on:
#   - a role/systemrole name compared with or assigned a role value
#     (role === 'Owner', systemrole = 'Support', 'role' => 'Owner', Role: "Admin")
#   - a role value in a SQL string (role IN ('Moderator','Owner'), FIND_IN_SET)
#   - a role column named with a literal value (->where('role', 'Owner'), Update("role", "Owner"))
#   - a role argument to a test helper or factory (CreateTestUser, CreateTestMembership ...)
#   - a call on a role function with a literal (hasRole('Owner'))
#   - an option value (<option value="Moderator">, value: 'Admin')
#   - a role in a request body or query string (role: 'Owner', ?role=Owner)
#   - two role values side by side (['Moderator', 'Owner']), a case label,
#     or a map key to a number ('Moderator': 3)
#
# SCOPE: .go .php .js .mjs .ts .vue in iznik-server-go, iznik-batch, iznik-nuxt3.
# Not migrations (frozen history that defines the columns), node_modules, vendor,
# build output, iznik-routing-go or iznik-spatial-go. Constant definitions use the
# upper-case names (ROLE_MEMBER = 'Member') which the contexts above do not match.
#
# MODES:
#   (no args)        Claude Code PreToolUse hook on Write|Edit. Reads the tool call
#                    as JSON on stdin and judges only the text being written.
#   --diff [BASE]    CI gate. Only lines added by `git diff BASE...HEAD`
#                    (BASE defaults to origin/master). Holds the line whatever
#                    editor the change was made in.
#   --all [PATH...]  Whole tree, or only the given path prefixes, relative to the
#                    repo root (--all iznik-server-go). One line per hit
#                    (file:line: snippet), a count per file, a total. Exits 1 if any.
#
# ESCAPE HATCH: a hit on a line carrying `role-literal-ok: <reason>` is allowed.
# Use it where the string really is not a role (another enum that shares the word)
# and the context above could not tell.

V='(User|Moderator|Support|Admin|Member|Owner)'
V2='(Moderator|Support|Admin|Member|Owner)'
Q="[\"'\`]"
VQ="${Q}${V}${Q}"
V2Q="${Q}${V2}${Q}"
NAME='[Rr]ole[A-Za-z_]*'
CALL='(\([^)]*\))?'

PATTERNS=(
  # role-ish name compared with / assigned a role value.
  "${NAME}${CALL}${Q}?\]?[[:space:]]*(===?|!==?|:=|=>|<>|[!=:]=?)[[:space:]]*${VQ}"
  # the other way round: 'Owner' === role
  "${VQ}[[:space:]]*(===?|!==?|<>)[[:space:]]*[A-Za-z_.\$>-]*[Rr]ole"
  # SQL IN list and FIND_IN_SET
  "${NAME}[[:space:]]+(NOT[[:space:]]+)?[Ii][Nn][[:space:]]*\(.*${VQ}"
  "FIND_IN_SET\([[:space:]]*${VQ}"
  # a role column named as a string, value next: where('role', 'Owner'), Update("role", "Owner")
  "${Q}[A-Za-z0-9_.]*[Rr]ole[A-Za-z_]*${Q}[[:space:]]*,[[:space:]]*(${Q}[<>=!]+${Q}[[:space:]]*,[[:space:]]*)?(\[[[:space:]]*)?${VQ}"
  # a placeholder then the literal: Where("role = ?", "Owner")
  "${NAME}[[:space:]]*(=|!=|<>|IN)[[:space:]]*\(?\?\)?${Q}?,[[:space:]]*(\[[[:space:]]*)?${VQ}"
  # test helpers and factories taking a role argument
  "[Cc]reate[A-Za-z]*(User|Membership|Member|Moderator|Owner|Admin|Support)[A-Za-z]*\(.*${VQ}"
  # a role function called with a literal: hasRole('Owner')
  "${NAME}\(.*${VQ}"
  # option values
  "<option[^>]*value=${Q}+${V}${Q}"
  "[[:space:]:]value=${Q}+${V2}${Q}"
  "value:[[:space:]]*${Q}${V2}${Q}"
  # request body / query string
  "[?&](system)?role=${V}"
  # two role values side by side
  "${VQ}[[:space:]]*,[[:space:]]*${VQ}"
  # INSERT of a role column
  "INSERT INTO (memberships|users)[^)]*(role|systemrole)[^)]*\).*VALUES.*${VQ}"
  # member-ish variable compared with a role: amAMember === 'Owner'
  "[A-Za-z_.]*[Mm]ember[A-Za-z_]*${CALL}[[:space:]]*(===?|!==?)[[:space:]]*${VQ}"
  # an assertion on a role: toBe('Member'), Equal(t, "Member", getActualRole(..))
  "[Rr]ole[A-Za-z_]*[^'\"\`]*\)\.(toBe|toEqual|toStrictEqual)\(${VQ}"
  "Equal\(t,[[:space:]]*${VQ}.*([Rr]ole|Systemrole)"
  "[Mm]ember[A-Za-z_.]*\.(mockReturnValue|mockResolvedValue)\(${VQ}"
  # a user/session helper whose last argument is a role: session(t, tx, "Admin")
  "[A-Za-z]*(User|[Ss]ession|Login|Mod|Member)[A-Za-z]*\([^)]*,[[:space:]]*${VQ}[[:space:]]*\)"
  # case label and map key to a number
  "case[[:space:]]+${V2Q}[[:space:]]*:"
  "${VQ}[[:space:]]*:[[:space:]]*[0-9]"
)

PATTERN=$(IFS='|'; echo "${PATTERNS[*]}")
OK_MARK='role-literal-ok:'

# Is this path one the guard judges? Paths are relative in CI and absolute in the
# editor, so patterns carry no leading slash requirement.
in_scope() {
  case "$1" in
    *node_modules/*|*vendor/*|*/.nuxt/*|*/.output/*|*/.nitro/*|*/dist/*|*database/migrations/*) return 1 ;;
    *iznik-routing-go/*|*iznik-spatial-go/*|*.min.js) return 1 ;;
    *iznik-server-go/*|*iznik-batch/*|*iznik-nuxt3/*) ;;
    *) return 1 ;;
  esac
  case "$1" in
    *.go|*.php|*.js|*.mjs|*.ts|*.vue) return 0 ;;
  esac
  return 1
}

explain() {
  cat >&2 <<'EOF'

A role is read from a constant, never written as a string:
  Go        utils.ROLE_MEMBER / ROLE_MODERATOR / ROLE_OWNER
            utils.SYSTEMROLE_USER / SYSTEMROLE_MODERATOR / SYSTEMROLE_SUPPORT / SYSTEMROLE_ADMIN
  PHP       Membership::ROLE_*   and   User::SYSTEMROLE_*
  Frontend  ROLE_* and SYSTEMROLE_* from iznik-nuxt3/constants.js
"Moderator" is two constants: use the one for the column it is compared with
(memberships.role is ROLE_MODERATOR, users.systemrole is SYSTEMROLE_MODERATOR).
In SQL, bind the role as a parameter fed from the constant, not inside the string.

If the string is genuinely not a role (another enum that shares the word), say so on
the same line and this check will allow it:   // role-literal-ok: <why>
EOF
}

# Filters "file:line:text" on stdin: drops escape-hatch lines and out-of-scope
# files, prints "file:line: snippet".
format_hits() {
  while IFS= read -r row; do
    file=${row%%:*}
    in_scope "$file" || continue
    rest=${row#*:}
    line=${rest%%:*}
    text=${rest#*:}
    case "$text" in *"$OK_MARK"*) continue ;; esac
    text=${text#"${text%%[![:space:]]*}"}
    case "$text" in "//"*|"#"*|"*"*|"/*"*|"<!--"*) continue ;; esac
    printf '%s:%s: %s\n' "$file" "$line" "${text:0:160}"
  done
}

report() {
  # $1 = file of hits. Prints per-file counts and total. Returns 1 if any.
  local hits=$1
  [ -s "$hits" ] || return 0
  cat "$hits"
  echo
  echo "Per file:"
  cut -d: -f1 "$hits" | sort | uniq -c | sort -rn | awk '{printf "  %6d  %s\n", $1, $2}'
  echo
  echo "Total: $(wc -l < "$hits") role literal(s) in $(cut -d: -f1 "$hits" | sort -u | wc -l) file(s)"
  return 1
}

TMP=$(mktemp)
trap 'rm -f "$TMP"' EXIT

# ---------------------------------------------------------------- all mode ----
if [ "${1:-}" = "--all" ]; then
  shift
  git ls-files -z -- "$@" 2>/dev/null | while IFS= read -r -d '' f; do
    in_scope "$f" && printf '%s\0' "$f"
  done | xargs -0 -r grep -nE -H -e "$PATTERN" 2>/dev/null | format_hits > "$TMP"
  if report "$TMP"; then
    echo "check-role-literals: no role literals found."
    exit 0
  fi
  exit 1
fi

# ---------------------------------------------------------------- CI mode ----
if [ "${1:-}" = "--diff" ]; then
  BASE="${2:-origin/master}"
  git rev-parse --verify "$BASE" >/dev/null 2>&1 || {
    echo "check-role-literals: cannot resolve base ref '$BASE'" >&2
    exit 2
  }
  # Added lines with their file and new line number.
  git diff -U0 --diff-filter=d "$BASE...HEAD" | awk '
    /^\+\+\+ b\// { file = substr($0, 7); next }
    /^\+\+\+/ { file = ""; next }
    /^@@/ { if (match($0, /\+[0-9]+/)) { n = substr($0, RSTART+1, RLENGTH-1) + 0 }; next }
    /^\+/ { if (file != "") print file ":" n ":" substr($0, 2); n++ }
  ' | grep -E -e "$PATTERN" | format_hits > "$TMP"
  if [ -s "$TMP" ]; then
    echo "BLOCKED: this branch adds a role written as a string." >&2
    echo >&2
    cat "$TMP" >&2
    explain
    exit 1
  fi
  exit 0
fi

# ------------------------------------------------------------ editor mode ----
INPUT=$(cat 2>/dev/null)
[ -z "$INPUT" ] && exit 0
FILE=$(echo "$INPUT" | jq -r '.tool_input.file_path // empty' 2>/dev/null)
[ -z "$FILE" ] && exit 0
in_scope "$FILE" || exit 0
ADDED=$(echo "$INPUT" | jq -r '(.tool_input.new_string // .tool_input.content // empty)' 2>/dev/null)
[ -z "$ADDED" ] && exit 0

HITS=$(echo "$ADDED" | grep -E -e "$PATTERN" | grep -v -F "$OK_MARK" | grep -vE '^[[:space:]]*(//|#|\*|/\*|<!--)')
[ -z "$HITS" ] && exit 0

echo "BLOCKED: this writes a role as a string in $FILE." >&2
echo >&2
echo "$HITS" | head -5 | sed 's/^[[:space:]]*/  /' >&2
explain
exit 2
