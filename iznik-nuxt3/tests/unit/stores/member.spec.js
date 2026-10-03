import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

// Self-moderating rework: modtools/stores/member.js keeps only the
// national member search-and-card actions (ban, posting status,
// notes/flag-clear, merge) - see the header comment on the store. Every
// action routes through api().modmembers (api/ModMembersAPI.js), which is
// unscoped by community, so there is no groupid/memberships.* surface to
// mock any more. This replaces the old per-group memberships.* mocking
// entirely.
const mockFetch = vi.fn().mockResolvedValue({ members: [], ratings: [] })
const mockFetchOne = vi.fn().mockResolvedValue({ member: {} })
const mockBan = vi.fn().mockResolvedValue()
const mockUnban = vi.fn().mockResolvedValue()
const mockSetPostingStatus = vi.fn().mockResolvedValue()
const mockClearFlag = vi.fn().mockResolvedValue()
const mockMergeAsk = vi.fn().mockResolvedValue()
const mockMergeIgnore = vi.fn().mockResolvedValue()

vi.mock('~/api', () => ({
  default: () => ({
    modmembers: {
      fetch: mockFetch,
      fetchOne: mockFetchOne,
      ban: mockBan,
      unban: mockUnban,
      setPostingStatus: mockSetPostingStatus,
      clearFlag: mockClearFlag,
    },
    merge: {
      ask: mockMergeAsk,
      ignore: mockMergeIgnore,
    },
  }),
}))

const mockAuthWork = { relatedmembers: 0 }

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({
    work: mockAuthWork,
  }),
}))

const mockUserStoreFetch = vi.fn().mockResolvedValue({})

vi.mock('~/stores/user', () => ({
  useUserStore: () => ({
    fetch: mockUserStoreFetch,
  }),
}))

describe('member store', () => {
  let useMemberStore

  beforeEach(async () => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    mockAuthWork.relatedmembers = 0

    // Re-establish default resolved values: clearAllMocks only clears call
    // history, but a test that queued extra mockResolvedValueOnce calls
    // could otherwise leak an unconsumed one into the next test.
    mockFetch.mockResolvedValue({ members: [], ratings: [] })
    mockFetchOne.mockResolvedValue({ member: {} })
    mockBan.mockResolvedValue()
    mockUnban.mockResolvedValue()
    mockSetPostingStatus.mockResolvedValue()
    mockClearFlag.mockResolvedValue()
    mockMergeAsk.mockResolvedValue()
    mockMergeIgnore.mockResolvedValue()
    mockUserStoreFetch.mockResolvedValue({})

    const mod = await import('~/modtools/stores/member')
    useMemberStore = mod.useMemberStore
  })

  describe('init', () => {
    it('stores the config for later api() calls', () => {
      const store = useMemberStore()
      store.init({ some: 'config' })
      expect(store.config).toEqual({ some: 'config' })
    })
  })

  describe('clear', () => {
    it('empties the list and ratings', () => {
      const store = useMemberStore()
      store.list[1] = { id: 1 }
      store.ratings = [{ id: 1, rating: 5 }]
      store.clear()
      expect(store.list).toEqual({})
      expect(store.ratings).toEqual([])
    })
  })

  describe('fetchMembers', () => {
    it('fetches members and indexes them into list by id', async () => {
      mockFetch.mockResolvedValueOnce({
        members: [
          { id: 1, displayname: 'Alice' },
          { id: 2, displayname: 'Bob' },
        ],
        ratings: [],
      })
      const store = useMemberStore()
      store.config = {}

      const count = await store.fetchMembers({ filter: 'new' })

      expect(mockFetch).toHaveBeenCalledWith({ filter: 'new' })
      expect(count).toBe(2)
      expect(store.list[1].displayname).toBe('Alice')
      expect(store.list[2].displayname).toBe('Bob')
    })

    it('stores ratings when the response includes them', async () => {
      mockFetch.mockResolvedValueOnce({
        members: [{ id: 1 }],
        ratings: [{ id: 1, rating: 4.5 }],
      })
      const store = useMemberStore()
      store.config = {}

      await store.fetchMembers({})

      expect(store.ratings).toEqual([{ id: 1, rating: 4.5 }])
    })

    it('leaves existing ratings unchanged when the response has none', async () => {
      mockFetch.mockResolvedValueOnce({ members: [], ratings: [] })
      const store = useMemberStore()
      store.config = {}
      store.ratings = [{ id: 9, rating: 1 }]

      await store.fetchMembers({})

      expect(store.ratings).toEqual([{ id: 9, rating: 1 }])
    })
  })

  describe('fetch', () => {
    it('fetches a single member and stores it by id', async () => {
      mockFetchOne.mockResolvedValueOnce({
        member: { id: 42, displayname: 'Carol' },
      })
      const store = useMemberStore()
      store.config = {}

      await store.fetch(42)

      expect(mockFetchOne).toHaveBeenCalledWith(42)
      expect(store.list[42].displayname).toBe('Carol')
    })
  })

  describe('ban', () => {
    it('calls modmembers.ban with the id and reason', async () => {
      const store = useMemberStore()
      store.config = {}

      await store.ban(42, 'Spamming')

      expect(mockBan).toHaveBeenCalledWith(42, 'Spamming')
    })
  })

  describe('unban', () => {
    it('calls modmembers.unban with the id', async () => {
      const store = useMemberStore()
      store.config = {}

      await store.unban(42)

      expect(mockUnban).toHaveBeenCalledWith(42)
    })
  })

  describe('setPostingStatus', () => {
    it('calls modmembers.setPostingStatus then force-refreshes the cached user (Discourse #10008)', async () => {
      const store = useMemberStore()
      store.config = {}

      await store.setPostingStatus(42, 'PROHIBITED')

      expect(mockSetPostingStatus).toHaveBeenCalledWith(42, 'PROHIBITED')
      // Nothing tells userStore about the write, so the cached entry (and
      // anything gating on it, e.g. ModMessageButtons' :cantpost prop)
      // would keep the stale value without this forced refresh.
      expect(mockUserStoreFetch).toHaveBeenCalledWith(42, true)
    })
  })

  describe('clearFlag', () => {
    it('calls modmembers.clearFlag and removes the entry from list', async () => {
      const store = useMemberStore()
      store.config = {}
      store.list[42] = { id: 42 }

      await store.clearFlag(42)

      expect(mockClearFlag).toHaveBeenCalledWith(42)
      expect(store.list[42]).toBeUndefined()
    })

    it('is a no-op on the list when the id is not present', async () => {
      const store = useMemberStore()
      store.config = {}

      await store.clearFlag(999)

      expect(mockClearFlag).toHaveBeenCalledWith(999)
    })

    it('matches the entry even when the id argument is a string but the stored id is numeric', async () => {
      const store = useMemberStore()
      store.config = {}
      store.list[42] = { id: 42 }

      await store.clearFlag('42')

      expect(store.list[42]).toBeUndefined()
    })
  })

  describe('askMerge', () => {
    it('calls merge.ask, removes the entry, and decrements work.relatedmembers', async () => {
      mockAuthWork.relatedmembers = 3
      const store = useMemberStore()
      store.config = {}
      store.list[42] = { id: 42 }

      await store.askMerge(42, { user1: 42, user2: 43 })

      expect(mockMergeAsk).toHaveBeenCalledWith({ user1: 42, user2: 43 })
      expect(store.list[42]).toBeUndefined()
      expect(mockAuthWork.relatedmembers).toBe(2)
    })

    it('does not decrement relatedmembers below zero', async () => {
      mockAuthWork.relatedmembers = 0
      const store = useMemberStore()
      store.config = {}

      await store.askMerge(42, {})

      expect(mockAuthWork.relatedmembers).toBe(0)
    })

    it('leaves relatedmembers alone when it is not a number', async () => {
      mockAuthWork.relatedmembers = null
      const store = useMemberStore()
      store.config = {}

      await store.askMerge(42, {})

      expect(mockAuthWork.relatedmembers).toBeNull()
    })
  })

  describe('ignoreMerge', () => {
    it('calls merge.ignore, removes the entry, and decrements work.relatedmembers', async () => {
      mockAuthWork.relatedmembers = 1
      const store = useMemberStore()
      store.config = {}
      store.list[42] = { id: 42 }

      await store.ignoreMerge(42, { user1: 42, user2: 43 })

      expect(mockMergeIgnore).toHaveBeenCalledWith({ user1: 42, user2: 43 })
      expect(store.list[42]).toBeUndefined()
      expect(mockAuthWork.relatedmembers).toBe(0)
    })
  })

  describe('get getter', () => {
    it('returns the stored member by id', () => {
      const store = useMemberStore()
      store.list[42] = { id: 42, displayname: 'Dave' }

      expect(store.get(42)).toEqual({ id: 42, displayname: 'Dave' })
    })

    it('returns undefined when the id is not present', () => {
      const store = useMemberStore()

      expect(store.get(999)).toBeUndefined()
    })
  })

  describe('ratingById getter', () => {
    it('returns the rating matching id, comparing numerically', () => {
      const store = useMemberStore()
      store.ratings = [{ id: '42', rating: 4.5 }]

      expect(store.ratingById(42)).toEqual({ id: '42', rating: 4.5 })
    })

    it('returns undefined when no rating matches', () => {
      const store = useMemberStore()
      store.ratings = [{ id: 1, rating: 5 }]

      expect(store.ratingById(999)).toBeUndefined()
    })
  })
})
