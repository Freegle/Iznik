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
# from them: iznik (the dev database) and iznik_go_test (schema only, as on MySQL). Fixture data for
# PostgreSQL does not exist yet, so neither database has rows.
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

build "$PG_DATABASE" || exit 1
build "$PG_TEST_DATABASE" || exit 1
echo "PostgreSQL databases ready (schema only; PostgreSQL fixtures are not built yet)"
