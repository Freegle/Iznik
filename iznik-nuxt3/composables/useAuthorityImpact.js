import dayjs from 'dayjs'
import { ref, computed } from '#imports'
import { useAuthorityStore } from '~/stores/authority'
import { useStatsStore } from '~/stores/stats'
import {
  getBenefitPerTonne,
  CO2_PER_TONNE,
} from '~/composables/useReuseBenefit'
import { parseOutcomeDate } from '~/composables/useAuthoritySearch'
import councilConfig from '~/config/council-impact.json'

/**
 * Fetches and aggregates impact stats for a local authority.
 * Mirrors the data-fetching logic in pages/stats/authority/[[id]].vue but
 * returns clean aggregated totals suitable for the impact infographic.
 */
export function useAuthorityImpact(authorityId, fromDate, toDate) {
  const authorityStore = useAuthorityStore()
  const statsStore = useStatsStore()

  const loading = ref(false)
  const authority = ref(null)
  const totalWeightKg = ref(0)
  const totalGiftsCount = ref(0)
  const totalMembersCount = ref(0)
  const primaryGroupCount = ref(0)
  const partialGroupCount = ref(0)

  const totalWeight = computed(() => Math.round(totalWeightKg.value / 100) / 10)
  const totalBenefit = computed(() =>
    Math.round((totalWeightKg.value * getBenefitPerTonne()) / 1000)
  )
  const totalCO2 = computed(
    () => Math.round((totalWeightKg.value * CO2_PER_TONNE) / 100) / 10
  )
  const totalGifts = computed(() => totalGiftsCount.value)
  const totalMembers = computed(() => totalMembersCount.value)
  const groupCount = computed(() => primaryGroupCount.value)

  async function fetchStats() {
    loading.value = true
    totalWeightKg.value = 0
    totalGiftsCount.value = 0
    totalMembersCount.value = 0
    primaryGroupCount.value = 0
    partialGroupCount.value = 0

    try {
      await authorityStore.fetch(authorityId)
      const auth = authorityStore.list[authorityId]
      if (!auth) return

      authority.value = auth

      const start = dayjs(fromDate.value).format('YYYY-MM-DD')
      const end = dayjs(toDate.value)
        .endOf('month')
        .add(1, 'day')
        .format('YYYY-MM-DD')
      const monthsCovered =
        dayjs(toDate.value).diff(dayjs(fromDate.value), 'months') + 1

      const startDjs = dayjs(fromDate.value)
      const endDjs = dayjs(toDate.value)

      let weightKgTotal = 0
      let giftsTotal = 0
      let membersTotal = 0
      let primaryCount = 0
      let partialCount = 0

      for (const group of auth.groups) {
        await statsStore.clear()
        await statsStore.fetch({
          group: group.id,
          grouptype: 'Freegle',
          components: 'Weight,ApprovedMemberCount,Outcomes',
          start,
          end,
          force: true,
        })

        const overlap = group.overlap
        const weights = Array.isArray(statsStore.Weight)
          ? statsStore.Weight
          : []
        const outcomes = Array.isArray(statsStore.Outcomes)
          ? statsStore.Outcomes
          : []
        const members = Array.isArray(statsStore.ApprovedMemberCount)
          ? statsStore.ApprovedMemberCount
          : []

        let groupWeight = 0
        for (const w of weights) {
          groupWeight += w.count * overlap
        }

        const avpermonth = groupWeight / monthsCovered

        if (avpermonth > 1 || auth.groups.length === 1 || overlap === 1) {
          weightKgTotal += groupWeight

          for (const outcome of outcomes) {
            const m = dayjs(parseOutcomeDate(outcome.date))
            if (!m.isBefore(startDjs) && !m.isAfter(endDjs)) {
              giftsTotal += outcome.count * overlap
            }
          }

          if (members.length > 0) {
            const latest = members[members.length - 1]
            membersTotal += latest.count * overlap
          }

          if (overlap >= 0.95) {
            primaryCount++
          } else {
            partialCount++
          }
        }
      }

      totalWeightKg.value = weightKgTotal
      totalGiftsCount.value = Math.round(giftsTotal)
      totalMembersCount.value = Math.round(membersTotal)
      primaryGroupCount.value = primaryCount
      partialGroupCount.value = partialCount
    } finally {
      loading.value = false
    }
  }

  function getTestimonials() {
    const key = String(authorityId)
    return (
      councilConfig[key]?.testimonials ??
      councilConfig.example?.testimonials ??
      []
    )
  }

  function getPhotoUrl() {
    const key = String(authorityId)
    return councilConfig[key]?.photoUrl ?? null
  }

  return {
    loading,
    authority,
    totalWeight,
    totalBenefit,
    totalCO2,
    totalGifts,
    totalMembers,
    groupCount,
    partialGroupCount,
    fetchStats,
    getTestimonials,
    getPhotoUrl,
  }
}
