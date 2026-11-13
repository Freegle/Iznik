import { setTestState, isTestRunning } from '../../utils/testState'
import { checkContainersReady, containersNotReadyMessage } from '../../utils/requiredContainers'
import { startPgSuite, shq } from '../../utils/pgSuite'

const prefix = process.env.COMPOSE_PROJECT_NAME || 'freegle'

// The Laravel suite on PostgreSQL (DB_CONNECTION=pgsql). The MySQL run is /api/tests/laravel and is
// untouched; this one builds a fresh PostgreSQL iznik_batch_test from the baseline, runs every test
// file in chunks and checks the failures against tools/pg-ratchet/known-failures/laravel.txt.
// percona stays required because the parity tests compare the two engines.
const REQUIRED_CONTAINERS = ['percona', 'postgres', 'batch', 'spatial-knn']

export default defineEventHandler(async () => {
  if (isTestRunning('laravel-pg')) {
    throw createError({ statusCode: 409, message: 'Laravel tests on PostgreSQL are already running' })
  }

  const readiness = checkContainersReady(prefix, REQUIRED_CONTAINERS)
  if (!readiness.ok) {
    const message = containersNotReadyMessage('Laravel on PostgreSQL', readiness)
    setTestState('laravel-pg', {
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

  const exec = JSON.stringify([
    'docker', 'exec', '-w', '{cwd}',
    '-e', 'VIA_STATUS_CONTAINER=1',
    '-e', 'DB_CONNECTION=pgsql',
    '-e', 'TEST_DB_ENGINE=pgsql',
    '-e', 'PGSQL_DATABASE=iznik_batch_test',
    '-e', 'DB_DATABASE=iznik_batch_test',
    `${prefix}-batch`,
  ])

  const out = '/tmp/pg-ratchet/laravel'
  // The workers are stopped for the run and started again afterwards, as the MySQL run does, and
  // started even when the run fails.
  const script = `
    rm -rf ${out}; mkdir -p ${out}
    docker exec ${prefix}-batch supervisorctl stop all 2>&1 || true
    trap 'docker exec ${prefix}-batch supervisorctl start all 2>&1 || true' EXIT
    echo "Building the PostgreSQL iznik_batch_test from the baseline..."
    COMPOSE_PROJECT_NAME=${prefix} PG_DATABASE=iznik_batch_test PG_TEST_DATABASE=iznik_batch_test PG_REBUILD=1 PG_FIXTURES=0 /project/scripts/setup-pg-database.sh
    docker exec ${prefix}-batch php artisan view:clear 2>&1 || true
    docker exec ${prefix}-batch php artisan config:clear 2>&1 || true
    node /project/tools/pg-ratchet/laravel-suite.mjs --deadline-min 30 --out ${out} --phpunit-xml /project/iznik-batch/phpunit.xml --exec-json ${shq(exec)}
    node /project/tools/pg-ratchet/ratchet.mjs check --suite laravel --results ${out}/laravel-results.txt --list /project/tools/pg-ratchet/known-failures/laravel.txt
  `
  startPgSuite('laravel-pg', 'Laravel tests on PostgreSQL', script)
  return { status: 'started' }
})
