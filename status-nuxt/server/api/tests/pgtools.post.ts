import { setTestState, isTestRunning } from '../../utils/testState'
import { checkContainersReady, containersNotReadyMessage } from '../../utils/requiredContainers'
import { startPgSuite } from '../../utils/pgSuite'

const prefix = process.env.COMPOSE_PROJECT_NAME || 'freegle'

// The tests of the PostgreSQL tooling: the schema converter, the fixture loader, the data migration
// tooling, the stored functions, the ratchet itself, and the checks that the committed baseline and
// the routing service's test schema match what the converter makes now. The tests that need a database
// use scratch databases of their own in the postgres container and read the migrated MySQL schema.
const REQUIRED_CONTAINERS = ['percona', 'postgres']

export default defineEventHandler(async () => {
  if (isTestRunning('pgtools')) {
    throw createError({ statusCode: 409, message: 'The PostgreSQL tooling tests are already running' })
  }

  const readiness = checkContainersReady(prefix, REQUIRED_CONTAINERS)
  if (!readiness.ok) {
    const message = containersNotReadyMessage('PostgreSQL tooling', readiness)
    setTestState('pgtools', {
      status: 'failed',
      success: false,
      message,
      logs: message + '\n',
      progress: { completed: 0, total: 0, passed: 0, failed: 0, current: '' },
      startTime: Date.now(),
      endTime: Date.now(),
    })
    throw createError({ statusCode: 503, message })
  }

  const psql = `docker exec -i ${prefix}-postgres psql -U root`
  const mysql = `docker exec -i ${prefix}-percona mysql -uroot -piznik`
  // Each step runs whatever the one before did, and the run fails if any step did.
  const script = `
    set +e
    FAILED=""
    cd /project
    step() {
      name="$1"; shift
      echo "=== $name"
      if "$@"; then echo "PGTOOLS $name: ok"; else echo "PGTOOLS $name: FAILED"; FAILED="$FAILED $name"; fi
    }
    step "no-database tests" timeout 600 node --test --test-concurrency=1 tools/pg-ratchet/ratchet.test.mjs tools/pg-schema/convert.test.mjs tools/fixtures/fixtures.test.mjs tools/pg-migrate/migrate.test.mjs
    step "schema, functions and fixtures on PostgreSQL" env PG_SCHEMA_PSQL="${psql}" timeout 900 node --test --test-concurrency=1 tools/pg-schema/pg.test.mjs tools/pg-schema/functions.test.mjs tools/fixtures/pg.test.mjs
    step "migration rehearsal on both engines" env PG_PSQL="${psql}" MYSQL_CMD="${mysql}" timeout 900 node --test --test-concurrency=1 tools/pg-migrate/rehearse.test.mjs
    # The dump is kept under /tmp/pg-ratchet/pgtools, so a stale baseline can be regenerated from the very
    # schema CI built (DUMP_FILE=<it> tools/pg-schema/regenerate.sh).
    mkdir -p /tmp/pg-ratchet/pgtools
    docker exec ${prefix}-percona sh -c "mysqldump -uroot -piznik --no-data --routines --triggers iznik" 2>/dev/null > /tmp/pg-ratchet/pgtools/schema-dump.sql
    step "baseline matches the migrated schema" env COMPOSE_PROJECT_NAME=${prefix} DUMP_FILE=/tmp/pg-ratchet/pgtools/schema-dump.sql timeout 300 tools/pg-schema/regenerate.sh --check
    step "routing test schema matches the baseline" timeout 120 node iznik-routing-go/scripts/pg-test-schema.mjs --check
    if [ -n "$FAILED" ]; then echo "RATCHET pgtools: FAILED:$FAILED"; exit 1; fi
    echo "RATCHET pgtools: ok"
  `
  startPgSuite('pgtools', 'PostgreSQL tooling tests', script)
  return { status: 'started' }
})
