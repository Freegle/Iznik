<template>
  <ModSysAdminUxPanel
    title="Search and browse"
    explanation="Searches that found nothing, searches repeated within a minute (the first one probably did not help), and browse sessions where people scrolled out of the feed without opening anything."
    collapsible
    :open="open"
    :summary="summary"
    :notes="notes"
    :loading="store.search.loading"
    :error="store.search.error"
    :missing="store.search.missing"
    :empty="!days.length"
    empty-text="No search data for this period."
    @toggle="emit('toggle')"
  >
    <ul class="list-unstyled mb-3">
      <li>
        Searches with no results: <strong>{{ totals.zero }}</strong>
      </li>
      <li>
        Searched again within a minute: <strong>{{ totals.again }}</strong>
      </li>
      <li>
        Browse sessions scrolled out: <strong>{{ totals.scrolled }}</strong>
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
import { withCount, pct, sumBy } from '~/modtools/composables/useUxFormat'
import ModSysAdminUxPanel from '~/modtools/components/ModSysAdminUxPanel.vue'

defineProps({
  // Whether the person opened it. The section keeps this in the address.
  open: { type: Boolean, default: false },
})
const emit = defineEmits(['toggle'])

const store = useUxStore()

const fields = [
  { key: 'date', label: 'Date' },
  { key: 'searches', label: 'Searches' },
  { key: 'zero', label: 'No results' },
  { key: 'again', label: 'Searched again within a minute' },
  { key: 'scrolled', label: 'Browse scrolled out' },
]

const days = computed(() => store.search.data?.days || [])

const totals = computed(() => ({
  zero: withCount(
    sumBy(days.value, 'zero_result'),
    sumBy(days.value, 'searches')
  ),
  again: withCount(
    sumBy(days.value, 're_searched_60s'),
    sumBy(days.value, 'searches')
  ),
  scrolled: withCount(
    sumBy(days.value, 'browse_scrolled_out'),
    sumBy(days.value, 'browse_sessions')
  ),
}))

const summary = computed(() => {
  const searches = sumBy(days.value, 'searches')
  if (!searches) return 'No searches in this period.'
  return `${pct(sumBy(days.value, 'zero_result'), searches)} of ${searches.toLocaleString()} searches found nothing.`
})

const notes = computed(() => store.notes('search'))

const rows = computed(() =>
  days.value.map((d) => ({
    date: d.date,
    searches: (d.searches || 0).toLocaleString(),
    zero: withCount(d.zero_result, d.searches),
    again: withCount(d.re_searched_60s, d.searches),
    scrolled: withCount(d.browse_scrolled_out, d.browse_sessions),
  }))
)

const rate = (n, d) => (d ? Math.round((n / d) * 1000) / 10 : 0)

const chartData = computed(() => [
  [
    'Date',
    'Searches with no results (%)',
    'Searched again within a minute (%)',
  ],
  ...days.value.map((d) => [
    d.date,
    rate(d.zero_result, d.searches),
    rate(d.re_searched_60s, d.searches),
  ]),
])

const chartOptions = {
  legend: { position: 'bottom' },
  chartArea: { width: '80%', height: '65%' },
  vAxis: { title: '% of searches', viewWindow: { min: 0 } },
}

defineExpose({ chartData })
</script>

<style scoped lang="scss">
.chart-container,
.chart {
  width: 100%;
  height: 300px;
}
</style>
