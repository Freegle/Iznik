<template>
  <div class="impact-page">
    <!-- Filter controls (hidden on print) -->
    <div class="impact-controls no-print">
      <label>
        From:
        <OurDatePicker v-model="fromDate" />
      </label>
      <label>
        To:
        <OurDatePicker v-model="toDate" />
      </label>
      <b-button variant="primary" size="sm" @click="reload">Update</b-button>
    </div>

    <div v-if="loading" class="impact-loading">
      <Spinner :size="40" />
      <p>Crunching the numbers…</p>
    </div>

    <div v-else-if="authority" class="impact-slide-wrap">
      <CouncilSlide
        :authority-name="authority.name"
        :year-label="yearLabel"
        :total-weight="totalWeight"
        :total-benefit="totalBenefit"
        :total-c-o2="totalCO2"
        :total-gifts="totalGifts"
        :total-members="totalMembers"
        :group-count="groupCount"
        :partial-group-count="partialGroupCount"
        :testimonials="getTestimonials()"
        :photo-url="getPhotoUrl()"
        :scale="slideScale"
      />
    </div>

    <div v-else class="impact-not-found">
      <p>Council not found.</p>
    </div>
  </div>
</template>

<script setup>
import dayjs from 'dayjs'
import {
  ref,
  computed,
  onMounted,
  useRoute,
  useHead,
  useRuntimeConfig,
} from '#imports'
import OurDatePicker from '~/components/OurDatePicker.vue'
import Spinner from '~/components/Spinner.vue'
import CouncilSlide from '~/components/impact/CouncilSlide.vue'
import { useAuthorityImpact } from '~/composables/useAuthorityImpact'
import { buildHead } from '~/composables/useBuildHead'

const route = useRoute()
const runtimeConfig = useRuntimeConfig()
const id = route.params.id

useHead(
  buildHead(route, runtimeConfig, 'Council Impact', 'Freegle impact report')
)

const fromDate = ref(
  dayjs()
    .subtract(1, 'month')
    .endOf('month')
    .subtract(1, 'year')
    .add(1, 'month')
    .startOf('month')
    .toDate()
)
const toDate = ref(dayjs().subtract(1, 'month').endOf('month').toDate())

const {
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
} = useAuthorityImpact(id, fromDate, toDate)

const yearLabel = computed(() => {
  const y1 = dayjs(fromDate.value).format('YYYY')
  const y2 = dayjs(toDate.value).format('YYYY')
  return y1 === y2 ? y1 : `${y1} –\n${y2}`
})

const slideScale = computed(() => {
  if (process.server) return 0.45
  const available = Math.min(window.innerWidth - 40, 960)
  return Math.min(0.45, available / 2000)
})

async function reload() {
  await fetchStats()
}

onMounted(async () => {
  await fetchStats()
})
</script>

<style scoped>
.impact-page {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 20px;
  background: #f0f0f0;
  min-height: 100vh;
}

.impact-controls {
  display: flex;
  gap: 12px;
  align-items: center;
  margin-bottom: 14px;
  background: white;
  border-radius: 8px;
  padding: 8px 16px;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
  font-size: 14px;
}

.impact-loading {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  margin-top: 40px;
  color: #555;
}

.impact-not-found {
  color: #888;
  margin-top: 40px;
}

@media print {
  .no-print {
    display: none !important;
  }

  .impact-page {
    padding: 0;
    background: none;
  }
}
</style>
