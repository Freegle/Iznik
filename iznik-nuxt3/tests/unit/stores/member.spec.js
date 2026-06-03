import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const mockReviewIgnore = vi.fn().mockResolvedValue()
const mockRemoveMember = vi.fn().mockResolvedValue()
const mockFetchMembers = vi.fn()
const mockMergeAsk = vi.fn().mockResolvedValue()
const mockMergeIgnore = vi.fn().mockResolvedValue()
const mockMembershipsUpdate = vi.fn().mockResolvedValue({ ret: 0 })

vi.mock('~/api', () => ({
  default: () => ({
    memberships: {
      reviewIgnore: mockReviewIgnore,
      remove: mockRemoveMember,
      fetchMembers: mockFetchMembers,
      update: mockMembershipsUpdate,
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
    const mod = await import('~/modtools/stores/member')
    useMemberStore = mod.useMemberStore
  })

  describe('spamignore', () => {
    it('removes only acted-on group and keeps entry when other memberships remain (Discourse #9481)', async () => {
      // The backend is now per-group: ReviewIgnore only clears the one group clicked
      // (reverted in commit 4749246f6 from the all-groups approach in e67355026).
      // The frontend must keep the store entry when other memberships remain so the
      // card stays visible for groups still under review.
      const store = useMemberStore()
      store.config = {}

      store.list[123] = {
        id: 123,
        userid: 456,
        memberships: [
          {
            id: 111,
            groupid: 789,
            membershipid: 111,
            reviewrequestedat: '2024-01-01T10:00:00Z',
          },
          {
            id: 222,
            groupid: 999,
            membershipid: 222,
            reviewrequestedat: '2024-01-01T10:00:00Z',
          },
        ],
      }

      await store.spamignore({ userid: 456, groupid: 789 })

      expect(mockReviewIgnore).toHaveBeenCalledWith(456, 789)
      // Entry kept — group 999 still has a pending review.
      expect(store.list[123]).toBeDefined()
      expect(store.list[123].memberships.length).toBe(1)
      expect(store.list[123].memberships[0].groupid).toBe(999)
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

  describe('remove - Spam review context', () => {
    beforeEach(() => {
      mockRemoveMember.mockResolvedValue()
    })

    it('keeps entry when other memberships remain after remove (Discourse #9481)', async () => {
      const store = useMemberStore()
      store.config = {}

      store.list[123] = {
        id: 123,
        userid: 456,
        memberships: [
          { id: 111, groupid: 789, membershipid: 111 },
          { id: 222, groupid: 999, membershipid: 222 },
        ],
      }

      await store.remove(456, 789)

      // Entry kept — group 999 still listed for review.
      expect(store.list[123]).toBeDefined()
      expect(store.list[123].memberships.length).toBe(1)
      expect(store.list[123].memberships[0].groupid).toBe(999)
    })

    it('removes entire entry when only membership is removed in Spam context', async () => {
      const store = useMemberStore()
      store.config = {}

      store.list[123] = {
        id: 123,
        userid: 456,
        memberships: [{ id: 111, groupid: 789, membershipid: 111 }],
      }

      await store.remove(456, 789)

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
      await store.fetchMembers({
        collection: 'Approved',
        groupid: 1,
        limit: 20,
      })

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

      await store.fetchMembers({
        collection: 'Approved',
        groupid: 1,
        limit: 20,
      })
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
      await store.fetchMembers({
        collection: 'Approved',
        groupid: 1,
        limit: 20,
      })

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

  /*
   * Regression — Discourse #9481 post 545 ("Trainee not showing as a Mod in
   * the group logs"). PATCH /memberships with a `role` triggers V1's
   * setRole -> updateSystemRole UPDATE on users.systemrole, but the
   * cached userStore entry stays stale and ModLogUser's crown gate keeps
   * failing on the next render. memberStore.update must force-refresh the
   * userStore entry whenever the patch carries a role.
   */
  describe('update — force-refresh userStore on role change', () => {
    it('calls userStore.fetch(userid, true) when params include a role', async () => {
      const store = useMemberStore()
      store.config = {}

      await store.update({ userid: 42, groupid: 7, role: 'Moderator' })

      expect(mockMembershipsUpdate).toHaveBeenCalledWith({
        userid: 42,
        groupid: 7,
        role: 'Moderator',
      })
      expect(mockUserStoreFetch).toHaveBeenCalledTimes(1)
      expect(mockUserStoreFetch).toHaveBeenCalledWith(42, true)
    })

    it('force-refreshes on demote too (role=Member clears users.systemrole when no other Mod groups remain)', async () => {
      const store = useMemberStore()
      store.config = {}

      await store.update({ userid: 42, groupid: 7, role: 'Member' })

      expect(mockUserStoreFetch).toHaveBeenCalledWith(42, true)
    })

    it('does NOT force-refresh when params lack a role (e.g. emailfrequency-only patch)', async () => {
      const store = useMemberStore()
      store.config = {}

      await store.update({ userid: 42, groupid: 7, emailfrequency: 24 })

      expect(mockMembershipsUpdate).toHaveBeenCalled()
      expect(mockUserStoreFetch).not.toHaveBeenCalled()
    })

    it('does NOT force-refresh when role is set but userid is missing (defensive: bad caller, nothing to invalidate)', async () => {
      const store = useMemberStore()
      store.config = {}

      await store.update({ groupid: 7, role: 'Moderator' })

      expect(mockUserStoreFetch).not.toHaveBeenCalled()
    })

    it('returns the API response untouched (force-refresh is a side-effect, not a transform)', async () => {
      mockMembershipsUpdate.mockResolvedValueOnce({
        ret: 0,
        status: 'Success',
      })
      const store = useMemberStore()
      store.config = {}

      const data = await store.update({
        userid: 42,
        groupid: 7,
        role: 'Moderator',
      })

      expect(data).toEqual({ ret: 0, status: 'Success' })
    })
  })
})
