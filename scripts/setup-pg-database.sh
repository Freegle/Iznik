#!/bin/bash
# Builds the PostgreSQL test databases from the generated baseline.
#
# Called by scripts/setup-test-database.sh when the PostgreSQL container is running, and runnable
# on its own:
#
#   COMPOSE_PROJECT_NAME=freegle-<name> ./scripts/setup-pg-database.sh
#
# The schema comes from iznik-batch/database/postgres/baseline.sql, which
# tools/pg-schema/regenerate.sh produces from the migrated MySQL schema. The stored functions come
# from iznik-batch/database/postgres/functions.sql, loaded in the same transaction. Two databases are built
# from them: iznik (the dev database) and iznik_go_test (schema only, as on MySQL). The fixtures in
# scripts/fixtures are then loaded into iznik, as on MySQL, by tools/fixtures/load.mjs (Node, on the host or
# in a container), and the fixture posts are rolled forward so they stay inside the feed's 31 day window.
#
# A database that already has tables is left alone, so re-running is cheap, except that the functions
# file is loaded again over it (it is all CREATE OR REPLACE) so a change to a function reaches a
# database that was built before. PG_REBUILD=1 (or SELF_HOSTED_RUNNER=true) drops and rebuilds it.
#
# Settings, all optional:
#   COMPOSE_PROJECT_NAME  container prefix, as for setup-test-database.sh (default freegle)
#   PG_CONTAINER          the PostgreSQL container (default <prefix>-postgres)
#   PG_PSQL               a psql command line without -d, replacing the docker exec default
#   PG_DATABASE           dev database name (default iznik)
#   PG_TEST_DATABASE      Go test database name (default iznik_go_test)
#   PG_BASELINE           a baseline file to load instead of the committed one (tests use this)
#   PG_FIXTURES           0 skips the fixtures (default: load them into PG_DATABASE)
#   PG_FIXTURE_ORPHANS    1 prints every foreign key that loaded rows break, after the load
#   PG_FUNCTIONS          a functions file to load instead of the committed one

REPO_DIR="$(cd "$(dirname "$(readlink -f "$0")")/.." && pwd)"
BASELINE="${PG_BASELINE:-${REPO_DIR}/iznik-batch/database/postgres/baseline.sql}"
FUNCTIONS="${PG_FUNCTIONS:-${REPO_DIR}/iznik-batch/database/postgres/functions.sql}"
PREFIX="${COMPOSE_PROJECT_NAME:-freegle}"
PG_CONTAINER="${PG_CONTAINER:-${PREFIX}-postgres}"
PG_DATABASE="${PG_DATABASE:-iznik}"
PG_TEST_DATABASE="${PG_TEST_DATABASE:-iznik_go_test}"

if [ -z "${PG_PSQL:-}" ]; then
  PG_PSQL="docker exec -i ${PG_CONTAINER} psql -U root"
  echo "Verifying ${PG_CONTAINER} is running..."
  if ! docker inspect -f '{{.State.Running}}' "$PG_CONTAINER" 2>/dev/null | grep -q "true"; then
    echo "Container ${PG_CONTAINER} is not running! Start the stack with the database profile."
    exit 1
  fi
  echo "${PG_CONTAINER} is running"
fi

if [ ! -f "$BASELINE" ]; then
  echo "Missing ${BASELINE}. Run tools/pg-schema/regenerate.sh."
  exit 1
fi
if [ ! -f "$FUNCTIONS" ]; then
  echo "Missing ${FUNCTIONS}."
  exit 1
fi

# Wait for the server to take connections.
ready=""
for _ in $(seq 1 30); do
  if $PG_PSQL -d postgres -X -q -At -c "SELECT 1" >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 2
done
if [ -z "$ready" ]; then
  echo "PostgreSQL did not accept connections"
  exit 1
fi

build() {
  local db="$1"
  local exists tables
  exists=$($PG_PSQL -d postgres -X -q -At -c "SELECT 1 FROM pg_database WHERE datname = '${db}'")
  tables=0
  if [ "$exists" = "1" ]; then
    tables=$($PG_PSQL -d "$db" -X -q -At -c "SELECT count(*) FROM pg_class WHERE relkind = 'r' AND relnamespace = 'public'::regnamespace AND relname <> 'spatial_ref_sys'")
  fi
  if [ "$tables" != "0" ] && [ "${PG_REBUILD:-}" != "1" ] && [ "${SELF_HOSTED_RUNNER:-}" != "true" ]; then
    echo "PostgreSQL database ${db} already has ${tables} tables; set PG_REBUILD=1 to rebuild it"
    if ! $PG_PSQL -d "$db" -X -q -1 -v ON_ERROR_STOP=1 < "$FUNCTIONS" >/dev/null; then
      echo "Loading the PostgreSQL stored functions into ${db} FAILED"
      return 1
    fi
    return 0
  fi
  echo "Building PostgreSQL database ${db} from the baseline..."
  # The baseline and the functions load in one transaction (-1), so a failure leaves an empty database
  # that the next run rebuilds, never a half-built one that looks finished.
  if ! $PG_PSQL -d postgres -X -q -v ON_ERROR_STOP=1 -c "DROP DATABASE IF EXISTS \"${db}\" WITH (FORCE)" -c "CREATE DATABASE \"${db}\""; then
    echo "Could not create PostgreSQL database ${db}"
    return 1
  fi
  if ! { cat "$BASELINE"; echo; cat "$FUNCTIONS"; } | $PG_PSQL -d "$db" -X -q -1 -v ON_ERROR_STOP=1 >/dev/null; then
    echo "Loading the PostgreSQL baseline and functions into ${db} FAILED"
    return 1
  fi
  tables=$($PG_PSQL -d "$db" -X -q -At -c "SELECT count(*) FROM pg_class WHERE relkind = 'r' AND relnamespace = 'public'::regnamespace AND relname <> 'spatial_ref_sys'")
  echo "PostgreSQL database ${db} ready (${tables} tables)"
}

# The fixture loader is Node 20 or later. Use the host's, or a throwaway node container where the host has
# none or an older one.
fixture_loader() {
  if command -v node >/dev/null 2>&1 && [ "$(node -p 'process.versions.node.split(".")[0]')" -ge 20 ] 2>/dev/null; then
    node "${REPO_DIR}/tools/fixtures/load.mjs" "$@"
  else
    docker run --rm -v "${REPO_DIR}:/repo:ro" node:22-slim node /repo/tools/fixtures/load.mjs "$@"
  fi
}

# Loads the neutral fixtures into one database, in a single transaction: a value PostgreSQL refuses
# stops the load and leaves the database as it was, so a half-seeded database never looks finished.
# Foreign keys are switched off for the load, as on MySQL, which needs a superuser. The load can be
# repeated: rows already there are left alone.
load_fixtures() {
  local db="$1"
  echo "Loading test fixtures into PostgreSQL database ${db} (scripts/fixtures)..."
  if ! ( set -o pipefail; fixture_loader --engine pgsql | $PG_PSQL -d "$db" -X -q -1 -v ON_ERROR_STOP=1 >/dev/null ); then
    echo "Loading the fixtures into PostgreSQL database ${db} FAILED"
    return 1
  fi
  echo "Fixtures loaded into ${db}"
}

# The same roll-forward as setup-test-database.sh step 3a, for the same reason: the fixture posts carry
# the dates they were captured with, and the feed only shows the last 31 days. Shifting by whole days
# keeps their relative ages, and is a no-op once the newest post is a day old. `deadline` is not moved.
roll_forward_fixtures() {
  local db="$1" age
  if ! $PG_PSQL -d "$db" -X -q -v ON_ERROR_STOP=1 -f - >/dev/null <<'SQL'
SET TIME ZONE 'UTC';
SELECT GREATEST((CURRENT_DATE - max(arrival)::date) - 1, 0) AS delta FROM messages_groups WHERE arrival <= now() \gset
UPDATE messages_groups
   SET arrival    = arrival    + make_interval(days => :delta),
       approvedat = approvedat + make_interval(days => :delta),
       rejectedat = rejectedat + make_interval(days => :delta)
 WHERE :delta > 0 AND arrival <= now();
UPDATE messages
   SET arrival = arrival + make_interval(days => :delta),
       date    = date    + make_interval(days => :delta)
 WHERE :delta > 0 AND arrival <= now();
UPDATE messages_spatial
   SET arrival = arrival + make_interval(days => :delta)
 WHERE :delta > 0 AND arrival <= now();
SQL
  then
    echo "Fixture date roll-forward FAILED on PostgreSQL database ${db}"
    return 1
  fi
  age=$($PG_PSQL -d "$db" -X -q -At -c "SELECT CURRENT_DATE - max(arrival)::date FROM messages_groups WHERE arrival <= now()" | tr -d '[:space:]')
  if [ -n "$age" ] && [ "$age" -ge 31 ] 2>/dev/null; then
    echo "Newest seeded post in ${db} is ${age} days old, outside the 31 day feed window. Aborting."
    return 1
  fi
  echo "Newest seeded post in ${db} is ${age:-?} day(s) old, inside the feed window"
}

build "$PG_DATABASE" || exit 1
if [ "$PG_TEST_DATABASE" != "$PG_DATABASE" ]; then
  build "$PG_TEST_DATABASE" || exit 1
fi
if [ "${PG_FIXTURES:-}" != "0" ]; then
  load_fixtures "$PG_DATABASE" || exit 1
  roll_forward_fixtures "$PG_DATABASE" || exit 1
  if [ "${PG_FIXTURE_ORPHANS:-}" = "1" ]; then
    $PG_PSQL -d "$PG_DATABASE" -X -q -At -f - < "${REPO_DIR}/tools/fixtures/orphans.pg.sql" 2>&1
  fi
  echo "PostgreSQL databases ready (${PG_DATABASE} has the fixtures; ${PG_TEST_DATABASE} is schema only)"
else
  echo "PostgreSQL databases ready (schema only; PG_FIXTURES=0)"
fi
