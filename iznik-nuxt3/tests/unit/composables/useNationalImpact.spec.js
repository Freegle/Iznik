import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { ref } from 'vue'
import { useNationalImpact } from '~/composables/useNationalImpact'
import { getBenefitPerTonne, CO2_PER_TONNE } from '~/composables/useReuseBenefit'

vi.mock('#imports', async () => {
  const vue = await vi.importActual('vue')
  return {
    ref: vue.ref,
    computed: vue.computed,
    useRuntimeConfig: () => ({ public: { APIv2: 'http://api.test' } }),
  }
})

const WEIGHT_KG = 11_060_000

function mockFetchResponse(data) {
  return vi.fn().mockResolvedValue({
    ok: true,
    json: () => Promise.resolve(data),
  })
}

describe('useNationalImpact — computed totals from raw data', () => {
  let originalFetch

  beforeEach(() => {
    originalFetch = global.fetch
  })

  afterEach(() => {
    global.fetch = originalFetch
  })

  it('computes totalWeight in tonnes from kg', async () => {
    global.fetch = mockFetchResponse({
      year: 2024,
      totalWeightKg: WEIGHT_KG,
      totalGifts: 0,
      totalMembers: 0,
      groupCount: 0,
    })

    const year = ref(2024)
    const { totalWeight, fetchStats } = useNationalImpact(year)
    await fetchStats()
    expect(totalWeight.value).toBeCloseTo(11060, 0)
  })

  it('computes totalBenefit in £ from weight kg', async () => {
    global.fetch = mockFetchResponse({
      year: 2024,
      totalWeightKg: WEIGHT_KG,
      totalGifts: 0,
      totalMembers: 0,
      groupCount: 0,
    })

    const year = ref(2024)
    const { totalBenefit, fetchStats } = useNationalImpact(year)
    await fetchStats()
    const expectedBenefit = Math.round((WEIGHT_KG * getBenefitPerTonne()) / 1000)
    expect(totalBenefit.value).toBe(expectedBenefit)
  })

  it('computes totalCO2 from weight kg', async () => {
    global.fetch = mockFetchResponse({
      year: 2024,
      totalWeightKg: WEIGHT_KG,
      totalGifts: 0,
      totalMembers: 0,
      groupCount: 0,
    })

    const year = ref(2024)
    const { totalCO2, fetchStats } = useNationalImpact(year)
    await fetchStats()
    const expectedCO2 = Math.round((WEIGHT_KG * CO2_PER_TONNE) / 100) / 10
    expect(totalCO2.value).toBeCloseTo(expectedCO2, 1)
  })

  it('exposes totalGifts from API response', async () => {
    global.fetch = mockFetchResponse({
      year: 2024,
      totalWeightKg: 0,
      totalGifts: 664408,
      totalMembers: 0,
      groupCount: 0,
    })

    const year = ref(2024)
    const { totalGifts, fetchStats } = useNationalImpact(year)
    await fetchStats()
    expect(totalGifts.value).toBe(664408)
  })

  it('exposes totalMembers from API response', async () => {
    global.fetch = mockFetchResponse({
      year: 2024,
      totalWeightKg: 0,
      totalGifts: 0,
      totalMembers: 4500000,
      groupCount: 0,
    })

    const year = ref(2024)
    const { totalMembers, fetchStats } = useNationalImpact(year)
    await fetchStats()
    expect(totalMembers.value).toBe(4500000)
  })

  it('exposes groupCount from API response', async () => {
    global.fetch = mockFetchResponse({
      year: 2024,
      totalWeightKg: 0,
      totalGifts: 0,
      totalMembers: 0,
      groupCount: 500,
    })

    const year = ref(2024)
    const { groupCount, fetchStats } = useNationalImpact(year)
    await fetchStats()
    expect(groupCount.value).toBe(500)
  })

  it('returns zero totals before fetchStats is called', () => {
    const year = ref(2024)
    const { totalWeight, totalGifts, totalMembers, groupCount } =
      useNationalImpact(year)
    expect(totalWeight.value).toBe(0)
    expect(totalGifts.value).toBe(0)
    expect(totalMembers.value).toBe(0)
    expect(groupCount.value).toBe(0)
  })

  it('sets loading true during fetch and false after', async () => {
    let resolveResponse
    const responsePromise = new Promise((resolve) => {
      resolveResponse = resolve
    })
    global.fetch = vi.fn().mockReturnValue(responsePromise)

    const year = ref(2024)
    const { loading, fetchStats } = useNationalImpact(year)
    expect(loading.value).toBe(false)

    const promise = fetchStats()
    expect(loading.value).toBe(true)

    resolveResponse({
      ok: true,
      json: () =>
        Promise.resolve({
          year: 2024,
          totalWeightKg: 0,
          totalGifts: 0,
          totalMembers: 0,
          groupCount: 0,
        }),
    })
    await promise
    expect(loading.value).toBe(false)
  })

  it('calls the correct URL with year parameter', async () => {
    const fetchSpy = mockFetchResponse({
      year: 2023,
      totalWeightKg: 0,
      totalGifts: 0,
      totalMembers: 0,
      groupCount: 0,
    })
    global.fetch = fetchSpy

    const year = ref(2023)
    const { fetchStats } = useNationalImpact(year)
    await fetchStats()
    expect(fetchSpy).toHaveBeenCalledWith(
      'http://api.test/api/impact/national?year=2023'
    )
  })
})
