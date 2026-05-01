import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const mockReviewIgnore = vi.fn().mockResolvedValue()
const mockFetchMembers = vi.fn()
const mockMergeAsk = vi.fn().mockResolvedValue()
const mockMergeIgnore = vi.fn().mockResolvedValue()

vi.mock('~/api', () => ({
  default: () => ({
    memberships: {
      reviewIgnore: mockReviewIgnore,
      fetchMembers: mockFetchMembers,
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
})
