export type TestType = 'go' | 'spatial' | 'routing' | 'php' | 'laravel' | 'vitest' | 'playwright'

export type TestStatus = 'idle' | 'running' | 'completed' | 'failed'

export interface TestProgress {
  total: number
  completed: number
  passed: number
  failed: number
  current?: string // Current test name
}

export interface TestState {
  type: TestType
  status: TestStatus
  message: string
  logs: string
  progress: TestProgress
  startTime?: Date
  endTime?: Date
  filter?: string
  reportUrl?: string // For Playwright HTML report
}

export interface TestConfig {
  type: TestType
  name: string
  description: string
  filterPlaceholder: string
  hasReport: boolean
}

// Test configurations
export const testConfigs: Record<TestType, TestConfig> = {
  go: {
    type: 'go',
    name: 'Go API Tests',
    description: 'Tests for the v2 API (iznik-server-go)',
    filterPlaceholder: 'Filter tests (e.g., TestMessage)',
    hasReport: false,
  },
  spatial: {
    type: 'spatial',
    name: 'Spatial Server Tests',
    description: 'Tests for the spatial KNN server (iznik-spatial-go)',
    filterPlaceholder: 'Filter tests (e.g., TestDeleteByExtID)',
    hasReport: false,
  },
  routing: {
    type: 'routing',
    name: 'Routing Server Tests',
    description: 'Tests for the isochrone/routing server (iznik-routing-go)',
    filterPlaceholder: 'Filter tests (e.g., TestIsochrone)',
    hasReport: false,
  },
  php: {
    type: 'php',
    name: 'PHP v1 API Tests',
    description: 'Tests for the v1 API (iznik-server)',
    filterPlaceholder: 'Filter tests (e.g., messageTest)',
    hasReport: false,
  },
  laravel: {
    type: 'laravel',
    name: 'Laravel Batch Tests',
    description: 'Tests for batch processing (iznik-batch)',
    filterPlaceholder: 'Filter tests (e.g., EmailTest)',
    hasReport: false,
  },
  vitest: {
    type: 'vitest',
    name: 'Vitest Unit Tests',
    description: 'Unit tests for frontend components (iznik-nuxt3)',
    filterPlaceholder: 'Filter tests (e.g., BaseAPI)',
    hasReport: false,
  },
  playwright: {
    type: 'playwright',
    name: 'Playwright E2E Tests',
    description: 'End-to-end browser tests (iznik-nuxt3)',
    filterPlaceholder: 'Filter tests (e.g., login)',
    hasReport: true,
  },
}
