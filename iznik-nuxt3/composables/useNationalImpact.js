import { ref, computed, useRuntimeConfig } from '#imports'
import { getBenefitPerTonne, CO2_PER_TONNE } from '~/composables/useReuseBenefit'

export function useNationalImpact(year) {
  const runtimeConfig = useRuntimeConfig()
  const loading = ref(false)
  const rawData = ref(null)

  const totalWeightKg = computed(() => rawData.value?.totalWeightKg ?? 0)
  const totalGifts = computed(() => rawData.value?.totalGifts ?? 0)
  const totalMembers = computed(() => rawData.value?.totalMembers ?? 0)
  const groupCount = computed(() => rawData.value?.groupCount ?? 0)

  const totalWeight = computed(
    () => Math.round(totalWeightKg.value / 100) / 10
  )
  const totalBenefit = computed(() =>
    Math.round((totalWeightKg.value * getBenefitPerTonne()) / 1000)
  )
  const totalCO2 = computed(
    () => Math.round((totalWeightKg.value * CO2_PER_TONNE) / 100) / 10
  )

  async function fetchStats() {
    loading.value = true
    rawData.value = null
    try {
      const url = `${runtimeConfig.public.APIv2}/api/impact/national?year=${year.value}`
      const resp = await fetch(url)
      if (resp.ok) {
        rawData.value = await resp.json()
      }
    } finally {
      loading.value = false
    }
  }

  return {
    loading,
    totalWeightKg,
    totalGifts,
    totalMembers,
    groupCount,
    totalWeight,
    totalBenefit,
    totalCO2,
    fetchStats,
  }
}
