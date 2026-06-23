<template>
  <div>
    <p class="text-muted small mb-2">
      <strong>How to read this:</strong> rippling is working in a group when its
      reply rate and reuse rate step up after you switch it on - with a real
      share of replies coming via rippling, and travel distance staying
      sensible. If reply and reuse stay flat it isn't helping there; if distance
      climbs with no gain, tighten the reach. Pick a date range, and a group to
      compare places.
    </p>

    <ModEmailDateFilter
      :loading="loading"
      fetch-label="Fetch"
      default-preset="30days"
      @fetch="onFilterFetch"
    />

    <div v-if="loading" class="text-center py-3">
      <b-spinner />
    </div>
    <div v-else-if="error" class="text-danger">Failed to load: {{ error }}</div>
    <div v-else>
      <b-form-group
        v-if="groupOptions.length"
        label="Group:"
        label-cols="auto"
        label-class="small fw-bold"
        class="mb-2"
      >
        <b-form-select
          v-model="groupFilter"
          size="sm"
          style="width: auto"
          @change="onGroupChange"
        >
          <option :value="0">All groups</option>
          <option v-for="g in groupOptions" :key="g.id" :value="g.id">
            {{ g.name }}
          </option>
        </b-form-select>
      </b-form-group>
      <p v-else class="text-muted small fst-italic mb-2">
        Per-group comparison appears here once groups have rippled.
      </p>

      <div class="mb-3">
        <strong class="small">Are more offers getting a reply?</strong>
        <p class="text-muted small mb-1">
          Share of offers that get a reply within 36h.
          <span class="text-success fw-bold">Good:</span> trends up after
          rippling starts. <span class="text-danger fw-bold">Bad:</span> flat -
          rippling isn't getting posts seen here.
        </p>
        <GChart
          v-if="replyRateChart"
          type="LineChart"
          :data="replyRateChart"
          :options="pctChartOptions('Reply rate (%)', '#28a745')"
          style="width: 100%; height: 300px"
        />
        <p v-else class="text-muted small">No data yet.</p>
      </div>

      <div class="mb-3">
        <strong class="small">How much of the lift is rippling?</strong>
        <p class="text-muted small mb-1">
          Share of replies that came via rippling vs your own members.
          <span class="text-success fw-bold">Good:</span> a real share - the
          lift is coming from reach.
          <span class="text-danger fw-bold">Bad:</span> ~0% - reach isn't
          producing replies.
        </p>
        <GChart
          v-if="replySourceChart"
          type="LineChart"
          :data="replySourceChart"
          :options="pctChartOptions('Share of replies (%)', '#007bff')"
          style="width: 100%; height: 300px"
        />
        <p v-else class="text-muted small">
          No data yet — accrues from reply-time capture
          (<code>rippling_reply_attribution</code>).
        </p>
      </div>

      <div class="mb-3">
        <strong class="small">Are repliers a sensible distance away?</strong>
        <p class="text-muted small mb-1">
          Median distance from a post to its replier.
          <span class="fw-bold">Watch:</span> climbing while the reply rate
          stays flat means you're reaching too far for no gain - tighten the
          reach.
        </p>
        <GChart
          v-if="replyDistanceChart"
          type="LineChart"
          :data="replyDistanceChart"
          :options="kmChartOptions()"
          style="width: 100%; height: 300px"
        />
        <p v-else class="text-muted small">No data yet.</p>
      </div>

      <div class="mb-3">
        <strong class="small">Is more stuff actually being reused?</strong>
        <p class="text-muted small mb-1">
          Share of posts taken/received - the real outcome.
          <span class="text-success fw-bold">Good:</span> up - rippling is
          driving reuse. This is the number that justifies it.
        </p>
        <GChart
          v-if="takenRateChart"
          type="LineChart"
          :data="takenRateChart"
          :options="pctChartOptions('Taken/received (%)', '#6f42c1')"
          style="width: 100%; height: 300px"
        />
        <p v-else class="text-muted small">No data yet.</p>
      </div>

      <h6 class="mt-4">Where to look — geographic hotspots</h6>
      <p class="text-muted small mb-1">
        Areas behaving unusually vs the rest of the network - a local problem
        the overall average hides.
        <span class="fw-bold">Action:</span> investigate any row flagged
        <b-badge variant="danger">alert</b-badge>.
      </p>
      <b-table-simple hover responsive small>
        <b-thead>
          <b-tr>
            <b-th>Area</b-th>
            <b-th>Metric</b-th>
            <b-th>Value</b-th>
            <b-th>Baseline</b-th>
            <b-th>Deviation</b-th>
            <b-th>Severity</b-th>
          </b-tr>
        </b-thead>
        <b-tbody>
          <b-tr v-for="(h, ix) in hotspots" :key="ix">
            <b-td>{{ h.area_name || h.area_id }}</b-td>
            <b-td>
              <code>{{ h.metric }}</code>
            </b-td>
            <b-td>{{ h.value }}</b-td>
            <b-td>{{ h.baseline }}</b-td>
            <b-td>{{ h.deviation }}</b-td>
            <b-td>
              <b-badge :variant="h.severity === 'alert' ? 'danger' : 'warning'">
                {{ h.severity }}
              </b-badge>
            </b-td>
          </b-tr>
          <b-tr v-if="!hotspots.length">
            <b-td colspan="6" class="text-muted">
              No hotspots flagged — nothing unusual to act on.
            </b-td>
          </b-tr>
        </b-tbody>
      </b-table-simple>

      <h6 class="mt-4">Reply friction — held external replies</h6>
      <p class="text-muted small mb-1">
        Email / TrashNothing replies held because the post hadn't yet rippled to
        the replier's location.
        <span class="fw-bold">Action:</span> a growing held count means replies
        are waiting - consider widening reach or shortening the hold.
      </p>
      <b-table-simple hover responsive small>
        <b-thead>
          <b-tr>
            <b-th>Status</b-th>
            <b-th>Count</b-th>
            <b-th>Avg hold (h)</b-th>
          </b-tr>
        </b-thead>
        <b-tbody>
          <b-tr v-for="(h, ix) in heldReplySummary" :key="ix">
            <b-td>
              <code>{{ h.status }}</code>
            </b-td>
            <b-td>{{ h.count }}</b-td>
            <b-td>{{
              h.median_hold_hours > 0 ? h.median_hold_hours.toFixed(1) : '—'
            }}</b-td>
          </b-tr>
          <b-tr v-if="!heldReplySummary.length">
            <b-td colspan="3" class="text-muted"
              >No held replies recorded yet.</b-td
            >
          </b-tr>
        </b-tbody>
      </b-table-simple>
    </div>
  </div>
</template>

<script setup>
import { GChart } from 'vue-google-charts'
import ModEmailDateFilter from '~/modtools/components/ModEmailDateFilter.vue'
import api from '~/api'

const runtimeConfig = useRuntimeConfig()
const apiInstance = api(runtimeConfig)

const loading = ref(true)
const error = ref(null)
// Date range driving the headline KPI queries (set by ModEmailDateFilter).
const startDate = ref('')
const endDate = ref('')
// Action surfaces kept on the page: where to look, and reply friction.
const hotspots = ref([])
const heldReplySummary = ref([])
// Headline reply KPIs (per-day series for the line charts)
const replyRate = ref([])
const replySource = ref([])
const replyDistance = ref([])
const takenRate = ref([])
// Per-group KPI filter (results differ a lot by place; 0 = all groups).
const groupOptions = ref([])
const groupFilter = ref(0)

const replyRateChart = computed(() => {
  if (!replyRate.value.length) return null
  const rows = [...replyRate.value]
    .reverse()
    .map((r) => [new Date(r.day), r.reply_pct])
  return [['Date', '% with reply in 36h'], ...rows]
})
const replySourceChart = computed(() => {
  if (!replySource.value.length) return null
  const rows = [...replySource.value]
    .reverse()
    .map((r) => [new Date(r.day), r.ripple_pct])
  return [['Date', '% of replies via rippling'], ...rows]
})
const replyDistanceChart = computed(() => {
  if (!replyDistance.value.length) return null
  const rows = [...replyDistance.value]
    .reverse()
    .map((r) => [new Date(r.day), r.median_km])
  return [['Date', 'Median km'], ...rows]
})
const takenRateChart = computed(() => {
  if (!takenRate.value.length) return null
  const rows = [...takenRate.value]
    .reverse()
    .map((r) => [new Date(r.day), r.taken_pct])
  return [['Date', '% taken/received'], ...rows]
})

function pctChartOptions(vTitle, color) {
  return {
    curveType: 'function',
    legend: { position: 'none' },
    chartArea: { width: '85%', height: '70%' },
    vAxis: { title: vTitle, viewWindow: { min: 0 }, format: '#.#' },
    hAxis: { title: 'Date', format: 'dd MMM' },
    series: { 0: { color } },
    animation: { startup: true, duration: 400, easing: 'out' },
  }
}
function kmChartOptions() {
  return {
    curveType: 'function',
    legend: { position: 'none' },
    chartArea: { width: '85%', height: '70%' },
    vAxis: { title: 'Median km', viewWindow: { min: 0 }, format: '#.#' },
    hAxis: { title: 'Date', format: 'dd MMM' },
    series: { 0: { color: '#fd7e14' } },
    animation: { startup: true, duration: 400, easing: 'out' },
  }
}

async function fetchMetrics() {
  loading.value = true
  error.value = null
  try {
    const result = await apiInstance.rippling.fetchMetrics(
      groupFilter.value,
      startDate.value,
      endDate.value
    )
    hotspots.value = result?.hotspots || []
    heldReplySummary.value = result?.held_reply_summary || []
    replyRate.value = result?.reply_rate_36h || []
    replySource.value = result?.reply_source_split || []
    replyDistance.value = result?.reply_distance_median || []
    takenRate.value = result?.taken_rate || []
    groupOptions.value = result?.groups || []
  } catch (e) {
    error.value = e.message || 'Unknown error'
  } finally {
    loading.value = false
  }
}

function onGroupChange() {
  fetchMetrics()
}

// ModEmailDateFilter fires this on mount and whenever the period changes, so it
// drives the initial load too (no separate onMounted fetch needed).
function onFilterFetch({ start, end }) {
  startDate.value = start || ''
  endDate.value = end || ''
  fetchMetrics()
}

defineExpose({ fetchMetrics, groupFilter, onGroupChange, onFilterFetch })
</script>
