<template>
  <div class="browse-scroll">
    <p class="text-muted">
      How far down the <strong>browse feed</strong> do people scroll? Each
      browse session records the furthest feed position it reached. This shows,
      for each position (0 = top), the percentage of sessions that scrolled at
      least that far — the browse-feed counterpart to the digest
      click-by-position chart, so you can see where people stop.
    </p>

    <ModEmailDateFilter
      :loading="loading"
      fetch-label="Fetch"
      default-preset="30days"
      @fetch="onFilterFetch"
    />

    <div v-if="loading" class="text-center py-4">
      <b-spinner small class="me-2" />
      Loading browse scroll depth...
    </div>

    <NoticeMessage v-else-if="error" variant="danger" class="mb-3">
      {{ error }}
    </NoticeMessage>

    <template v-else-if="positions.length">
      <NoticeMessage v-if="insight" variant="info" class="mb-3">
        {{ insight }}
      </NoticeMessage>

      <div class="chart-container mb-4">
        <GChart
          type="LineChart"
          :data="chartData"
          :options="chartOptions"
          class="chart"
        />
      </div>

      <b-table
        :items="tableRows"
        :fields="tableFields"
        striped
        hover
        responsive
        small
      />
      <p class="text-muted small">
        Based on <strong>{{ total.toLocaleString() }}</strong> browse sessions
        in the selected period. <strong>Sessions reaching</strong> counts
        sessions that scrolled to at least that position.
      </p>
    </template>

    <div v-else class="text-muted text-center py-4">
      <p>No browse scroll data for the selected period.</p>
      <p class="small">Try widening the date range.</p>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { GChart } from 'vue-google-charts'
import api from '~/api'
import ModEmailDateFilter from '~/modtools/components/ModEmailDateFilter.vue'

const runtimeConfig = useRuntimeConfig()
const apiInstance = api(runtimeConfig)

const loading = ref(false)
const error = ref(null)
const positions = ref([])
const total = ref(0)
const startDate = ref('')
const endDate = ref('')

const tableFields = [
  { key: 'position', label: 'Feed position', sortable: true },
  { key: 'sessions_reaching', label: 'Sessions reaching', sortable: true },
  { key: 'pct', label: '% of sessions', sortable: true },
]

const tableRows = computed(() =>
  (positions.value || []).map((p) => ({
    position: p.position,
    sessions_reaching: (p.sessions_reaching || 0).toLocaleString(),
    pct: `${(p.pct || 0).toFixed(1)}%`,
  }))
)

const chartData = computed(() => {
  const rows = positions.value || []
  if (!rows.length) return null
  return [
    ['Feed position', '% of sessions reaching'],
    ...rows.map((p) => [p.position, p.pct || 0]),
  ]
})

const chartOptions = {
  title: 'Browse-feed scroll depth: % of sessions reaching each position',
  legend: { position: 'none' },
  chartArea: { width: '80%', height: '70%' },
  hAxis: { title: 'Feed position (0 = top)' },
  vAxis: {
    title: '% of sessions reaching',
    viewWindow: { min: 0, max: 100 },
    format: '#.#',
  },
  colors: ['#17a2b8'],
  animation: { startup: true, duration: 500, easing: 'out' },
}

// Plain-English takeaway: where reach falls below half.
const insight = computed(() => {
  const rows = positions.value || []
  if (rows.length < 2) return null
  const half = rows.find((p) => (p.pct || 0) < 50)
  if (!half) return null
  return `Half of browse sessions never scroll past position ${half.position}.`
})

async function fetchScrollDepth() {
  loading.value = true
  error.value = null
  try {
    const result = await apiInstance.browse.fetchScrollDepth({
      start: startDate.value,
      end: endDate.value,
    })
    positions.value = result?.positions || []
    total.value = result?.total || 0
  } catch (e) {
    error.value = e.message || 'Unknown error'
  } finally {
    loading.value = false
  }
}

// ModEmailDateFilter fires this on mount and whenever the period changes, so it
// drives the initial load too.
function onFilterFetch({ start, end }) {
  startDate.value = start || ''
  endDate.value = end || ''
  fetchScrollDepth()
}

defineExpose({ fetchScrollDepth, onFilterFetch, chartData, tableRows, insight })
</script>

<style scoped lang="scss">
.browse-scroll {
  .chart-container {
    width: 100%;
    min-height: 380px;
  }

  .chart {
    width: 100%;
    height: 380px;
  }
}
</style>
