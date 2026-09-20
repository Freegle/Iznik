import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const mockFetchGroupsMT = vi.fn()
const mockFetchWork = vi.fn()
const mockFetchGroupMT = vi.fn()
const mockListMT = vi.fn()
const mockPatch = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    group: {
      fetchGroupsMT: mockFetchGroupsMT,
      fetchWork: mockFetchWork,
      fetchGroupMT: mockFetchGroupMT,
      listMT: mockListMT,
      patch: mockPatch,
    },
  }),
}))

let authState = { groups: [], user: null }
vi.mock('~/stores/auth', () => ({
  useAuthStore: () => authState,
}))

const mockGroupStoreClear = vi.fn()
const mockGroupStoreFetch = vi.fn()
let groupSummaryList = {}
vi.mock('~/stores/group', () => ({
  useGroupStore: () => ({
    clear: mockGroupStoreClear,
    fetch: mockGroupStoreFetch,
    get summaryList() {
      return groupSummaryList
    },
  }),
}))

describe('modgroup store — full behaviour', () => {
  let useModGroupStore

  beforeEach(async () => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    authState = { groups: [], user: null }
    groupSummaryList = {}
    const mod = await import('~/modtools/stores/modgroup')
    useModGroupStore = mod.useModGroupStore
  })

  describe('init/clear', () => {
    it('init stores config and an api handle', () => {
      const store = useModGroupStore()
      store.init({ a: 1 })
      expect(store.config).toEqual({ a: 1 })
      expect(store.$api).toBeTruthy()
    })

    it('clear resets loaded groups but not allGroups', () => {
      const store = useModGroupStore()
      store.list = { 1: {} }
      store.getting = [1]
      store.received = true
      store.failedGroups = [1]
      store.allGroups = { 1: {} }

      store.clear()

      expect(store.list).toEqual({})
      expect(store.getting).toEqual([])
      expect(store.received).toBe(false)
      expect(store.failedGroups).toEqual([])
      expect(store.allGroups).toEqual({ 1: {} })
    })
  })

  describe('getModGroups', () => {
    it('clears state when the user has no groups', async () => {
      const store = useModGroupStore()
      store.list = { 1: {} }
      authState = { groups: [], user: null }
      groupSummaryList = { 5: {} } // non-empty so it skips the base fetch

      await store.getModGroups()

      expect(mockGroupStoreClear).toHaveBeenCalled()
      expect(store.list).toEqual({})
    })

    it('fetches base groups once when the summary list is empty', async () => {
      const store = useModGroupStore()
      authState = { groups: [{ groupid: 1 }], user: { id: 9 } }
      groupSummaryList = {}
      store.$api = { group: { fetchWork: mockFetchWork } }
      mockFetchWork.mockResolvedValue([])

      await store.getModGroups()

      expect(mockGroupStoreFetch).toHaveBeenCalled()
    })

    it('caches per-group work and applies it to already-loaded groups', async () => {
      const store = useModGroupStore()
      authState = { groups: { a: { groupid: 42 } }, user: { id: 9 } }
      groupSummaryList = { 5: {} }
      store.list = { 42: { id: 42 } }
      store.$api = { group: { fetchWork: mockFetchWork } }
      mockFetchWork.mockResolvedValue([{ groupid: 42, count: 3 }])

      await store.getModGroups()

      expect(store.cachedWorkData).toEqual([{ groupid: 42, count: 3 }])
      expect(store.list[42].work).toEqual({ groupid: 42, count: 3 })
    })

    it('swallows a work-fetch failure and continues', async () => {
      const store = useModGroupStore()
      authState = { groups: [], user: { id: 9 } }
      groupSummaryList = { 5: {} }
      store.$api = { group: { fetchWork: mockFetchWork } }
      mockFetchWork.mockRejectedValue(new Error('down'))

      await expect(store.getModGroups()).resolves.toBeUndefined()
      expect(store.cachedWorkData).toBeNull()
    })

    it('batch-fetches groups the user belongs to that are not yet loaded', async () => {
      const store = useModGroupStore()
      authState = {
        groups: { a: { groupid: 1 }, b: { groupid: 2 } },
        user: { id: 9 },
      }
      groupSummaryList = { 5: {} }
      store.$api = { group: { fetchWork: mockFetchWork } }
      mockFetchWork.mockResolvedValue([])
      mockFetchGroupsMT.mockResolvedValue([{ id: 1 }, { id: 2 }])

      await store.getModGroups()

      expect(mockFetchGroupsMT).toHaveBeenCalledWith(
        [1, 2],
        true,
        true,
        true,
        true
      )
    })

    it('does not batch-fetch when all groups are already loaded', async () => {
      const store = useModGroupStore()
      authState = { groups: { a: { groupid: 1 } }, user: null }
      groupSummaryList = { 5: {} }
      store.list = { 1: { id: 1 } }

      await store.getModGroups()

      expect(mockFetchGroupsMT).not.toHaveBeenCalled()
    })

    it('catches and logs an unexpected top-level failure', async () => {
      const store = useModGroupStore()
      authState = { groups: [], user: null }
      groupSummaryList = {} // triggers groupStore.fetch()
      mockGroupStoreFetch.mockRejectedValue(new Error('fatal'))

      await expect(store.getModGroups()).resolves.toBeUndefined()
    })
  })

  describe('fetchGroupMT', () => {
    it('logs and returns for a zero id', async () => {
      const store = useModGroupStore()
      await store.fetchGroupMT(0)
      expect(mockFetchGroupMT).not.toHaveBeenCalled()
    })

    it('applies role/mysettings from sessionGroups and marks received when getting is empty', async () => {
      const store = useModGroupStore()
      store.config = {}
      store.sessionGroups = [{ groupid: 7, role: 'Owner' }]
      store.getting = [7]
      mockFetchGroupMT.mockResolvedValue({ id: 7 })

      await store.fetchGroupMT(7)

      expect(store.list[7].role).toBe('Owner')
      expect(store.list[7].myrole).toBe('Owner')
      expect(store.list[7].mysettings).toEqual({ groupid: 7, role: 'Owner' })
      expect(store.getting).toEqual([])
      expect(store.received).toBe(true)
    })

    it('falls back to authStore.groups when sessionGroups is unset', async () => {
      const store = useModGroupStore()
      store.config = {}
      authState.groups = [{ id: 8 }]
      mockFetchGroupMT.mockResolvedValue({ id: 8 })

      await store.fetchGroupMT(8)

      expect(store.sessionGroups).toEqual([{ id: 8 }])
      expect(store.list[8]).toBeTruthy()
    })

    it('applies cached work data when the group has none', async () => {
      const store = useModGroupStore()
      store.config = {}
      store.cachedWorkData = [{ groupid: 3, count: 1 }]
      mockFetchGroupMT.mockResolvedValue({ id: 3 })

      await store.fetchGroupMT(3)

      expect(store.list[3].work).toEqual({ groupid: 3, count: 1 })
      expect(mockFetchWork).not.toHaveBeenCalled()
    })

    it('fetches and caches work data when nothing is cached yet', async () => {
      const store = useModGroupStore()
      store.config = {}
      store.$api = { group: { fetchWork: mockFetchWork } }
      mockFetchGroupMT.mockResolvedValue({ id: 4 })
      mockFetchWork.mockResolvedValue([{ groupid: 4, count: 9 }])

      await store.fetchGroupMT(4)

      expect(store.cachedWorkData).toEqual([{ groupid: 4, count: 9 }])
      expect(store.list[4].work).toEqual({ groupid: 4, count: 9 })
    })

    it('silently swallows a work fetch failure', async () => {
      const store = useModGroupStore()
      store.config = {}
      store.$api = { group: { fetchWork: mockFetchWork } }
      mockFetchGroupMT.mockResolvedValue({ id: 5 })
      mockFetchWork.mockRejectedValue(new Error('down'))

      await expect(store.fetchGroupMT(5)).resolves.toBeUndefined()
      expect(store.list[5]).toBeTruthy()
    })

    it('does nothing further when the API returns no group', async () => {
      const store = useModGroupStore()
      store.config = {}
      mockFetchGroupMT.mockResolvedValue(null)

      await store.fetchGroupMT(6)

      expect(store.list[6]).toBeUndefined()
    })

    it('leaves received false while other ids are still being fetched', async () => {
      const store = useModGroupStore()
      store.config = {}
      store.getting = [1, 2]
      mockFetchGroupMT.mockResolvedValue({ id: 1 })

      await store.fetchGroupMT(1)

      expect(store.getting).toEqual([2])
      expect(store.received).toBe(false)
    })
  })

  describe('fetchGroupsMTBatch — beyond chunking', () => {
    it('applies role and cached work data to each fetched group', async () => {
      const store = useModGroupStore()
      store.config = {}
      store.sessionGroups = [{ groupid: 1, role: 'Admin' }]
      store.cachedWorkData = [{ groupid: 2, count: 4 }]
      mockFetchGroupsMT.mockResolvedValue([{ id: 1 }, { id: 2 }])

      await store.fetchGroupsMTBatch([1, 2])

      expect(store.list[1].role).toBe('Admin')
      expect(store.list[1].mysettings).toEqual({ groupid: 1, role: 'Admin' })
      expect(store.list[2].work).toEqual({ groupid: 2, count: 4 })
      expect(store.received).toBe(true)
    })

    it('falls back to per-id fetchIfNeedBeMT when the batch call fails', async () => {
      const store = useModGroupStore()
      store.config = {}
      mockFetchGroupsMT.mockRejectedValue(new Error('no batch endpoint'))
      mockFetchGroupMT.mockResolvedValue({ id: 1 })

      await store.fetchGroupsMTBatch([1])

      expect(mockFetchGroupMT).toHaveBeenCalledWith(1, true, true, true, true)
    })
  })

  describe('listMT', () => {
    it('populates allGroups keyed by id', async () => {
      const store = useModGroupStore()
      store.config = {}
      mockListMT.mockResolvedValue([{ id: 1 }, { id: 2 }])

      await store.listMT({ some: 'params' })

      expect(store.allGroups).toEqual({ 1: { id: 1 }, 2: { id: 2 } })
    })

    it('leaves allGroups empty when the API returns nothing', async () => {
      const store = useModGroupStore()
      store.config = {}
      mockListMT.mockResolvedValue(null)

      await store.listMT({})

      expect(store.allGroups).toEqual({})
    })
  })

  describe('fetchIfNeedBeMT', () => {
    it('does nothing for a falsy id', async () => {
      const store = useModGroupStore()
      await store.fetchIfNeedBeMT(null)
      expect(mockFetchGroupMT).not.toHaveBeenCalled()
    })

    it('does nothing when the group is already loaded', async () => {
      const store = useModGroupStore()
      store.list = { 1: { id: 1 } }
      await store.fetchIfNeedBeMT(1)
      expect(mockFetchGroupMT).not.toHaveBeenCalled()
    })

    it('resolves immediately by polling when the id is already being fetched and lands', async () => {
      const store = useModGroupStore()
      store.getting = [2]
      store.list = { 2: { id: 2 } } // already present so the poll resolves on its first check

      await store.fetchIfNeedBeMT(2)

      expect(mockFetchGroupMT).not.toHaveBeenCalled()
    })

    it('pushes the id and fetches it when not already known', async () => {
      const store = useModGroupStore()
      store.config = {}
      mockFetchGroupMT.mockResolvedValue({ id: 3 })

      await store.fetchIfNeedBeMT(3)

      expect(store.list[3]).toBeTruthy()
    })
  })

  describe('updateMT', () => {
    it('patches then re-fetches the full group', async () => {
      const store = useModGroupStore()
      store.config = {}
      mockPatch.mockResolvedValue({})
      mockFetchGroupMT.mockResolvedValue({ id: 11 })

      await store.updateMT({ id: 11, name: 'New name' })

      expect(mockPatch).toHaveBeenCalledWith({ id: 11, name: 'New name' })
      expect(store.list[11]).toBeTruthy()
    })
  })

  describe('get getter', () => {
    it('returns null for a falsy or non-numeric id', () => {
      const store = useModGroupStore()
      expect(store.get(0)).toBeNull()
      expect(store.get('abc')).toBeNull()
    })

    it('coerces a numeric string id and looks it up', () => {
      const store = useModGroupStore()
      store.list = { 7: { id: 7 } }
      expect(store.get('7')).toEqual({ id: 7 })
    })

    it('returns null when the group is not yet loaded', () => {
      const store = useModGroupStore()
      expect(store.get(99)).toBeNull()
    })
  })

  describe('getfromall getter', () => {
    it('returns null for a falsy id', () => {
      const store = useModGroupStore()
      expect(store.getfromall(null)).toBeNull()
    })

    it('returns the group when present', () => {
      const store = useModGroupStore()
      store.allGroups = { 4: { id: 4 } }
      expect(store.getfromall(4)).toEqual({ id: 4 })
    })

    it('returns null when absent', () => {
      const store = useModGroupStore()
      expect(store.getfromall(4)).toBeNull()
    })
  })
})
