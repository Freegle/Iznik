#!/bin/bash
# Set up test databases via Laravel migrations (single source of truth) + captured fixtures.
#
# Laravel migrations in iznik-batch are the authoritative schema definition. Test fixture
# DATA lives in scripts/test-fixtures.sql (captured once from the retired V1 seeders — see
# scripts/regenerate-test-fixtures.sh). All DB operations run through the `percona`
# container's mysql/mysqldump clients; the `batch` container runs the migrations.

# Resolve the repo dir from the script location BEFORE any cd (robust on CI + local).
REPO_DIR="$(cd "$(dirname "$(readlink -f "$0")")/.." && pwd)"

# Support both CircleCI (~/project) and local (~/FreegleDocker) paths
if [ -d "$HOME/FreegleDocker" ]; then
    cd "$HOME/FreegleDocker"
fi
echo "Setting up test database and environment..."

# Use dynamic container name prefix (freegle-ci on CI, freegle locally)
PREFIX="${COMPOSE_PROJECT_NAME:-freegle}"

# Verify required containers are still running
echo "Verifying required containers..."
for service in percona batch; do
    container="${PREFIX}-${service}"
    if ! docker inspect -f '{{.State.Running}}' "$container" 2>/dev/null | grep -q "true"; then
    echo "Container $container is not running!"
    echo ""
    echo "=== Container status ==="
    docker ps -a --filter "name=$container" --format "table {{.Names}}\t{{.Status}}\t{{.State}}"
    echo ""
    echo "=== Container logs (last 50 lines) ==="
    docker logs "$container" --tail 50 2>&1 || echo "Could not get logs"
    echo ""
    echo "=== All container statuses ==="
    docker ps -a --format "table {{.Names}}\t{{.Status}}\t{{.State}}" | head -30
    exit 1
    fi
    echo "$container is running"
done

# 1. Create database and run Laravel migrations (single source of truth)
# On self-hosted runner, drop and recreate the database to clear stale test data
# from previous CI runs. Cloud CI always starts fresh.
if [ "${SELF_HOSTED_RUNNER:-}" = "true" ]; then
  echo "Self-hosted runner: dropping iznik database to ensure clean state..."
  docker exec "${PREFIX}-percona" sh -c "mysql -u root -piznik -e 'DROP DATABASE IF EXISTS iznik;'"
fi
echo "Creating iznik database and running Laravel migrations..."
docker exec "${PREFIX}-percona" sh -c "mysql -u root -piznik -e 'CREATE DATABASE IF NOT EXISTS iznik;'"
if ! docker exec "${PREFIX}-batch" php artisan migrate --force --no-interaction; then
  echo "Laravel migrations FAILED — aborting test database setup"
  docker logs "${PREFIX}-batch" --tail 50 2>&1 || true
  exit 1
fi
echo "Laravel migrations complete"

# 2. Set SQL mode (disable ONLY_FULL_GROUP_BY)
echo "Setting SQL mode..."
docker exec "${PREFIX}-percona" sh -c "mysql -u root -piznik \
  -e \"SET GLOBAL sql_mode = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'\" && \
  mysql -u root -piznik \
  -e \"SET GLOBAL sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''));\""

# 2.5. Verify every table referenced in test-fixtures.sql still exists in the
# just-migrated schema. A migration that drops a table (e.g. drop_keyword_search_index)
# without also removing its LOCK TABLES/INSERT block from test-fixtures.sql breaks
# the fixture load below with a cryptic "ERROR 1146 ... doesn't exist" that aborts on
# the FIRST stale table and hides any others. Catch ALL stale blocks here up front.
echo "Checking test-fixtures.sql tables against migrated schema..."
FIXTURE_TABLES=$(grep -oP "(?<=^LOCK TABLES \`)[^\`]+" "${REPO_DIR}/scripts/test-fixtures.sql" | sort -u)
STALE_TABLES=""
for table in $FIXTURE_TABLES; do
  exists=$(docker exec "${PREFIX}-percona" sh -c "mysql -u root -piznik -N -B -e \"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='iznik' AND table_name='${table}'\"")
  if [ "$exists" = "0" ]; then
    STALE_TABLES="${STALE_TABLES} ${table}"
  fi
done
if [ -n "$STALE_TABLES" ]; then
  echo "ERROR: scripts/test-fixtures.sql references tables that no longer exist in the migrated schema:${STALE_TABLES}"
  echo "A migration dropped these tables. Remove their LOCK TABLES/INSERT blocks from scripts/test-fixtures.sql."
  exit 1
fi
echo "Fixture tables OK"

# 3. Load captured fixture data into iznik (replaces the retired V1 testenv.php seeding).
# Fixtures are idempotent (INSERT IGNORE, explicit ids) so this is safe on a fresh or
# already-populated iznik. Used by the running dev/prod stack + Playwright E2E; the Go
# suite runs against the schema-only iznik_go_test clone below and self-seeds its own data.
echo "Loading test fixtures into iznik (scripts/test-fixtures.sql)..."
docker cp "${REPO_DIR}/scripts/test-fixtures.sql" "${PREFIX}-percona:/tmp/test-fixtures.sql"
if ! docker exec "${PREFIX}-percona" sh -c "mysql -u root -piznik iznik < /tmp/test-fixtures.sql"; then
  echo "Fixture load FAILED — aborting test database setup"
  exit 1
fi
echo "Fixtures loaded"

# 3a. Roll the fixture posts forward so they are recent.
#
# test-fixtures.sql is a mysqldump, so every message carries the ABSOLUTE date it
# had when the dump was taken. The browse/explore feed only returns posts from the
# last 31 days (group/groupMessages.go: `then := now.AddDate(0, 0, -31)`), so the
# seeded posts silently drop out of the feed 31 days after the dump was captured —
# and every test that browses for a pre-seeded post starts failing, on every branch
# at once, with no code change to blame.
#
# That is not hypothetical: the fixtures were dated 2026-07-09 10:01, and on
# 2026-08-09 the suite went from green at 07:58 to five failures at 10:30. The
# 31-day boundary fell at 10:02. Tests that CREATE a post and then browse for it
# kept passing throughout, which is what made it look like a flake.
#
# Shifting by a whole number of days preserves the relative ages and the ordering
# the fixtures encode, and lands the newest post one day old. It is self-limiting:
# once the newest post is a day old the delta is 0, so re-running is a no-op.
# `deadline` is deliberately NOT shifted — some fixtures carry a deliberately
# expired deadline, which is the point of the tests that use them.
echo "Rolling fixture post dates forward so they stay inside the feed's 31-day window..."
if ! docker exec -i "${PREFIX}-percona" sh -c "mysql -u root -piznik iznik" <<'SQL'
SET @delta := (
  SELECT GREATEST(DATEDIFF(NOW(), MAX(arrival)) - 1, 0)
  FROM messages_groups
  WHERE arrival <= NOW()
);

UPDATE messages_groups
   SET arrival     = arrival     + INTERVAL @delta DAY,
       approvedat  = approvedat  + INTERVAL @delta DAY,
       rejectedat  = rejectedat  + INTERVAL @delta DAY
 WHERE @delta > 0 AND arrival <= NOW();

UPDATE messages
   SET arrival = arrival + INTERVAL @delta DAY,
       date    = date    + INTERVAL @delta DAY
 WHERE @delta > 0 AND arrival <= NOW();

-- messages_spatial is the table browse and explore actually read the feed from,
-- so leaving it behind empties the feed even when the other two look right.
UPDATE messages_spatial
   SET arrival = arrival + INTERVAL @delta DAY
 WHERE @delta > 0 AND arrival <= NOW();

SELECT CONCAT('Fixture posts shifted forward by ', @delta, ' day(s); newest is now ',
              IFNULL((SELECT MAX(arrival) FROM messages_groups), 'n/a'),
              ' (spatial ', IFNULL((SELECT MAX(arrival) FROM messages_spatial), 'n/a'), ')') AS result;
SQL
then
  echo "Fixture date roll-forward FAILED — aborting (the feed tests would fail with no obvious cause)"
  exit 1
fi

# Assert it worked. If the newest seeded post is outside the feed window the browse
# and reply-flow specs will all fail on an empty feed, and the cause is invisible
# from the test output — it looks like a flake on whichever branch happens to run.
# Fail here instead, where the message names the actual problem.
FEED_WINDOW_DAYS=31
NEWEST_AGE=$(docker exec "${PREFIX}-percona" sh -c \
  "mysql -u root -piznik iznik -N -B -e \"SELECT DATEDIFF(NOW(), MAX(arrival)) FROM messages_groups WHERE arrival <= NOW()\"" 2>/dev/null | tr -d '[:space:]')
if [ -n "$NEWEST_AGE" ] && [ "$NEWEST_AGE" -ge "$FEED_WINDOW_DAYS" ] 2>/dev/null; then
  echo "Newest seeded post is ${NEWEST_AGE} days old, outside the ${FEED_WINDOW_DAYS}-day feed window"
  echo "(group/groupMessages.go). Browse and explore would return nothing and every"
  echo "test that browses for a seeded post would fail. Aborting."
  exit 1
fi
echo "Newest seeded post is ${NEWEST_AGE:-?} day(s) old — inside the ${FEED_WINDOW_DAYS}-day feed window"

# 4. Create iznik_go_test by cloning schema (no data) from the migrated iznik DB.
# Go tests create their own fixture data at runtime, so only the schema is needed.
echo "Setting up iznik_go_test database for Go tests..."
docker exec "${PREFIX}-percona" sh -c "\
    mysql -u root -piznik -e 'DROP DATABASE IF EXISTS iznik_go_test; CREATE DATABASE iznik_go_test;' && \
    mysqldump -u root -piznik --no-data --routines --triggers iznik | \
      mysql -u root -piznik iznik_go_test"
echo "iznik_go_test ready (schema cloned from migrated iznik)"

echo "Test database and environment ready!"
