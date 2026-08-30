import { spawn } from 'child_process'
import { getTestState, setTestState, isTestRunning, appendTestLogs, condenseCrashDumps } from '../../utils/testState'

const prefix = process.env.COMPOSE_PROJECT_NAME || 'freegle'

export default defineEventHandler(async (event) => {
  console.log('Starting routing server tests...')

  const query = getQuery(event)
  const withCoverage = query.coverage === 'true'

  if (isTestRunning('routing')) {
    throw createError({
      statusCode: 409,
      message: 'Routing server tests are already running'
    })
  }

  setTestState('routing', {
    status: 'running',
    message: 'Running routing server tests...',
    logs: '',
    progress: { completed: 0, total: 0, passed: 0, failed: 0, current: '' },
    startTime: Date.now(),
    endTime: null,
    withCoverage,
  })

  // Targets the iznik-routing-go container (compose service "spatial").
  // Tests use the small committed testdata PBFs (bristol/rutland) baked into the
  // image — no MySQL DB and no UK PBF needed.
  const testCmd = withCoverage
    ? `go test -v -race -timeout 10m -coverprofile=coverage.out ./... -coverpkg ./...`
    : `go test -count=1 ./... -v`

  // Sync current host source into the (baked-source) container so the suite runs
  // against the working tree. SOURCE ONLY — never testdata/, which can hold
  // multi-GB UK PBFs in a dev checkout. Falls back to baked source if absent.
  const src = '/project/iznik-routing-go'
  const testProcess = spawn('sh', ['-c', `
    if [ -d "${src}" ]; then
      echo "Syncing routing source into ${prefix}-spatial:/app (source only)..."
      docker cp "${src}/go.mod" ${prefix}-spatial:/app/ || true
      docker cp "${src}/go.sum" ${prefix}-spatial:/app/ || true
      for f in "${src}"/*.go; do docker cp "$f" ${prefix}-spatial:/app/ || true; done
      [ -d "${src}/cmd" ] && docker cp "${src}/cmd" ${prefix}-spatial:/app/ || true
    fi
    echo "Running routing server tests..."
    docker exec -w /app ${prefix}-spatial sh -c "${testCmd} 2>&1"
  `], { stdio: 'pipe' })

  let stdoutBuffer = ''

  testProcess.stdout.on('data', (data) => {
    const text = data.toString()
    appendTestLogs('routing', text)

    const combined = stdoutBuffer + text
    const parts = combined.split('\n')
    stdoutBuffer = parts.pop() || ''

    const state = getTestState('routing')

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
    setTestState('routing', state)
  })

  testProcess.stderr.on('data', (data) => {
    appendTestLogs('routing', data.toString())
  })

  testProcess.on('close', (code) => {
    if (stdoutBuffer.length > 0) {
      const state = getTestState('routing')
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
      setTestState('routing', state)
      stdoutBuffer = ''
    }

    const state = getTestState('routing')
    const p = state.progress
    setTestState('routing', {
      status: code === 0 ? 'completed' : 'failed',
      success: code === 0,
      endTime: Date.now(),
      message: code === 0
        ? `All tests passed (${p.passed}✓)`
        : `Tests failed (${p.passed}✓ ${p.failed}✗)`,
      // A fatal crash dumps every goroutine; bound the dump so the panic
      // header - the only part that names the crashing line - survives the
      // orb's tail-limited failure report instead of being the part cut.
      ...(code === 0 ? {} : { logs: condenseCrashDumps(state.logs) }),
    })
    console.log(`Routing server tests completed with code ${code}`)
  })

  testProcess.on('error', (error) => {
    setTestState('routing', {
      status: 'failed',
      message: `Error: ${error.message}`,
      endTime: Date.now(),
    })
  })

  return { status: 'started' }
})
