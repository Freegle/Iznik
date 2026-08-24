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
