import { spawn, execFile } from 'child_process'
import { readFileSync, writeFileSync, mkdirSync } from 'fs'
import { dirname } from 'path'
import { getTestState, setTestState, appendTestLogs, isTestRunning } from '../../utils/testState'

// The progress bar needs a total. `vitest list` gives one, but it collects every spec
// file to do so, which took about 40s of wall clock before the first test could start,
// and the POST sat blocked on it. Each finished run reports its own total in the
// summary line, so that is remembered here, per filter, and reused next time. Only a
// run with nothing remembered lists, and it does so in the background while the
// tests are already running.
const TOTALS_FILE = '/tmp/freegle-tests/vitest-totals.json'

function totalsKey(filter: string): string {
  return filter || '*'
}

function readTotals(): Record<string, number> {
  try {
    return JSON.parse(readFileSync(TOTALS_FILE, 'utf8'))
  } catch {
    return {}
  }
}

function rememberTotal(filter: string, total: number): void {
  try {
    mkdirSync(dirname(TOTALS_FILE), { recursive: true })
    const totals = readTotals()
    totals[totalsKey(filter)] = total
    writeFileSync(TOTALS_FILE, JSON.stringify(totals))
  } catch (e) {
    console.error('could not remember vitest total:', e instanceof Error ? e.message : e)
  }
}

export default defineEventHandler(async (event) => {
  console.log('Starting Vitest tests...')

  const body = await readBody(event).catch(() => ({}))
  const filter = body?.filter || ''

  // ?coverage=true writes coverage/lcov.info inside the container, mirroring the Go
  // endpoint. Needed to diagnose coverage locally at all: CI archives a Go profile
  // but no frontend one, so without this the only way to see a frontend coverage
  // number is to push and read Coveralls.
  const query = getQuery(event)
  const withCoverage = query.coverage === 'true'

  if (isTestRunning('vitest')) {
    throw createError({
      statusCode: 409,
      message: 'Vitest tests are already running'
    })
  }

  const prefix = process.env.COMPOSE_PROJECT_NAME || 'freegle'
  const container = `${prefix}-modtools-dev-local`

  const knownTotal = readTotals()[totalsKey(filter)] || 0

  setTestState('vitest', {
    status: 'running',
    message: 'Starting Vitest...',
    logs: '',
    progress: { completed: 0, total: knownTotal, passed: 0, failed: 0, current: '' },
    startTime: Date.now(),
    endTime: null,
  })

  const filterArg = filter ? ` --reporter=verbose "${filter}"` : ' --reporter=verbose'
  const coverageArg = withCoverage ? ' --coverage' : ''
  const testCmd = `cd /app && npx vitest run${filterArg}${coverageArg} 2>&1`

  const testProcess = spawn('sh', ['-c', `
    docker exec -w /app ${container} sh -c '${testCmd}'
  `], { stdio: 'pipe' })

  if (!knownTotal) {
    // Nothing remembered for this filter yet: count the tests in the background so
    // the bar gets a total part-way through this run, and the summary line (which
    // is authoritative) gets remembered for the next one either way.
    const filterListArg = filter ? ` "${filter}"` : ''
    execFile(
      'docker',
      ['exec', container, 'sh', '-c', `cd /app && npx vitest list${filterListArg} 2>&1`],
      { encoding: 'utf8', timeout: 600000, maxBuffer: 10 * 1024 * 1024 },
      (err, stdout) => {
        if (err) {
          console.error('vitest list failed:', err.message)
          return
        }
        // vitest list outputs one line per test as "file > suite > test name"
        const listed = String(stdout).split('\n').filter(l => l.includes(' > ')).length
        const state = getTestState('vitest')
        if (listed > 0 && state.status === 'running' && !state.progress.total) {
          state.progress.total = listed
          setTestState('vitest', state)
        }
      },
    )
  }

  testProcess.stdout.on('data', (data) => {
    const text = data.toString()
    appendTestLogs('vitest', text)

    const state = getTestState('vitest')
    // The verbose reporter colours its output even without a terminal, so each
    // line starts with an escape sequence rather than the tick the patterns
    // below look for. Strip the colours before matching, or the bar sits at
    // zero until the summary line arrives.
    const lines = text.replace(/\x1b\[[0-9;]*m/g, '').split('\n')

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
    if (p.total > 0) {
      rememberTotal(filter, p.total)
    }
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
