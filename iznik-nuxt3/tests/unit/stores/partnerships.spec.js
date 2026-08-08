import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const mockList = vi.fn()
const mockFetch = vi.fn()
const mockSummary = vi.fn()
const mockAdd = vi.fn()
const mockEdit = vi.fn()
const mockRemove = vi.fn()
const mockFetchGroups = vi.fn()
const mockPatchGroups = vi.fn()
const mockSetYears = vi.fn()
const mockAddPayment = vi.fn()
const mockEditPayment = vi.fn()
const mockRemovePayment = vi.fn()
const mockListStatsJobs = vi.fn()
const mockAddStatsJob = vi.fn()
const mockRemoveStatsJob = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    partnerships: {
      list: mockList,
      fetch: mockFetch,
      summary: mockSummary,
      add: mockAdd,
      edit: mockEdit,
      remove: mockRemove,
      fetchGroups: mockFetchGroups,
      patchGroups: mockPatchGroups,
      setYears: mockSetYears,
      addPayment: mockAddPayment,
      editPayment: mockEditPayment,
      removePayment: mockRemovePayment,
      listStatsJobs: mockListStatsJobs,
      addStatsJob: mockAddStatsJob,
      removeStatsJob: mockRemoveStatsJob,
    },
  }),
}))

describe('partnerships store', () => {
  let usePartnershipsStore

  const partnership = (overrides = {}) => ({
    id: 1,
    name: 'Northshire Council',
    authorityid: 10,
    authorityname: 'Northshire',
    startdate: '2026-04-01',
    enddate: '2027-03-31',
    amount: 6000,
    agreed: true,
    expiring: false,
    expired: false,
    ...overrides,
  })

  beforeEach(async () => {
    vi.clearAllMocks()
    setActivePinia(createPinia())

    mockList.mockResolvedValue([])
    mockSummary.mockResolvedValue({ total: 0, years: [] })
    mockFetch.mockResolvedValue({
      partnership: partnership(),
      groups: [],
      years: [],
      payments: [],
    })

    const mod = await import('~/stores/partnerships')
    usePartnershipsStore = mod.usePartnershipsStore
  })

  const makeStore = () => {
    const store = usePartnershipsStore()
    store.init({ public: {} })
    return store
  }

  describe('initial state', () => {
    it('starts empty', () => {
      const store = makeStore()
      expect(store.list).toEqual([])
      expect(store.detail).toEqual({})
      expect(store.summary).toBeNull()
      expect(store.statsJobs).toEqual([])
    })
  })

  describe('fetching', () => {
    it('stores the list', async () => {
      mockList.mockResolvedValue([partnership()])
      const store = makeStore()

      await store.fetch()

      expect(store.list).toHaveLength(1)
      expect(store.list[0].name).toBe('Northshire Council')
    })

    it('stores the summary', async () => {
      mockSummary.mockResolvedValue({ total: 6000, years: [] })
      const store = makeStore()

      await store.fetchSummary()

      expect(store.summary.total).toBe(6000)
    })

    it('keeps detail keyed by id so several can be open at once', async () => {
      const store = makeStore()

      await store.fetchOne(1)

      expect(store.byId(1).partnership.name).toBe('Northshire Council')
      expect(store.byId(2)).toBeNull()
    })

    it('refresh reloads the list and the totals together', async () => {
      const store = makeStore()

      await store.refresh()

      expect(mockList).toHaveBeenCalled()
      expect(mockSummary).toHaveBeenCalled()
    })
  })

  describe('editing', () => {
    it('add refreshes so new deals appear in the totals', async () => {
      mockAdd.mockResolvedValue(7)
      const store = makeStore()

      const id = await store.add({ authorityid: 10 })

      expect(id).toBe(7)
      expect(mockAdd).toHaveBeenCalledWith({ authorityid: 10 })
      expect(mockList).toHaveBeenCalled()
      expect(mockSummary).toHaveBeenCalled()
    })

    it('edit reloads the deal it changed', async () => {
      const store = makeStore()

      await store.edit(1, { tagline: 'New' })

      expect(mockEdit).toHaveBeenCalledWith(1, { tagline: 'New' })
      expect(mockFetch).toHaveBeenCalledWith(1)
    })

    it('remove drops the cached detail', async () => {
      const store = makeStore()
      await store.fetchOne(1)
      expect(store.byId(1)).not.toBeNull()

      await store.remove(1)

      expect(store.byId(1)).toBeNull()
      expect(mockRemove).toHaveBeenCalledWith(1)
    })
  })

  describe('groups', () => {
    it('adds a group and reloads the deal', async () => {
      const store = makeStore()

      await store.addGroup(1, 55)

      expect(mockPatchGroups).toHaveBeenCalledWith(1, {
        action: 'Add',
        groupid: 55,
      })
      expect(mockFetch).toHaveBeenCalledWith(1)
    })

    it('removes a group', async () => {
      const store = makeStore()

      await store.removeGroup(1, 55)

      expect(mockPatchGroups).toHaveBeenCalledWith(1, {
        action: 'Remove',
        groupid: 55,
      })
    })

    it('re-derives from the council boundary', async () => {
      const store = makeStore()

      await store.redetectGroups(1)

      expect(mockPatchGroups).toHaveBeenCalledWith(1, { action: 'Redetect' })
    })
  })

  describe('money', () => {
    it('saving a year split refreshes the totals behind the graph', async () => {
      const store = makeStore()

      await store.setYears(1, [{ financialyear: 2026, amount: 6000 }])

      expect(mockSetYears).toHaveBeenCalledWith(1, [
        { financialyear: 2026, amount: 6000 },
      ])
      expect(mockSummary).toHaveBeenCalled()
    })

    it('adding a payment refreshes the deal and the totals', async () => {
      mockAddPayment.mockResolvedValue(3)
      const store = makeStore()

      const id = await store.addPayment(1, { date: '2026-04-15', amount: 100 })

      expect(id).toBe(3)
      expect(mockFetch).toHaveBeenCalledWith(1)
      expect(mockSummary).toHaveBeenCalled()
    })

    it('marking a payment paid refreshes the totals', async () => {
      const store = makeStore()

      await store.editPayment(1, 3, { paid: '2026-05-01' })

      expect(mockEditPayment).toHaveBeenCalledWith(1, 3, { paid: '2026-05-01' })
      expect(mockSummary).toHaveBeenCalled()
    })

    it('deleting a payment refreshes the totals', async () => {
      const store = makeStore()

      await store.removePayment(1, 3)

      expect(mockRemovePayment).toHaveBeenCalledWith(1, 3)
      expect(mockSummary).toHaveBeenCalled()
    })
  })

  describe('expiring getter', () => {
    it('lists only deals running out, soonest first', async () => {
      mockList.mockResolvedValue([
        partnership({ id: 1, enddate: '2026-12-01', expiring: true }),
        partnership({ id: 2, enddate: '2030-01-01', expiring: false }),
        partnership({ id: 3, enddate: '2026-09-01', expiring: true }),
      ])
      const store = makeStore()

      await store.fetch()

      expect(store.expiring.map((p) => p.id)).toEqual([3, 1])
    })
  })

  describe('stats jobs', () => {
    it('queues a job and reloads the list', async () => {
      mockAddStatsJob.mockResolvedValue(9)
      mockListStatsJobs.mockResolvedValue([
        { id: 9, status: 'Pending', files: [] },
      ])
      const store = makeStore()

      const id = await store.addStatsJob({ authorityids: [10] })

      expect(id).toBe(9)
      expect(store.statsJobs).toHaveLength(1)
    })

    it('knows when something is still building', async () => {
      mockListStatsJobs.mockResolvedValue([
        { id: 9, status: 'Running', files: [] },
      ])
      const store = makeStore()

      await store.fetchStatsJobs()

      expect(store.statsRunning).toBe(true)
    })

    it('knows when everything has finished', async () => {
      mockListStatsJobs.mockResolvedValue([
        { id: 9, status: 'Ready', files: [] },
        { id: 8, status: 'Failed', files: [] },
      ])
      const store = makeStore()

      await store.fetchStatsJobs()

      expect(store.statsRunning).toBe(false)
    })

    it('deleting a job reloads the list', async () => {
      mockListStatsJobs.mockResolvedValue([])
      const store = makeStore()

      await store.removeStatsJob(9)

      expect(mockRemoveStatsJob).toHaveBeenCalledWith(9)
      expect(store.statsJobs).toEqual([])
    })
  })
})
