import { spawn } from 'child_process'
import { readFileSync, writeFileSync, mkdirSync } from 'fs'
import { dirname } from 'path'
import { getTestState, setTestState, appendTestLogs, isTestRunning } from '../../utils/testState'

// The progress bar wants a total. This runner used to get one from `vitest list`,
// which collects every spec file to count the tests: about 40s of wall clock with the
// POST blocked on it, and when that was moved into the background it ran in the same
// container as the tests and competed with the workers for CPU. Nothing lists now.
// Each finished run reports its own total in its summary line, which is remembered
// here per filter and used as the next run's total from the start; a run with nothing
// remembered shows progress without a total until the summary arrives. The file is in
// the status container's /tmp, so a recreate forgets it and the next run is a first run
// again, which costs nothing.
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
      // The verbose reporter prints one line per test, as
      //   ✓ tests/unit/x.spec.js > Suite > test name 12ms
      // In Vitest 4 that is all it prints per test (its printTestModule is empty),
      // but the default reporter and older verbose ones also print a line per
      // finished file, "✓ tests/unit/x.spec.js (30 tests) 123ms" or "(30)", which
      // starts with a tick too and would be counted as a pass. Only lines with " > "
      // between the file and the suite are tests; a file line just names the file.
      const isTestLine = line.includes(' > ')
      if (isTestLine && line.match(/^\s*[✓✔]/)) {
        state.progress.passed++
        state.progress.completed++
        // The verbose reporter ends a test line with its duration as "12ms",
        // older reporters with "(12ms)"; take the name from before either.
        const nameMatch = line.match(/[✓✔]\s+(.+?)(?:\s+\(?\d+\s*ms\)?)?\s*$/)
        if (nameMatch) {
          state.progress.current = nameMatch[1].trim()
        }
      }
      // Match fail: × test name or ✗ test name
      if (isTestLine && line.match(/^\s*[×✗✘]/)) {
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
      // A finished file, from a reporter that prints them: "(30 tests)", "(30)" from
      // older ones, "(30 tests | 2 failed)" when some failed.
      const fileMatch = line.match(/^\s*[✓✔×✗✘❯]\s+(tests\/\S+)\s+\((\d+)(?:\s+tests?)?[^)]*\)/)
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
    // A run whose total fell since the last one at the same filter has lost tests: a
    // spec that no longer parses counts as one failed file and its tests vanish, and
    // a deleted file just vanishes. Pass or fail is still the exit code; this makes
    // the drop visible in the log rather than only in a progress bar.
    if (knownTotal > 0 && p.total > 0 && p.total < knownTotal) {
      appendTestLogs(
        'vitest',
        `\nNOTE: this run has ${p.total} tests; the previous run with the same filter had ${knownTotal}. ${knownTotal - p.total} fewer.\n`
      )
    }
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
