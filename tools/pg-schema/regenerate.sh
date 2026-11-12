#!/bin/bash
# Regenerates iznik-batch/database/postgres/baseline.sql from a migrated MySQL schema.
#
# The dump is read-only: mysqldump --no-data from the percona container. Run it after
# scripts/setup-test-database.sh (or `php artisan migrate`) has built the schema.
#
#   COMPOSE_PROJECT_NAME=freegle-<name> tools/pg-schema/regenerate.sh          # rewrite the baseline
#   COMPOSE_PROJECT_NAME=freegle-<name> tools/pg-schema/regenerate.sh --check  # fail if it is stale
#
# --check converts the same dump to a temporary file and compares it with the committed baseline,
# so it shows when a migration or the converter has changed and the baseline has not been
# regenerated. It writes nothing.
#
# Settings, all optional:
#   MYSQL_DB     the schema to dump (default iznik)
#   DUMP_FILE    use this mysqldump file instead of dumping from the container
set -euo pipefail

TOOL_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_DIR="$(cd "$TOOL_DIR/../.." && pwd)"
PREFIX="${COMPOSE_PROJECT_NAME:-freegle}"
DB="${MYSQL_DB:-iznik}"
BASELINE="${REPO_DIR}/iznik-batch/database/postgres/baseline.sql"
CHECK=""
[ "${1:-}" = "--check" ] && CHECK=1

TMP="$(mktemp)"
DUMP="${DUMP_FILE:-$(mktemp)}"
trap 'rm -f "$TMP"; [ -n "${DUMP_FILE:-}" ] || rm -f "$DUMP"' EXIT

if [ -z "${DUMP_FILE:-}" ]; then
  echo "Dumping the ${DB} schema from ${PREFIX}-percona..."
  docker exec "${PREFIX}-percona" sh -c "mysqldump -uroot -piznik --no-data --routines --triggers ${DB}" 2>/dev/null > "$DUMP"
fi
if ! grep -q '^CREATE TABLE ' "$DUMP"; then
  echo "The dump has no tables. Build the MySQL schema first (scripts/setup-test-database.sh)."
  exit 1
fi

node "${TOOL_DIR}/convert.mjs" "$DUMP" "$TMP" --migrations-dir "${REPO_DIR}/iznik-batch/database/migrations"

if [ -n "$CHECK" ]; then
  if cmp -s "$TMP" "$BASELINE"; then
    echo "The committed baseline matches a fresh conversion."
    exit 0
  fi
  echo "The committed baseline is stale. Run tools/pg-schema/regenerate.sh and commit the result."
  diff "$BASELINE" "$TMP" | head -40 || true
  exit 1
fi

mkdir -p "$(dirname "$BASELINE")"
cp "$TMP" "$BASELINE"
echo "Wrote ${BASELINE}"
