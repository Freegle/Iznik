import { spawn, execSync } from 'child_process'
import { getTestState, setTestState, appendTestLogs, isTestRunning, condenseCrashDumps } from '../../utils/testState'
import { checkContainersReady, containersNotReadyMessage } from '../../utils/requiredContainers'

const prefix = process.env.COMPOSE_PROJECT_NAME || 'freegle'

// The database, the container the tests compile and run inside, and the spatial-knn
// finder the API calls. A missing spatial-knn does not fail fast - it stalls the
// suite on connect timeouts until the whole run looks broken.
const REQUIRED_CONTAINERS = ['percona', 'apiv2', 'spatial-knn']

export default defineEventHandler(async (event) => {
  console.log('Starting Go tests...')

  const query = getQuery(event)
  const withCoverage = query.coverage === 'true'
  // Optional ?filter=<regexp> narrows the run to matching test names. A full run
  // takes minutes and its output is condensed, so a single failing test's
  // assertion message is otherwise unreadable. Restricted to the characters a Go
  // -run pattern needs, because it is interpolated into a shell command.
  const rawFilter = typeof query.filter === 'string' ? query.filter : ''
  const filter = /^[A-Za-z0-9_|^$/.\-]{1,200}$/.test(rawFilter) ? rawFilter : ''
  if (rawFilter && !filter) {
    throw createError({ statusCode: 400, message: 'filter may only contain Go test-name pattern characters' })
  }

  // Check if already running
  if (isTestRunning('go')) {
    throw createError({
      statusCode: 409,
      message: 'Go tests are already running'
    })
  }

  // Refuse to start rather than produce a red run that blames the code.
  const readiness = checkContainersReady(prefix, REQUIRED_CONTAINERS)
  if (!readiness.ok) {
    const message = containersNotReadyMessage('Go', readiness)
    setTestState('go', {
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
  setTestState('go', {
    status: 'running',
    message: 'Setting up Go test database...',
    logs: '',
    progress: { completed: 0, total: 0, passed: 0, failed: 0, current: '' },
    failedTests: [],
    startTime: Date.now(),
    endTime: null,
    withCoverage,
  })

  // Resolve percona IP to bypass Go's stale DNS resolver in Docker
  let perconaIp = 'percona'
  try {
    perconaIp = execSync(`docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' ${prefix}-percona`, { encoding: 'utf8' }).trim()
    console.log(`Resolved percona IP: ${perconaIp}`)
  } catch (e) {
    console.log('Failed to resolve percona IP, using hostname')
  }

  // Build test command. Both variants need an explicit -timeout: the big integration
  // package normally finishes just under go test's default 10m, so under any extra DB
  // load (e.g. the Laravel suite running concurrently) it gets killed at exactly 600s
  // with zero test failures — a phantom red.
  // The coverage variant also pins -p 1. Without it ~10 packages run concurrently
  // against the single iznik_go_test database, on a runner where CI deliberately runs
  // iznik-batch, Playwright, Go and Vitest in parallel (load hit 14.26 while Go was
  // running, build 32736). Load-dependent error and timeout branches then get covered
  // on one run and not the next, which is what puts "Coverage decreased (-0.004%)" —
  // one statement — on commits containing no Go at all. Serialising costs effectively
  // nothing here: Go finishes ~2m into a step whose critical path is Playwright at
  // ~19m, so the suite can slow down several-fold without moving the job's wall clock,
  // and the timeouts above it are 30m (go test) / 35m (orb poll) / 48m (watchdog).
  // Not applied to the plain variant: no coverage is produced there, so determinism
  // buys nothing and developers keep the faster parallel run.
  const runArg = filter ? ` -run '${filter}'` : ''
  const testCmd = withCoverage
    ? `export CGO_ENABLED=1 && export MYSQL_HOST=${perconaIp} && export MYSQL_DBNAME=iznik_go_test && go mod tidy && go test -v -race -p 1 -timeout 30m${runArg} -coverprofile=coverage.out ./... -coverpkg ./...`
    : `export MYSQL_HOST=${perconaIp} && export MYSQL_DBNAME=iznik_go_test && go test -count=1 -timeout 30m${runArg} ./... -v`

  // Run tests asynchronously
  const testProcess = spawn('sh', ['-c', `
    set -e
    echo "Setting up Go test database (iznik_go_test)..."

    # Clone the schema (no data) from the migrated iznik database via the percona container.
    # Go tests create their own fixture data at runtime, so only the schema is required.
    docker exec ${prefix}-percona sh -c "\\
      mysql -u root -piznik -e 'DROP DATABASE IF EXISTS iznik_go_test; CREATE DATABASE iznik_go_test;' && \\
      mysqldump -u root -piznik --no-data --routines --triggers iznik | mysql -u root -piznik iznik_go_test && \\
      mysql -u root -piznik -e \\"SET GLOBAL sql_mode = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'\\" && \\
      mysql -u root -piznik -e \\"SET GLOBAL sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''));\\"" || echo "Warning: Database setup had issues, continuing..."

    echo "Running Go tests against iznik_go_test database..."
    docker exec -w /app ${prefix}-apiv2 sh -c "${testCmd} 2>&1"
  `], { stdio: 'pipe' })

  // Buffer for incomplete lines split across chunks.
  let stdoutBuffer = ''

  testProcess.stdout.on('data', (data) => {
    const text = data.toString()
    appendTestLogs('go', text)

    // Prepend any leftover from the previous chunk.
    const combined = stdoutBuffer + text
    const parts = combined.split('\n')
    // Last element is incomplete (no trailing newline) — save for next chunk.
    stdoutBuffer = parts.pop() || ''

    const state = getTestState('go')

    for (const line of parts) {
      // Count test starts: === RUN   TestName (top-level only, exclude subtests with /)
      const runMatch = line.match(/=== RUN\s+(\S+)/)
      if (runMatch) {
        state.progress.current = runMatch[1]
        if (!runMatch[1].includes('/')) {
          state.progress.total++
        }
      }
      // Count passes: --- PASS: TestName (top-level only).
      // Exclude subtests which have 4+ leading spaces: "    --- PASS:"
      if (line.match(/--- PASS:/) && !line.match(/^\s{4,}--- PASS:/)) {
        state.progress.passed++
        state.progress.completed++
      }
      // Count failures: --- FAIL: TestName (top-level only), and keep the names.
      // go test buffers a package's output and flushes it all when the package
      // finishes, so on the big integration package the FAIL lines arrive in one
      // burst seconds before the process exits - and land in the middle that
      // condenseCrashDumps elides, leaving a red run that names nothing.
      const failMatch = line.match(/--- FAIL:\s+(\S+)/)
      if (failMatch && !line.match(/^\s{4,}--- FAIL:/)) {
        state.progress.failed++
        state.progress.completed++
        if (!state.failedTests) state.failedTests = []
        state.failedTests.push(failMatch[1])
      }
    }

    // Update message with current progress
    const p = state.progress
    if (p.current) {
      state.message = `Running: ${p.current} (${p.passed}✓ ${p.failed}✗)`
    }

    setTestState('go', state)
  })

  testProcess.stderr.on('data', (data) => {
    appendTestLogs('go', data.toString())
  })

  testProcess.on('close', (code) => {
    // Process any remaining buffered output.
    if (stdoutBuffer.length > 0) {
      const state = getTestState('go')
      const line = stdoutBuffer
      const runMatch = line.match(/=== RUN\s+(\S+)/)
      if (runMatch && !runMatch[1].includes('/')) {
        state.progress.total++
      }
      if (line.match(/--- PASS:/) && !line.match(/^\s{4,}--- PASS:/)) {
        state.progress.passed++
        state.progress.completed++
      }
      const failMatch = line.match(/--- FAIL:\s+(\S+)/)
      if (failMatch && !line.match(/^\s{4,}--- FAIL:/)) {
        state.progress.failed++
        state.progress.completed++
        if (!state.failedTests) state.failedTests = []
        state.failedTests.push(failMatch[1])
      }
      setTestState('go', state)
      stdoutBuffer = ''
    }

    const state = getTestState('go')
    const p = state.progress
    setTestState('go', {
      status: code === 0 ? 'completed' : 'failed',
      success: code === 0,
      endTime: Date.now(),
      message: code === 0
        ? `All tests passed (${p.passed}✓)`
        : `Tests failed (${p.passed}✓ ${p.failed}✗)` + (state.failedTests?.length ? `: ${state.failedTests.join(', ')}` : ''),
      // A fatal crash dumps every goroutine; bound the dump so the panic
      // header - the only part that names the crashing line - survives the
      // orb's tail-limited failure report instead of being the part cut.
      ...(code === 0 ? {} : { logs: condenseCrashDumps(state.logs) }),
    })
    console.log(`Go tests completed with code ${code}`)
  })

  testProcess.on('error', (error) => {
    setTestState('go', {
      status: 'failed',
      message: `Error: ${error.message}`,
      endTime: Date.now(),
    })
  })

  return { status: 'started' }
})
