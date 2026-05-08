import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref } from 'vue'
import { useAuthorityImpact } from '~/composables/useAuthorityImpact'
import { getBenefitPerTonne, CO2_PER_TONNE } from '~/composables/useReuseBenefit'

// Stub stores — each test controls fetchStats by controlling store responses
const mockAuthorityFetch = vi.fn()
const mockStatsClear = vi.fn()
const mockStatsFetch = vi.fn()

let mockAuthorityList = {}
let mockStats = {}

vi.mock('~/stores/authority', () => ({
  useAuthorityStore: () => ({
    fetch: mockAuthorityFetch,
    get list() {
      return mockAuthorityList
    },
  }),
}))

vi.mock('~/stores/stats', () => ({
  useStatsStore: () => ({
    clear: mockStatsClear,
    fetch: mockStatsFetch,
    get Weight() {
      return mockStats.Weight ?? []
    },
    get Outcomes() {
      return mockStats.Outcomes ?? []
    },
    get ApprovedMemberCount() {
      return mockStats.ApprovedMemberCount ?? []
    },
  }),
}))

vi.mock('#imports', async () => {
  const vue = await vi.importActual('vue')
  return { ref: vue.ref, computed: vue.computed }
})

describe('useAuthorityImpact — getTestimonials', () => {
  it('returns example testimonials for an unconfigured authority ID', () => {
    const { getTestimonials } = useAuthorityImpact(
      '99999',
      ref(new Date()),
      ref(new Date())
    )
    const t = getTestimonials()
    expect(Array.isArray(t)).toBe(true)
    expect(t.length).toBeGreaterThan(0)
    expect(t[0]).toHaveProperty('quote')
  })

  it('returns an array for any unconfigured authority ID', () => {
    const { getTestimonials } = useAuthorityImpact(
      '99999',
      ref(new Date()),
      ref(new Date())
    )
    expect(Array.isArray(getTestimonials())).toBe(true)
  })
})

describe('useAuthorityImpact — getPhotoUrl', () => {
  it('returns null for an unconfigured authority ID', () => {
    const { getPhotoUrl } = useAuthorityImpact(
      '99999',
      ref(new Date()),
      ref(new Date())
    )
    expect(getPhotoUrl()).toBeNull()
  })
})

describe('useAuthorityImpact — computed totals', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockAuthorityList = {}
    mockStats = {}
  })

  it('converts weight from kg to tonnes correctly', async () => {
    mockAuthorityList = {
      42: {
        name: 'Test Council',
        groups: [{ id: 1, overlap: 1 }],
      },
    }
    mockStats = {
      Weight: [{ count: 50000 }, { count: 50000 }],
      Outcomes: [],
      ApprovedMemberCount: [{ count: 100 }],
    }

    const fromDate = ref(new Date('2024-01-01'))
    const toDate = ref(new Date('2024-12-31'))
    const { totalWeight, fetchStats } = useAuthorityImpact(42, fromDate, toDate)

    await fetchStats()

    // 100,000 kg → 100 tonnes (rounded to 1dp since >= 10 → integer)
    expect(totalWeight.value).toBe(100)
  })

  it('applies overlap weighting to weight totals', async () => {
    mockAuthorityList = {
      43: {
        name: 'Partial Council',
        groups: [{ id: 1, overlap: 0.5 }],
      },
    }
    mockStats = {
      Weight: [{ count: 10000 }],
      Outcomes: [],
      ApprovedMemberCount: [{ count: 200 }],
    }

    const fromDate = ref(new Date('2024-01-01'))
    const toDate = ref(new Date('2024-12-31'))
    const { totalWeight, fetchStats } = useAuthorityImpact(43, fromDate, toDate)

    await fetchStats()

    // 10,000kg × 0.5 overlap = 5,000kg → 5 tonnes (< 10 → 1dp)
    expect(totalWeight.value).toBe(5)
  })

  it('calculates CO2 as weight × CO2_PER_TONNE', async () => {
    mockAuthorityList = {
      44: {
        name: 'CO2 Council',
        groups: [{ id: 1, overlap: 1 }],
      },
    }
    // 2,000kg = 2 tonnes
    mockStats = {
      Weight: [{ count: 2000 }],
      Outcomes: [],
      ApprovedMemberCount: [{ count: 50 }],
    }

    const fromDate = ref(new Date('2024-01-01'))
    const toDate = ref(new Date('2024-12-31'))
    const { totalCO2, fetchStats } = useAuthorityImpact(44, fromDate, toDate)

    await fetchStats()

    // 2000kg × CO2_PER_TONNE / 1000 = 2 × 0.51 = 1.02 → rounded to 1.0
    const expected = Math.round(2000 * CO2_PER_TONNE / 100) / 10
    expect(totalCO2.value).toBeCloseTo(expected, 5)
  })

  it('calculates benefit using getBenefitPerTonne', async () => {
    mockAuthorityList = {
      45: {
        name: 'Benefit Council',
        groups: [{ id: 1, overlap: 1 }],
      },
    }
    // 1,000kg = 1 tonne
    mockStats = {
      Weight: [{ count: 1000 }],
      Outcomes: [],
      ApprovedMemberCount: [{ count: 10 }],
    }

    const fromDate = ref(new Date('2024-01-01'))
    const toDate = ref(new Date('2024-12-31'))
    const { totalBenefit, fetchStats } = useAuthorityImpact(45, fromDate, toDate)

    await fetchStats()

    const expected = Math.round(1000 * getBenefitPerTonne() / 1000)
    expect(totalBenefit.value).toBe(expected)
  })

  it('sums member counts across groups', async () => {
    mockAuthorityList = {
      46: {
        name: 'Multi Council',
        groups: [
          { id: 1, overlap: 1 },
          { id: 2, overlap: 1 },
        ],
      },
    }

    let callCount = 0
    mockStatsFetch.mockImplementation(() => {
      callCount++
      if (callCount === 1) {
        mockStats = {
          Weight: [{ count: 1000 }],
          Outcomes: [],
          ApprovedMemberCount: [{ count: 100 }],
        }
      } else {
        mockStats = {
          Weight: [{ count: 2000 }],
          Outcomes: [],
          ApprovedMemberCount: [{ count: 200 }],
        }
      }
    })

    const fromDate = ref(new Date('2024-01-01'))
    const toDate = ref(new Date('2024-12-31'))
    const { totalMembers, fetchStats } = useAuthorityImpact(46, fromDate, toDate)

    await fetchStats()

    expect(totalMembers.value).toBe(300)
  })

  it('returns zero totals when authority is not found', async () => {
    mockAuthorityList = {}

    const fromDate = ref(new Date('2024-01-01'))
    const toDate = ref(new Date('2024-12-31'))
    const { totalWeight, totalGifts, totalMembers, fetchStats } =
      useAuthorityImpact(999, fromDate, toDate)

    await fetchStats()

    expect(totalWeight.value).toBe(0)
    expect(totalGifts.value).toBe(0)
    expect(totalMembers.value).toBe(0)
  })
})
