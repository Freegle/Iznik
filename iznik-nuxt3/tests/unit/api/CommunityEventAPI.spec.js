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

let CommunityEventAPI

describe('CommunityEventAPI', () => {
  beforeEach(async () => {
    vi.clearAllMocks()
    vi.resetModules()
    const mod = await import('~/api/CommunityEventAPI.js')
    CommunityEventAPI = mod.default
  })

  function createApi() {
    return new CommunityEventAPI({
      public: { APIv2: 'https://api.test.com' },
    })
  }

  /** The single fetch call: URL object plus the fetch options. */
  function lastCall() {
    expect(mockFetch).toHaveBeenCalledTimes(1)
    const [url, options] = mockFetch.mock.calls[0]
    return { url: new URL(url), options }
  }

  describe('requests', () => {
    const start = '2026-11-01 10:00:00'
    const end = '2026-11-01 12:00:00'

    const table = [
      {
        name: 'fetch',
        call: (api) => api.fetch(7),
        verb: 'GET',
        path: '/communityevent/7',
        body: null,
      },
      {
        name: 'fetch with logError off',
        call: (api) => api.fetch(7, false),
        verb: 'GET',
        path: '/communityevent/7',
        body: null,
      },
      {
        name: 'listGroup',
        call: (api) => api.listGroup(55),
        verb: 'GET',
        path: '/communityevent/group/55',
        body: null,
      },
      {
        name: 'save',
        call: (api) => api.save({ id: 7, title: 'Repair cafe' }),
        verb: 'PATCH',
        path: '/communityevent',
        body: { id: 7, title: 'Repair cafe', modtools: false },
      },
      {
        name: 'addGroup',
        call: (api) => api.addGroup(7, 55),
        verb: 'PATCH',
        path: '/communityevent',
        body: { id: 7, groupid: 55, action: 'AddGroup', modtools: false },
      },
      {
        name: 'removeGroup',
        call: (api) => api.removeGroup(7, 55),
        verb: 'PATCH',
        path: '/communityevent',
        body: { id: 7, groupid: 55, action: 'RemoveGroup', modtools: false },
      },
      {
        name: 'setPhoto',
        call: (api) => api.setPhoto(7, 99),
        verb: 'PATCH',
        path: '/communityevent',
        body: { id: 7, photoid: 99, action: 'SetPhoto', modtools: false },
      },
      {
        name: 'addDate',
        call: (api) => api.addDate(7, start, end),
        verb: 'PATCH',
        path: '/communityevent',
        body: { id: 7, start, end, action: 'AddDate', modtools: false },
      },
      {
        name: 'removeDate',
        call: (api) => api.removeDate(7, 3),
        verb: 'PATCH',
        path: '/communityevent',
        body: { id: 7, dateid: 3, action: 'RemoveDate', modtools: false },
      },
      {
        name: 'del',
        call: (api) => api.del(7),
        verb: 'DELETE',
        path: '/communityevent/7',
        body: { modtools: false },
      },
    ]

    it.each(table)('$name sends $verb $path', async (c) => {
      mockFetch.mockResolvedValue([200, { ret: 0 }])

      const result = await c.call(createApi())

      const { url, options } = lastCall()
      expect(options.method).toBe(c.verb)
      expect(url.origin + url.pathname).toBe('https://api.test.com' + c.path)
      expect(url.searchParams.get('loggedInAs')).toBe('123')
      expect(url.searchParams.get('requestid')).not.toBeNull()
      if (c.body === null) {
        expect(options.body).toBeNull()
      } else {
        expect(JSON.parse(options.body)).toEqual(c.body)
      }
      expect(result).toEqual({ ret: 0 })
    })
  })

  describe('list', () => {
    it('sends params as the query string on a GET', async () => {
      mockFetch.mockResolvedValue([200, []])

      await createApi().list({ groupid: 55, pending: true })

      const { url, options } = lastCall()
      expect(options.method).toBe('GET')
      expect(url.origin + url.pathname).toBe(
        'https://api.test.com/communityevent'
      )
      expect(url.searchParams.get('groupid')).toBe('55')
      expect(url.searchParams.get('pending')).toBe('true')
    })

    it('drops falsey params', async () => {
      mockFetch.mockResolvedValue([200, []])

      await createApi().list({ groupid: 0, pending: true })

      expect(lastCall().url.searchParams.has('groupid')).toBe(false)
    })

    it('returns the response data', async () => {
      mockFetch.mockResolvedValue([200, [{ id: 1 }, { id: 2 }]])

      expect(await createApi().list({})).toEqual([{ id: 1 }, { id: 2 }])
    })
  })

  describe('add', () => {
    it('POSTs the event and returns just the new id', async () => {
      mockFetch.mockResolvedValue([200, { id: 321, ret: 0 }])

      const id = await createApi().add({ title: 'Jumble sale' })

      const { url, options } = lastCall()
      expect(options.method).toBe('POST')
      expect(url.origin + url.pathname).toBe(
        'https://api.test.com/communityevent'
      )
      expect(JSON.parse(options.body)).toEqual({
        title: 'Jumble sale',
        modtools: false,
      })
      expect(id).toBe(321)
    })

    it('rejects when the server refuses', async () => {
      mockFetch.mockResolvedValue([500, {}])

      await expect(createApi().add({ title: 'x' })).rejects.toThrow(
        /API Error POST/
      )
    })
  })

  describe('error reporting flags', () => {
    it('fetch reports a failure to Sentry by default', async () => {
      mockFetch.mockResolvedValue([404, {}])

      await expect(createApi().fetch(7)).rejects.toThrow(/status: 404/)
      expect(captureMessage).toHaveBeenCalledTimes(1)
    })

    it('fetch with logError=false keeps the failure out of Sentry', async () => {
      mockFetch.mockResolvedValue([404, {}])

      await expect(createApi().fetch(7, false)).rejects.toThrow(/status: 404/)
      expect(captureMessage).not.toHaveBeenCalled()
    })

    it('save does not report a held-by-another-moderator conflict', async () => {
      mockFetch.mockResolvedValue([409, { heldby: 9 }])

      await expect(createApi().save({ id: 7 })).rejects.toThrow(/status: 409/)
      expect(captureMessage).not.toHaveBeenCalled()
    })

    it('save still reports any other failure', async () => {
      mockFetch.mockResolvedValue([500, {}])

      await expect(createApi().save({ id: 7 })).rejects.toThrow(/status: 500/)
      expect(captureMessage).toHaveBeenCalledTimes(1)
    })

    it('other patch methods report failures by default', async () => {
      mockFetch.mockResolvedValue([409, { heldby: 9 }])

      await expect(createApi().addGroup(7, 55)).rejects.toThrow()
      expect(captureMessage).toHaveBeenCalledTimes(1)
    })
  })
})
