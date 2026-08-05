#!/bin/bash
# Keep new raw SQL out of the codebase - the guard that replaces the ORM
# migration ratchet.
#
# The migration converted 1,593 Go call sites and left raw at zero there. What
# held that line was ci-ratchet.sh's gate (b) - "the code contains a raw SQL
# call site whose ID is absent from the manifest" - backed by a 5,974-entry
# inventory, an AST extractor and a golden-SQL parity harness. That machinery
# existed to CARRY OUT a migration. Once the Go migration landed it was carrying
# ~8MB of inventory to answer one question: did someone add raw SQL back? This
# hook answers that at the point it happens, which is earlier and far cheaper.
#
# Covers BOTH stacks, for different reasons:
#   - iznik-server-go: raw is at 0. Anything new is a regression.
#   - iznik-batch (Laravel): 569 raw sites, 0 converted - the migration has not
#     started. The hook is not trying to fix those; it stops the pile GROWING
#     silently, and makes each new one carry a written reason.
#
# It judges ONLY the text being written, never the whole file, so editing near
# existing raw SQL is unaffected. Exemptions:
#   - *_test.go / iznik-batch/tests/  - fixtures legitimately use raw SQL
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

if [ -n "$CI" ]; then
  exit 0
fi

INPUT=$(cat 2>/dev/null)
[ -z "$INPUT" ] && exit 0

FILE=$(echo "$INPUT" | jq -r '.tool_input.file_path // empty' 2>/dev/null)
[ -z "$FILE" ] && exit 0

# Judge only what is being written: new_string for an Edit, content for a Write.
ADDED=$(echo "$INPUT" | jq -r '(.tool_input.new_string // .tool_input.content // empty)' 2>/dev/null)
[ -z "$ADDED" ] && exit 0

# Already justified - same rule in both languages.
if echo "$ADDED" | grep -qiE '(//|#|\*)\s*keep-raw:'; then
  exit 0
fi

LANG=""
case "$FILE" in
  *iznik-server-go/*.go)
    case "$FILE" in *_test.go) exit 0 ;; esac
    LANG=go
    ;;
  *iznik-batch/*.php)
    case "$FILE" in
      */iznik-batch/tests/*) exit 0 ;;
      */database/migrations/*) exit 0 ;;
      *EeeSqlite*) exit 0 ;;
    esac
    LANG=php
    ;;
  *) exit 0 ;;
esac

if [ "$LANG" = go ]; then
  # gorm.Expr / clause.Expr are SQL fragments inside a real GORM chain - how a
  # converted site says NOW(), a spatial predicate or an index hint. Not raw
  # statements, not blocked.
  echo "$ADDED" | grep -qE '\.(Raw|Exec)\(|RetryExec\(|ExecInsertGetID\(' || exit 0
  cat >&2 <<'EOF'
BLOCKED: this adds raw SQL to iznik-server-go, where the ORM migration left the
count at zero.

Use a GORM chain - db.Table(...).Select(...).Where(...) - as the other 1,593
converted sites do. For a SQL fragment inside a chain (NOW(), a spatial
predicate, an index hint) use gorm.Expr / clause.Expr; those are not blocked.

If it genuinely cannot go through GORM - dynamic table or column name, an index
hint that must survive, something GORM will not render - say why on the line
above and this hook will allow it:

    // keep-raw: <why this cannot be a GORM chain>
    db.Raw("...")
EOF
else
  echo "$ADDED" | grep -qE 'DB::(select|statement|insert|update|delete|unprepared)\(|->(whereRaw|selectRaw|orderByRaw|groupByRaw|havingRaw)\(' || exit 0
  cat >&2 <<'EOF'
BLOCKED: this adds raw SQL to iznik-batch.

The Laravel side has 569 raw sites and no conversions yet - this hook is not
asking you to fix those, only to stop the pile growing without a reason.

Use the query builder or Eloquent instead. If it genuinely cannot be expressed
that way - a window function, a dialect-specific construct, a statement the
builder will not render - say why on the line above and this hook will allow it:

    // keep-raw: <why the builder cannot express this>
    DB::select('...');

Migrations, tests and the EeeSqlite store are already exempt, so if you are
hitting this there, the path check needs fixing rather than a comment.
EOF
fi
exit 2
