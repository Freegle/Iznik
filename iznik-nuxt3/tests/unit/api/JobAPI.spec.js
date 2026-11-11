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

let JobAPI

describe('JobAPI', () => {
  beforeEach(async () => {
    vi.clearAllMocks()
    vi.resetModules()
    const mod = await import('~/api/JobAPI.js')
    JobAPI = mod.default
  })

  function createApi() {
    return new JobAPI({
      public: { APIv2: 'https://api.test.com' },
    })
  }

  /** The URL passed to fetch for the first (only) call, as a URL object. */
  function calledUrl() {
    expect(mockFetch).toHaveBeenCalledTimes(1)
    return new URL(mockFetch.mock.calls[0][0])
  }

  describe('fetchOnev2', () => {
    it('asks for the one job', async () => {
      mockFetch.mockResolvedValue([200, { id: 42 }])

      await createApi().fetchOnev2(42, false)

      const url = calledUrl()
      expect(url.origin + url.pathname).toBe('https://api.test.com/job/42')
    })

    // The email job page reports the browser's clock, because a browser whose clock is in the UK
    // is never asked for an extra tap.
    it("sends the browser's time zone as the tz query", async () => {
      mockFetch.mockResolvedValue([200, { id: 42 }])

      await createApi().fetchOnev2(42, false, 'Europe/London')

      expect(calledUrl().searchParams.get('tz')).toBe('Europe/London')
    })

    it('encodes the zone, so its slash cannot split the query', async () => {
      mockFetch.mockResolvedValue([200, { id: 42 }])

      await createApi().fetchOnev2(42, false, 'America/Argentina/Buenos_Aires')

      expect(mockFetch.mock.calls[0][0]).toContain(
        'tz=America%2FArgentina%2FBuenos_Aires'
      )
    })

    it('sends no tz when the browser would not say', async () => {
      mockFetch.mockResolvedValue([200, { id: 42 }])

      await createApi().fetchOnev2(42, false, undefined)

      expect(calledUrl().searchParams.has('tz')).toBe(false)
    })

    it('sends no tz for an empty zone', async () => {
      mockFetch.mockResolvedValue([200, { id: 42 }])

      await createApi().fetchOnev2(42, false, '')

      expect(calledUrl().searchParams.has('tz')).toBe(false)
    })

    it('does not report a missing job to Sentry, because an expired job is an expected outcome', async () => {
      mockFetch.mockResolvedValue([404, null])

      await expect(
        createApi().fetchOnev2(42, false, 'Europe/London')
      ).rejects.toThrow()

      expect(captureMessage).not.toHaveBeenCalled()
    })
  })
})
