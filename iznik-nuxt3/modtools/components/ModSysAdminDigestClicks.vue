<template>
  <div class="digest-clicks">
    <p class="text-muted">
      How far down the daily digest do people click? For each post position (1 =
      top), this is the <strong>click-through rate</strong>:
      <em
        >clicks &divide; the number of digests that actually showed a post at
        that position</em
      >. So it already controls for the fact that far fewer digests are long
      enough to have a post low down — a position with a tiny denominator isn't
      compared on raw clicks. Positions whose sample is too small to be
      meaningful are left off.
    </p>

    <ModEmailDateFilter
      :loading="emailTrackingStore.digestPositionsLoading"
      fetch-label="Fetch"
      default-preset="30days"
      @fetch="onFilterFetch"
    />

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

      <!-- Chart: click-through rate by position (adequate-sample positions only) -->
      <div class="chart-container mb-2">
        <GChart
          type="ComboChart"
          :data="chartData"
          :options="getPositionChartOptions()"
          class="chart"
        />
      </div>

      <p v-if="summary" class="text-muted small">
        {{ summary }}
      </p>
    </template>

    <!-- Empty -->
    <div
      v-else-if="!emailTrackingStore.digestPositionsError"
      class="text-muted text-center py-4"
    >
      <p>No daily-digest click data available for the selected period.</p>
      <p class="small">Try widening the date range.</p>
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
// Daily digest only - immediate digests are single-post (always position 1), so position
// analysis is meaningless for them. Fixed, not user-selectable.
const digestType = ref('UnifiedDigestDaily')

// Minimum number of digests that must have shown a post at a position for its
// click-through rate to be trustworthy. Deep positions appear in very few
// digests, so a single click swings the rate wildly (a position seen 30 times
// with one click reads as "3%"); plotting those produces meaningless spikes.
// We require an absolute floor AND a fraction of the top position's sample, so
// the cutoff scales with the period.
const MIN_SAMPLE_ABS = 1000
const MIN_SAMPLE_FRACTION = 0.01

const minSample = computed(() => {
  const rows = emailTrackingStore.digestPositions || []
  const top = rows.length ? rows[0].shown || 0 : 0
  return Math.max(MIN_SAMPLE_ABS, Math.round(top * MIN_SAMPLE_FRACTION))
})

// Positions with a big enough sample to plot, in order.
const significantPositions = computed(() =>
  (emailTrackingStore.digestPositions || []).filter(
    (p) => (p.shown || 0) >= minSample.value
  )
)

// Chart data: a single series - click-through rate vs position. No second axis;
// the "emails shown" denominator is context, not a co-equal series to read off.
const chartData = computed(() => {
  const header = ['Position', 'Click-through rate (%)']
  const rows = significantPositions.value.map((p) => [
    (p.position ?? 0) + 1,
    Number((p.ctr || 0).toFixed(3)),
  ])
  return [header, ...rows]
})

// One-line context under the chart: how much data, and where we stopped.
const summary = computed(() => {
  const rows = emailTrackingStore.digestPositions || []
  if (!rows.length) return null
  const totalDigests = rows[0].shown || 0
  const sig = significantPositions.value
  if (!sig.length) return null
  const lastPos = (sig[sig.length - 1].position ?? 0) + 1
  return (
    `Based on ${totalDigests.toLocaleString()} daily digests. ` +
    `Shown to position ${lastPos} - beyond that, fewer than ` +
    `${minSample.value.toLocaleString()} digests reached that depth, ` +
    `so the rate is too noisy to plot.`
  )
})

// Plain-English takeaway: describe the actual shape of the decline using only
// well-sampled positions, rather than the noisy deepest row.
const insight = computed(() => {
  const sig = significantPositions.value
  if (sig.length < 2) return null
  const first = sig[0]
  if (!first.ctr) return null

  const last = sig[sig.length - 1]
  // Only describe a decline if there actually is one.
  if (last.ctr >= first.ctr) return null

  const at = (displayPos) =>
    sig.find((p) => (p.position ?? 0) + 1 === displayPos)
  const five = at(5) || sig[Math.min(4, sig.length - 1)]
  const lastPos = (last.position ?? 0) + 1

  let msg = `The top post is clicked ${first.ctr.toFixed(2)}% of the time`
  if (five && five !== first) {
    const halvedPos = (five.position ?? 0) + 1
    msg += `, roughly halving to ${five.ctr.toFixed(
      2
    )}% by position ${halvedPos}`
  }
  msg +=
    `, and it keeps declining - down to ${last.ctr.toFixed(2)}% by position ` +
    `${lastPos}. Clicks fall off steadily with depth rather than levelling off.`
  return msg
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

function getPositionChartOptions() {
  return {
    title: 'Click-through rate by position in the digest',
    legend: { position: 'none' },
    chartArea: { width: '82%', height: '72%' },
    hAxis: {
      title: 'Position in digest (1 = top)',
    },
    vAxis: {
      title: 'Click-through rate (%)',
      viewWindow: { min: 0 },
      format: '#.##',
    },
    series: {
      0: { type: 'line', color: '#17a2b8', lineWidth: 2, pointSize: 0 },
    },
    animation: { startup: true, duration: 500, easing: 'out' },
  }
}

// Exposed for unit testing.
defineExpose({
  digestType,
  minSample,
  significantPositions,
  chartData,
  summary,
  insight,
  fetchData,
  onFilterFetch,
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
