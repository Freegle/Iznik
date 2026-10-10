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

describe('member store — clear, init and getters', () => {
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

  it('clear resets the list and ratings', () => {
    store.list = { 1: {} }
    store.ratings = [{ id: 1 }]

    store.clear()

    expect(store.list).toEqual({})
    expect(store.ratings).toEqual([])
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
