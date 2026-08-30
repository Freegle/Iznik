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
      l.includes('[signal SIG')
  )
  if (crashAt === -1) {
    return logs
  }

  const keepAfter = 250 // panic header + the crashing goroutine, with room
  const tail = 120 // the FAIL summary and anything printed after the dump
  const cutFrom = crashAt + keepAfter
  if (lines.length <= cutFrom + tail) {
    return logs
  }

  return [
    ...lines.slice(0, cutFrom),
    `... [${lines.length - cutFrom - tail} goroutine-dump lines elided by condenseCrashDumps] ...`,
    ...lines.slice(lines.length - tail),
  ].join('\n')
}
