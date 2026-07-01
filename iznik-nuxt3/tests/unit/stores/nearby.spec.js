import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const mockFetchMessages = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    isochrone: {
      fetchMessages: mockFetchMessages,
    },
  }),
}))

describe('nearby store', () => {
  let useNearbyStore

  beforeEach(async () => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    const mod = await import('~/stores/nearby')
    useNearbyStore = mod.useNearbyStore
  })

  describe('initial state', () => {
    it('starts with empty messageList and null bounds', () => {
      const store = useNearbyStore()
      expect(store.messageList).toEqual([])
      expect(store.bounds).toBeNull()
      expect(store.fetchingMessages).toBeNull()
    })
  })

  describe('init', () => {
    it('sets config', () => {
      const store = useNearbyStore()
      store.init({ public: {} })
      expect(store.config).toEqual({ public: {} })
    })
  })

  describe('fetchMessages', () => {
    let store

    beforeEach(() => {
      store = useNearbyStore()
      store.init({ public: {} })
    })

    it('fetches messages', async () => {
      mockFetchMessages.mockResolvedValue([
        { id: 1, subject: 'Offering sofa' },
      ])

      const result = await store.fetchMessages()
      expect(result).toHaveLength(1)
      expect(store.messageList).toHaveLength(1)
    })

    it('returns cached messages without refetching', async () => {
      store.messageList = [{ id: 1 }]

      const result = await store.fetchMessages()
      expect(result).toHaveLength(1)
      expect(mockFetchMessages).not.toHaveBeenCalled()
    })

    it('refetches when force=true', async () => {
      store.messageList = [{ id: 1 }]
      mockFetchMessages.mockResolvedValue([{ id: 1 }, { id: 2 }])

      const result = await store.fetchMessages(true)
      expect(result).toHaveLength(2)
    })

    it('deduplicates concurrent fetches', async () => {
      let resolveFirst
      const firstPromise = new Promise((r) => {
        resolveFirst = r
      })
      mockFetchMessages.mockReturnValueOnce(firstPromise)

      const fetch1 = store.fetchMessages(true)
      const fetch2 = store.fetchMessages(true)

      resolveFirst([{ id: 1 }])
      await fetch1
      await fetch2

      expect(mockFetchMessages).toHaveBeenCalledTimes(1)
    })
  })

  describe('markSeen', () => {
    it('marks specified messages as seen', () => {
      const store = useNearbyStore()
      store.messageList = [
        { id: 1, unseen: true },
        { id: 2, unseen: true },
        { id: 3, unseen: true },
      ]

      store.markSeen([1, 3])

      expect(store.messageList[0].unseen).toBe(false)
      expect(store.messageList[1].unseen).toBe(true)
      expect(store.messageList[2].unseen).toBe(false)
    })

    it('does nothing when messageList is empty', () => {
      const store = useNearbyStore()
      store.messageList = []

      store.markSeen([1])
      expect(store.messageList).toEqual([])
    })
  })

  describe('bounds getter', () => {
    it('returns null when there are no messages', () => {
      const store = useNearbyStore()
      store.messageList = []

      expect(store.bounds).toBeNull()
    })

    it('computes the bounding box from the messages we have', () => {
      const store = useNearbyStore()
      store.messageList = [
        { id: 1, lat: 51.0, lng: -1.0 },
        { id: 2, lat: 52.0, lng: 0.0 },
        { id: 3, lat: 51.5, lng: -0.5 },
      ]

      expect(store.bounds).toEqual([
        [51.0, -1.0],
        [52.0, 0.0],
      ])
    })

    it('ignores messages with no coordinates', () => {
      const store = useNearbyStore()
      store.messageList = [
        { id: 1, lat: null, lng: null },
        { id: 2, lat: 51.5, lng: -0.5 },
      ]

      expect(store.bounds).toEqual([
        [51.5, -0.5],
        [51.5, -0.5],
      ])
    })

    it('returns null when no messages have coordinates', () => {
      const store = useNearbyStore()
      store.messageList = [{ id: 1, lat: null, lng: null }]

      expect(store.bounds).toBeNull()
    })
  })
})
