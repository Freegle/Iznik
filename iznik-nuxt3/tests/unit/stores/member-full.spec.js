import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const mockApproveMember = vi.fn().mockResolvedValue()
const mockRejectMember = vi.fn().mockResolvedValue()
const mockReply = vi.fn().mockResolvedValue()
const mockDelete = vi.fn().mockResolvedValue()
const mockMembershipFetch = vi.fn()
const mockPut = vi.fn()
const mockBan = vi.fn().mockResolvedValue()
const mockUnban = vi.fn().mockResolvedValue()
const mockHappinessReviewed = vi.fn().mockResolvedValue()
const mockReviewHold = vi.fn().mockResolvedValue()
const mockReviewRelease = vi.fn().mockResolvedValue()
const mockFetchMembers = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    memberships: {
      approveMember: mockApproveMember,
      rejectMember: mockRejectMember,
      reply: mockReply,
      delete: mockDelete,
      fetch: mockMembershipFetch,
      put: mockPut,
      ban: mockBan,
      unban: mockUnban,
      happinessReviewed: mockHappinessReviewed,
      reviewHold: mockReviewHold,
      reviewRelease: mockReviewRelease,
      fetchMembers: mockFetchMembers,
    },
  }),
}))

let mockAuthUser = { id: 999 }
const mockAuthWork = { relatedmembers: 3 }

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({
    get user() {
      return mockAuthUser
    },
    work: mockAuthWork,
  }),
}))

vi.mock('~/stores/user', () => ({
  useUserStore: () => ({ fetch: vi.fn().mockResolvedValue({}) }),
}))

describe('member store — actions beyond fetchMembers/spamignore/askMerge', () => {
  let useMemberStore
  let store

  beforeEach(async () => {
    vi.clearAllMocks()
    mockAuthUser = { id: 999 }
    mockAuthWork.relatedmembers = 3
    setActivePinia(createPinia())
    const mod = await import('~/modtools/stores/member')
    useMemberStore = mod.useMemberStore
    store = useMemberStore()
  })

  it('init stores config', () => {
    store.init({ a: 1 })
    expect(store.config).toEqual({ a: 1 })
  })

  it('clear resets all state', () => {
    store.list = { 1: {} }
    store.context = { id: 1 }
    store.instance = 5
    store.ratings = [{ id: 1 }]
    store.rawindex = 7
    store.filtercount = 10

    store.clear()

    expect(store.list).toEqual({})
    expect(store.context).toBeNull()
    expect(store.instance).toBe(1)
    expect(store.ratings).toEqual([])
    expect(store.rawindex).toBe(0)
    expect(store.filtercount).toBeNull()
  })

  describe('reviewHeld', () => {
    it('sets heldby on the matching membership by id', () => {
      store.list = {
        a: { membershipid: 5, heldby: null },
        b: { membershipid: 6, heldby: null },
      }
      store.reviewHeld({ membershipid: 5, heldby: { id: 1 } })
      expect(store.list.a.heldby).toEqual({ id: 1 })
      expect(store.list.b.heldby).toBeNull()
    })
  })

  describe('approve', () => {
    it('approves the member with the given details', async () => {
      await store.approve({
        id: 1,
        groupid: 2,
        subject: 's',
        stdmsgid: 3,
        body: 'b',
      })
      expect(mockApproveMember).toHaveBeenCalledWith(1, 2, 's', 3, 'b')
    })

    it('re-fetches via the held-conflict refresh path on a 409', async () => {
      const conflict = new Error('held')
      conflict.response = { status: 409, data: { heldby: 2 } }
      mockApproveMember.mockRejectedValueOnce(conflict)
      mockMembershipFetch.mockResolvedValue({ member: { id: 1 } })

      await expect(store.approve({ id: 1, groupid: 2 })).rejects.toThrow()
      expect(mockMembershipFetch).toHaveBeenCalledWith({
        userid: 1,
        groupid: 2,
      })
    })
  })

  describe('reject', () => {
    it('rejects the member with the given details', async () => {
      await store.reject({
        id: 1,
        groupid: 2,
        subject: 's',
        stdmsgid: 3,
        body: 'b',
      })
      expect(mockRejectMember).toHaveBeenCalledWith(1, 2, 's', 3, 'b')
    })
  })

  describe('reply', () => {
    it('sends a reply for the membership', async () => {
      await store.reply({
        id: 1,
        groupid: 2,
        subject: 's',
        stdmsgid: 3,
        body: 'b',
      })
      expect(mockReply).toHaveBeenCalledWith(1, 2, 's', 3, 'b')
    })
  })

  describe('delete', () => {
    it('deletes the API side then removes the matching local entry', async () => {
      store.list = {
        7: { id: 7, userid: 1, groupid: 2 },
        8: { id: 8, userid: 1, groupid: 9 },
      }

      await store.delete({
        id: 1,
        groupid: 2,
        subject: 's',
        stdmsgid: 3,
        body: 'b',
      })

      expect(mockDelete).toHaveBeenCalledWith(1, 2, 's', 3, 'b')
      expect(store.list[7]).toBeUndefined()
      expect(store.list[8]).toBeDefined()
    })

    it('does nothing locally when no matching membership is found', async () => {
      store.list = { 7: { id: 7, userid: 5, groupid: 5 } }
      await store.delete({ id: 1, groupid: 2 })
      expect(store.list[7]).toBeDefined()
    })
  })

  describe('fetch', () => {
    it('fetches a single member and stores it', async () => {
      mockMembershipFetch.mockResolvedValue({ member: { id: 42, name: 'X' } })
      await store.fetch({ id: 42 })
      expect(store.list[42]).toEqual({ id: 42, name: 'X' })
    })
  })

  describe('add', () => {
    it('resets context and returns the new id', async () => {
      store.context = { id: 1 }
      mockPut.mockResolvedValue({ id: 55 })
      const id = await store.add({ userid: 1, groupid: 2 })
      expect(store.context).toBeNull()
      expect(id).toBe(55)
    })
  })

  describe('ban / unban', () => {
    it('ban calls the API with userid/groupid', async () => {
      await store.ban(1, 2)
      expect(mockBan).toHaveBeenCalledWith(1, 2)
    })

    it('unban calls the API with userid/groupid', async () => {
      await store.unban(1, 2)
      expect(mockUnban).toHaveBeenCalledWith(1, 2)
    })
  })

  describe('happinessReviewed', () => {
    it('stringifies the happiness id and tags the action', async () => {
      await store.happinessReviewed({
        userid: 1,
        groupid: 2,
        happinessid: 5,
      })
      expect(mockHappinessReviewed).toHaveBeenCalledWith({
        userid: 1,
        groupid: 2,
        happiness: '5',
        action: 'HappinessReviewed',
      })
    })
  })

  describe('reviewHold', () => {
    it('holds via the API then marks the membership held by the current user', async () => {
      mockAuthUser = { id: 111 }
      store.list = { a: { membershipid: 5, heldby: null } }

      await store.reviewHold({ userid: 1, groupid: 2, membershipid: 5 })

      expect(mockReviewHold).toHaveBeenCalledWith(1, 2)
      expect(store.list.a.heldby).toEqual({ id: 111 })
    })
  })

  describe('reviewRelease', () => {
    it('releases via the API then clears the local hold', async () => {
      store.list = { a: { membershipid: 5, heldby: { id: 111 } } }

      await store.reviewRelease({ userid: 1, groupid: 2, membershipid: 5 })

      expect(mockReviewRelease).toHaveBeenCalledWith(1, 2)
      expect(store.list.a.heldby).toBeNull()
    })
  })

  describe('getters', () => {
    beforeEach(() => {
      store.list = {
        1: { id: 1, groupid: 10 },
        2: { id: 2, groupid: 10 },
        3: { id: 3, groupid: 20 },
      }
      store.ratings = [{ id: 1, rating: 5 }]
    })

    it('getByGroup filters by coerced groupid', () => {
      expect(store.getByGroup('10').map((m) => m.id)).toEqual([1, 2])
    })

    it('get finds a single member by coerced id', () => {
      expect(store.get('2')).toEqual({ id: 2, groupid: 10 })
    })

    it('get returns undefined when not found', () => {
      expect(store.get(999)).toBeUndefined()
    })

    it('ratingById finds a rating by coerced id', () => {
      expect(store.ratingById('1')).toEqual({ id: 1, rating: 5 })
    })

    it('ratingById returns undefined when not found', () => {
      expect(store.ratingById(999)).toBeUndefined()
    })
  })
})
