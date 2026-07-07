#!/usr/bin/env bash
# run-parity-check.sh
#
# Runs V1 behavior extraction + V2 coverage checking across all V1 API
# endpoints and produces a markdown gap report in docs/parity/.
#
# Usage: ./scripts/parsers/run-parity-check.sh
#
# Requirements:
#   - freegle-apiv1 container running
#   - Python 3 on host (at /usr/bin/python3)
#   - scripts/parsers/php-go-mapping.json present
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
MAPPING="$SCRIPT_DIR/php-go-mapping.json"
V1_API_DIR="/var/www/iznik/http/api"
PHP_EXTRACTOR_HOST="$SCRIPT_DIR/v1-behavior-extractor.php"
PHP_EXTRACTOR_CONTAINER="/var/www/iznik/scripts/parsers/v1-behavior-extractor.php"
GO_ROOT="$REPO_ROOT/iznik-server-go"
REPORT_DIR="$REPO_ROOT/docs/parity"
REPORT="$REPORT_DIR/$(date +%Y-%m-%d)-parity-report.md"
ANNOTATED_DIR="$REPORT_DIR/annotated"
TMP_DIR="$(mktemp -d)"
PROJECT_NAME=$(grep -E '^COMPOSE_PROJECT_NAME=' "$REPO_ROOT/.env" 2>/dev/null | cut -d= -f2)
PROJECT_NAME="${PROJECT_NAME:-freegle}"
CONTAINER="${PROJECT_NAME}-apiv1"

trap 'rm -rf "$TMP_DIR"' EXIT

mkdir -p "$REPORT_DIR" "$ANNOTATED_DIR"

echo "Copying PHP extractor into container..."
if ! docker exec "$CONTAINER" mkdir -p "$(dirname "$PHP_EXTRACTOR_CONTAINER")"; then
    echo "ERROR: failed to create directory in container $CONTAINER" >&2
    exit 1
fi
if ! docker cp "$PHP_EXTRACTOR_HOST" "$CONTAINER:$PHP_EXTRACTOR_CONTAINER"; then
    echo "ERROR: failed to copy PHP extractor into container $CONTAINER" >&2
    exit 1
fi

# Write report header now so partial results survive a mid-run failure
cat > "$REPORT" <<HEADER
# V1→V2 Migration Parity Report

Generated: $(date -u '+%Y-%m-%d %H:%M UTC')

Only NOT_FOUND and UNCERTAIN behaviors are shown per endpoint. The checker searches all V2 Go packages (not just the target package) to avoid false NOT_FOUNDs from transitive shared-class includes.
NOT_FOUND means the extractor found no evidence of the V1 behavior in V2 Go source.
UNCERTAIN means the table name could not be extracted from the V1 SQL string.
FOUND_PARTIAL means the table is in V2 but specific columns written by V1 are absent (possible missing column writes).

---

## Summary

| Endpoint | Behaviors | NOT_FOUND | UNCERTAIN | FOUND_PARTIAL |
|----------|-----------|-----------|-----------|---------------|
HEADER

# Read mapping as tab-separated pairs
declare -A PHP_TO_GO
while IFS=$'\t' read -r php go; do
    PHP_TO_GO["$php"]="$go"
done < <(python3 -c "
import json, sys
m = json.load(open('$MAPPING'))
for php, go in sorted(m.items()):
    print(php + '\t' + go)
")

# Associative arrays for report data
declare -A SUMMARY_ROWS
declare -A DETAILS

echo "Processing ${#PHP_TO_GO[@]} endpoints..."

while IFS= read -r php_file; do
    go_pkg="${PHP_TO_GO[$php_file]}"

    if [[ "$go_pkg" == SKIP:* ]]; then
        echo "  SKIP $php_file ($go_pkg)"
        continue
    fi

    go_dir="$GO_ROOT/$go_pkg"
    if [[ ! -d "$go_dir" ]]; then
        echo "  WARN: Go dir not found: $go_dir"
        continue
    fi

    echo -n "  $php_file ... "

    ledger="$TMP_DIR/${php_file%.php}.json"
    annotated="$TMP_DIR/${php_file%.php}-annotated.json"

    # Step 1: extract V1 behaviors
    if ! docker exec "$CONTAINER" php "$PHP_EXTRACTOR_CONTAINER" \
            "$V1_API_DIR/$php_file" \
            2>/dev/null > "$ledger"; then
        echo "EXTRACT ERROR"
        continue
    fi

    # Step 2: check V2 coverage
    if ! python3 "$SCRIPT_DIR/v2-coverage-checker.py" \
            "$ledger" "$GO_ROOT" \
            2>/dev/null > "$annotated"; then
        echo "CHECKER ERROR"
        continue
    fi
    # Persist annotated JSON for gap analysis tool
    cp "$annotated" "$ANNOTATED_DIR/${php_file%.php}.json"

    # Step 3: count results
    if ! counts=$(python3 - "$annotated" <<'PYEOF'
import json, sys
data = json.load(open(sys.argv[1]))
total = len(data)
not_found = sum(1 for b in data if b['v2_status'] == 'NOT_FOUND')
uncertain = sum(1 for b in data if b['v2_status'] == 'UNCERTAIN')
partial = sum(1 for b in data if b['v2_status'] == 'FOUND_PARTIAL')
print(f"{total}\t{not_found}\t{uncertain}\t{partial}")
PYEOF
); then
        echo "COUNT ERROR"
        continue
    fi
    total=$(echo "$counts" | cut -f1)
    not_found=$(echo "$counts" | cut -f2)
    uncertain=$(echo "$counts" | cut -f3)
    partial=$(echo "$counts" | cut -f4)

    SUMMARY_ROWS["$php_file"]="| \`$php_file\` | $total | $not_found | $uncertain | $partial |"

    # Step 4: collect gap details
    if ! details=$(python3 - "$annotated" <<'PYEOF'
import json, sys
data = json.load(open(sys.argv[1]))
gaps = [b for b in data if b['v2_status'] in ('NOT_FOUND', 'UNCERTAIN', 'FOUND_PARTIAL')]
if not gaps:
    print('(none)')
else:
    for b in gaps:
        status = b['v2_status']
        if status == 'FOUND_PARTIAL':
            missing = b.get('missing_columns', [])
            status = f"FOUND_PARTIAL: missing cols {', '.join(missing)}"
        cat = b['category']
        desc = b['description']
        loc = f"{b['file']}:{b['line']}"
        print(f"- [{status}] **{cat}**: {desc} — `{loc}`")
PYEOF
); then
        echo "DETAILS ERROR"
        continue
    fi
    DETAILS["$php_file"]="$details"

    echo "total=$total not_found=$not_found uncertain=$uncertain"
done < <(printf '%s\n' "${!PHP_TO_GO[@]}" | sort)

# Write summary table rows (sorted)
while IFS= read -r php_file; do
    echo "${SUMMARY_ROWS[$php_file]}" >> "$REPORT"
done < <(printf '%s\n' "${!SUMMARY_ROWS[@]}" | sort)

# Write per-endpoint details
{
    echo ""
    echo "---"
    echo ""
    echo "## Per-Endpoint Gaps"
} >> "$REPORT"

while IFS= read -r php_file; do
    {
        echo ""
        echo "### \`$php_file\`"
        echo ""
        echo "${DETAILS[$php_file]}"
    } >> "$REPORT"
done < <(printf '%s\n' "${!DETAILS[@]}" | sort)

echo ""
echo "Report written to: $REPORT"
