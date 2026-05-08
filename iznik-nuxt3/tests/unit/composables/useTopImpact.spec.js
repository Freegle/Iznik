import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { ref } from 'vue'
import { useTopImpact } from '~/composables/useTopImpact'

vi.mock('#imports', async () => {
  const vue = await vi.importActual('vue')
  return {
    ref: vue.ref,
    useRuntimeConfig: () => ({ public: { APIv2: 'http://api.test' } }),
  }
})

const SAMPLE_COMMUNITIES = [
  { name: 'Oxford Freegle', weightKg: 240000 },
  { name: 'Croydon Freegle', weightKg: 202000 },
]

function mockFetchResponse(data) {
  return vi.fn().mockResolvedValue({
    ok: true,
    json: () => Promise.resolve(data),
  })
}

describe('useTopImpact — fetchStats', () => {
  let originalFetch

  beforeEach(() => {
    originalFetch = global.fetch
  })

  afterEach(() => {
    global.fetch = originalFetch
  })

  it('populates communities from API response', async () => {
    global.fetch = mockFetchResponse({
      year: 2024,
      communities: SAMPLE_COMMUNITIES,
    })

    const year = ref(2024)
    const limit = ref(5)
    const { communities, fetchStats } = useTopImpact(year, limit)
    await fetchStats()
    expect(communities.value).toHaveLength(2)
    expect(communities.value[0].name).toBe('Oxford Freegle')
  })

  it('sets returnedYear from API response', async () => {
    global.fetch = mockFetchResponse({
      year: 2024,
      communities: SAMPLE_COMMUNITIES,
    })

    const year = ref(2024)
    const limit = ref(5)
    const { returnedYear, fetchStats } = useTopImpact(year, limit)
    await fetchStats()
    expect(returnedYear.value).toBe(2024)
  })

  it('starts with empty communities', () => {
    const year = ref(2024)
    const limit = ref(5)
    const { communities } = useTopImpact(year, limit)
    expect(communities.value).toHaveLength(0)
  })

  it('calls the correct URL with year and limit', async () => {
    const fetchSpy = mockFetchResponse({ year: 2023, communities: [] })
    global.fetch = fetchSpy

    const year = ref(2023)
    const limit = ref(3)
    const { fetchStats } = useTopImpact(year, limit)
    await fetchStats()
    expect(fetchSpy).toHaveBeenCalledWith(
      'http://api.test/api/impact/top?year=2023&limit=3'
    )
  })

  it('sets loading true during fetch and false after', async () => {
    let resolveResponse
    const responsePromise = new Promise((resolve) => {
      resolveResponse = resolve
    })
    global.fetch = vi.fn().mockReturnValue(responsePromise)

    const year = ref(2024)
    const limit = ref(5)
    const { loading, fetchStats } = useTopImpact(year, limit)
    expect(loading.value).toBe(false)

    const promise = fetchStats()
    expect(loading.value).toBe(true)

    resolveResponse({
      ok: true,
      json: () => Promise.resolve({ year: 2024, communities: [] }),
    })
    await promise
    expect(loading.value).toBe(false)
  })

  it('handles empty communities array', async () => {
    global.fetch = mockFetchResponse({ year: 2024, communities: [] })

    const year = ref(2024)
    const limit = ref(5)
    const { communities, fetchStats } = useTopImpact(year, limit)
    await fetchStats()
    expect(communities.value).toHaveLength(0)
  })
})
