<template>
  <div class="digest-clicks">
    <p class="text-muted">
      How far down the unified digest do people click? Each post in a digest is
      tracked with its position (1 = top). This shows the
      <strong>click-through rate</strong> — the percentage of digests displaying
      a post at each position where the recipient clicked that post — so you can
      see how a post's position affects engagement.
    </p>

    <!-- Date Range + digest type filter -->
    <ModEmailDateFilter
      :loading="emailTrackingStore.digestPositionsLoading"
      fetch-label="Fetch"
      default-preset="30days"
      @fetch="onFilterFetch"
    >
      <template #extra-filters>
        <label class="filter-label">Digest:</label>
        <b-form-select
          v-model="digestType"
          :options="digestTypeOptions"
          size="sm"
          style="width: 150px"
          @change="onTypeChange"
        />
      </template>
    </ModEmailDateFilter>

    <!-- Error -->
    <NoticeMessage
      v-if="emailTrackingStore.digestPositionsError"
      variant="danger"
      class="mb-3"
    >
      {{ emailTrackingStore.digestPositionsError }}
    </NoticeMessage>

    <!-- Loading -->
    <div
      v-if="emailTrackingStore.digestPositionsLoading"
      class="text-center py-4"
    >
      <b-spinner small class="me-2" />
      Loading digest click positions...
    </div>

    <!-- Results -->
    <template v-else-if="emailTrackingStore.hasDigestPositions">
      <!-- Headline insight -->
      <NoticeMessage v-if="insight" variant="info" class="mb-3">
        {{ insight }}
      </NoticeMessage>

      <!-- Chart -->
      <div class="chart-container mb-4">
        <GChart
          type="ComboChart"
          :data="emailTrackingStore.digestPositionsChartData"
          :options="getPositionChartOptions()"
          class="chart"
        />
      </div>

      <!-- Detail table -->
      <b-table
        :items="tableRows"
        :fields="tableFields"
        striped
        hover
        responsive
        small
      />
      <p class="text-muted small">
        <strong>Emails shown</strong> is the number of digests that displayed a
        post at that position (the click-through denominator).
        <strong>Clicked</strong> counts the distinct digests where the recipient
        clicked the post at that position.
      </p>
    </template>

    <!-- Empty -->
    <div
      v-else-if="!emailTrackingStore.digestPositionsError"
      class="text-muted text-center py-4"
    >
      <p>No digest click data available for the selected period.</p>
      <p class="small">
        Try widening the date range or selecting a different digest type.
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { GChart } from 'vue-google-charts'
import { useEmailTrackingStore } from '~/modtools/stores/emailtracking'
import ModEmailDateFilter from '~/modtools/components/ModEmailDateFilter.vue'

const emailTrackingStore = useEmailTrackingStore()

const startDate = ref('')
const endDate = ref('')
const digestType = ref('')

// The unified digest is sent in two modes; allow filtering to each.
const digestTypeOptions = [
  { text: 'All digests', value: '' },
  { text: 'Immediate', value: 'UnifiedDigestImmediate' },
  { text: 'Daily', value: 'UnifiedDigestDaily' },
]

const tableFields = [
  { key: 'position', label: 'Position', sortable: true },
  { key: 'shown', label: 'Emails shown', sortable: true },
  { key: 'clicks', label: 'Total clicks', sortable: true },
  { key: 'emails_clicked', label: 'Clicked', sortable: true },
  { key: 'ctr', label: 'Click-through rate', sortable: true },
]

const tableRows = computed(() =>
  (emailTrackingStore.digestPositions || []).map((p) => ({
    position: (p.position ?? 0) + 1,
    shown: (p.shown || 0).toLocaleString(),
    clicks: (p.clicks || 0).toLocaleString(),
    emails_clicked: (p.emails_clicked || 0).toLocaleString(),
    ctr: `${(p.ctr || 0).toFixed(1)}%`,
  }))
)

// A plain-English takeaway comparing the top position to the rest.
const insight = computed(() => {
  const rows = emailTrackingStore.digestPositions || []
  if (rows.length < 2) return null
  const first = rows[0]
  const last = rows[rows.length - 1]
  if (!first.ctr) return null
  const drop = first.ctr - last.ctr
  if (drop <= 0) return null
  const pct = Math.round((drop / first.ctr) * 100)
  return (
    `The top post is clicked ${first.ctr.toFixed(1)}% of the time, ` +
    `falling to ${last.ctr.toFixed(1)}% by position ${last.position + 1} — ` +
    `${pct}% lower. Posts further down the digest get fewer clicks.`
  )
})

function fetchData() {
  emailTrackingStore.setFilters({
    type: digestType.value,
    start: startDate.value,
    end: endDate.value,
  })
  emailTrackingStore.fetchDigestPositions()
}

function onFilterFetch({ start, end }) {
  startDate.value = start
  endDate.value = end
  fetchData()
}

function onTypeChange() {
  // Re-fetch with the existing date range when the digest type changes.
  if (startDate.value && endDate.value) {
    fetchData()
  }
}

function getPositionChartOptions() {
  return {
    title: 'Click-through rate by position in the digest',
    seriesType: 'bars',
    legend: { position: 'bottom' },
    chartArea: { width: '80%', height: '70%' },
    hAxis: {
      title: 'Position in digest (1 = top)',
    },
    vAxes: {
      0: {
        title: 'Click-through rate (%)',
        viewWindow: { min: 0 },
        format: '#.#',
      },
      1: {
        title: 'Emails shown',
        viewWindow: { min: 0 },
      },
    },
    series: {
      0: { type: 'bars', targetAxisIndex: 0, color: '#17a2b8' },
      1: {
        type: 'line',
        targetAxisIndex: 1,
        color: '#adb5bd',
        lineDashStyle: [4, 4],
        pointSize: 3,
      },
    },
    animation: { startup: true, duration: 500, easing: 'out' },
  }
}

// Exposed for unit testing.
defineExpose({
  digestType,
  digestTypeOptions,
  tableFields,
  tableRows,
  insight,
  fetchData,
  onFilterFetch,
  onTypeChange,
  getPositionChartOptions,
})
</script>

<style scoped lang="scss">
.digest-clicks {
  .filter-label {
    font-size: 0.85rem;
    font-weight: 600;
    margin: 0;
  }

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
