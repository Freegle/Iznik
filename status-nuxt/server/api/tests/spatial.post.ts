import { spawn } from 'child_process'
import { getTestState, setTestState, appendTestLogs, isTestRunning } from '../../utils/testState'

const prefix = process.env.COMPOSE_PROJECT_NAME || 'freegle'

export default defineEventHandler(async (event) => {
  console.log('Starting spatial server tests...')

  const query = getQuery(event)
  const withCoverage = query.coverage === 'true'

  if (isTestRunning('spatial')) {
    throw createError({
      statusCode: 409,
      message: 'Spatial server tests are already running'
    })
  }

  setTestState('spatial', {
    status: 'running',
    message: 'Running spatial server tests...',
    logs: '',
    progress: { completed: 0, total: 0, passed: 0, failed: 0, current: '' },
    startTime: Date.now(),
    endTime: null,
    withCoverage,
  })

  // No database setup needed — tests use in-memory SQLite.
  const testCmd = withCoverage
    ? `go test -v -race -timeout 10m -coverprofile=coverage.out ./... -coverpkg ./...`
    : `go test -count=1 ./... -v`

  const testProcess = spawn('sh', ['-c', `
    echo "Running spatial server tests..."
    docker exec -w /app ${prefix}-spatial sh -c "${testCmd} 2>&1"
  `], { stdio: 'pipe' })

  let stdoutBuffer = ''

  testProcess.stdout.on('data', (data) => {
    const text = data.toString()
    appendTestLogs('spatial', text)

    const combined = stdoutBuffer + text
    const parts = combined.split('\n')
    stdoutBuffer = parts.pop() || ''

    const state = getTestState('spatial')

    for (const line of parts) {
      const runMatch = line.match(/=== RUN\s+(\S+)/)
      if (runMatch) {
        state.progress.current = runMatch[1]
        if (!runMatch[1].includes('/')) {
          state.progress.total++
        }
      }
      if (line.match(/--- PASS:/) && !line.match(/^\s{4,}--- PASS:/)) {
        state.progress.passed++
        state.progress.completed++
      }
      if (line.match(/--- FAIL:/) && !line.match(/^\s{4,}--- FAIL:/)) {
        state.progress.failed++
        state.progress.completed++
      }
    }

    const p = state.progress
    if (p.current) {
      state.message = `Running: ${p.current} (${p.passed}✓ ${p.failed}✗)`
    }
    setTestState('spatial', state)
  })

  testProcess.stderr.on('data', (data) => {
    appendTestLogs('spatial', data.toString())
  })

  testProcess.on('close', (code) => {
    if (stdoutBuffer.length > 0) {
      const state = getTestState('spatial')
      const line = stdoutBuffer
      const runMatch = line.match(/=== RUN\s+(\S+)/)
      if (runMatch && !runMatch[1].includes('/')) {
        state.progress.total++
      }
      if (line.match(/--- PASS:/) && !line.match(/^\s{4,}--- PASS:/)) {
        state.progress.passed++
        state.progress.completed++
      }
      if (line.match(/--- FAIL:/) && !line.match(/^\s{4,}--- FAIL:/)) {
        state.progress.failed++
        state.progress.completed++
      }
      setTestState('spatial', state)
      stdoutBuffer = ''
    }

    const state = getTestState('spatial')
    const p = state.progress
    setTestState('spatial', {
      status: code === 0 ? 'completed' : 'failed',
      success: code === 0,
      endTime: Date.now(),
      message: code === 0
        ? `All tests passed (${p.passed}✓)`
        : `Tests failed (${p.passed}✓ ${p.failed}✗)`,
    })
    console.log(`Spatial server tests completed with code ${code}`)
  })

  testProcess.on('error', (error) => {
    setTestState('spatial', {
      status: 'failed',
      message: `Error: ${error.message}`,
      endTime: Date.now(),
    })
  })

  return { status: 'started' }
})
