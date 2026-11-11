import { describe, it, expect, vi, beforeEach } from 'vitest'

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

let NewsAPI

describe('NewsAPI', () => {
  beforeEach(async () => {
    vi.clearAllMocks()
    vi.resetModules()
    const mod = await import('~/api/NewsAPI.js')
    NewsAPI = mod.default
    mockFetch.mockResolvedValue([200, {}])
  })

  function createApi() {
    return new NewsAPI({
      public: { APIv2: 'https://api.test.com' },
    })
  }

  /** The URL passed to fetch for the first (only) call. */
  function calledUrl() {
    expect(mockFetch).toHaveBeenCalledTimes(1)
    return mockFetch.mock.calls[0][0]
  }

  /** The request options passed to fetch for the first (only) call. */
  function calledOptions() {
    expect(mockFetch).toHaveBeenCalledTimes(1)
    return mockFetch.mock.calls[0][1]
  }

  /** The JSON-decoded request body for the first (only) call. */
  function calledBody() {
    return JSON.parse(calledOptions().body)
  }

  /** The URL's path, without the host. */
  function calledPath() {
    return new URL(calledUrl()).pathname
  }

  /** The URL's query string as an object. */
  function calledQuery() {
    return Object.fromEntries(new URL(calledUrl()).searchParams)
  }

  describe('fetchFeed', () => {
    it('GETs /newsfeed with the params in the query string', async () => {
      await createApi().fetchFeed({ distance: 5, context: 'abc' })

      expect(calledOptions().method).toBe('GET')
      expect(calledPath()).toBe('/newsfeed')
      const q = calledQuery()
      expect(q.distance).toBe('5')
      expect(q.context).toBe('abc')
      expect(q.loggedInAs).toBe('123')
    })

    it('GETs /newsfeed with no params', async () => {
      await createApi().fetchFeed()

      expect(calledOptions().method).toBe('GET')
      expect(calledPath()).toBe('/newsfeed')
    })
  })

  describe('fetch', () => {
    it('GETs /newsfeed/<id> when an id is given, passing every option', async () => {
      await createApi().fetch(42, 10, true, false, 'all', 30)

      expect(calledOptions().method).toBe('GET')
      expect(calledPath()).toBe('/newsfeed/42')
      const q = calledQuery()
      expect(q.distance).toBe('10')
      expect(q.lovelist).toBe('true')
      expect(q.newsletters).toBe('all')
      expect(q.minutes).toBe('30')
    })

    it('GETs /newsfeed when no id is given', async () => {
      await createApi().fetch(null, 20, true, true, 'all', 15)

      expect(calledOptions().method).toBe('GET')
      expect(calledPath()).toBe('/newsfeed')
      const q = calledQuery()
      expect(q.distance).toBe('20')
      expect(q.lovelist).toBe('true')
      expect(q.newsletters).toBe('all')
      expect(q.minutes).toBe('15')
    })

    it('leaves out unset options rather than sending "undefined"', async () => {
      await createApi().fetch(7)

      expect(calledPath()).toBe('/newsfeed/7')
      const q = calledQuery()
      expect(q).not.toHaveProperty('distance')
      expect(q).not.toHaveProperty('lovelist')
      expect(q).not.toHaveProperty('newsletters')
      expect(q).not.toHaveProperty('minutes')
    })

    it('forwards logError=false so a failure is not reported', async () => {
      const spy = vi.spyOn(NewsAPI.prototype, '$getv2')
      await createApi().fetch(1, 5, false, false)

      expect(spy).toHaveBeenCalledWith(
        '/newsfeed/1',
        expect.objectContaining({ distance: 5 }),
        false
      )
    })

    it('forwards logError=true', async () => {
      const spy = vi.spyOn(NewsAPI.prototype, '$getv2')
      await createApi().fetch(undefined, 5, false, true)

      expect(spy).toHaveBeenCalledWith('/newsfeed', expect.any(Object), true)
    })

    it('returns the response data', async () => {
      mockFetch.mockResolvedValue([200, { newsfeed: [{ id: 1 }] }])

      const ret = await createApi().fetch(1)

      expect(ret).toEqual({ newsfeed: [{ id: 1 }] })
    })
  })

  describe('send', () => {
    it('POSTs the data to /newsfeed and unwraps the id', async () => {
      mockFetch.mockResolvedValue([200, { id: 999 }])

      const id = await createApi().send({ message: 'Hello', replyto: 5 })

      expect(id).toBe(999)
      expect(calledOptions().method).toBe('POST')
      expect(calledPath()).toBe('/newsfeed')
      const body = calledBody()
      expect(body.message).toBe('Hello')
      expect(body.replyto).toBe(5)
    })
  })

  describe('edit', () => {
    it('PATCHes /newsfeed with id, message and action Edit', async () => {
      await createApi().edit(12, 'New text')

      expect(calledOptions().method).toBe('PATCH')
      expect(calledPath()).toBe('/newsfeed')
      expect(calledBody()).toMatchObject({
        id: 12,
        message: 'New text',
        action: 'Edit',
      })
    })
  })

  // Methods that POST a single action to /newsfeed.
  describe.each([
    { method: 'love', args: [3], body: { id: 3, action: 'Love' } },
    { method: 'unlove', args: [3], body: { id: 3, action: 'Unlove' } },
    { method: 'unfollow', args: [4], body: { id: 4, action: 'Unfollow' } },
    {
      method: 'report',
      args: [5, 'Spam'],
      body: { id: 5, reason: 'Spam', action: 'Report' },
    },
    {
      method: 'referto',
      args: [6, 'WANTED'],
      body: { id: 6, action: 'ReferToWANTED' },
    },
    {
      method: 'referto',
      args: [6, 'OFFER'],
      body: { id: 6, action: 'ReferToOFFER' },
    },
    {
      method: 'referto',
      args: [6, 'Support'],
      body: { id: 6, action: 'ReferToSupport' },
    },
    { method: 'unhide', args: [7, 'x'], body: { id: 7, action: 'Unhide' } },
    { method: 'hide', args: [8, 'x'], body: { id: 8, action: 'Hide' } },
    {
      method: 'convertToStory',
      args: [9, 'x'],
      body: { id: 9, action: 'ConvertToStory' },
    },
    {
      method: 'convertedToPost',
      args: [10, 555],
      body: { id: 10, msgid: 555, action: 'ConvertedToPost' },
    },
  ])('$method($args)', ({ method, args, body }) => {
    it(`POSTs action ${body.action} to /newsfeed`, async () => {
      await createApi()[method](...args)

      expect(calledOptions().method).toBe('POST')
      expect(calledPath()).toBe('/newsfeed')
      expect(calledBody()).toMatchObject(body)
    })
  })

  describe('type argument is not sent for hide/unhide/convertToStory', () => {
    it.each(['hide', 'unhide', 'convertToStory'])(
      '%s ignores the type',
      async (method) => {
        await createApi()[method](1, 'SomeType')

        const b = calledBody()
        expect(b).not.toHaveProperty('type')
        expect(Object.keys(b).sort()).toEqual(['action', 'id', 'modtools'])
      }
    )
  })

  describe('return values', () => {
    it.each(['unfollow', 'report', 'referto', 'hide', 'unhide', 'seen'])(
      '%s resolves to undefined',
      async (method) => {
        mockFetch.mockResolvedValue([200, { ret: 0 }])

        expect(await createApi()[method](1, 'x')).toBeUndefined()
      }
    )

    it.each(['love', 'unlove'])('%s returns the response', async (method) => {
      mockFetch.mockResolvedValue([200, { ret: 0, status: 'Success' }])

      expect(await createApi()[method](1)).toEqual({
        ret: 0,
        status: 'Success',
      })
    })

    it('convertedToPost returns the response', async () => {
      mockFetch.mockResolvedValue([200, { ret: 0 }])

      expect(await createApi().convertedToPost(1, 2)).toEqual({ ret: 0 })
    })
  })

  describe('seen', () => {
    it('POSTs Seen to /newsfeed with the id in the bump query param', async () => {
      await createApi().seen(77)

      expect(calledOptions().method).toBe('POST')
      expect(calledPath()).toBe('/newsfeed')
      expect(calledQuery().bump).toBe('77')
      expect(calledBody()).toMatchObject({ id: 77, action: 'Seen' })
    })
  })

  describe('seenAll', () => {
    it('POSTs SeenAll to /newsfeed with no id and no bump', async () => {
      await createApi().seenAll()

      expect(calledOptions().method).toBe('POST')
      expect(calledPath()).toBe('/newsfeed')
      expect(calledQuery()).not.toHaveProperty('bump')
      const b = calledBody()
      expect(b.action).toBe('SeenAll')
      expect(b).not.toHaveProperty('id')
    })
  })

  describe('duplicate', () => {
    it('GETs /newsfeed/<id>/duplicate', async () => {
      mockFetch.mockResolvedValue([200, { duplicate: { id: 2 } }])

      const ret = await createApi().duplicate(31)

      expect(calledOptions().method).toBe('GET')
      expect(calledPath()).toBe('/newsfeed/31/duplicate')
      expect(ret).toEqual({ duplicate: { id: 2 } })
    })
  })

  describe('convertInfo', () => {
    it('GETs /newsfeed/<id>/convertinfo', async () => {
      mockFetch.mockResolvedValue([200, { postcode: 'EH1' }])

      const ret = await createApi().convertInfo(32)

      expect(calledOptions().method).toBe('GET')
      expect(calledPath()).toBe('/newsfeed/32/convertinfo')
      expect(ret).toEqual({ postcode: 'EH1' })
    })
  })

  describe('del', () => {
    it('DELETEs /newsfeed/<id> via $requestv2', async () => {
      const spy = vi.spyOn(NewsAPI.prototype, '$requestv2')

      await createApi().del(55)

      expect(spy).toHaveBeenCalledWith(
        'DELETE',
        '/newsfeed/55',
        expect.any(Object)
      )
      expect(calledOptions().method).toBe('DELETE')
      expect(calledPath()).toBe('/newsfeed/55')
    })
  })

  describe('count', () => {
    it('GETs /newsfeedcount with distance and minutes', async () => {
      mockFetch.mockResolvedValue([200, { count: 4 }])

      const ret = await createApi().count(15, true, 20)

      expect(calledOptions().method).toBe('GET')
      expect(calledPath()).toBe('/newsfeedcount')
      const q = calledQuery()
      expect(q.distance).toBe('15')
      expect(q.minutes).toBe('20')
      expect(ret).toEqual({ count: 4 })
    })

    it('forwards the log flag as logError', async () => {
      const spy = vi.spyOn(NewsAPI.prototype, '$getv2')

      await createApi().count(15, false, 20)

      expect(spy).toHaveBeenCalledWith(
        '/newsfeedcount',
        { distance: 15, minutes: 20 },
        false
      )
    })

    it('leaves out unset distance and minutes', async () => {
      await createApi().count()

      expect(calledPath()).toBe('/newsfeedcount')
      const q = calledQuery()
      expect(q).not.toHaveProperty('distance')
      expect(q).not.toHaveProperty('minutes')
    })
  })
})
