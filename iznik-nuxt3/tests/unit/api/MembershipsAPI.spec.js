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

let MembershipsAPI

describe('MembershipsAPI', () => {
  beforeEach(async () => {
    vi.clearAllMocks()
    vi.resetModules()
    const mod = await import('~/api/MembershipsAPI.js')
    MembershipsAPI = mod.default
  })

  function createApi() {
    return new MembershipsAPI({
      public: { APIv2: 'https://api.test.com' },
    })
  }

  describe('fetchMembers', () => {
    it('returns members array when API responds with object containing members key', async () => {
      const pair = { id: 1, userid: 10, collection: 'Pending' }
      mockFetch.mockResolvedValue([
        200,
        { members: [pair], context: 'ctx1', ratings: [], filtercount: 5 },
      ])

      const api = createApi()
      const result = await api.fetchMembers({ collection: 'Pending' })

      expect(result.members).toEqual([pair])
    })

    it('returns members array when API responds with a raw array (Related collection)', async () => {
      // The Go API's getRelatedMembers returns c.JSON([]fiber.Map{...}) — a raw array,
      // not the { members: [...] } envelope used by other collections.
      // Regression introduced by 360ebc092 which removed the raw-array fallback.
      const pair = { id: 1507195, user1: 38040808, user2: 44774192 }
      mockFetch.mockResolvedValue([200, [pair]])

      const api = createApi()
      const result = await api.fetchMembers({ collection: 'Related' })

      expect(result.members).toEqual([pair])
    })

    it('returns empty members list when Related API returns empty raw array', async () => {
      mockFetch.mockResolvedValue([200, []])

      const api = createApi()
      const result = await api.fetchMembers({ collection: 'Related' })

      expect(result.members).toEqual([])
    })
  })
})
