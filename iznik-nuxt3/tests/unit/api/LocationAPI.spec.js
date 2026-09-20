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

let LocationAPI

describe('LocationAPI', () => {
  beforeEach(async () => {
    vi.clearAllMocks()
    vi.resetModules()
    const mod = await import('~/api/LocationAPI.js')
    LocationAPI = mod.default
  })

  function createApi() {
    return new LocationAPI({
      public: { APIv2: 'https://api.test.com' },
    })
  }

  /** The URL passed to fetch for the first (only) call. */
  function calledUrl() {
    expect(mockFetch).toHaveBeenCalledTimes(1)
    return mockFetch.mock.calls[0][0]
  }

  /** The JSON-decoded request body for the first (only) call. */
  function calledBody() {
    expect(mockFetch).toHaveBeenCalledTimes(1)
    return JSON.parse(mockFetch.mock.calls[0][1].body)
  }

  describe('typeahead', () => {
    it('URL-encodes the query so punctuation cannot split the query string', async () => {
      mockFetch.mockResolvedValue([200, []])

      await createApi().typeahead('sofa & chair #2')

      const url = calledUrl()
      // An unencoded & would start a bogus parameter and truncate the search term;
      // an unencoded # would drop everything after it.
      expect(url).toContain('q=sofa%20%26%20chair%20%232')
      expect(url).not.toContain('q=sofa & chair')
    })

    it('targets the v2 typeahead endpoint', async () => {
      mockFetch.mockResolvedValue([200, []])

      await createApi().typeahead('bristol')

      expect(calledUrl()).toContain('https://api.test.com/location/typeahead')
    })
  })

  describe('resolve', () => {
    it('does not report a 404 to Sentry, because an unknown place name is an expected outcome', async () => {
      // resolve() passes logError=false precisely so that "no such place" does not
      // become Sentry noise - it is how the caller decides whether to offer
      // "search near <place>". Asserting the decision, not just the argument.
      mockFetch.mockResolvedValue([404, null])

      await expect(createApi().resolve('Nowheresville')).rejects.toThrow()

      expect(captureMessage).not.toHaveBeenCalled()
    })

    it('still reports a 404 for a call that has not opted out of logging', async () => {
      // Contrast case: without this, the test above would also pass if logError
      // were ignored everywhere.
      mockFetch.mockResolvedValue([404, null])

      await expect(createApi().fetchv2(12345)).rejects.toThrow()

      expect(captureMessage).toHaveBeenCalled()
    })
  })

  describe('endpoint paths', () => {
    it('fetchAddresses targets the addresses sub-resource of the location', async () => {
      mockFetch.mockResolvedValue([200, []])

      await createApi().fetchAddresses(4567)

      expect(calledUrl()).toContain(
        'https://api.test.com/location/4567/addresses'
      )
    })

    it('latlng passes both coordinates', async () => {
      mockFetch.mockResolvedValue([200, {}])

      await createApi().latlng(51.5074, -0.1278)

      const url = calledUrl()
      expect(url).toContain('https://api.test.com/location/latlng')
      expect(url).toContain('lat=51.5074')
      expect(url).toContain('lng=-0.1278')
    })
  })

  describe('del', () => {
    it('excludes the location rather than deleting it, by id not by name', async () => {
      mockFetch.mockResolvedValue([200, {}])

      await createApi().del(999, 42)

      const body = calledBody()
      // 'Exclude' removes the location from a group's coverage; a different action
      // value here would be a destructive change to a shared locations table.
      expect(body.action).toBe('Exclude')
      expect(body.byname).toBe(false)
      expect(body.id).toBe(999)
      expect(body.groupid).toBe(42)
    })
  })

  describe('convertKML', () => {
    it('sends the KML with the ConvertKML action', async () => {
      mockFetch.mockResolvedValue([200, {}])

      await createApi().convertKML('<kml/>')

      const body = calledBody()
      expect(body.action).toBe('ConvertKML')
      expect(body.kml).toBe('<kml/>')
    })
  })
})
