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
#   - a single-value list (['Owner'], []string{"Owner"}), a role function or list on a literal
#     (roles.includes('Owner')), an assertion on a role (assertEquals('Owner', $m->role)),
#     a ternary on a role (role = x ? 'Owner' : 'Member') and a match arm in a match on a role
# In SQL (a role column named in a quoted string) the column and the value match in any case,
# and an escaped quote (\\"Support\\", \\'Owner\\') does not hide the value.
#
# DELIBERATE LIMITS, so nobody is surprised: a role held under a neutral variable name, a
# comparison or SQL split over several lines, and an Edit fragment that replaces only the value
# are not seen. CI runs --all, which does catch the last. Untracked files are not seen by --all.
#
# SCOPE: .go .php .js .mjs .ts .vue in iznik-server-go, iznik-batch, iznik-nuxt3.
# Not migrations (frozen history that defines the columns), node_modules, vendor,
# build output, iznik-routing-go or iznik-spatial-go. Constant definitions use the
# upper-case names (ROLE_MEMBER = 'Member') which the contexts above do not match.
#
# MODES:
#   (no args)        Claude Code PreToolUse hook on Write|Edit. Reads the tool call
#                    as JSON on stdin and judges only the text being written.
#   --diff [BASE]    Only lines added by `git diff BASE...HEAD` (BASE defaults to
#                    origin/master). For checking a branch on its own.
#   --all [PATH...]  CI gate. Whole tree, or only the given path prefixes, relative
#                    to the repo root (--all iznik-server-go). One line per hit
#                    (file:line: snippet), a count per file, a total. Exits 1 if any.
#                    The tree holds at zero, so CI runs it with no paths.
#
# ESCAPE HATCH: a hit on a line carrying `role-literal-ok: <reason>` is allowed.
# Use it where the string really is not a role (another enum that shares the word)
# and the context above could not tell.

ci() { echo "$1" | sed 's/./[\L&\U&]/g'; }
V='(User|Moderator|Support|Admin|Member|Owner)'
V2='(Moderator|Support|Admin|Member|Owner)'
Q="[\"'\`]"
# A quote, optionally written escaped (\"Support\") inside a string.
EQ="\\\\?${Q}"
VQ="${EQ}${V}${EQ}"
V2Q="${EQ}${V2}${EQ}"
# Case-insensitive forms for SQL, which does not care about case.
VCI="($(ci User)|$(ci Moderator)|$(ci Support)|$(ci Admin)|$(ci Member)|$(ci Owner))"
ROLECI="(($(ci system))?$(ci role))"
SQ="\\\\?['\"]"
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
  # SQL, any case: role = 'owner', WHERE ROLE='Owner', IN lists, INSERT, FIND_IN_SET
  "${ROLECI}[[:space:]]*(=|!=|<>)[[:space:]]*${SQ}${VCI}${SQ}"
  "${ROLECI}[[:space:]]+($(ci NOT)[[:space:]]+)?$(ci IN)[[:space:]]*\(.*${SQ}${VCI}${SQ}"
  "$(ci INSERT)[[:space:]]+$(ci INTO)[[:space:]]+($(ci memberships)|$(ci users))[^)]*${ROLECI}[^)]*\).*$(ci VALUES).*${SQ}${VCI}${SQ}"
  "$(ci FIND_IN_SET)\([[:space:]]*${SQ}${VCI}${SQ}"
  # a single-value list: ['Owner'], ["Admin"], []string{"Owner"}
  "(^|[^])A-Za-z0-9_])\\[[[:space:]]*${V2Q}[[:space:]]*\\]"
  "[Rr]ole[A-Za-z_]*[^[]*\[[[:space:]]*${VQ}[[:space:]]*\]"
  "\[\]string\{[[:space:]]*${VQ}"
  # a role list or role value tested with a literal: roles.includes('Owner')
  "[Rr]oles?[A-Za-z_]*(\(\))?\.(includes|has|indexOf|contains)\(${VQ}"
  # an assertion on a role, PHP: assertEquals('Owner', $m->role), either order
  "assert(Equals|Same)\([[:space:]]*${VQ}[[:space:]]*,.*[Rr]ole"
  "assert(Equals|Same)\(.*[Rr]ole.*,[[:space:]]*${VQ}"
  # a ternary on a role key or variable
  "${NAME}${Q}?\]?[[:space:]]*(=>|=|:=|:)[^;?]*\?[^;]*${VQ}"
  # a match on a role, arm on the same line
  "match[[:space:]]*\(.*[Rr]ole.*\)[[:space:]]*\{.*${VQ}[[:space:]]*=>"
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
    case "$text" in "//"*|"#"*|"* "*|"*/"*|"*"|"/*"*|"<!--"*) continue ;; esac
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
  cut -d: -f1 "$hits" | sort | uniq -c | sort -rn | sed -E 's/^ *([0-9]+) (.*)$/  \1  \2/'
  echo
  echo "Total: $(wc -l < "$hits") role literal(s) in $(cut -d: -f1 "$hits" | sort -u | wc -l) file(s)"
  return 1
}

TMP=$(mktemp)
trap 'rm -f "$TMP" "$TMP.rows"' EXIT

# A match on a role, with the arms on the following lines. Reads file:line:text rows
# in file order and prints the arm rows (arm keyed by a role value) that follow a
# `match (... role ...)` line, until the closing brace.
ARM_RE="${VQ}[[:space:]]*=>"
MATCH_RE='match[[:space:]]*\(.*[Rr]ole'
arm_scan() {
  ARM="$ARM_RE" MATCHRE="$MATCH_RE" awk '
    BEGIN { arm = ENVIRON["ARM"]; mre = ENVIRON["MATCHRE"] }
    {
      p = index($0, ":"); file = substr($0, 1, p - 1)
      if (match($0, /^[^:]*:[0-9]+:/)) text = substr($0, RLENGTH + 1); else next
      if (file != cur) { inm = 0; cur = file }
      if (text ~ mre) { inm = 1; next }
      if (inm && text ~ /^[[:space:]]*\}/) { inm = 0; next }
      if (inm && text ~ arm) print $0
    }'
}

# Needs the repo root: --all and --diff paths are relative to it, wherever they are run from.
goto_root() {
  local root
  if ! root=$(git rev-parse --show-toplevel 2>&1); then
    echo "check-role-literals: not inside a git repository, so there is nothing to scan: $root" >&2
    exit 2
  fi
  cd "$root" || { echo "check-role-literals: cannot enter $root" >&2; exit 2; }
}

# ---------------------------------------------------------------- all mode ----
if [ "${1:-}" = "--all" ]; then
  shift
  goto_root
  if ! git ls-files -z -- "$@" > "$TMP.files"; then
    echo "check-role-literals: git ls-files failed" >&2
    rm -f "$TMP.files"
    exit 2
  fi
  while IFS= read -r -d '' f; do
    in_scope "$f" && printf '%s\0' "$f"
  done < "$TMP.files" > "$TMP.scope"
  rm -f "$TMP.files"
  {
    xargs -0 -r grep -nE -H -e "$PATTERN" < "$TMP.scope"
    xargs -0 -r grep -lE -e "$MATCH_RE" < "$TMP.scope" \
      | while IFS= read -r f; do grep -nH '' -- "$f" | arm_scan; done
  } | sort -t: -k1,1 -k2,2n -u | format_hits > "$TMP"
  rm -f "$TMP.scope"
  if report "$TMP"; then
    echo "check-role-literals: no role literals found."
    exit 0
  fi
  exit 1
fi

# ---------------------------------------------------------------- CI mode ----
if [ "${1:-}" = "--diff" ]; then
  BASE="${2:-origin/master}"
  goto_root
  git rev-parse --verify "$BASE" >/dev/null 2>&1 || {
    echo "check-role-literals: cannot resolve base ref '$BASE'" >&2
    exit 2
  }
  # Added lines with their file and new line number.
  if ! git diff -U0 --diff-filter=d "$BASE...HEAD" > "$TMP.diff"; then
    echo "check-role-literals: git diff against $BASE failed" >&2
    rm -f "$TMP.diff"
    exit 2
  fi
  awk '
    /^\+\+\+ b\// { file = substr($0, 7); next }
    /^\+\+\+/ { file = ""; next }
    /^@@/ { if (match($0, /\+[0-9]+/)) { n = substr($0, RSTART+1, RLENGTH-1) + 0 }; next }
    /^\+/ { if (file != "") print file ":" n ":" substr($0, 2); n++ }
  ' "$TMP.diff" > "$TMP.rows"
  rm -f "$TMP.diff"
  { grep -E -e "$PATTERN" "$TMP.rows"; arm_scan < "$TMP.rows"; } \
    | sort -t: -k1,1 -k2,2n -u | format_hits > "$TMP"
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
if ! command -v jq >/dev/null 2>&1; then
  echo "check-role-literals: jq is not installed, so this edit was NOT checked for role literals." >&2
  exit 1
fi
FILE=$(echo "$INPUT" | jq -r '.tool_input.file_path // empty' 2>/dev/null)
[ -z "$FILE" ] && exit 0
in_scope "$FILE" || exit 0
# Write gives content, Edit gives new_string, MultiEdit gives one new_string per edit.
ADDED=$(echo "$INPUT" | jq -r '[(.tool_input.new_string // empty), (.tool_input.content // empty), (.tool_input.edits[]?.new_string // empty)] | map(select(. != "")) | join("\n")' 2>/dev/null)
[ -z "$ADDED" ] && exit 0

echo "$ADDED" | awk '{ print "x:" NR ":" $0 }' > "$TMP.rows"
HITS=$({ grep -E -e "$PATTERN" "$TMP.rows"; arm_scan < "$TMP.rows"; } | sort -t: -k2,2n -u | sed 's/^x:[0-9]*://' \
  | grep -v -F "$OK_MARK" | grep -vE '^[[:space:]]*(//|#|\*( |/|$)|/\*|<!--)')
[ -z "$HITS" ] && exit 0

echo "BLOCKED: this writes a role as a string in $FILE." >&2
echo >&2
echo "$HITS" | head -5 | sed 's/^[[:space:]]*/  /' >&2
explain
exit 2
