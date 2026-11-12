import { execSync } from 'child_process'
import { setTestState, isTestRunning } from '../../utils/testState'
import { checkContainersReady, containersNotReadyMessage } from '../../utils/requiredContainers'
import { startPgSuite, shq } from '../../utils/pgSuite'

const prefix = process.env.COMPOSE_PROJECT_NAME || 'freegle'

// The Go API suite on PostgreSQL (DB_ENGINE=postgres). The MySQL run is /api/tests/go and is
// untouched; this one builds a fresh PostgreSQL iznik_go_test from the baseline, runs every test and
// checks the failures against tools/pg-ratchet/known-failures/go.txt. percona stays required because
// the parity tests compare the two engines.
const REQUIRED_CONTAINERS = ['percona', 'postgres', 'apiv2', 'spatial-knn']

export default defineEventHandler(async () => {
  if (isTestRunning('go-pg')) {
    throw createError({ statusCode: 409, message: 'Go tests on PostgreSQL are already running' })
  }

  const readiness = checkContainersReady(prefix, REQUIRED_CONTAINERS)
  if (!readiness.ok) {
    const message = containersNotReadyMessage('Go on PostgreSQL', readiness)
    setTestState('go-pg', {
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

  // Go's resolver goes stale inside Docker, so the Go process gets the addresses, as the MySQL run does.
  const ip = (service: string) => {
    try {
      return execSync(`docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' ${prefix}-${service}`, { encoding: 'utf8' }).trim() || service
    } catch {
      return service
    }
  }

  const exec = JSON.stringify([
    'docker', 'exec', '-w', '{cwd}',
    '-e', 'DB_ENGINE=postgres',
    '-e', `PGSQL_HOST=${ip('postgres')}`,
    '-e', 'PGSQL_DATABASE=iznik_go_test',
    '-e', `MYSQL_HOST=${ip('percona')}`,
    '-e', 'MYSQL_DBNAME=iznik_go_test',
    '-e', 'PG_ENGINE=1',
    `${prefix}-apiv2`,
  ])

  const out = '/tmp/pg-ratchet/go'
  const script = `
    rm -rf ${out}; mkdir -p ${out}
    echo "Building the PostgreSQL iznik_go_test from the baseline..."
    COMPOSE_PROJECT_NAME=${prefix} PG_DATABASE=iznik_go_test PG_TEST_DATABASE=iznik_go_test PG_REBUILD=1 PG_FIXTURES=0 /project/scripts/setup-pg-database.sh
    node /project/tools/pg-ratchet/go-suite.mjs --root /app --out ${out} --exec-json ${shq(exec)}
    node /project/tools/pg-ratchet/ratchet.mjs check --suite go --results ${out}/go-results.txt --list /project/tools/pg-ratchet/known-failures/go.txt
  `
  startPgSuite('go-pg', 'Go tests on PostgreSQL', script)
  return { status: 'started' }
})
