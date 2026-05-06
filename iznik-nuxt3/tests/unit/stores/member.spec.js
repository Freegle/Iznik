import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const mockReviewIgnore = vi.fn().mockResolvedValue()
const mockFetchMembers = vi.fn()
const mockMergeAsk = vi.fn().mockResolvedValue()
const mockMergeIgnore = vi.fn().mockResolvedValue()
const mockReviewRelease = vi.fn().mockResolvedValue()
const mockReviewHold = vi.fn().mockResolvedValue()
const mockDelete = vi.fn().mockResolvedValue()

vi.mock('~/api', () => ({
  default: () => ({
    memberships: {
      reviewIgnore: mockReviewIgnore,
      fetchMembers: mockFetchMembers,
      reviewRelease: mockReviewRelease,
      reviewHold: mockReviewHold,
      delete: mockDelete,
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
    user: { id: 999 },
    work: mockAuthWork,
  }),
}))

describe('member store', () => {
  let useMemberStore

  beforeEach(async () => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    const mod = await import('~/modtools/stores/member')
    useMemberStore = mod.useMemberStore
  })

  describe('delete', () => {
    it('removes membership by matching userid and groupid', async () => {
      const store = useMemberStore()
      store.config = {}
      store.list[111] = {
        id: 111,
        userid: 456,
        groupid: 789,
      }
      store.list[222] = {
        id: 222,
        userid: 999,
        groupid: 111,
      }

      await store.delete({
        id: 456,
        groupid: 789,
        subject: 'test',
        stdmsgid: 1,
        body: 'test body',
      })

      expect(store.list[111]).toBeUndefined()
      expect(store.list[222]).toBeTruthy()
    })
  })

  describe('spamignore', () => {
    it('removes entire user entry on ignore (backend clears all mod groups at once)', async () => {
      const store = useMemberStore()
      store.config = {}

      // Simulate a member in review on two groups.
      store.list[123] = {
        id: 123,
        userid: 456,
        memberships: [
          { id: 111, groupid: 789, membershipid: 111 },
          { id: 222, groupid: 999, membershipid: 222 },
        ],
      }

      // Ignore on group 789 — backend now clears ALL mod groups, so the
      // whole entry should be removed immediately (Discourse #9618 fix).
      await store.spamignore({ userid: 456, groupid: 789 })

      expect(mockReviewIgnore).toHaveBeenCalledWith(456, 789)
      expect(store.list[123]).toBeUndefined()
    })

    it('removes entire entry when single membership is ignored', async () => {
      const store = useMemberStore()
      store.config = {}

      store.list[123] = {
        id: 123,
        userid: 456,
        memberships: [{ id: 111, groupid: 789, membershipid: 111 }],
      }

      await store.spamignore({ userid: 456, groupid: 789 })

      expect(store.list[123]).toBeUndefined()
    })
  })

  describe('askMerge / ignoreMerge — related-members counter (regression #9631)', () => {
    // Regression: after PR #306 fixed the backend login-history query, the counter
    // still showed 1 after a valid pair was processed because askMerge/ignoreMerge
    // removed the pair from the store but did not decrement authStore.work.relatedmembers.
    // The counter only updated on the next checkWork() cycle (up to 30 seconds later),
    // leaving the nav badge stuck at 1 while the list was empty.

    beforeEach(() => {
      mockAuthWork.relatedmembers = 1
    })

    it('ignoreMerge decrements work.relatedmembers immediately', async () => {
      const store = useMemberStore()
      store.config = {}
      store.list[10] = { id: 10, user1: 100, user2: 200, collection: 'Related' }

      await store.ignoreMerge(10, { user1: 100, user2: 200 })

      expect(mockAuthWork.relatedmembers).toBe(0)
      expect(store.list[10]).toBeUndefined()
    })

    it('askMerge decrements work.relatedmembers immediately', async () => {
      const store = useMemberStore()
      store.config = {}
      store.list[10] = { id: 10, user1: 100, user2: 200, collection: 'Related' }

      await store.askMerge(10, { user1: 100, user2: 200 })

      expect(mockAuthWork.relatedmembers).toBe(0)
      expect(store.list[10]).toBeUndefined()
    })

    it('ignoreMerge does not decrement below zero', async () => {
      mockAuthWork.relatedmembers = 0
      const store = useMemberStore()
      store.config = {}
      store.list[10] = { id: 10, user1: 100, user2: 200, collection: 'Related' }

      await store.ignoreMerge(10, { user1: 100, user2: 200 })

      expect(mockAuthWork.relatedmembers).toBe(0)
    })

    it('askMerge does not decrement below zero', async () => {
      mockAuthWork.relatedmembers = 0
      const store = useMemberStore()
      store.config = {}
      store.list[10] = { id: 10, user1: 100, user2: 200, collection: 'Related' }

      await store.askMerge(10, { user1: 100, user2: 200 })

      expect(mockAuthWork.relatedmembers).toBe(0)
    })

    it('fetchMembers for Related returns empty list → counter resets to 0 (regression: PR#347 only decremented on askMerge/ignoreMerge, not on auto-notified path)', async () => {
      // Regression (Discourse topic 9631, post 16): the counter can reach 1 via checkWork()
      // for a pair that the backend auto-notifies when the list endpoint is fetched (pair had
      // one user with no login history). PR#347 added decrement hooks only on askMerge and
      // ignoreMerge; it did not account for the path where the backend silently removes a pair
      // from the actionable list and fetchMembers therefore returns 0 pairs.
      //
      // Expected: fetchMembers({collection:'Related'}) returning [] should reset relatedmembers
      //           to 0 when the store holds no Related pairs afterward.
      // Actual:   relatedmembers stays at 1 because only askMerge/ignoreMerge decrement it.
      mockFetchMembers.mockResolvedValue({
        members: [],
        context: null,
        ratings: [],
      })
      mockAuthWork.relatedmembers = 1

      const store = useMemberStore()
      store.config = {}

      await store.fetchMembers({ collection: 'Related', groupid: 0 })

      // The Related list is now empty and the store holds no Related pairs.
      // The counter must reflect reality and reset to 0.
      expect(mockAuthWork.relatedmembers).toBe(0)
    })

    it('fetchMembers for Related returning new pairs after counter was zeroed via askMerge/ignoreMerge → counter reflects new pair count (regression: no increment path)', async () => {
      // Regression (Discourse topic 9631): after PR#347 the counter is only updated by
      // decrementing in askMerge/ignoreMerge and resetting to 0 when fetchMembers returns
      // an empty list.  There is no INCREMENT path: when fetchMembers returns new Related
      // pairs after the counter has already been driven to 0, the counter stays at 0
      // even though the store now contains 1 actionable pair.
      //
      // Scenario:
      //   1. Start: 2 pairs, counter = 2
      //   2. askMerge pair A → counter = 1, pair A removed
      //   3. ignoreMerge pair B → counter = 0, pair B removed
      //   4. fetchMembers returns new pair C → store has 1 pair, counter MUST become 1
      //
      // Expected: counter == 1 (derived from actual pair count in the store)
      // Actual:   counter stays 0 because fetchMembers only resets-to-0, it never increments

      const store = useMemberStore()
      store.config = {}

      // Step 1: 2 pairs, counter = 2
      store.list[10] = { id: 10, user1: 100, user2: 200, collection: 'Related' }
      store.list[11] = { id: 11, user1: 300, user2: 400, collection: 'Related' }
      mockAuthWork.relatedmembers = 2

      // Step 2: process pair A
      await store.askMerge(10, { user1: 100, user2: 200 })
      expect(mockAuthWork.relatedmembers).toBe(1)

      // Step 3: process pair B
      await store.ignoreMerge(11, { user1: 300, user2: 400 })
      expect(mockAuthWork.relatedmembers).toBe(0)

      // Step 4: fetch returns a fresh pair — the backend found a new related-member pair
      mockFetchMembers.mockResolvedValue({
        members: [{ id: 20, user1: 500, user2: 600 }],
        context: null,
        ratings: [],
      })

      await store.fetchMembers({ collection: 'Related', groupid: 0 })

      // The store now holds 1 actionable Related pair; the counter must reflect that.
      // This assertion FAILS on the current code because fetchMembers has no increment
      // path — it only resets the counter to 0 when the list is empty, never updates it
      // upward when new pairs arrive.
      expect(mockAuthWork.relatedmembers).toBe(1)
    })
  })

  describe('fetchMembers - pagination context', () => {
    it('stores the integer context returned by the API', async () => {
      mockFetchMembers.mockResolvedValue({
        members: Array.from({ length: 20 }, (_, i) => ({
          id: i + 1,
          userid: i + 1,
          groupid: 1,
          collection: 'Approved',
        })),
        context: 456,
        ratings: [],
        filtercount: null,
      })

      const store = useMemberStore()
      store.config = {}
      await store.fetchMembers({ collection: 'Approved', groupid: 1, limit: 20 })

      expect(store.context).toBe(456)
    })

    it('passes integer context to the API on the second page request', async () => {
      mockFetchMembers.mockResolvedValue({
        members: Array.from({ length: 20 }, (_, i) => ({
          id: i + 1,
          userid: i + 1,
          groupid: 1,
          collection: 'Approved',
        })),
        context: 456,
        ratings: [],
        filtercount: null,
      })

      const store = useMemberStore()
      store.config = {}

      await store.fetchMembers({ collection: 'Approved', groupid: 1, limit: 20 })
      await store.fetchMembers({
        collection: 'Approved',
        groupid: 1,
        limit: 20,
        context: store.context,
      })

      const secondCallParams = mockFetchMembers.mock.calls[1][0]
      expect(secondCallParams.context).toBe(456)
    })

    it('stores null context when API returns null (no more pages)', async () => {
      mockFetchMembers.mockResolvedValue({
        members: [{ id: 1, userid: 1, groupid: 1, collection: 'Approved' }],
        context: null,
        ratings: [],
        filtercount: null,
      })

      const store = useMemberStore()
      store.config = {}
      await store.fetchMembers({ collection: 'Approved', groupid: 1, limit: 20 })

      expect(store.context).toBeNull()
    })
  })

  describe('fetchMembers - Related collection', () => {
    it('stores pairs and creates synthetic member entries', async () => {
      mockFetchMembers.mockResolvedValue({
        members: [
          { id: 10, user1: 100, user2: 200 },
          { id: 11, user1: 300, user2: 400 },
        ],
        context: null,
        ratings: [],
      })

      const store = useMemberStore()
      store.config = {}

      await store.fetchMembers({ collection: 'Related' })

      // Pair entries stored by pair id.
      expect(store.list[10]).toMatchObject({
        id: 10,
        user1: 100,
        user2: 200,
        collection: 'Related',
      })
      expect(store.list[11]).toMatchObject({
        id: 11,
        user1: 300,
        user2: 400,
        collection: 'Related',
      })

      // Synthetic member entries for each user.
      expect(store.list[100]).toMatchObject({
        id: 100,
        userid: 100,
        _syntheticRelated: true,
      })
      expect(store.list[200]).toMatchObject({
        id: 200,
        userid: 200,
        _syntheticRelated: true,
      })
      expect(store.list[300]).toMatchObject({
        id: 300,
        userid: 300,
        _syntheticRelated: true,
      })
      expect(store.list[400]).toMatchObject({
        id: 400,
        userid: 400,
        _syntheticRelated: true,
      })
    })

    it('deduplicates by userid when searching across all groups', async () => {
      // User 456 is a member of two groups — API returns two rows.
      mockFetchMembers.mockResolvedValue({
        members: [
          { id: 101, userid: 456, groupid: 1, collection: 'Approved' },
          { id: 102, userid: 456, groupid: 2, collection: 'Approved' },
          { id: 103, userid: 789, groupid: 1, collection: 'Approved' },
        ],
        context: null,
        ratings: [],
      })

      const store = useMemberStore()
      store.config = {}

      await store.fetchMembers({
        collection: 'Approved',
        search: 'alice',
        groupid: 0,
      })

      // Only one entry per user — keyed by userid.
      expect(store.list[456]).toBeTruthy()
      expect(store.list[456].userid).toBe(456)
      expect(store.list[789]).toBeTruthy()
      // Should NOT have duplicate entries for user 456.
      expect(store.list[101]).toBeUndefined()
      expect(store.list[102]).toBeUndefined()
    })

    it('does not overwrite existing entries with synthetic ones', async () => {
      const store = useMemberStore()
      store.config = {}

      // Pre-existing entry for user 100.
      store.list[100] = { id: 100, userid: 100, displayname: 'Existing' }

      mockFetchMembers.mockResolvedValue({
        members: [{ id: 10, user1: 100, user2: 200 }],
        context: null,
        ratings: [],
      })

      await store.fetchMembers({ collection: 'Related' })

      // Existing entry should not be overwritten.
      expect(store.list[100].displayname).toBe('Existing')
      // New synthetic entry for user 200 should exist.
      expect(store.list[200]._syntheticRelated).toBe(true)
    })
  })

  describe('clear', () => {
    it('resets all state to initial values', () => {
      const store = useMemberStore()
      store.list = { 1: { id: 1 } }
      store.context = 99
      store.instance = 5
      store.ratings = [{ id: 1 }]
      store.rawindex = 10
      store.filtercount = 20

      store.clear()

      expect(store.list).toEqual({})
      expect(store.context).toBeNull()
      expect(store.instance).toBe(1)
      expect(store.ratings).toEqual([])
      expect(store.rawindex).toBe(0)
      expect(store.filtercount).toBeNull()
    })
  })

  describe('reviewHeld', () => {
    it('updates heldby on the matching member', () => {
      const store = useMemberStore()
      store.list[42] = { membershipid: 42, heldby: null }

      store.reviewHeld({ membershipid: 42, heldby: { id: 5 } })

      expect(store.list[42].heldby).toEqual({ id: 5 })
    })

    it('does not affect non-matching members', () => {
      const store = useMemberStore()
      store.list[42] = { membershipid: 42, heldby: null }
      store.list[99] = { membershipid: 99, heldby: { id: 3 } }

      store.reviewHeld({ membershipid: 42, heldby: { id: 5 } })

      expect(store.list[99].heldby).toEqual({ id: 3 })
    })
  })

  describe('reviewHold', () => {
    it('updates heldby with current user on the matching member', async () => {
      const store = useMemberStore()
      store.config = {}
      store.list[42] = { membershipid: 42, heldby: null }

      await store.reviewHold({ userid: 123, groupid: 456, membershipid: 42 })

      expect(store.list[42].heldby).toEqual({ id: 999 }) // 999 is the mocked user id
    })
  })

  describe('reviewRelease', () => {
    it('updates heldby to null on the matching member', async () => {
      const store = useMemberStore()
      store.config = {}
      store.list[42] = { membershipid: 42, heldby: { id: 5 } }

      await store.reviewRelease({ userid: 123, groupid: 456, membershipid: 42 })

      expect(store.list[42].heldby).toBeNull()
    })
  })

  describe('getters', () => {
    it('getByGroup returns members matching a groupid', () => {
      const store = useMemberStore()
      store.list[1] = { id: 1, groupid: 10 }
      store.list[2] = { id: 2, groupid: 20 }
      store.list[3] = { id: 3, groupid: 10 }

      const result = store.getByGroup(10)

      expect(result).toHaveLength(2)
      expect(result.map((m) => m.id)).toContain(1)
      expect(result.map((m) => m.id)).toContain(3)
    })

    it('get returns a member by id', () => {
      const store = useMemberStore()
      store.list[5] = { id: 5, userid: 100 }
      store.list[6] = { id: 6, userid: 200 }

      const result = store.get(5)

      expect(result).toMatchObject({ id: 5, userid: 100 })
    })

    it('get returns undefined when member not found', () => {
      const store = useMemberStore()
      store.list[5] = { id: 5, userid: 100 }

      const result = store.get(999)

      expect(result).toBeUndefined()
    })

    it('ratingById returns the matching rating', () => {
      const store = useMemberStore()
      store.ratings = [
        { id: 1, value: 'Up' },
        { id: 2, value: 'Down' },
      ]

      expect(store.ratingById(1)).toMatchObject({ id: 1, value: 'Up' })
      expect(store.ratingById(2)).toMatchObject({ id: 2, value: 'Down' })
      expect(store.ratingById(99)).toBeUndefined()
    })
  })
})
