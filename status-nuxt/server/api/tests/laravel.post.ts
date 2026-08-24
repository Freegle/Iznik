import { spawn, execSync } from 'child_process'
import { getTestState, setTestState, appendTestLogs, isTestRunning } from '../../utils/testState'
import { checkContainersReady, containersNotReadyMessage } from '../../utils/requiredContainers'

const prefix = process.env.COMPOSE_PROJECT_NAME || 'freegle'

// What the Unit+Feature suites actually talk to: the database, the container the
// tests run in, and the spatial-knn finder that SeedsSpatialIndex posts to. With
// spatial-knn down the suite still ran to the end and reported 31 errors that read
// like broken code (Discourse 10001 investigation, 2026-08-10).
const REQUIRED_CONTAINERS = ['percona', 'batch', 'spatial-knn']

export default defineEventHandler(async (event) => {
  console.log('Starting Laravel tests...')

  // Read optional filter/testsuite params from request body
  let filter = ''
  let testsuite = 'Unit,Feature'
  try {
    const body = await readBody(event)
    if (body?.filter) filter = body.filter
    if (body?.testsuite) testsuite = body.testsuite
  } catch {}

  // Check if already running
  if (isTestRunning('laravel')) {
    throw createError({
      statusCode: 409,
      message: 'Laravel tests are already running'
    })
  }

  // Refuse to start rather than produce a red run that blames the code.
  const readiness = checkContainersReady(prefix, REQUIRED_CONTAINERS)
  if (!readiness.ok) {
    const message = containersNotReadyMessage('Laravel', readiness)
    setTestState('laravel', {
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

  // Initialize test status
  setTestState('laravel', {
    status: 'running',
    message: 'Starting Laravel tests...',
    logs: '',
    progress: { completed: 0, total: 0, passed: 0, failed: 0, current: '' },
    startTime: Date.now(),
    endTime: null,
  })

  // Run tests asynchronously
  const testProcess = spawn('sh', ['-c', `
    set -e
    echo "Setting up Laravel test environment..."

    # Stop supervisor workers before running tests
    echo "Stopping supervisor workers..."
    docker exec ${prefix}-batch supervisorctl stop all 2>&1 || true

    # Set up fresh test database
    echo "Setting up fresh test database..."
    docker exec ${prefix}-batch mysql -h percona -u root -piznik --skip-ssl -e "CREATE DATABASE IF NOT EXISTS iznik_batch_test" 2>&1
    docker exec -e DB_DATABASE=iznik_batch_test ${prefix}-batch php artisan migrate:fresh --database=mysql --force 2>&1

    # Recompile Blade views from the working tree. Without this, a previously
    # compiled view (e.g. an old email template referencing a now-removed
    # variable) is rendered instead of the current source, causing spurious
    # MJML "Undefined variable" render errors in the mail tests.
    echo "Clearing compiled views/config..."
    docker exec ${prefix}-batch php artisan view:clear 2>&1 || true
    docker exec ${prefix}-batch php artisan config:clear 2>&1 || true

    echo "Running Laravel tests with coverage..."
    docker exec -e VIA_STATUS_CONTAINER=1 -e DB_DATABASE=iznik_batch_test ${prefix}-batch vendor/bin/phpunit --testsuite=${testsuite}${filter ? ` --filter="${filter}"` : ''} --coverage-clover=/tmp/laravel-coverage.xml 2>&1
  `], { stdio: 'pipe' })

  testProcess.stdout.on('data', (data) => {
    const text = data.toString()
    appendTestLogs('laravel', text)
    parseLaravelTestOutput(text)
  })

  testProcess.stderr.on('data', (data) => {
    appendTestLogs('laravel', data.toString())
  })

  testProcess.on('close', (code) => {
    const state = getTestState('laravel')
    const p = state.progress
    const total = p.total > 0 ? p.total : p.completed
    const passed = total - p.failed

    setTestState('laravel', {
      status: code === 0 ? 'completed' : 'failed',
      success: code === 0,
      endTime: Date.now(),
      message: code === 0
        ? `All ${total} tests passed ✓`
        : `Tests failed: ${passed}✓ ${p.failed}✗ of ${total}`,
    })
    console.log(`Laravel tests completed with code ${code}`)

    // Restart supervisor workers
    try {
      execSync(`docker exec ${prefix}-batch supervisorctl start all 2>&1 || true`, {
        encoding: 'utf8',
        timeout: 30000,
      })
      console.log('Restarted supervisor workers after Laravel tests')
    } catch (e: any) {
      console.log('Warning: Failed to restart supervisor workers:', e.message)
    }
  })

  testProcess.on('error', (error) => {
    setTestState('laravel', {
      status: 'failed',
      message: `Error: ${error.message}`,
      endTime: Date.now(),
    })
  })

  return { status: 'started' }
})

function parseLaravelTestOutput(text: string) {
  const state = getTestState('laravel')
  const lines = text.split('\n')

  for (const line of lines) {
    // Paratest progress: "63 / 801 (  7%)"
    const paratestMatch = line.match(/(\d+)\s*\/\s*(\d+)\s*\(/)
    if (paratestMatch) {
      state.progress.completed = parseInt(paratestMatch[1])
      state.progress.total = parseInt(paratestMatch[2])
      state.progress.passed = state.progress.completed - state.progress.failed
    }

    // Test count: "801 tests, 1234 assertions"
    const countMatch = line.match(/(\d+)\s+tests?,\s+(\d+)\s+assertions?/)
    if (countMatch) {
      state.progress.total = parseInt(countMatch[1])
    }

    // OK result
    if (line.includes('OK (')) {
      const okMatch = line.match(/OK \((\d+) tests?/)
      if (okMatch) {
        state.progress.passed = parseInt(okMatch[1])
        state.progress.completed = parseInt(okMatch[1])
        state.progress.failed = 0
      }
    }

    // Failures and errors. PHPUnit reports them separately and prints only the
    // headline it has: "FAILURES!" with a Failures: line, or "ERRORS!" with an
    // Errors: line, or both. Counting failures alone let a run of 31 errors be
    // summarised as "5389✓ 0✗" - passing arithmetic on a red run, which is worse
    // than no summary at all. Both count as not-passed.
    const summaryMatch = line.match(/^Tests:\s+\d+,/)
    if (line.includes('FAILURES!') || line.includes('ERRORS!') || summaryMatch) {
      const failMatch = line.match(/Failures:\s*(\d+)/)
      const errMatch = line.match(/Errors:\s*(\d+)/)
      if (failMatch || errMatch) {
        state.progress.failed =
          (failMatch ? parseInt(failMatch[1]) : 0) + (errMatch ? parseInt(errMatch[1]) : 0)
      }
    }
  }

  // Update message with progress
  const p = state.progress
  if (p.total > 0) {
    const percent = Math.round((p.completed / p.total) * 100)
    state.message = `Running tests... ${p.completed}/${p.total} (${percent}%)${p.failed > 0 ? ` - ${p.failed} failed` : ''}`
  } else {
    state.message = `Running tests... ${p.passed}✓ ${p.failed}✗`
  }

  setTestState('laravel', state)
}
