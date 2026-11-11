import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const mockFetch = vi.fn()
const mockFetchMod = vi.fn()
const mockFetchStats = vi.fn()
const mockFetchHistory = vi.fn()
const mockFetchHeld = vi.fn()
const mockPatch = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    lockdown: {
      fetch: mockFetch,
      fetchMod: mockFetchMod,
      fetchStats: mockFetchStats,
      fetchHistory: mockFetchHistory,
      fetchHeld: mockFetchHeld,
      patch: mockPatch,
    },
  }),
}))

describe('lockdown store', () => {
  let useLockdownStore

  beforeEach(async () => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    const mod = await import('~/stores/lockdown')
    useLockdownStore = mod.useLockdownStore
  })

  describe('initial state', () => {
    it('starts with no active lockdown and no notice', () => {
      const store = useLockdownStore()
      expect(store.notice).toBeNull()
      expect(store.active).toBe(false)
      expect(store.surfaces).toEqual({})
      expect(store.stats).toBeNull()
      expect(store.history).toEqual([])
    })
  })

  describe('fetch (public)', () => {
    it('fetches and stores the public notice', async () => {
      const store = useLockdownStore()
      store.init({ public: {} })
      mockFetch.mockResolvedValue({
        notice: { text: 'We are dealing with a spam attack.' },
      })

      await store.fetch()

      expect(mockFetch).toHaveBeenCalledTimes(1)
      expect(store.notice).toEqual({
        text: 'We are dealing with a spam attack.',
      })
    })

    it('clears the notice when the server returns null', async () => {
      const store = useLockdownStore()
      store.init({ public: {} })
      store.notice = { text: 'old notice' }
      mockFetch.mockResolvedValue({ notice: null })

      await store.fetch()

      expect(store.notice).toBeNull()
    })
  })

  describe('fetchMod', () => {
    it('fetches and stores the full moderator state', async () => {
      const store = useLockdownStore()
      store.init({ public: {} })
      mockFetchMod.mockResolvedValue({
        active: true,
        incidentid: 5,
        surfaces: { mods: true, posts: true },
        reason: 'spam wave',
        // GET /modtools/lockdown returns the notice text itself, unlike
        // the public GET /lockdown endpoint, which wraps it as {text}.
        notice: 'Messages may be delayed.',
        startedat: '2026-09-27 10:00:00',
        startedby: 1,
        startedbyname: 'Support',
      })

      await store.fetchMod()

      expect(store.active).toBe(true)
      expect(store.incidentid).toBe(5)
      expect(store.surfaces).toEqual({ mods: true, posts: true })
      expect(store.reason).toBe('spam wave')
      expect(store.notice).toBe('Messages may be delayed.')
      expect(store.startedat).toBe('2026-09-27 10:00:00')
      expect(store.startedby).toBe(1)
      expect(store.startedbyname).toBe('Support')
    })

    it('resets to inactive when the server reports no lockdown', async () => {
      const store = useLockdownStore()
      store.init({ public: {} })
      store.active = true
      store.surfaces = { mods: true }
      mockFetchMod.mockResolvedValue({ active: false, surfaces: {} })

      await store.fetchMod()

      expect(store.active).toBe(false)
      expect(store.surfaces).toEqual({})
    })
  })

  describe('fetchStats', () => {
    it('fetches and stores stats', async () => {
      const store = useLockdownStore()
      store.init({ public: {} })
      mockFetchStats.mockResolvedValue({ counts: { post: 12 } })

      await store.fetchStats()

      expect(store.stats).toEqual({ counts: { post: 12 } })
    })
  })

  describe('fetchHeld', () => {
    it('passes the search through and returns one page', async () => {
      const store = useLockdownStore()
      store.init({ public: {} })
      mockFetchHeld.mockResolvedValue({ items: [{ id: 1 }], next: 1 })

      const page = await store.fetchHeld({ kind: 'chat', q: 'voucher' })

      expect(mockFetchHeld).toHaveBeenCalledWith({ kind: 'chat', q: 'voucher' })
      expect(page).toEqual({ items: [{ id: 1 }], next: 1 })
    })

    it('returns an empty page when the server returns nothing', async () => {
      const store = useLockdownStore()
      store.init({ public: {} })
      mockFetchHeld.mockResolvedValue(null)

      expect(await store.fetchHeld({ kind: 'post' })).toEqual({
        items: [],
        next: null,
      })
    })
  })

  describe('fetchHistory', () => {
    it('fetches and stores history', async () => {
      const store = useLockdownStore()
      store.init({ public: {} })
      // GET /modtools/lockdown/history returns a bare JSON array, not
      // wrapped in a `history` key.
      mockFetchHistory.mockResolvedValue([{ incidentid: 1, reason: 'test' }])

      await store.fetchHistory()

      expect(store.history).toEqual([{ incidentid: 1, reason: 'test' }])
    })

    it('stores an empty array when the server returns nothing', async () => {
      const store = useLockdownStore()
      store.init({ public: {} })
      mockFetchHistory.mockResolvedValue(null)

      await store.fetchHistory()

      expect(store.history).toEqual([])
    })
  })

  describe('patch', () => {
    it('sends the action and refreshes the moderator state', async () => {
      const store = useLockdownStore()
      store.init({ public: {} })
      mockPatch.mockResolvedValue({ active: true })
      mockFetchMod.mockResolvedValue({
        active: true,
        surfaces: { mods: true },
      })

      await store.patch({ action: 'press', reason: 'spam wave' })

      expect(mockPatch).toHaveBeenCalledWith({
        action: 'press',
        reason: 'spam wave',
      })
      expect(mockFetchMod).toHaveBeenCalledTimes(1)
      expect(store.active).toBe(true)
      expect(store.surfaces).toEqual({ mods: true })
    })
  })

  describe('held getter', () => {
    it('is false when no lockdown is active', () => {
      const store = useLockdownStore()
      store.active = false
      store.surfaces = { mods: true }
      expect(store.held('mods')).toBe(false)
    })

    it('is false when active but the surface is not held', () => {
      const store = useLockdownStore()
      store.active = true
      store.surfaces = { mods: false, posts: true }
      expect(store.held('mods')).toBe(false)
    })

    it('is true when active and the surface is held', () => {
      const store = useLockdownStore()
      store.active = true
      store.surfaces = { mods: true }
      expect(store.held('mods')).toBe(true)
    })
  })

  describe('modsHeld getter', () => {
    it('mirrors held("mods")', () => {
      const store = useLockdownStore()
      store.active = true
      store.surfaces = { mods: true }
      expect(store.modsHeld).toBe(true)
    })
  })
})
