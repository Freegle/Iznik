import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const mockFetchOnev2 = vi.fn()
const mockFetchv2 = vi.fn()
const mockLog = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    job: {
      fetchOnev2: mockFetchOnev2,
      fetchv2: mockFetchv2,
      log: mockLog,
    },
  }),
}))

describe('job store', () => {
  let useJobStore

  beforeEach(async () => {
    vi.clearAllMocks()
    localStorage.clear()
    setActivePinia(createPinia())
    const mod = await import('~/stores/job')
    useJobStore = mod.useJobStore
  })

  describe('initial state', () => {
    it('starts with empty list and not blocked', () => {
      const store = useJobStore()
      expect(store.list).toEqual([])
      expect(store.blocked).toBe(false)
    })
  })

  describe('fetchOne', () => {
    it('fetches a single job by id', async () => {
      const store = useJobStore()
      store.init({ public: {} })
      mockFetchOnev2.mockResolvedValue({ id: 42, title: 'Dev' })

      const result = await store.fetchOne(42)
      expect(result).toEqual({ id: 42, title: 'Dev' })
      expect(mockFetchOnev2).toHaveBeenCalledWith(42, false)
    })

    it('sets blocked on error', async () => {
      const store = useJobStore()
      store.init({ public: {} })
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      mockFetchOnev2.mockRejectedValue(new Error('Ad blocked'))

      const result = await store.fetchOne(42)
      expect(result).toBeNull()
      expect(store.blocked).toBe(true)
      logSpy.mockRestore()
    })
  })

  describe('fetch', () => {
    it('fetches jobs list', async () => {
      const store = useJobStore()
      store.init({ public: {} })
      mockFetchv2.mockResolvedValue([{ id: 1 }, { id: 2 }])

      const result = await store.fetch(51.5, -0.1)
      expect(result).toHaveLength(2)
      expect(store.list).toHaveLength(2)
    })

    it('returns cached list without refetching', async () => {
      const store = useJobStore()
      store.init({ public: {} })
      store.list = [{ id: 1 }]

      const result = await store.fetch(51.5, -0.1)
      expect(result).toHaveLength(1)
      expect(mockFetchv2).not.toHaveBeenCalled()
    })

    it('refetches when force=true', async () => {
      const store = useJobStore()
      store.init({ public: {} })
      store.list = [{ id: 1 }]
      mockFetchv2.mockResolvedValue([{ id: 1 }, { id: 2 }])

      const result = await store.fetch(51.5, -0.1, null, true)
      expect(result).toHaveLength(2)
    })

    it('deduplicates concurrent fetches', async () => {
      const store = useJobStore()
      store.init({ public: {} })

      let resolveFirst
      mockFetchv2.mockReturnValueOnce(
        new Promise((resolve) => {
          resolveFirst = resolve
        })
      )

      const f1 = store.fetch(51.5, -0.1, null, true)
      const f2 = store.fetch(51.5, -0.1, null, true)

      resolveFirst([{ id: 1 }])
      await f1
      await f2

      expect(mockFetchv2).toHaveBeenCalledTimes(1)
    })

    it('sets blocked on error', async () => {
      const store = useJobStore()
      store.init({ public: {} })
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      mockFetchv2.mockRejectedValue(new Error('Ad blocked'))

      await store.fetch(51.5, -0.1)
      expect(store.blocked).toBe(true)
      logSpy.mockRestore()
    })

    it('passes category parameter', async () => {
      const store = useJobStore()
      store.init({ public: {} })
      mockFetchv2.mockResolvedValue([])

      await store.fetch(51.5, -0.1, 'tech')
      expect(mockFetchv2).toHaveBeenCalledWith(51.5, -0.1, 'tech')
    })
  })

  describe('log', () => {
    it('calls API log', () => {
      const store = useJobStore()
      store.init({ public: {} })
      store.log({ action: 'click', jobid: 1 })
      expect(mockLog).toHaveBeenCalledWith({ action: 'click', jobid: 1 })
    })
  })

  describe('byId getter', () => {
    it('finds job by id', () => {
      const store = useJobStore()
      store.list = [{ id: 1 }, { id: 2, title: 'Dev' }]
      expect(store.byId(2)).toEqual({ id: 2, title: 'Dev' })
    })

    it('returns undefined for unknown id', () => {
      const store = useJobStore()
      store.list = [{ id: 1 }]
      expect(store.byId(999)).toBeUndefined()
    })
  })

  // WhatJobs does not pay for a repeat click from the same device on the same advert within
  // 24h, and counts it against the first, so the store remembers what this device opened.
  describe('opened adverts', () => {
    afterEach(() => {
      vi.useRealTimers()
    })

    it('hides an opened advert from the ad slots once the swap delay has passed', () => {
      vi.useFakeTimers()
      const store = useJobStore()
      store.list = [{ id: 1 }, { id: 2 }, { id: 3 }]

      store.recordOpened(2)
      // Still there while the browser follows the link that was tapped.
      expect(store.available.map((j) => j.id)).toEqual([1, 2, 3])

      vi.advanceTimersByTime(1500)
      expect(store.available.map((j) => j.id)).toEqual([1, 3])
      expect(store.byId(2)).toEqual({ id: 2 })
    })

    it('hides it at once when there is no swap delay', () => {
      const store = useJobStore()
      store.list = [{ id: 1 }, { id: 2 }]
      store.recordOpened(1, 0)
      expect(store.available.map((j) => j.id)).toEqual([2])
    })

    it('knows an advert was opened, including from an earlier page load', () => {
      const store = useJobStore()
      store.recordOpened(7)
      expect(store.openedRecently(7)).toBe(true)
      expect(store.openedRecently(8)).toBe(false)

      // A fresh page load reads it back from storage.
      setActivePinia(createPinia())
      const reloaded = useJobStore()
      expect(reloaded.openedRecently(7)).toBe(true)
    })

    it('forgets an advert after 24 hours', () => {
      vi.useFakeTimers()
      const store = useJobStore()
      store.list = [{ id: 1 }]
      store.recordOpened(1, 0)

      vi.advanceTimersByTime(24 * 60 * 60 * 1000 + 1)
      expect(store.openedRecently(1)).toBe(false)
      store.list = [{ id: 1 }]
      expect(store.available).toHaveLength(1)
    })

    it('restores the opened adverts when the list is fetched', async () => {
      useJobStore().recordOpened(2, 0)
      setActivePinia(createPinia())
      const store = useJobStore()
      store.init({ public: {} })
      mockFetchv2.mockResolvedValue([{ id: 1 }, { id: 2 }])

      await store.fetch(51.5, -0.1)
      expect(store.available.map((j) => j.id)).toEqual([1])
    })

    it('treats a tap within two seconds of opening an advert as a double-tap', () => {
      vi.useFakeTimers()
      const store = useJobStore()
      expect(store.isDoubleTap()).toBe(false)

      store.recordOpened(1)
      expect(store.isDoubleTap()).toBe(true)

      vi.advanceTimersByTime(2000)
      expect(store.isDoubleTap()).toBe(false)
    })

    it('still works when storage is unavailable', () => {
      const spy = vi
        .spyOn(Storage.prototype, 'getItem')
        .mockImplementation(() => {
          throw new Error('blocked')
        })
      const store = useJobStore()
      store.list = [{ id: 1 }, { id: 2 }]
      store.recordOpened(1, 0)

      expect(store.openedRecently(1)).toBe(true)
      expect(store.available.map((j) => j.id)).toEqual([2])
      spy.mockRestore()
    })
  })
})
