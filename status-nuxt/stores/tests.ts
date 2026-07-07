import { defineStore } from 'pinia'
import type { TestState, TestType, TestStatus, TestProgress } from '~/types/test'

function createInitialTestState(type: TestType): TestState {
  return {
    type,
    status: 'idle',
    message: '',
    logs: '',
    progress: {
      total: 0,
      completed: 0,
      passed: 0,
      failed: 0,
    },
  }
}

interface TestStoreState {
  tests: Record<TestType, TestState>
  pollingIntervals: Map<TestType, ReturnType<typeof setInterval>>
}

export const useTestStore = defineStore('tests', {
  state: (): TestStoreState => ({
    tests: {
      go: createInitialTestState('go'),
      php: createInitialTestState('php'),
      laravel: createInitialTestState('laravel'),
      vitest: createInitialTestState('vitest'),
      playwright: createInitialTestState('playwright'),
    },
    pollingIntervals: new Map(),
  }),

  getters: {
    getTestState: (state) => (type: TestType): TestState => {
      return state.tests[type]
    },

    isAnyRunning: (state): boolean => {
      return Object.values(state.tests).some(t => t.status === 'running')
    },
  },

  actions: {
    async refreshTestStatus(type: TestType) {
      try {
        const response = await $fetch<any>(`/api/tests/${type}/status`)
        this.tests[type] = {
          ...this.tests[type],
          status: response.status as TestStatus,
          message: response.message || '',
          logs: response.logs || '',
          progress: response.progress || this.tests[type].progress,
          reportUrl: response.reportUrl,
        }

        // Auto-start polling if tests are running but we're not already polling
        if (response.status === 'running' && !this.pollingIntervals.has(type)) {
          this.startPolling(type)
        }
      }
      catch (err) {
        console.error(`Failed to fetch ${type} test status:`, err)
      }
    },

    async runTests(type: TestType, filter?: string) {
      // Update state to running
      this.tests[type].status = 'running'
      this.tests[type].message = 'Starting tests...'
      this.tests[type].logs = ''
      this.tests[type].filter = filter
      this.tests[type].startTime = new Date()
      this.tests[type].progress = {
        total: 0,
        completed: 0,
        passed: 0,
        failed: 0,
      }

      try {
        // Start the tests
        await $fetch(`/api/tests/${type}`, {
          method: 'POST',
          body: { filter },
        })

        // Start polling for status
        this.startPolling(type)
      }
      catch (err) {
        this.tests[type].status = 'failed'
        this.tests[type].message = err instanceof Error ? err.message : 'Failed to start tests'
      }
    },

    startPolling(type: TestType) {
      // Clear any existing interval
      this.stopPolling(type)

      // Poll every 2 seconds
      const interval = setInterval(async () => {
        await this.refreshTestStatus(type)

        // Stop polling when tests complete
        if (this.tests[type].status !== 'running') {
          this.stopPolling(type)
          this.tests[type].endTime = new Date()
        }
      }, 2000)

      this.pollingIntervals.set(type, interval)
    },

    stopPolling(type: TestType) {
      const interval = this.pollingIntervals.get(type)
      if (interval) {
        clearInterval(interval)
        this.pollingIntervals.delete(type)
      }
    },

    clearLogs(type: TestType) {
      this.tests[type].logs = ''
    },
  },
})
