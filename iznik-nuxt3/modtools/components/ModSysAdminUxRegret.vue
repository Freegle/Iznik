<template>
  <ModSysAdminUxPanel
    title="Regret"
    explanation="Signs that someone wished they had done something differently, such as withdrawing a post within a day of posting it. Each is shown against the number of times the action could have happened."
    collapsible
    :open="open"
    :summary="summary"
    :notes="notes"
    :loading="store.regret.loading"
    :error="store.regret.error"
    :missing="store.regret.missing"
    :empty="!signals.length"
    empty-text="No regret data for this period."
    @toggle="emit('toggle')"
  >
    <!-- The names are whole sentences, so the legend is drawn here, where it wraps, and not by the
         chart, which cuts each name short. -->
    <ul class="legend list-unstyled small d-flex flex-wrap column-gap-3 mb-1">
      <li v-for="l in legend" :key="l.label">
        <span class="swatch" :style="{ background: l.colour }" />
        {{ l.label }}
      </li>
    </ul>
    <div class="chart-container mb-3">
      <GChart
        type="LineChart"
        :data="chartData"
        :options="chartOptions"
        class="chart"
      />
    </div>
    <b-table :items="rows" :fields="fields" small responsive />
  </ModSysAdminUxPanel>
</template>

<script setup>
import { computed } from 'vue'
import { GChart } from 'vue-google-charts'
import { useUxStore } from '~/modtools/stores/ux'
import {
  withCount,
  pct,
  signed,
  sumBy,
  regretLabel,
} from '~/modtools/composables/useUxFormat'
import ModSysAdminUxPanel from '~/modtools/components/ModSysAdminUxPanel.vue'

defineProps({
  // Whether the person opened it. The section keeps this in the address.
  open: { type: Boolean, default: false },
})
const emit = defineEmits(['toggle'])

const store = useUxStore()

const fields = [
  { key: 'signal', label: 'Signal' },
  { key: 'rate', label: 'Occurrences / opportunities' },
  { key: 'previous', label: 'Previous period (count)' },
  { key: 'change', label: 'Change (count)' },
]

const signals = computed(() => store.regret.data?.signals || [])

const rows = computed(() =>
  signals.value.map((s) => ({
    signal: regretLabel(s.signal),
    rate: withCount(s.total, sumBy(s.days, 'base')),
    previous: (s.previous_total || 0).toLocaleString(),
    change: signed((s.total || 0) - (s.previous_total || 0)),
  }))
)

// The signal with the highest share of the times it could have happened. A signal that could not
// have happened at all (no base) has no share, so it only wins when none has one.
const summary = computed(() => {
  let best = null
  let bestShare = -1
  for (const s of signals.value) {
    const base = sumBy(s.days, 'base')
    const share = base ? (s.total || 0) / base : -1
    if (!best || share > bestShare) {
      best = { signal: s.signal, total: s.total || 0, base }
      bestShare = share
    }
  }
  if (!best) return ''
  return `Highest: ${regretLabel(best.signal)}, ${pct(best.total, best.base)} (${best.total.toLocaleString()} of ${best.base.toLocaleString()}).`
})

const notes = computed(() => store.notes('regret'))

const chartData = computed(() => {
  const dates = [
    ...new Set(signals.value.flatMap((s) => (s.days || []).map((d) => d.date))),
  ].sort()
  return [
    ['Date', ...signals.value.map((s) => regretLabel(s.signal))],
    ...dates.map((date) => [
      date,
      ...signals.value.map(
        (s) => (s.days || []).find((d) => d.date === date)?.count || 0
      ),
    ]),
  ]
})

// The chart's own colours, in the order of the signals, so the legend above it can match them.
const COLOURS = [
  '#3366cc',
  '#dc3912',
  '#ff9900',
  '#109618',
  '#990099',
  '#0099c6',
]
const legend = computed(() =>
  signals.value.map((s, i) => ({
    label: regretLabel(s.signal),
    colour: COLOURS[i % COLOURS.length],
  }))
)

const chartOptions = {
  legend: { position: 'none' },
  colors: COLOURS,
  chartArea: { width: '85%', height: '78%' },
  vAxis: { title: 'Occurrences', viewWindow: { min: 0 } },
}

defineExpose({ chartData })
</script>

<style scoped lang="scss">
.chart-container,
.chart {
  width: 100%;
  height: 300px;
}

.swatch {
  display: inline-block;
  width: 0.8rem;
  height: 0.8rem;
  margin-right: 0.25rem;
  border-radius: 2px;
  vertical-align: -0.1rem;
}
</style>
