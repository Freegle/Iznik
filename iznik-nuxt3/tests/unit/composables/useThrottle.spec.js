import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import {
  throttleFetches,
  checkThrottle,
  fetchOurOffers,
} from '~/composables/useThrottle'
import { useMessageStore } from '~/stores/message'
import { useAuthStore } from '~/stores/auth'

vi.mock('~/stores/message')
vi.mock('~/stores/auth')

describe('useThrottle', () => {
  let mockMessageStore
  let mockAuthStore

  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers()

    mockMessageStore = {
      fetchingCount: 0,
      byId: vi.fn(),
      fetch: vi.fn(),
      fetchByUser: vi.fn(),
    }

    mockAuthStore = {
      user: { id: 123 },
    }

    useMessageStore.mockReturnValue(mockMessageStore)
    useAuthStore.mockReturnValue(mockAuthStore)
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.clearAllMocks()
  })

  describe('throttleFetches', () => {
    it('should return resolved promise when fetchingCount is less than 5', async () => {
      mockMessageStore.fetchingCount = 3

      const result = await throttleFetches()

      expect(result).toBeUndefined()
    })

    it('should return resolved promise when fetchingCount is 0', async () => {
      mockMessageStore.fetchingCount = 0

      const result = await throttleFetches()

      expect(result).toBeUndefined()
    })

    it('should return a promise that waits when fetchingCount is 5', () => {
      mockMessageStore.fetchingCount = 5

      const promise = throttleFetches()

      expect(promise).toBeInstanceOf(Promise)
    })

    it('should return a promise that waits when fetchingCount exceeds 5', () => {
      mockMessageStore.fetchingCount = 10

      const promise = throttleFetches()

      expect(promise).toBeInstanceOf(Promise)
    })

    it('should resolve after checkThrottle completes', () => {
      mockMessageStore.fetchingCount = 5
      const mockResolve = vi.fn()

      checkThrottle(mockResolve)

      // Advance timers with fetchingCount > 0, should not resolve
      vi.advanceTimersByTime(300)
      expect(mockResolve).not.toHaveBeenCalled()

      // Set fetchingCount to 0 and advance timers
      mockMessageStore.fetchingCount = 0
      vi.advanceTimersByTime(100)
      expect(mockResolve).toHaveBeenCalledTimes(1)
    })

    it('should resolve the throttleFetches promise when fetchingCount drops to 0', async () => {
      mockMessageStore.fetchingCount = 5

      const promise = throttleFetches()

      // Still throttled — first timer fires but fetchingCount still 5
      vi.advanceTimersByTime(100)

      // Now clear the throttle and advance — timer resolves the promise
      mockMessageStore.fetchingCount = 0
      vi.advanceTimersByTime(100)

      await promise
    })
  })

  describe('checkThrottle', () => {
    it('should resolve immediately when fetchingCount is 0', () => {
      mockMessageStore.fetchingCount = 0
      const mockResolve = vi.fn()

      checkThrottle(mockResolve)

      expect(mockResolve).toHaveBeenCalledTimes(1)
    })

    it('should not resolve when fetchingCount is greater than 0', () => {
      mockMessageStore.fetchingCount = 5
      const mockResolve = vi.fn()

      checkThrottle(mockResolve)

      expect(mockResolve).not.toHaveBeenCalled()
    })

    it('should schedule retry after 100ms when fetchingCount is greater than 0', () => {
      mockMessageStore.fetchingCount = 5
      const mockResolve = vi.fn()

      checkThrottle(mockResolve)

      expect(vi.getTimerCount()).toBe(1)
    })

    it('should eventually resolve after fetchingCount becomes 0', () => {
      mockMessageStore.fetchingCount = 2
      const mockResolve = vi.fn()

      checkThrottle(mockResolve)

      // First call should schedule a timeout
      expect(mockResolve).not.toHaveBeenCalled()

      // Advance timers and set fetchingCount to 0
      mockMessageStore.fetchingCount = 0
      vi.advanceTimersByTime(100)

      expect(mockResolve).toHaveBeenCalledTimes(1)
    })

    it('should retry multiple times until fetchingCount reaches 0', () => {
      mockMessageStore.fetchingCount = 3
      const mockResolve = vi.fn()

      checkThrottle(mockResolve)

      expect(mockResolve).not.toHaveBeenCalled()

      // First retry
      vi.advanceTimersByTime(100)
      mockMessageStore.fetchingCount = 2
      expect(mockResolve).not.toHaveBeenCalled()

      // Second retry
      vi.advanceTimersByTime(100)
      mockMessageStore.fetchingCount = 1
      expect(mockResolve).not.toHaveBeenCalled()

      // Third retry
      vi.advanceTimersByTime(100)
      mockMessageStore.fetchingCount = 0
      expect(mockResolve).not.toHaveBeenCalled()

      // Fourth retry (callback sees fetchingCount = 0 and resolves)
      vi.advanceTimersByTime(100)
      expect(mockResolve).toHaveBeenCalledTimes(1)
    })
  })

  describe('fetchOurOffers', () => {
    it('should return empty array when user is not logged in', async () => {
      mockAuthStore.user = null
      mockMessageStore.fetchByUser.mockResolvedValue([])

      const result = await fetchOurOffers()

      expect(result).toEqual([])
      expect(mockMessageStore.fetchByUser).not.toHaveBeenCalled()
    })

    it('should fetch offers by current user ID', async () => {
      mockAuthStore.user = { id: 456 }
      mockMessageStore.fetchByUser.mockResolvedValue([
        { id: 1, type: 'Offer', successful: false },
      ])

      await fetchOurOffers()

      expect(mockMessageStore.fetchByUser).toHaveBeenCalledWith(456, true)
    })

    it('should filter to only unsuccessful offers', async () => {
      const offers = [
        { id: 1, type: 'Offer', successful: false },
        { id: 2, type: 'Offer', successful: true },
        { id: 3, type: 'Offer', successful: false },
      ]
      mockMessageStore.fetchByUser.mockResolvedValue(offers)
      mockMessageStore.byId.mockImplementation((id) =>
        offers.find((o) => o.id === id)
      )
      mockMessageStore.fetch.mockResolvedValue(undefined)

      const result = await fetchOurOffers()

      expect(result).toHaveLength(2)
      expect(result.map((r) => r.id)).toEqual([1, 3])
    })

    it('should filter out non-Offer message types', async () => {
      const offers = [
        { id: 1, type: 'Offer', successful: false },
        { id: 2, type: 'Wanted', successful: false },
        { id: 3, type: 'Offer', successful: false },
      ]
      mockMessageStore.fetchByUser.mockResolvedValue(offers)
      mockMessageStore.byId.mockImplementation((id) =>
        offers.find((o) => o.id === id)
      )
      mockMessageStore.fetch.mockResolvedValue(undefined)

      const result = await fetchOurOffers()

      expect(result).toHaveLength(2)
      expect(result.every((r) => r.type === 'Offer')).toBe(true)
    })

    it('should truncate results to 100 offers', async () => {
      const offers = Array.from({ length: 150 }, (_, i) => ({
        id: i + 1,
        type: 'Offer',
        successful: false,
      }))
      mockMessageStore.fetchByUser.mockResolvedValue(offers)
      mockMessageStore.byId.mockImplementation((id) =>
        offers.find((o) => o.id === id)
      )
      mockMessageStore.fetch.mockResolvedValue(undefined)

      const result = await fetchOurOffers()

      expect(result).toHaveLength(100)
      expect(result[0].id).toBe(1)
      expect(result[99].id).toBe(100)
    })

    it('should sort offers by arrival time (most recent first)', async () => {
      const offers = [
        {
          id: 1,
          type: 'Offer',
          successful: false,
          arrival: '2026-01-01T10:00:00Z',
        },
        {
          id: 2,
          type: 'Offer',
          successful: false,
          arrival: '2026-01-05T10:00:00Z',
        },
        {
          id: 3,
          type: 'Offer',
          successful: false,
          arrival: '2026-01-03T10:00:00Z',
        },
      ]
      mockMessageStore.fetchByUser.mockResolvedValue(offers)
      mockMessageStore.byId.mockImplementation((id) =>
        offers.find((o) => o.id === id)
      )
      mockMessageStore.fetch.mockResolvedValue(undefined)

      const result = await fetchOurOffers()

      expect(result[0].id).toBe(2) // Most recent
      expect(result[1].id).toBe(3)
      expect(result[2].id).toBe(1) // Least recent
    })

    it('should handle offers with missing arrival date', async () => {
      const offers = [
        {
          id: 1,
          type: 'Offer',
          successful: false,
          arrival: '2026-01-01T10:00:00Z',
        },
        { id: 2, type: 'Offer', successful: false },
        {
          id: 3,
          type: 'Offer',
          successful: false,
          arrival: '2026-01-03T10:00:00Z',
        },
      ]
      mockMessageStore.fetchByUser.mockResolvedValue(offers)
      mockMessageStore.byId.mockImplementation((id) =>
        offers.find((o) => o.id === id)
      )
      mockMessageStore.fetch.mockResolvedValue(undefined)

      const result = await fetchOurOffers()

      // Missing dates should be treated as epoch (0)
      expect(result[0].id).toBe(3) // Most recent
      expect(result[1].id).toBe(1)
      expect(result[2].id).toBe(2) // Missing date treated as epoch
    })

    it('should fetch full details for each unsuccessful offer', async () => {
      const offers = [
        { id: 1, type: 'Offer', successful: false },
        { id: 2, type: 'Offer', successful: false },
      ]
      mockMessageStore.fetchByUser.mockResolvedValue(offers)
      mockMessageStore.byId.mockImplementation((id) =>
        offers.find((o) => o.id === id)
      )
      mockMessageStore.fetch.mockResolvedValue(undefined)

      await fetchOurOffers()

      expect(mockMessageStore.fetch).toHaveBeenCalledWith(1)
      expect(mockMessageStore.fetch).toHaveBeenCalledWith(2)
      expect(mockMessageStore.fetch).toHaveBeenCalledTimes(2)
    })

    it('should only return offers that exist after fetching', async () => {
      const offers = [
        { id: 1, type: 'Offer', successful: false },
        { id: 2, type: 'Offer', successful: false },
        { id: 3, type: 'Offer', successful: false },
      ]
      mockMessageStore.fetchByUser.mockResolvedValue(offers)
      // Only return id 1 and 3 after fetching
      mockMessageStore.byId.mockImplementation((id) => {
        if (id === 2) return undefined
        return offers.find((o) => o.id === id)
      })
      mockMessageStore.fetch.mockResolvedValue(undefined)

      const result = await fetchOurOffers()

      expect(result).toHaveLength(2)
      expect(result.map((r) => r.id)).toEqual([1, 3])
    })

    it('should wait for all fetch promises to complete', async () => {
      const offers = [
        { id: 1, type: 'Offer', successful: false },
        { id: 2, type: 'Offer', successful: false },
      ]
      mockMessageStore.fetchByUser.mockResolvedValue(offers)
      mockMessageStore.byId.mockImplementation((id) =>
        offers.find((o) => o.id === id)
      )

      let fetchCompleted = false
      mockMessageStore.fetch.mockImplementation(() => {
        return new Promise((resolve) => {
          setTimeout(() => {
            fetchCompleted = true
            resolve()
          }, 50)
        })
      })

      const promise = fetchOurOffers()

      // Fetches have been called but not yet resolved
      expect(fetchCompleted).toBe(false)

      // Advance all timers to allow the mocked fetch promises to resolve (without infinite loop)
      await vi.advanceTimersByTimeAsync(100)

      // Wait for Promise.all to complete
      await promise

      expect(fetchCompleted).toBe(true)
    })
  })
})
