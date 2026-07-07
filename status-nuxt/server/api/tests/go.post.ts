import { spawn, execSync } from 'child_process'
import { getTestState, setTestState, appendTestLogs, isTestRunning } from '../../utils/testState'

const prefix = process.env.COMPOSE_PROJECT_NAME || 'freegle'

export default defineEventHandler(async (event) => {
  console.log('Starting Go tests...')

  const query = getQuery(event)
  const withCoverage = query.coverage === 'true'

  // Check if already running
  if (isTestRunning('go')) {
    throw createError({
      statusCode: 409,
      message: 'Go tests are already running'
    })
  }

  // Initialize test status
  setTestState('go', {
    status: 'running',
    message: 'Setting up Go test database...',
    logs: '',
    progress: { completed: 0, total: 0, passed: 0, failed: 0, current: '' },
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

  // Build test command
  const testCmd = withCoverage
    ? `export CGO_ENABLED=1 && export MYSQL_HOST=${perconaIp} && export MYSQL_DBNAME=iznik_go_test && go mod tidy && go test -v -race -timeout 30m -coverprofile=coverage.out ./... -coverpkg ./...`
    : `export MYSQL_HOST=${perconaIp} && export MYSQL_DBNAME=iznik_go_test && go test -count=1 ./... -v`

  // Run tests asynchronously
  const testProcess = spawn('sh', ['-c', `
    set -e
    echo "Setting up Go test database (iznik_go_test)..."

    # Copy schema from main iznik database
    docker exec ${prefix}-apiv1 sh -c "\\
      mysql -h percona -u root -piznik -e 'DROP DATABASE IF EXISTS iznik_go_test; CREATE DATABASE iznik_go_test;' && \\
      mysqldump -h percona -u root -piznik --no-data --routines --triggers iznik | mysql -h percona -u root -piznik iznik_go_test && \\
      mysql -h percona -u root -piznik -e \\"SET GLOBAL sql_mode = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'\\" && \\
      mysql -h percona -u root -piznik -e \\"SET GLOBAL sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''));\\"" || echo "Warning: Database setup had issues, continuing..."

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
      // Count failures: --- FAIL: TestName (top-level only)
      if (line.match(/--- FAIL:/) && !line.match(/^\s{4,}--- FAIL:/)) {
        state.progress.failed++
        state.progress.completed++
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
      if (line.match(/--- FAIL:/) && !line.match(/^\s{4,}--- FAIL:/)) {
        state.progress.failed++
        state.progress.completed++
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
        : `Tests failed (${p.passed}✓ ${p.failed}✗)`,
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
