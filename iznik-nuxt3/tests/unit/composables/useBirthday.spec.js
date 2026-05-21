import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import dayjs from 'dayjs'

// ============================================================
// Store mocks — must be declared before any vi.mock() calls
// ============================================================
const mockGroupStoreGet = vi.fn()
const mockGroupStoreFetch = vi.fn()
const mockStatsStoreFetch = vi.fn()
const mockStatsStoreClear = vi.fn()
let mockWeights = null
let mockActivity = []

vi.mock('~/stores/group', () => ({
  useGroupStore: () => ({
    get: mockGroupStoreGet,
    fetch: mockGroupStoreFetch,
  }),
}))

vi.mock('~/stores/stats', () => ({
  useStatsStore: () => ({
    get Weight() {
      return mockWeights
    },
    get Activity() {
      return mockActivity
    },
    fetch: mockStatsStoreFetch,
    clear: mockStatsStoreClear,
  }),
}))

vi.mock('~/composables/useBuildHead', () => ({
  buildHead: vi.fn(() => ({ title: 'mocked-title', meta: [] })),
}))

import { useBirthday } from '~/composables/useBirthday'
import { buildHead } from '~/composables/useBuildHead'

// ============================================================
// Helpers
// ============================================================
const makeGroup = (overrides = {}) => ({
  id: 1,
  namefull: 'Test Community',
  founded: '2010-05-21',
  profile: null,
  ...overrides,
})

const setRoute = (params = {}) => {
  globalThis.__testUseRoute = () => ({
    params,
    query: {},
    path: '/',
    name: 'index',
    fullPath: `/birthday/${params.groupname || ''}`,
  })
}

// ============================================================
// Tests
// ============================================================
describe('useBirthday', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockWeights = null
    mockActivity = []
    mockGroupStoreGet.mockReturnValue(null)
    mockGroupStoreFetch.mockResolvedValue(undefined)
    mockStatsStoreFetch.mockResolvedValue(undefined)
    mockStatsStoreClear.mockReturnValue(undefined)

    // Default route provides a groupname
    setRoute({ groupname: 'test-group' })

    // useHead is Nuxt-auto-imported; provide a global stub so the composable doesn't throw
    globalThis.useHead = vi.fn()
  })

  afterEach(() => {
    delete globalThis.useHead
  })

  // ----------------------------------------------------------
  describe('initial state', () => {
    it('loading is true before any async work', () => {
      const { loading } = useBirthday()
      expect(loading.value).toBe(true)
    })

    it('dataReady is false initially', () => {
      const { dataReady } = useBirthday()
      expect(dataReady.value).toBe(false)
    })

    it('exposes groupname taken from route params', () => {
      const { groupname } = useBirthday()
      expect(groupname).toBe('test-group')
    })

    it('groupname is undefined when route has no groupname param', () => {
      setRoute({})
      const { groupname } = useBirthday()
      expect(groupname).toBeUndefined()
    })
  })

  // ----------------------------------------------------------
  describe('group computed', () => {
    it('calls groupStore.get with the groupname', () => {
      useBirthday()
      // trigger the computed
      const { group } = useBirthday()
      group.value // access to trigger
      expect(mockGroupStoreGet).toHaveBeenCalledWith('test-group')
    })

    it('returns the group object from the store', () => {
      const fakeGroup = makeGroup({ id: 42 })
      mockGroupStoreGet.mockReturnValue(fakeGroup)
      const { group } = useBirthday()
      expect(group.value).toEqual(fakeGroup)
    })

    it('returns null when the store has no group for the given name', () => {
      mockGroupStoreGet.mockReturnValue(null)
      const { group } = useBirthday()
      expect(group.value).toBeNull()
    })
  })

  // ----------------------------------------------------------
  describe('groupId computed', () => {
    it('returns the group id when the group exists', () => {
      mockGroupStoreGet.mockReturnValue(makeGroup({ id: 99 }))
      const { groupId } = useBirthday()
      expect(groupId.value).toBe(99)
    })

    it('returns null when the group is null', () => {
      mockGroupStoreGet.mockReturnValue(null)
      const { groupId } = useBirthday()
      expect(groupId.value).toBeNull()
    })

    it('returns null when the group object has no id property', () => {
      mockGroupStoreGet.mockReturnValue({ namefull: 'No ID Group' })
      const { groupId } = useBirthday()
      expect(groupId.value).toBeNull()
    })
  })

  // ----------------------------------------------------------
  describe('groupName computed', () => {
    it('returns namefull from the group', () => {
      mockGroupStoreGet.mockReturnValue(makeGroup({ namefull: 'Brighton Freegle' }))
      const { groupName } = useBirthday()
      expect(groupName.value).toBe('Brighton Freegle')
    })

    it('falls back to "Community" when group is null', () => {
      mockGroupStoreGet.mockReturnValue(null)
      const { groupName } = useBirthday()
      expect(groupName.value).toBe('Community')
    })

    it('falls back to "Community" when group has no namefull', () => {
      mockGroupStoreGet.mockReturnValue({ id: 5 })
      const { groupName } = useBirthday()
      expect(groupName.value).toBe('Community')
    })

    it('falls back to "Community" when namefull is empty string', () => {
      mockGroupStoreGet.mockReturnValue(makeGroup({ namefull: '' }))
      const { groupName } = useBirthday()
      expect(groupName.value).toBe('Community')
    })
  })

  // ----------------------------------------------------------
  describe('groupAge computed', () => {
    it('returns 0 when group is null', () => {
      mockGroupStoreGet.mockReturnValue(null)
      const { groupAge } = useBirthday()
      expect(groupAge.value).toBe(0)
    })

    it('returns 0 when group has no founded date', () => {
      mockGroupStoreGet.mockReturnValue({ id: 1, namefull: 'Test' })
      const { groupAge } = useBirthday()
      expect(groupAge.value).toBe(0)
    })

    it('calculates the correct age in full years', () => {
      // Founded 2010-01-01 — as of 2026-05-21 that is 16 full years
      mockGroupStoreGet.mockReturnValue(makeGroup({ founded: '2010-01-01' }))
      const { groupAge } = useBirthday()
      const expected = Math.floor(
        (Date.now() - new Date('2010-01-01')) / (365.25 * 24 * 60 * 60 * 1000)
      )
      expect(groupAge.value).toBe(expected)
    })

    it('uses floor — not yet reached the anniversary counts as the lower year', () => {
      // Founded almost exactly 10 years ago but 5 days short → should be 9
      const almostTenYearsAgo = new Date()
      almostTenYearsAgo.setFullYear(almostTenYearsAgo.getFullYear() - 10)
      almostTenYearsAgo.setDate(almostTenYearsAgo.getDate() + 5)
      mockGroupStoreGet.mockReturnValue(
        makeGroup({ founded: almostTenYearsAgo.toISOString() })
      )
      const { groupAge } = useBirthday()
      expect(groupAge.value).toBe(9)
    })

    it('returns 0 for a group founded today', () => {
      const today = new Date().toISOString()
      mockGroupStoreGet.mockReturnValue(makeGroup({ founded: today }))
      const { groupAge } = useBirthday()
      expect(groupAge.value).toBe(0)
    })
  })

  // ----------------------------------------------------------
  describe('isToday computed', () => {
    it('returns false when group is null', () => {
      mockGroupStoreGet.mockReturnValue(null)
      const { isToday } = useBirthday()
      expect(isToday.value).toBe(false)
    })

    it('returns false when group has no founded date', () => {
      mockGroupStoreGet.mockReturnValue({ id: 1 })
      const { isToday } = useBirthday()
      expect(isToday.value).toBe(false)
    })

    it('returns true when the founded MM-DD matches today', () => {
      const todayMMDD = dayjs().format('MM-DD')
      const foundedDate = `2005-${todayMMDD}`
      mockGroupStoreGet.mockReturnValue(makeGroup({ founded: foundedDate }))
      const { isToday } = useBirthday()
      expect(isToday.value).toBe(true)
    })

    it('returns false when the founded MM-DD does not match today', () => {
      // 1 day before today in a different year — the MM-DD will differ (unless day 1 of month)
      const notToday = dayjs().subtract(1, 'day')
      if (notToday.format('MM-DD') !== dayjs().format('MM-DD')) {
        const foundedDate = `2005-${notToday.format('MM-DD')}`
        mockGroupStoreGet.mockReturnValue(makeGroup({ founded: foundedDate }))
        const { isToday } = useBirthday()
        expect(isToday.value).toBe(false)
      } else {
        // Edge case: today is day 1 — subtract 2 days instead
        const twoBack = dayjs().subtract(2, 'day')
        const foundedDate = `2005-${twoBack.format('MM-DD')}`
        mockGroupStoreGet.mockReturnValue(makeGroup({ founded: foundedDate }))
        const { isToday } = useBirthday()
        expect(isToday.value).toBe(false)
      }
    })
  })

  // ----------------------------------------------------------
  describe('totalWeight computed', () => {
    it('returns 0 when Weight is null', () => {
      mockWeights = null
      const { totalWeight } = useBirthday()
      expect(totalWeight.value).toBe(0)
    })

    it('returns 0 when Weight is an empty array', () => {
      mockWeights = []
      const { totalWeight } = useBirthday()
      expect(totalWeight.value).toBe(0)
    })

    it('sums recent weights and converts grams to tonnes', () => {
      const recent = dayjs().subtract(10, 'days').format('YYYY-MM-DD')
      mockWeights = [
        { date: recent, count: 500 },
        { date: recent, count: 1500 },
      ]
      const { totalWeight } = useBirthday()
      expect(totalWeight.value).toBeCloseTo(2) // 2000g / 1000 = 2 tonnes
    })

    it('excludes weights older than 365 days', () => {
      const old = dayjs().subtract(400, 'days').format('YYYY-MM-DD')
      const recent = dayjs().subtract(10, 'days').format('YYYY-MM-DD')
      mockWeights = [
        { date: old, count: 100000 }, // over a year old — excluded
        { date: recent, count: 2000 }, // within 365 days
      ]
      const { totalWeight } = useBirthday()
      expect(totalWeight.value).toBeCloseTo(2) // only 2000g / 1000 = 2 tonnes
    })

    it('includes weights within the 365-day boundary', () => {
      // diff = 365 days: condition is diff <= 365, so it IS included
      const exactlyYear = dayjs().subtract(365, 'days').format('YYYY-MM-DD')
      mockWeights = [{ date: exactlyYear, count: 1000 }]
      const { totalWeight } = useBirthday()
      expect(totalWeight.value).toBeCloseTo(1)
    })

    it('handles a single weight entry correctly', () => {
      const recent = dayjs().subtract(5, 'days').format('YYYY-MM-DD')
      mockWeights = [{ date: recent, count: 3500 }]
      const { totalWeight } = useBirthday()
      expect(totalWeight.value).toBeCloseTo(3.5)
    })
  })

  // ----------------------------------------------------------
  describe('totalBenefit computed', () => {
    it('multiplies totalWeight by the WRAP factor of 711', () => {
      const recent = dayjs().subtract(5, 'days').format('YYYY-MM-DD')
      mockWeights = [{ date: recent, count: 2000 }] // 2 tonnes
      const { totalBenefit } = useBirthday()
      expect(totalBenefit.value).toBeCloseTo(1422) // 2 × 711
    })

    it('returns 0 when Weight is null', () => {
      mockWeights = null
      const { totalBenefit } = useBirthday()
      expect(totalBenefit.value).toBe(0)
    })

    it('returns 0 when Weight is empty', () => {
      mockWeights = []
      const { totalBenefit } = useBirthday()
      expect(totalBenefit.value).toBe(0)
    })
  })

  // ----------------------------------------------------------
  describe('totalCO2 computed', () => {
    it('multiplies totalWeight by the WRAP CO2 factor of 0.51', () => {
      const recent = dayjs().subtract(5, 'days').format('YYYY-MM-DD')
      mockWeights = [{ date: recent, count: 4000 }] // 4 tonnes
      const { totalCO2 } = useBirthday()
      expect(totalCO2.value).toBeCloseTo(2.04) // 4 × 0.51
    })

    it('returns 0 when Weight is null', () => {
      mockWeights = null
      const { totalCO2 } = useBirthday()
      expect(totalCO2.value).toBe(0)
    })

    it('returns 0 when Weight is empty', () => {
      mockWeights = []
      const { totalCO2 } = useBirthday()
      expect(totalCO2.value).toBe(0)
    })
  })

  // ----------------------------------------------------------
  describe('messagesThisYear computed', () => {
    it('returns 0 when Activity is empty', () => {
      mockActivity = []
      const { messagesThisYear } = useBirthday()
      expect(messagesThisYear.value).toBe(0)
    })

    it('sums the count of all activity items', () => {
      mockActivity = [{ count: 100 }, { count: 200 }, { count: 50 }]
      const { messagesThisYear } = useBirthday()
      expect(messagesThisYear.value).toBe(350)
    })

    it('treats items with a missing count as 0', () => {
      mockActivity = [{ count: 100 }, {}, { count: 50 }]
      const { messagesThisYear } = useBirthday()
      expect(messagesThisYear.value).toBe(150)
    })

    it('handles a single item', () => {
      mockActivity = [{ count: 42 }]
      const { messagesThisYear } = useBirthday()
      expect(messagesThisYear.value).toBe(42)
    })

    it('handles all zero counts', () => {
      mockActivity = [{ count: 0 }, { count: 0 }]
      const { messagesThisYear } = useBirthday()
      expect(messagesThisYear.value).toBe(0)
    })
  })

  // ----------------------------------------------------------
  describe('pageTitle computed', () => {
    it('combines groupName and groupAge into a title', () => {
      mockGroupStoreGet.mockReturnValue(
        makeGroup({ namefull: 'Brighton Freegle', founded: '2010-01-01' })
      )
      const { pageTitle } = useBirthday()
      const expectedAge = Math.floor(
        (Date.now() - new Date('2010-01-01')) / (365.25 * 24 * 60 * 60 * 1000)
      )
      expect(pageTitle.value).toBe(`Brighton Freegle is ${expectedAge} years old!`)
    })

    it('uses "Community" and age 0 when group is null', () => {
      mockGroupStoreGet.mockReturnValue(null)
      const { pageTitle } = useBirthday()
      expect(pageTitle.value).toBe('Community is 0 years old!')
    })
  })

  // ----------------------------------------------------------
  describe('loadBirthdayData', () => {
    it('sets loading=false and returns early when group is null', async () => {
      mockGroupStoreGet.mockReturnValue(null)
      const { loadBirthdayData, loading, dataReady } = useBirthday()

      await loadBirthdayData()

      expect(loading.value).toBe(false)
      expect(dataReady.value).toBe(false)
      expect(mockStatsStoreClear).not.toHaveBeenCalled()
      expect(mockStatsStoreFetch).not.toHaveBeenCalled()
    })

    it('clears previous stats and fetches new ones when group exists', async () => {
      mockGroupStoreGet.mockReturnValue(makeGroup({ id: 5 }))
      const { loadBirthdayData } = useBirthday()

      await loadBirthdayData()

      expect(mockStatsStoreClear).toHaveBeenCalledTimes(1)
      expect(mockStatsStoreFetch).toHaveBeenCalledTimes(1)
    })

    it('passes correct group and grouptype to stats fetch', async () => {
      mockGroupStoreGet.mockReturnValue(makeGroup({ id: 7 }))
      const { loadBirthdayData } = useBirthday()

      await loadBirthdayData()

      expect(mockStatsStoreFetch).toHaveBeenCalledWith(
        expect.objectContaining({ group: 7, grouptype: 'Freegle' })
      )
    })

    it('passes systemwide=false when groupId is non-null', async () => {
      mockGroupStoreGet.mockReturnValue(makeGroup({ id: 7 }))
      const { loadBirthdayData } = useBirthday()

      await loadBirthdayData()

      expect(mockStatsStoreFetch).toHaveBeenCalledWith(
        expect.objectContaining({ systemwide: false })
      )
    })

    it('passes systemwide=true when group has no id (null groupId)', async () => {
      mockGroupStoreGet.mockReturnValue({ namefull: 'No ID', founded: '2010-01-01' })
      const { loadBirthdayData } = useBirthday()

      await loadBirthdayData()

      expect(mockStatsStoreFetch).toHaveBeenCalledWith(
        expect.objectContaining({ systemwide: true })
      )
    })

    it('passes a date range of ~1 year (start-of-month to end-of-month)', async () => {
      mockGroupStoreGet.mockReturnValue(makeGroup({ id: 5 }))
      const { loadBirthdayData } = useBirthday()

      await loadBirthdayData()

      const callArgs = mockStatsStoreFetch.mock.calls[0][0]
      const expectedStart = dayjs().subtract(1, 'year').startOf('month').format('YYYY-MM-DD')
      const expectedEnd = dayjs().endOf('month').format('YYYY-MM-DD')

      expect(callArgs.start).toBe(expectedStart)
      expect(callArgs.end).toBe(expectedEnd)
    })

    it('sets dataReady=true and loading=false on success', async () => {
      mockGroupStoreGet.mockReturnValue(makeGroup({ id: 5 }))
      const { loadBirthdayData, loading, dataReady } = useBirthday()

      await loadBirthdayData()

      expect(dataReady.value).toBe(true)
      expect(loading.value).toBe(false)
    })

    it('sets loading=false on fetch error (does not throw)', async () => {
      mockGroupStoreGet.mockReturnValue(makeGroup({ id: 5 }))
      mockStatsStoreFetch.mockRejectedValue(new Error('Network error'))
      const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {})

      const { loadBirthdayData, loading, dataReady } = useBirthday()
      await loadBirthdayData()

      expect(loading.value).toBe(false)
      expect(dataReady.value).toBe(false)
      expect(consoleSpy).toHaveBeenCalledWith(
        'Error loading birthday data:',
        expect.any(Error)
      )
      consoleSpy.mockRestore()
    })
  })

  // ----------------------------------------------------------
  describe('setupPageHead', () => {
    it('does nothing when groupname is absent from route', async () => {
      setRoute({}) // no groupname param
      const { setupPageHead } = useBirthday()

      await setupPageHead()

      expect(mockGroupStoreFetch).not.toHaveBeenCalled()
      expect(buildHead).not.toHaveBeenCalled()
    })

    it('fetches group data with force=true', async () => {
      mockGroupStoreGet.mockReturnValue(makeGroup())
      const consoleSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      const { setupPageHead } = useBirthday()

      await setupPageHead()

      expect(mockGroupStoreFetch).toHaveBeenCalledWith('test-group', true)
      consoleSpy.mockRestore()
    })

    it('calls buildHead with the page title', async () => {
      mockGroupStoreGet.mockReturnValue(makeGroup({ namefull: 'Test Community', founded: '2010-01-01' }))
      const consoleSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      const { setupPageHead } = useBirthday()

      await setupPageHead()

      expect(buildHead).toHaveBeenCalledWith(
        expect.anything(),
        expect.anything(),
        expect.stringContaining('years old'),
        expect.any(String),
        null
      )
      consoleSpy.mockRestore()
    })

    it('uses a custom description when provided', async () => {
      mockGroupStoreGet.mockReturnValue(makeGroup())
      const consoleSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      const { setupPageHead } = useBirthday()
      const custom = 'Custom birthday page description'

      await setupPageHead(custom)

      expect(buildHead).toHaveBeenCalledWith(
        expect.anything(),
        expect.anything(),
        expect.anything(),
        custom,
        null
      )
      consoleSpy.mockRestore()
    })

    it('generates a default description mentioning the group name', async () => {
      mockGroupStoreGet.mockReturnValue(makeGroup({ namefull: 'West London Freegle' }))
      const consoleSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      const { setupPageHead } = useBirthday()

      await setupPageHead()

      const descArg = buildHead.mock.calls[0][3]
      expect(descArg).toContain('West London Freegle')
      consoleSpy.mockRestore()
    })

    it('passes the group profile image when one is set', async () => {
      const profileUrl = 'https://cdn.example.com/profile.jpg'
      mockGroupStoreGet.mockReturnValue(makeGroup({ profile: profileUrl }))
      const consoleSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      const { setupPageHead } = useBirthday()

      await setupPageHead()

      expect(buildHead).toHaveBeenCalledWith(
        expect.anything(),
        expect.anything(),
        expect.anything(),
        expect.anything(),
        profileUrl
      )
      consoleSpy.mockRestore()
    })

    it('passes null for image when group has no profile', async () => {
      mockGroupStoreGet.mockReturnValue(makeGroup({ profile: null }))
      const consoleSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      const { setupPageHead } = useBirthday()

      await setupPageHead()

      expect(buildHead).toHaveBeenCalledWith(
        expect.anything(),
        expect.anything(),
        expect.anything(),
        expect.anything(),
        null
      )
      consoleSpy.mockRestore()
    })

    it('calls useHead with the return value of buildHead', async () => {
      const mockedHeadObj = { title: 'Birthday!', meta: [{ name: 'test' }] }
      buildHead.mockReturnValueOnce(mockedHeadObj)
      mockGroupStoreGet.mockReturnValue(makeGroup())
      const consoleSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      const { setupPageHead } = useBirthday()

      await setupPageHead()

      expect(globalThis.useHead).toHaveBeenCalledWith(mockedHeadObj)
      consoleSpy.mockRestore()
    })
  })

  // ----------------------------------------------------------
  describe('returned API shape', () => {
    it('exposes all documented properties and methods', () => {
      const api = useBirthday()
      // Data
      expect(api.groupname).toBeDefined()
      expect(api.loading).toBeDefined()
      expect(api.dataReady).toBeDefined()
      // Computed
      expect(api.group).toBeDefined()
      expect(api.groupId).toBeDefined()
      expect(api.groupName).toBeDefined()
      expect(api.groupAge).toBeDefined()
      expect(api.isToday).toBeDefined()
      expect(api.totalWeight).toBeDefined()
      expect(api.totalBenefit).toBeDefined()
      expect(api.totalCO2).toBeDefined()
      expect(api.messagesThisYear).toBeDefined()
      expect(api.pageTitle).toBeDefined()
      // Methods
      expect(typeof api.setupPageHead).toBe('function')
      expect(typeof api.loadBirthdayData).toBe('function')
    })
  })
})
