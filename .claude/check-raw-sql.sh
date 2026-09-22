#!/bin/bash
# Keep new raw SQL out of the codebase - the guard that replaces the ORM
# migration ratchet.
#
# The migration converted 1,593 Go call sites. It did NOT reach zero raw SQL:
# about 44 sites in iznik-server-go are deliberately still raw (index hints,
# runtime-varying identifiers, statements GORM will not render). The manifest's
# "raw: 0" meant "nothing left untriaged", which is a bookkeeping fact, not an
# absence of raw SQL - worth stating plainly, because reading it as the latter
# is exactly the mistake this comment exists to stop the next person making.
#
# What held the line was ci-ratchet.sh's gate (b) - "the code contains a raw SQL
# call site whose ID is absent from the manifest" - backed by a 5,974-entry
# inventory, an AST extractor and a golden-SQL parity harness. That machinery
# existed to CARRY OUT a migration. Once the Go conversions landed it was
# carrying ~8MB of inventory to answer one question: did someone add raw SQL
# back?
#
# TWO MODES, AND BOTH ARE NEEDED - the editor mode alone is not a replacement
# for a CI gate, and an earlier revision of this script shipped as if it were:
#
#   (no args)      Claude Code PreToolUse hook. Reads a Write/Edit tool call as
#                  JSON on stdin and judges the text being written. Catches the
#                  addition at the moment it is made, which is the cheapest
#                  place to catch it - but ONLY for edits made through that one
#                  tool. Anything typed in a normal editor sails past.
#
#   --diff [BASE]  CI gate. Scans the added lines of `git diff BASE...HEAD`
#                  (BASE defaults to origin/master) and fails the build on any
#                  new raw SQL without a justification. This is what actually
#                  holds the line, because it does not care how the edit was
#                  made. Run it from .circleci/ on every branch.
#
# Covers BOTH stacks:
#   - iznik-server-go: converted, bar the deliberate remainder above.
#   - iznik-batch (Laravel): conversion in progress; the hook is not trying to
#     fix the existing sites, it stops the pile GROWING silently and makes each
#     new one carry a written reason.
#
# It WILL fire on an edit to one of the existing deliberate sites, because their
# justifications lived in the inventory rather than inline. That is accepted:
# both modes read diffs, so it only ever interrupts someone actually editing one
# of those queries, and an override is a sentence.
#
# It judges ONLY the text being added, never the whole file, so editing near
# existing raw SQL is unaffected. Exemptions:
#   - *_test.go / iznik-batch/tests/  - fixtures legitimately use raw SQL
#     (matched WITHOUT a leading slash: CI mode gets relative paths from git,
#     editor mode gets absolute ones, and a `*/x/*` pattern only matches the
#     latter - which silently disabled every exemption in CI until a test
#     caught it)
#   - iznik-batch/database/migrations/ - frozen DDL, already a blanket keep-raw
#     rule in the retired inventory; blocking these would fire constantly
#   - EeeSqliteService and friends - a separate local SQLite store, never in
#     scope for the MySQL ORM work
#
# Escape hatch, both languages: `// keep-raw: <reason>`. That is deliberately
# the same written-justification rule the retired gate (e) enforced, so nothing
# is lost by moving it here - the comment is now the whole audit trail.
#
# If the fragment builders (whereRaw/selectRaw/orderByRaw) prove too noisy for
# active Laravel work, narrow RAW_PHP to the whole-statement calls rather than
# switching the hook off.

RAW_GO='\.(Raw|Exec)\(|RetryExec\(|ExecInsertGetID\('
RAW_PHP='DB::(select|statement|insert|update|delete|unprepared)\(|->(whereRaw|selectRaw|orderByRaw|groupByRaw|havingRaw)\('
KEEP_RAW='(//|#|\*)[[:space:]]*keep-raw:'

# Which language's rules apply to a path, or "" if the path is out of scope or
# exempt. Single source of truth for both modes.
lang_for_path() {
  case "$1" in
    *_test.go|*iznik-server-go/test/*) return ;;
    *iznik-server-go/*.go) echo go ;;
    *iznik-batch/tests/*|*database/migrations/*|*EeeSqlite*) return ;;
    *iznik-batch/*.php) echo php ;;
  esac
}

pattern_for_lang() {
  [ "$1" = go ] && echo "$RAW_GO" || echo "$RAW_PHP"
}

explain_go() {
  cat >&2 <<'EOF'
Use a GORM chain - db.Table(...).Select(...).Where(...) - as the 1,593 converted
sites do. For a SQL fragment inside a chain (NOW(), a spatial predicate, an
index hint) use gorm.Expr / clause.Expr; those are not blocked.

If it genuinely cannot go through GORM - dynamic table or column name, an index
hint that must survive, something GORM will not render - say why on the line
above and this hook will allow it:

    // keep-raw: <why this cannot be a GORM chain>
    db.Raw("...")
EOF
}

explain_php() {
  cat >&2 <<'EOF'
Use the query builder or Eloquent instead. If it genuinely cannot be expressed
that way - a window function, a dialect-specific construct, a statement the
builder will not render - say why on the line above and this hook will allow it:

    // keep-raw: <why the builder cannot express this>
    DB::select('...');

Migrations, tests and the EeeSqlite store are already exempt, so if you are
hitting this there, the path check needs fixing rather than a comment.
EOF
}

# ---------------------------------------------------------------- CI mode ----
if [ "${1:-}" = "--diff" ]; then
  BASE="${2:-origin/master}"
  git rev-parse --verify "$BASE" >/dev/null 2>&1 || {
    echo "check-raw-sql: cannot resolve base ref '$BASE'" >&2
    exit 2
  }

  FOUND=0
  while IFS= read -r file; do
    [ -n "$file" ] || continue
    lang=$(lang_for_path "$file")
    [ -n "$lang" ] || continue
    [ -f "$file" ] || continue
    pattern=$(pattern_for_lang "$lang")

    # Added lines only, with their new-file line numbers, so the report points
    # at something a reviewer can open.
    git diff -U0 "$BASE...HEAD" -- "$file" | awk '
      /^@@/ { if (match($0, /\+[0-9]+/)) { n = substr($0, RSTART+1, RLENGTH-1) + 0 }; next }
      /^\+\+\+/ { next }
      /^\+/ { print n ":" substr($0, 2); n++ }
    ' | while IFS= read -r entry; do
      lineno=${entry%%:*}
      text=${entry#*:}
      echo "$text" | grep -qE "$pattern" || continue
      # A justification may be on the added line itself or above it in the file
      # as it now stands - the comment is usually pre-existing context, not part
      # of the diff.
      #
      # The window is 25 lines, not 8. A keep-raw comment sits above the WHOLE
      # statement, and a fluent builder chain routinely runs ten or more lines
      # before reaching its whereRaw. At 8 this gate blocked its own repository:
      # MigrateReachBoundsSchemaCommand's ST_GeometryType/ST_Contains predicates
      # carry an 8-line explanation immediately above a chain that reaches them
      # on the 11th line, and CI rejected them as unjustified.
      start=$((lineno > 25 ? lineno - 25 : 1))
      if sed -n "${start},${lineno}p" "$file" | grep -qiE "$KEEP_RAW"; then
        continue
      fi
      printf '%s:%s: %s\n' "$file" "$lineno" "$(echo "$text" | sed 's/^[[:space:]]*//')"
    done
  done < <(git diff --name-only --diff-filter=d "$BASE...HEAD") > /tmp/check-raw-sql.$$

  if [ -s /tmp/check-raw-sql.$$ ]; then
    echo "BLOCKED: this branch adds raw SQL without a justification." >&2
    echo >&2
    cat /tmp/check-raw-sql.$$ >&2
    echo >&2
    grep -q '\.go:' /tmp/check-raw-sql.$$ && explain_go
    grep -q '\.php:' /tmp/check-raw-sql.$$ && explain_php
    rm -f /tmp/check-raw-sql.$$
    exit 1
  fi
  rm -f /tmp/check-raw-sql.$$
  exit 0
fi

# ------------------------------------------------------------ editor mode ----
INPUT=$(cat 2>/dev/null)
[ -z "$INPUT" ] && exit 0

FILE=$(echo "$INPUT" | jq -r '.tool_input.file_path // empty' 2>/dev/null)
[ -z "$FILE" ] && exit 0

# Judge only what is being written: new_string for an Edit, content for a Write.
ADDED=$(echo "$INPUT" | jq -r '(.tool_input.new_string // .tool_input.content // empty)' 2>/dev/null)
[ -z "$ADDED" ] && exit 0

# Already justified - same rule in both languages.
if echo "$ADDED" | grep -qiE "$KEEP_RAW"; then
  exit 0
fi

LANG=$(lang_for_path "$FILE")
[ -n "$LANG" ] || exit 0

echo "$ADDED" | grep -qE "$(pattern_for_lang "$LANG")" || exit 0

if [ "$LANG" = go ]; then
  echo "BLOCKED: this adds raw SQL to iznik-server-go." >&2
  echo >&2
  explain_go
else
  echo "BLOCKED: this adds raw SQL to iznik-batch." >&2
  echo >&2
  explain_php
fi
exit 2
