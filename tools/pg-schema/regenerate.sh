#!/bin/bash
# Regenerates iznik-batch/database/postgres/baseline.sql from a migrated MySQL schema.
#
# The dump is read-only: mysqldump --no-data from the percona container. Run it after
# scripts/setup-test-database.sh (or `php artisan migrate`) has built the schema.
#
#   COMPOSE_PROJECT_NAME=freegle-<name> tools/pg-schema/regenerate.sh
#
# MYSQL_DB names the schema to dump (default iznik).
set -euo pipefail

TOOL_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_DIR="$(cd "$TOOL_DIR/../.." && pwd)"
PREFIX="${COMPOSE_PROJECT_NAME:-freegle}"
DB="${MYSQL_DB:-iznik}"
OUT="${REPO_DIR}/iznik-batch/database/postgres/baseline.sql"
DUMP="$(mktemp)"
trap 'rm -f "$DUMP"' EXIT

echo "Dumping the ${DB} schema from ${PREFIX}-percona..."
docker exec "${PREFIX}-percona" sh -c "mysqldump -uroot -piznik --no-data --routines --triggers ${DB}" 2>/dev/null > "$DUMP"

mkdir -p "$(dirname "$OUT")"
node "${TOOL_DIR}/convert.mjs" "$DUMP" "$OUT" --migrations-dir "${REPO_DIR}/iznik-batch/database/migrations"
echo "Wrote ${OUT}"
