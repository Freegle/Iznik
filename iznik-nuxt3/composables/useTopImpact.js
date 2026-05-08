import { ref, useRuntimeConfig } from '#imports'

export function useTopImpact(year, limit) {
  const runtimeConfig = useRuntimeConfig()
  const loading = ref(false)
  const communities = ref([])
  const returnedYear = ref(null)

  async function fetchStats() {
    loading.value = true
    communities.value = []
    try {
      const url = `${runtimeConfig.public.APIv2}/api/impact/top?year=${year.value}&limit=${limit.value}`
      const resp = await fetch(url)
      if (resp.ok) {
        const data = await resp.json()
        returnedYear.value = data.year
        communities.value = data.communities ?? []
      }
    } finally {
      loading.value = false
    }
  }

  return {
    loading,
    communities,
    returnedYear,
    fetchStats,
  }
}
