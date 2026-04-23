import { spawn, execSync } from 'child_process'
import { getTestState, setTestState, appendTestLogs, isTestRunning } from '../../utils/testState'

export default defineEventHandler(async (event) => {
  console.log('Starting Vitest tests...')

  const body = await readBody(event).catch(() => ({}))
  const filter = body?.filter || ''

  if (isTestRunning('vitest')) {
    throw createError({
      statusCode: 409,
      message: 'Vitest tests are already running'
    })
  }

  const prefix = process.env.COMPOSE_PROJECT_NAME || 'freegle'
  const container = `${prefix}-modtools-dev-local`

  // Count total tests upfront via `vitest list` so the progress bar is meaningful
  let totalTests = 0
  try {
    const filterListArg = filter ? ` "${filter}"` : ''
    const listOutput = execSync(
      `docker exec ${container} sh -c 'cd /app && npx vitest list${filterListArg} 2>&1'`,
      { encoding: 'utf8', timeout: 120000, maxBuffer: 10 * 1024 * 1024 }
    )
    // vitest list outputs one line per test as "file > suite > test name"
    totalTests = listOutput.split('\n').filter(l => l.includes(' > ')).length
  } catch (e) {
    console.error('vitest list failed:', e instanceof Error ? e.message : e)
  }

  setTestState('vitest', {
    status: 'running',
    message: 'Starting Vitest...',
    logs: '',
    progress: { completed: 0, total: totalTests, passed: 0, failed: 0, current: '' },
    startTime: Date.now(),
    endTime: null,
  })

  const filterArg = filter ? ` --reporter=verbose "${filter}"` : ' --reporter=verbose'
  const testCmd = `cd /app && npx vitest run${filterArg} --coverage 2>&1`

  const testProcess = spawn('sh', ['-c', `
    docker exec -w /app ${container} sh -c '${testCmd}'
  `], { stdio: 'pipe' })

  testProcess.stdout.on('data', (data) => {
    const text = data.toString()
    appendTestLogs('vitest', text)

    const state = getTestState('vitest')
    const lines = text.split('\n')

    for (const line of lines) {
      // Match pass: ✓ test name (duration)
      if (line.match(/^\s*[✓✔]/)) {
        state.progress.passed++
        state.progress.completed++
        const nameMatch = line.match(/[✓✔]\s+(.+?)(?:\s+\(\d+)/)
        if (nameMatch) {
          state.progress.current = nameMatch[1].trim()
        }
      }
      // Match fail: × test name or ✗ test name
      if (line.match(/^\s*[×✗✘]/)) {
        state.progress.failed++
        state.progress.completed++
      }
      // Match Vitest summary line: "Tests  2 failed | 941 passed (943)"
      // or "Tests  943 passed (943)" — total is always in parentheses at end
      const summaryPassedMatch = line.match(/Tests\s+.*?(\d+)\s+passed/)
      if (summaryPassedMatch) {
        state.progress.passed = parseInt(summaryPassedMatch[1])
      }
      const failedMatch = line.match(/(\d+)\s+failed/)
      if (failedMatch) {
        state.progress.failed = parseInt(failedMatch[1])
      }
      const summaryTotalMatch = line.match(/Tests\s+.*\((\d+)\)/)
      if (summaryTotalMatch) {
        state.progress.total = parseInt(summaryTotalMatch[1])
        // Summary is authoritative — update completed from passed+failed
        state.progress.completed = state.progress.passed + state.progress.failed
      }
      // Match test file progress: e.g. "✓ tests/unit/components/modtools/ModMessage.spec.js (30)"
      const fileMatch = line.match(/[✓✔]\s+(tests\/\S+)\s+\((\d+)\)/)
      if (fileMatch) {
        state.progress.current = fileMatch[1]
      }
    }

    const p = state.progress
    if (p.current) {
      state.message = `Running: ${p.current} (${p.passed}✓ ${p.failed}✗)`
    }

    setTestState('vitest', state)
  })

  testProcess.stderr.on('data', (data) => {
    appendTestLogs('vitest', data.toString())
  })

  testProcess.on('close', (code) => {
    const state = getTestState('vitest')
    const p = state.progress
    setTestState('vitest', {
      status: code === 0 ? 'completed' : 'failed',
      success: code === 0,
      endTime: Date.now(),
      message: code === 0
        ? `All tests passed (${p.passed}✓)`
        : `Tests failed (${p.passed}✓ ${p.failed}✗)`,
    })
    console.log(`Vitest tests completed with code ${code}`)
  })

  testProcess.on('error', (error) => {
    setTestState('vitest', {
      status: 'failed',
      message: `Error: ${error.message}`,
      endTime: Date.now(),
    })
  })

  return { status: 'started' }
})
