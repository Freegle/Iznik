#!/bin/bash
# Regenerate the test fixtures used by the local/CI test bootstrap.
#
# Produces, from a single clean seeding pass, two consistent artifacts:
#   - scripts/fixtures/tables/*.jsonl           (rows loaded into `iznik` after migrations)
#   - iznik-nuxt3/tests/e2e/test-envs.json      (per-prefix seeded IDs for Playwright)
#
# IMPORTANT: This script requires the (removed) V1 PHP `apiv1` container and its
# install/testenv.php + install/create-test-env.php. It only runs against a checkout
# that still contains `iznik-server/` (i.e. before this removal, via git history).
# It is committed as documentation of how the fixtures were produced; it is NOT part
# of the normal test bootstrap (see scripts/setup-test-database.sh for that).
#
# Only the tables are rewritten. scripts/fixtures/captured-steps.json, extra-steps.json and
# repairs.json are kept by hand; check them against the new tables afterwards
# (docs/developers/reference/test-fixtures.md).
set -euo pipefail

PREFIX="${COMPOSE_PROJECT_NAME:-freegle}"
REPO="$(cd "$(dirname "$0")/.." && pwd)"

# The set of Playwright spec prefixes that request a seeded environment — i.e. every e2e spec
# that uses the `testEnv` OR `postMessage` fixture (postMessage pulls in testEnv). The prefix
# is derived from the spec filename: test-modtools-X.spec.js -> mtX, test-X.spec.js -> X (dashes
# stripped). To refresh this list:
#   for f in iznik-nuxt3/tests/e2e/*.spec.js; do grep -lq 'testEnv\|postMessage' "$f" && \
#     basename "$f" .spec.js | sed 's/test-modtools-/mt/; s/test-//; s/-//g'; done | sort -u
PREFIXES="browse bulkofferflow bulkuxreview contactvolunteers editdeadline explore \
extendexpireddeadline mtchatlist mtchatreply mtdashboard mteditmessage mtedits mteditsflow \
mtholdrelease mtmemberlogs mtmemberreview mtmovemessage mtpageloads mtpendingmessages \
mtsettingsmodconfig mtspammers mtsupport postflow replyflowedgecases replyflowexistinguser \
replyflowloggedin replyflowlogging replyflownewuser replyflowsocial replytochat \
repostgroupchange userratings v2apipages"

mysql_e() { docker exec "${PREFIX}-apiv1" sh -c "mysql -h percona -u root -piznik -e \"$1\""; }

echo "[1/5] Fresh iznik DB + Laravel migrations..."
mysql_e "DROP DATABASE IF EXISTS iznik; CREATE DATABASE iznik;"
docker exec "${PREFIX}-batch" php artisan migrate --force --no-interaction >/dev/null
mysql_e "SET GLOBAL sql_mode='NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'"
mysql_e "SET GLOBAL sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))"

echo "[2/5] Base fixtures (testenv.php)..."
docker exec "${PREFIX}-apiv1" sh -c "rm -f /tmp/iznik.dbstatus.*.down; cd /var/www/iznik && php install/testenv.php" >/dev/null 2>&1

echo "[3/5] Per-prefix Playwright envs (create-test-env.php)..."
TMP="$(mktemp -d)"
for p in $PREFIXES; do
  docker exec "${PREFIX}-apiv1" sh -c "cd /var/www/iznik && php install/create-test-env.php $p" 2>/dev/null > "$TMP/$p.json"
done

echo "[4/5] Assemble test-envs.json..."
{
  echo "{"
  first=1
  for p in $PREFIXES; do
    [ $first -eq 0 ] && echo ","
    printf '  "%s": ' "$p"; cat "$TMP/$p.json"
    first=0
  done
  echo "}"
} | jq . > "$REPO/iznik-nuxt3/tests/e2e/test-envs.json"
rm -rf "$TMP"

echo "[5/5] Export the fixture tables (scripts/fixtures/tables)..."
DUMP_DIR="$(mktemp -d)"
docker exec "${PREFIX}-apiv1" sh -c "mysqldump -h percona -u root -piznik \
    --no-create-info --complete-insert --insert-ignore --hex-blob \
    --skip-triggers --no-tablespaces --single-transaction --skip-comments \
    --ignore-table=iznik.migrations iznik" 2>/dev/null > "$DUMP_DIR/data.sql"
docker exec "${PREFIX}-apiv1" sh -c "mysqldump -h percona -u root -piznik --no-data --skip-comments iznik" 2>/dev/null > "$DUMP_DIR/schema.sql"
node "$REPO/tools/fixtures/export.mjs" --dump "$DUMP_DIR/data.sql" --schema "$DUMP_DIR/schema.sql" --out "$REPO/scripts/fixtures"
rm -rf "$DUMP_DIR"

echo "Done. Artifacts:"
echo "  scripts/fixtures/tables              ($(ls "$REPO/scripts/fixtures/tables" | wc -l) tables)"
echo "  iznik-nuxt3/tests/e2e/test-envs.json ($(jq 'keys|length' "$REPO/iznik-nuxt3/tests/e2e/test-envs.json") prefixes)"
