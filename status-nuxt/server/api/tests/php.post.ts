import { spawn, execSync } from 'child_process'
import { getTestState, setTestState, appendTestLogs, isTestRunning } from '../../utils/testState'

const prefix = process.env.COMPOSE_PROJECT_NAME || 'freegle'

export default defineEventHandler(async (event) => {
  console.log('Starting PHP tests...')

  const body = await readBody(event).catch(() => ({}))
  const filter = body?.filter || ''

  if (filter) {
    console.log(`Running PHP tests with filter: ${body.filter}`)
  }

  // Check if already running
  if (isTestRunning('php')) {
    throw createError({
      statusCode: 409,
      message: 'PHP tests are already running'
    })
  }

  // Initialize test status
  setTestState('php', {
    status: 'running',
    message: 'Starting PHPUnit test container...',
    logs: '',
    progress: { completed: 0, total: 0, passed: 0, failed: 0, current: '' },
    startTime: Date.now(),
    endTime: null,
  })

  // Start the container
  try {
    execSync(`docker start ${prefix}-apiv1-phpunit`, {
      encoding: 'utf8',
      timeout: 60000,
    })
    appendTestLogs('php', 'Container started, waiting for health check...\n')
  } catch (error: any) {
    setTestState('php', {
      status: 'failed',
      message: `Failed to start container: ${error.message}`,
      endTime: Date.now(),
    })
    throw createError({
      statusCode: 500,
      message: `Failed to start PHPUnit container: ${error.message}`
    })
  }

  // Wait for container to be healthy (async)
  waitForHealthyAndRunTests(filter)

  return { status: 'started', message: 'PHP tests started successfully' }
})

async function waitForHealthyAndRunTests(filter: string) {
  const maxWait = 300000 // 5 minutes
  const startTime = Date.now()

  // Wait for healthy
  while (Date.now() - startTime < maxWait) {
    try {
      const health = execSync(
        `docker inspect --format="{{.State.Health.Status}}" ${prefix}-apiv1-phpunit 2>/dev/null || echo "unknown"`,
        { encoding: 'utf8' }
      ).trim()

      if (health === 'healthy') {
        appendTestLogs('php', 'Container is healthy!\n')
        break
      }

      setTestState('php', { message: `Waiting for container (${health})...` })
      await new Promise(resolve => setTimeout(resolve, 5000))
    } catch {
      await new Promise(resolve => setTimeout(resolve, 5000))
    }
  }

  const state = getTestState('php')
  if (state.status !== 'running') return

  // Setup test environment
  setTestState('php', { message: 'Setting up test environment...' })
  try {
    execSync(
      `docker exec ${prefix}-apiv1-phpunit sh -c "cd /var/www/iznik && php install/testenv.php"`,
      { encoding: 'utf8', timeout: 60000 }
    )
    appendTestLogs('php', 'Test environment set up\n')
  } catch (error: any) {
    appendTestLogs('php', `Warning: Test environment setup issue: ${error.message}\n`)
  }

  // Run tests with schema refresh inline before run-phpunit.sh
  // The schema refresh ensures test databases have the latest schema from iznik
  // (which has all migrations applied). This is done inline in the spawn command
  // to avoid shell escaping issues with execSync through multiple docker layers.
  const testPath = filter || '/var/www/iznik/test/ut/php/'
  setTestState('php', { message: 'Running PHPUnit tests...' })

  const testProcess = spawn('sh', ['-c', `
    docker exec -w /var/www/iznik ${prefix}-apiv1-phpunit sh -c "
      /var/www/iznik/run-phpunit.sh ${testPath} 2>&1"
  `], { stdio: 'pipe' })

  testProcess.stdout.on('data', (data) => {
    const text = data.toString()
    appendTestLogs('php', text)
    parsePhpTestOutput(text)
  })

  testProcess.stderr.on('data', (data) => {
    appendTestLogs('php', data.toString())
  })

  testProcess.on('close', (code) => {
    const s = getTestState('php')
    setTestState('php', {
      status: code === 0 ? 'completed' : 'failed',
      success: code === 0,
      endTime: Date.now(),
      message: code === 0
        ? `Tests passed (${s.progress.passed}✓)`
        : `Tests failed (${s.progress.passed}✓ ${s.progress.failed}✗)`,
    })
    console.log(`PHP tests completed with code ${code}`)
  })

  testProcess.on('error', (error) => {
    setTestState('php', {
      status: 'failed',
      message: `Error: ${error.message}`,
      endTime: Date.now(),
    })
  })
}

function parsePhpTestOutput(text: string) {
  const state = getTestState('php')
  const lines = text.split('\n')

  for (const line of lines) {
    // TeamCity format test count
    if (line.includes('##teamcity[testCount')) {
      const countMatch = line.match(/count='(\d+)'/)
      if (countMatch) {
        state.progress.total = parseInt(countMatch[1])
      }
    }

    // Test started marker
    if (line.includes('##PHPUNIT_TEST_STARTED##:')) {
      const testMatch = line.match(/##PHPUNIT_TEST_STARTED##:(.+)/)
      if (testMatch) {
        state.progress.current = testMatch[1]
        state.progress.completed++
      }
    }

    // Test failures
    if (line.includes('##teamcity[testFailed')) {
      state.progress.failed++
    }

    // Paratest progress: "63 / 801 (  7%)"
    const paratestMatch = line.match(/(\d+)\s*\/\s*(\d+)\s*\(/)
    if (paratestMatch) {
      state.progress.completed = parseInt(paratestMatch[1])
      state.progress.total = parseInt(paratestMatch[2])
    }

    // Update message
    if (state.progress.total > 0) {
      state.message = `Running tests (${state.progress.completed}/${state.progress.total})`
    }
  }

  setTestState('php', state)
}
