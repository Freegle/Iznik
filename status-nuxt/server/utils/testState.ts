import type { TestProgress } from '~/types/test'

export interface TestState {
  status: 'idle' | 'running' | 'completed' | 'failed'
  message: string
  logs: string
  progress: TestProgress
  startTime: number | null
  endTime: number | null
  success?: boolean
  withCoverage?: boolean
}

const initialTestState = (): TestState => ({
  status: 'idle',
  message: '',
  logs: '',
  progress: { completed: 0, total: 0, passed: 0, failed: 0 },
  startTime: null,
  endTime: null,
})

// Server-side test state storage
const testStates = new Map<string, TestState>([
  ['go', initialTestState()],
  ['spatial', initialTestState()],
  ['laravel', initialTestState()],
  ['playwright', initialTestState()],
  ['vitest', initialTestState()],
])

export function getTestState(testType: string): TestState {
  return testStates.get(testType) || initialTestState()
}

export function setTestState(testType: string, state: Partial<TestState>): void {
  const current = getTestState(testType)
  testStates.set(testType, { ...current, ...state })
}

export function resetTestState(testType: string): void {
  testStates.set(testType, initialTestState())
}

export function isTestRunning(testType: string): boolean {
  const state = getTestState(testType)
  return state.status === 'running'
}

export function updateTestProgress(
  testType: string,
  update: Partial<TestProgress>
): void {
  const state = getTestState(testType)
  state.progress = { ...state.progress, ...update }
  testStates.set(testType, state)
}

export function appendTestLogs(testType: string, logs: string): void {
  const state = getTestState(testType)
  state.logs += logs
  testStates.set(testType, state)
}

/**
 * Collapse Go runtime crash dumps so the DIAGNOSTIC part survives downstream
 * truncation. A fatal crash (panic, SIGSEGV, fatal error) dumps EVERY
 * goroutine — easily megabytes — and the orb's failure reporting tails the
 * grepped logs, so the one part that names the crashing line (the panic
 * header and the first, crashing goroutine) was exactly the part that got
 * cut. Keep everything up to the crash marker, a generous window after it,
 * an elision note, and the closing lines (the FAIL summary); dumps stay
 * bounded however many goroutines were parked.
 *
 * Applied only when a suite FAILS, to the accumulated logs — passing runs
 * are untouched.
 */
export function condenseCrashDumps(logs: string): string {
  const lines = logs.split('\n')
  const crashAt = lines.findIndex(
    (l) =>
      l.startsWith('panic:') ||
      l.startsWith('fatal error:') ||
      l.startsWith('fatal: ') ||
      l.startsWith('race: ') ||
      l.startsWith('runtime: ') ||
      l.startsWith('WARNING: DATA RACE') ||
      l.includes('[signal SIG') ||
      // The dump itself, as the last resort: whatever preceded the first
      // goroutine header is the crash reason, so cutting from here keeps it.
      /^goroutine \d+ \[/.test(l)
  )

  const keepAfter = 250 // crash header + the crashing goroutine, with room
  const tail = 120 // the FAIL summary and anything printed after the dump

  if (crashAt !== -1 && lines.length > crashAt + keepAfter + tail) {
    return [
      ...lines.slice(0, crashAt + keepAfter),
      `... [condenseCrashDumps: ${lines.length - crashAt - keepAfter - tail} dump lines elided] ...`,
      ...lines.slice(lines.length - tail),
    ].join('\n')
  }

  // Generic safety net: no recognised crash marker but the output is still
  // enormous (an unanticipated crash format would otherwise push the
  // diagnostic head past every downstream truncation, which is exactly how
  // the routing failure's panic header got lost twice).
  const maxChars = 300_000
  if (logs.length > maxChars) {
    const head = logs.slice(0, 200_000)
    const tailChars = logs.slice(-80_000)
    return `${head}\n... [condenseCrashDumps: ${logs.length - 280_000} chars elided] ...\n${tailChars}`
  }

  return logs
}
