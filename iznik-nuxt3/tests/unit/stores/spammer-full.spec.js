import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const mockUserStore = {
  list: {},
  fetchMultiple: vi.fn().mockResolvedValue(undefined),
}

const mockMemberStore = { list: {} }

vi.mock('~/stores/user', () => ({ useUserStore: () => mockUserStore }))
vi.mock('~/stores/member', () => ({ useMemberStore: () => mockMemberStore }))

const mockFetch = vi.fn()
const mockPatch = vi.fn()
const mockAdd = vi.fn()
const mockDel = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    spammers: {
      fetch: mockFetch,
      patch: mockPatch,
      add: mockAdd,
      del: mockDel,
    },
  }),
}))

const mockFetchMe = vi.fn().mockResolvedValue(undefined)
vi.mock('~/composables/useMe', () => ({
  fetchMe: (...args) => mockFetchMe(...args),
}))

describe('spammer store — actions beyond addAll/confirm', () => {
  let useSpammerStore
  let store

  beforeEach(async () => {
    vi.clearAllMocks()
    mockUserStore.list = {}
    mockMemberStore.list = {}
    mockFetch.mockResolvedValue({ spammers: [], context: null })
    mockPatch.mockResolvedValue({})
    mockAdd.mockResolvedValue({})
    mockDel.mockResolvedValue({})
    setActivePinia(createPinia())
    const mod = await import('~/modtools/stores/spammer')
    useSpammerStore = mod.useSpammerStore
    store = useSpammerStore()
  })

  it('init stores config', () => {
    store.init({ a: 1 })
    expect(store.config).toEqual({ a: 1 })
  })

  describe('clear', () => {
    it('resets list/context and increments the instance counter', () => {
      store.list = [{ id: 1 }]
      store.context = { id: 5 }
      store.instance = 3
      store.clear()
      expect(store.list).toEqual([])
      expect(store.context).toBeNull()
      expect(store.instance).toBe(4)
    })

    it('resets the instance counter to 1 when it was falsy', () => {
      store.instance = 0
      store.clear()
      expect(store.instance).toBe(1)
    })
  })

  describe('fetch', () => {
    it('sends the current context id and stores the new context', async () => {
      store.context = { id: 7 }
      mockFetch.mockResolvedValue({
        spammers: [],
        context: { id: 8 },
      })

      await store.fetch({ collection: 'PendingAdd' })

      expect(mockFetch).toHaveBeenCalledWith(
        expect.objectContaining({ context: 7, collection: 'PendingAdd' })
      )
      expect(store.context).toEqual({ id: 8 })
    })

    it('does not send a context param on the first fetch', async () => {
      mockFetch.mockResolvedValue({ spammers: [], context: null })
      await store.fetch({ collection: 'PendingAdd' })
      expect(mockFetch.mock.calls[0][0].context).toBeUndefined()
    })

    it('batch-fetches users for the returned spammers and adds them to the list', async () => {
      mockFetch.mockResolvedValue({
        spammers: [
          { id: 1, userid: 100, byuserid: 200 },
          { id: 2, userid: 101 },
        ],
        context: null,
      })

      await store.fetch({})

      expect(mockUserStore.fetchMultiple).toHaveBeenCalledWith(
        expect.arrayContaining([100, 200, 101]),
        true
      )
      expect(store.list).toHaveLength(2)
    })

    it('discards the result if the store was cleared during the fetch', async () => {
      let resolveFetch
      mockFetch.mockReturnValue(
        new Promise((resolve) => {
          resolveFetch = resolve
        })
      )

      const fetchPromise = store.fetch({})
      store.clear() // bumps `instance` while the fetch is in flight

      resolveFetch({ spammers: [{ id: 1, userid: 100 }], context: { id: 1 } })
      await fetchPromise

      expect(store.list).toEqual([])
      expect(store.context).toBeNull()
    })
  })

  describe('fetchUsers', () => {
    it('dedupes userid/byuserid across all spammers', async () => {
      await store.fetchUsers([
        { userid: 1, byuserid: 2 },
        { userid: 1, byuserid: 3 },
        { userid: 4 },
      ])

      expect(mockUserStore.fetchMultiple).toHaveBeenCalledTimes(1)
      const ids = mockUserStore.fetchMultiple.mock.calls[0][0]
      expect([...ids].sort()).toEqual([1, 2, 3, 4])
    })

    it('does nothing when there are no ids to fetch', async () => {
      await store.fetchUsers([{ userid: null, byuserid: null }])
      expect(mockUserStore.fetchMultiple).not.toHaveBeenCalled()
    })
  })

  describe('removeFromList', () => {
    it('removes the matching entry by id, coercing types', () => {
      store.list = [{ id: 1 }, { id: 2 }, { id: 3 }]
      store.removeFromList('2')
      expect(store.list.map((s) => s.id)).toEqual([1, 3])
    })
  })

  describe('report', () => {
    it('adds a PendingAdd entry and refreshes the current member', async () => {
      await store.report({ userid: 5, reason: 'spam' })
      expect(mockAdd).toHaveBeenCalledWith({
        userid: 5,
        collection: 'PendingAdd',
        reason: 'spam',
      })
      expect(mockFetchMe).toHaveBeenCalledWith(true)
    })
  })

  describe('confirm', () => {
    it('logs and returns without calling the API when id is missing', async () => {
      await store.confirm({ userid: 5 })
      expect(mockPatch).not.toHaveBeenCalled()
      expect(mockFetchMe).not.toHaveBeenCalled()
    })
  })

  describe('requestremove', () => {
    it('adds a PendingRemove entry, drops it locally, then refreshes', async () => {
      store.list = [{ id: 9 }]
      await store.requestremove({ id: 9, userid: 5 })

      expect(mockAdd).toHaveBeenCalledWith({
        id: 9,
        userid: 5,
        collection: 'PendingRemove',
      })
      expect(store.list).toEqual([])
      expect(mockFetchMe).toHaveBeenCalledWith(true)
    })
  })

  describe('remove', () => {
    it('deletes the spammer entry, refreshes, then drops it locally', async () => {
      store.list = [{ id: 9 }]
      await store.remove({ id: 9, userid: 5 })

      expect(mockDel).toHaveBeenCalledWith({ id: 9, userid: 5 })
      expect(mockFetchMe).toHaveBeenCalledWith(true)
      expect(store.list).toEqual([])
    })
  })

  describe('safelist', () => {
    it('adds a Whitelisted entry, refreshes, then drops it locally', async () => {
      store.list = [{ id: 9 }]
      await store.safelist({ id: 9, userid: 5, reason: 'false positive' })

      expect(mockAdd).toHaveBeenCalledWith({
        id: 9,
        userid: 5,
        reason: 'false positive',
        collection: 'Whitelisted',
      })
      expect(store.list).toEqual([])
    })
  })

  describe('hold', () => {
    it('logs and returns without calling the API when id is missing', async () => {
      await store.hold({ userid: 5, myid: 1 })
      expect(mockPatch).not.toHaveBeenCalled()
    })

    it('patches with heldby, resets context, and re-fetches the PendingAdd list', async () => {
      mockFetch.mockResolvedValue({ spammers: [], context: null })
      await store.hold({ id: 9, userid: 5, reason: 'r', myid: 1 })

      expect(mockPatch).toHaveBeenCalledWith(
        expect.objectContaining({ id: 9, heldby: 1, collection: 'PendingAdd' })
      )
      expect(mockFetch).toHaveBeenCalledWith({ collection: 'PendingAdd' })
    })

    it('refreshes via the held-conflict path when the patch is refused by another mod', async () => {
      const conflict = new Error('held')
      conflict.response = {
        status: 409,
        data: { heldby: 2, heldbyname: 'Bob' },
      }
      mockPatch.mockRejectedValue(conflict)
      mockFetch.mockResolvedValue({ spammers: [], context: null })

      await expect(
        store.hold({ id: 9, userid: 5, reason: 'r', myid: 1 })
      ).rejects.toThrow('Bob is holding this')

      // The re-thrown held error propagates out of hold() immediately, so only
      // runHoldAware's own refresh call reaches fetch() -- hold()'s trailing
      // context-reset/re-fetch never runs.
      expect(mockFetch).toHaveBeenCalledTimes(1)
    })
  })

  describe('release', () => {
    it('logs and returns without calling the API when id is missing', async () => {
      await store.release({ userid: 5 })
      expect(mockPatch).not.toHaveBeenCalled()
    })

    it('patches without heldby, resets context, and re-fetches the PendingAdd list', async () => {
      mockFetch.mockResolvedValue({ spammers: [], context: null })
      await store.release({ id: 9, userid: 5, reason: 'r' })

      const call = mockPatch.mock.calls[0][0]
      expect(call).toEqual({
        id: 9,
        userid: 5,
        reason: 'r',
        collection: 'PendingAdd',
      })
      expect(call.heldby).toBeUndefined()
      expect(mockFetch).toHaveBeenCalledWith({ collection: 'PendingAdd' })
    })
  })

  describe('getters', () => {
    beforeEach(() => {
      store.list = [
        { id: 1, collection: 'PendingAdd' },
        { id: 2, collection: 'Spammer' },
        { id: 3, collection: 'PendingAdd' },
      ]
    })

    it('byId finds an entry by coerced id', () => {
      expect(store.byId('2')).toEqual({ id: 2, collection: 'Spammer' })
    })

    it('byId returns null when not found', () => {
      expect(store.byId(999)).toBeNull()
    })

    it('getList filters by collection', () => {
      expect(store.getList('PendingAdd').map((s) => s.id)).toEqual([1, 3])
    })

    it('getContext returns the context when it has an id', () => {
      store.context = { id: 4 }
      expect(store.getContext()).toEqual({ id: 4 })
    })

    it('getContext returns null when there is no context', () => {
      store.context = null
      expect(store.getContext()).toBeNull()
    })

    it('getContext returns null when the context has no id', () => {
      store.context = { foo: 'bar' }
      expect(store.getContext()).toBeNull()
    })
  })
})
