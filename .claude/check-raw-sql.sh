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
# back? This hook answers that where it happens, earlier and far cheaper.
#
# Covers BOTH stacks:
#   - iznik-server-go: converted, bar the deliberate remainder above.
#   - iznik-batch (Laravel): 569 raw sites, 0 converted - that work has not
#     started. The hook is not trying to fix those; it stops the pile GROWING
#     silently, and makes each new one carry a written reason.
#
# It WILL fire on an edit to one of the existing deliberate sites, because their
# justifications lived in the inventory rather than inline. That is accepted:
# the hook reads diffs, so it only ever interrupts someone actually editing one
# of those queries, and an override is a sentence. Retro-fitting ~44 comments to
# pre-empt it would be churn for a prompt nobody minds.
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
    # *_test.go is obvious; iznik-server-go/test/ is the integration-test
    # package, whose non-_test.go helpers (testUtils.go and friends) are
    # fixture scaffolding and legitimately full of raw SQL.
    case "$FILE" in
      *_test.go) exit 0 ;;
      */iznik-server-go/test/*) exit 0 ;;
    esac
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
BLOCKED: this adds raw SQL to iznik-server-go.

Use a GORM chain - db.Table(...).Select(...).Where(...) - as the 1,593 converted
sites do. For a SQL fragment inside a chain (NOW(), a spatial
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
