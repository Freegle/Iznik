#!/bin/bash
# Measures how much simpler the code is on this branch than on a base ref.
# Usage: scripts/simplicity-report.sh [base-ref]     (default origin/master)
# "Before" is the base ref; "now" is the working tree, so uncommitted work counts.
# Counts are approximate and deliberately crude: non-blank lines, functions, branch
# points (if/else if/case/for/while/catch/&&/||/??) as a stand-in for cyclomatic
# complexity, HTTP routes, pages and components, tables and columns.
set -euo pipefail
BASE="${1:-origin/master}"
cd "$(git rev-parse --show-toplevel)"

# tier name ; paths ; include regex ; exclude regex  (semicolon-separated because the regexes contain |)
TIERS=(
  "Go API;iznik-server-go;\.go$;_test\.go$|swagger/docs"
  "Batch (Laravel);iznik-batch/app iznik-batch/routes iznik-batch/config;\.php$;/tests/"
  "Member site (Nuxt);iznik-nuxt3/components iznik-nuxt3/pages iznik-nuxt3/composables iznik-nuxt3/stores iznik-nuxt3/api iznik-nuxt3/layouts iznik-nuxt3/middleware iznik-nuxt3/plugins;\.(vue|js|ts|mjs)$;/tests/|api/index\.js$"
  "ModTools (Nuxt);iznik-nuxt3/modtools;\.(vue|js|ts|mjs)$;/tests/"
  "Spatial + routing (Go);iznik-spatial-go iznik-routing-go;\.go$;_test\.go$"
  "Tests (all tiers);iznik-server-go/test iznik-batch/tests iznik-nuxt3/tests/unit iznik-spatial-go iznik-routing-go;(_test\.go|Test\.php|\.spec\.js|\.test\.js)$;__NONE__"
)
BRANCH='\b(if|else if|case|for|foreach|while|catch)\b|&&|\|\||\?\?'
FUNC='^\s*(func |(public |private |protected |static )*function |const [A-Za-z_]+ = (async )?\(|export (default )?(async )?function |[A-Za-z_]+\s*\([^)]*\)\s*\{$)'

# list files at BASE (via git) or in the tree, filtered
files_at() { # <base|tree> <paths> <include> <exclude>
  local where="$1" paths="$2" inc="$3" exc="$4"
  if [ "$where" = base ]; then git ls-tree -r --name-only "$BASE" -- $paths 2>/dev/null; else git ls-files -co --exclude-standard -- $paths 2>/dev/null | while read -r f; do [ -f "$f" ] && echo "$f"; done; fi \
    | grep -E "$inc" | grep -vE "$exc" || true
}
cat_at() { # <base|tree> <file>
  if [ "$1" = base ]; then git show "$BASE:$2" 2>/dev/null; else cat "$2"; fi
}
measure() { # <base|tree> <paths> <inc> <exc>  -> "files loc funcs branches"
  local where="$1" paths="$2" inc="$3" exc="$4" n=0 loc=0 fn=0 br=0
  while read -r f; do
    [ -z "$f" ] && continue
    n=$((n+1))
    local body; body="$(cat_at "$where" "$f")"
    loc=$((loc + $(printf '%s\n' "$body" | grep -cvE '^\s*$' || true)))
    fn=$((fn + $(printf '%s\n' "$body" | grep -cE "$FUNC" || true)))
    br=$((br + $(printf '%s\n' "$body" | grep -oE "$BRANCH" | wc -l)))
  done < <(files_at "$where" "$paths" "$inc" "$exc")
  echo "$n $loc $fn $br"
}
pct() { # <before> <now>
  if [ "$1" -eq 0 ]; then echo "n/a"; else awk -v a="$1" -v b="$2" 'BEGIN{printf "%+.0f%%", (b-a)*100/a}'; fi
}

echo "Simplicity report: $BASE (before) versus the working tree (now)."
echo
echo "| Tier | Files before / now | Lines before / now | Change | Functions before / now | Branch points before / now | Change |"
echo "|---|---|---|---|---|---|---|"
for t in "${TIERS[@]}"; do
  IFS=';' read -r name paths inc exc <<<"$t"
  read -r bf bl bfn bbr <<<"$(measure base "$paths" "$inc" "$exc")"
  read -r nf nl nfn nbr <<<"$(measure tree "$paths" "$inc" "$exc")"
  echo "| $name | $bf / $nf | $bl / $nl | $(pct "$bl" "$nl") | $bfn / $nfn | $bbr / $nbr | $(pct "$bbr" "$nbr") |"
done
echo
routes() { grep -cE '\b(rg|app|v1|api)\.(Get|Post|Put|Patch|Delete)\(' "$1" 2>/dev/null || echo 0; }
echo "| Surface | Before | Now |"
echo "|---|---|---|"
echo "| Go API routes | $(git show "$BASE:iznik-server-go/router/routes.go" | routes /dev/stdin) | $(routes iznik-server-go/router/routes.go) |"
echo "| Routing service routes | $(git show "$BASE:iznik-routing-go/server.go" | routes /dev/stdin) | $(routes iznik-routing-go/server.go) |"
echo "| Member-site pages | $(git ls-tree -r --name-only "$BASE" -- iznik-nuxt3/pages | grep -c '\.vue$') | $(find iznik-nuxt3/pages -name '*.vue' | wc -l) |"
echo "| ModTools pages | $(git ls-tree -r --name-only "$BASE" -- iznik-nuxt3/modtools/pages | grep -c '\.vue$') | $(find iznik-nuxt3/modtools/pages -name '*.vue' 2>/dev/null | wc -l) |"
echo "| ModTools components | $(git ls-tree -r --name-only "$BASE" -- iznik-nuxt3/modtools/components | grep -c '\.vue$') | $(find iznik-nuxt3/modtools/components -name '*.vue' 2>/dev/null | wc -l) |"
echo "| Batch scheduled services | $(git ls-tree -r --name-only "$BASE" -- iznik-batch/app/Services | grep -c 'Service\.php$') | $(find iznik-batch/app/Services -name '*Service.php' | wc -l) |"
echo "| Files still mentioning the group model (code, not tests) | | $(grep -rlE 'messages_groups|\bmemberships\b|groupid|MessageGroups|myGroups|useGroupStore' iznik-server-go iznik-batch/app iznik-nuxt3/components iznik-nuxt3/pages iznik-nuxt3/composables iznik-nuxt3/stores iznik-nuxt3/api iznik-nuxt3/modtools --include='*.go' --include='*.php' --include='*.vue' --include='*.js' 2>/dev/null | grep -vE '_test\.go|/tests/' | wc -l) |"
# Database: share of tables, rows and bytes removed. Needs an information_schema export
# (TABLE_NAME, TABLE_ROWS, DATA_LENGTH+INDEX_LENGTH per base table) at $TABLES_TSV; the live
# one is read-only and lives outside the repository.
TABLES_TSV="${TABLES_TSV:-.claude-agent-status/data/live-tables.tsv}"
if [ -f "$TABLES_TSV" ]; then
  echo; echo "Database (from $TABLES_TSV):"; echo
  node scripts/dropped-tables-share.mjs "$TABLES_TSV"
else
  mig=iznik-batch/database/migrations/2026_09_20_000001_remove_group_model.php
  echo "| Database tables dropped | | $(awk '/DROP_TABLES = \[/,/\];/' "$mig" | grep -oE "'[a-z_]+'" | wc -l) (set TABLES_TSV for shares) |"
fi
