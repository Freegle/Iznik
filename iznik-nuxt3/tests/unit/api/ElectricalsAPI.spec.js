import { describe, it, expect, vi, beforeEach } from 'vitest'
import { captureMessage } from '@sentry/vue'

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({
    auth: { jwt: 'test-jwt', persistent: 'test-persistent' },
    user: { id: 123 },
  }),
}))

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({
    modtools: false,
    api: vi.fn(),
    waitForOnline: vi.fn().mockResolvedValue(),
  }),
}))

vi.mock('~/stores/loggingContext', () => ({
  useLoggingContextStore: () => ({
    getHeaders: () => ({}),
  }),
}))

vi.mock('~/composables/useTrace', () => ({
  getTraceHeaders: () => ({}),
}))

vi.mock('@sentry/vue', () => ({
  captureMessage: vi.fn(),
}))

const mockFetch = vi.fn()
vi.mock('~/composables/useFetchRetry', () => ({
  fetchRetry: () => mockFetch,
}))

let ElectricalsAPI

describe('ElectricalsAPI', () => {
  beforeEach(async () => {
    vi.clearAllMocks()
    vi.resetModules()
    const mod = await import('~/api/ElectricalsAPI.js')
    ElectricalsAPI = mod.default
  })

  function createApi() {
    return new ElectricalsAPI({
      public: { APIv2: 'https://api.test.com' },
    })
  }

  it('targets the v2 stats endpoint', async () => {
    mockFetch.mockResolvedValue([200, { counts: { electrical: 1 } }])

    const stats = await createApi().stats()

    expect(mockFetch).toHaveBeenCalledTimes(1)
    expect(mockFetch.mock.calls[0][0]).toContain(
      'https://api.test.com/electricals/stats'
    )
    expect(stats.counts.electrical).toBe(1)
  })

  it('does not report the pre-first-generation 404 to Sentry', async () => {
    // Before the daily generator has ever run, the endpoint 404s by design so an
    // ungenerated environment cannot read as "zero electricals". That is an
    // expected state, not an incident.
    mockFetch.mockResolvedValue([
      404,
      { message: 'No electricals stats have been generated yet' },
    ])

    await expect(createApi().stats()).rejects.toThrow()

    expect(captureMessage).not.toHaveBeenCalled()
  })

  it('still reports any other failure to Sentry', async () => {
    // The quiet gate is narrow: a 404 that is not the expected message (a broken
    // route, a deploy gone wrong) must stay loud.
    mockFetch.mockResolvedValue([404, { message: 'Not Found' }])

    await expect(createApi().stats()).rejects.toThrow()

    expect(captureMessage).toHaveBeenCalled()
  })

  it('reports a server error to Sentry', async () => {
    mockFetch.mockResolvedValue([
      500,
      { message: 'Failed to read electricals stats' },
    ])

    await expect(createApi().stats()).rejects.toThrow()

    expect(captureMessage).toHaveBeenCalled()
  })
})
