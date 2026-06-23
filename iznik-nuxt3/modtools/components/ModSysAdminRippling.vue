<template>
  <div>
    <p class="text-muted">
      Live counters for the rippling-out rollout: reply-blocked-by-reach, held /
      released / taken-gone external replies, secondary-group rejections, and
      immediate mails on expansion.
    </p>

    <div v-if="loading" class="text-center py-3">
      <b-spinner />
    </div>
    <div v-else-if="error" class="text-danger">Failed to load: {{ error }}</div>
    <div v-else>
      <h6>Reply outcomes (headline KPIs)</h6>
      <p class="text-muted small mb-2">
        The rollout's goal is to turn more 0-reply posts into 1-reply posts. (1)
        share of Offers that get a reply within 36h; (2) of replies, the share
        that came via rippling rather than from existing group members (captured
        at reply time, so a join-to-reply isn't mis-counted as a member); (3)
        median distance from post to replier.
      </p>

      <div class="mb-2">
        <strong class="small">% of Offers with a reply within 36h</strong>
        <GChart
          v-if="replyRateChart"
          type="LineChart"
          :data="replyRateChart"
          :options="pctChartOptions('Reply rate (%)', '#28a745')"
          style="width: 100%; height: 300px"
        />
        <p v-else class="text-muted small">No data yet.</p>
      </div>

      <div class="mb-2">
        <strong class="small">% of replies that came via rippling</strong>
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
        <strong class="small">Median reply distance (km)</strong>
        <GChart
          v-if="replyDistanceChart"
          type="LineChart"
          :data="replyDistanceChart"
          :options="kmChartOptions()"
          style="width: 100%; height: 300px"
        />
        <p v-else class="text-muted small">No data yet.</p>
      </div>

      <h6 class="mt-3">Totals (all time)</h6>
      <b-table-simple hover responsive small>
        <b-thead>
          <b-tr>
            <b-th>Event</b-th>
            <b-th>Count</b-th>
          </b-tr>
        </b-thead>
        <b-tbody>
          <b-tr v-for="t in totals" :key="t.event">
            <b-td>
              <code>{{ t.event }}</code>
            </b-td>
            <b-td>{{ t.count }}</b-td>
          </b-tr>
          <b-tr v-if="!totals.length">
            <b-td colspan="2" class="text-muted">
              No rippling events recorded yet.
            </b-td>
          </b-tr>
        </b-tbody>
      </b-table-simple>

      <h6 class="mt-3">Last 30 days</h6>
      <b-table-simple hover responsive small>
        <b-thead>
          <b-tr>
            <b-th>Day</b-th>
            <b-th>Event</b-th>
            <b-th>Count</b-th>
          </b-tr>
        </b-thead>
        <b-tbody>
          <b-tr v-for="(r, ix) in recent" :key="ix">
            <b-td class="text-nowrap">{{ r.day }}</b-td>
            <b-td>
              <code>{{ r.event }}</code>
            </b-td>
            <b-td>{{ r.count }}</b-td>
          </b-tr>
          <b-tr v-if="!recent.length">
            <b-td colspan="3" class="text-muted">No recent events.</b-td>
          </b-tr>
        </b-tbody>
      </b-table-simple>

      <h6 class="mt-3">Geographic hotspots (last 30 days)</h6>
      <p class="text-muted small mb-1">
        Areas whose metric is a robust outlier vs the rest of the network, so a
        local problem the overall average hides is surfaced here.
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
            <b-td colspan="6" class="text-muted">No hotspots flagged.</b-td>
          </b-tr>
        </b-tbody>
      </b-table-simple>

      <h6 class="mt-3">Proposed parameter changes (advisory)</h6>
      <b-table-simple hover responsive small>
        <b-thead>
          <b-tr>
            <b-th>ONS category</b-th>
            <b-th>max_minutes</b-th>
            <b-th>Rationale</b-th>
            <b-th>Proposed</b-th>
          </b-tr>
        </b-thead>
        <b-tbody>
          <b-tr v-for="(p, ix) in proposedParams" :key="ix">
            <b-td>
              <code>{{ p.ons_category }}</code>
            </b-td>
            <b-td>{{ p.max_minutes }}</b-td>
            <b-td>{{ p.rationale }}</b-td>
            <b-td class="text-nowrap">{{ p.proposed_at }}</b-td>
          </b-tr>
          <b-tr v-if="!proposedParams.length">
            <b-td colspan="4" class="text-muted">No proposals.</b-td>
          </b-tr>
        </b-tbody>
      </b-table-simple>

      <!-- §16.1/§16.2 Volume + reach: overall weekly rollup from ripple:tune -->
      <h6 class="mt-3">Volume &amp; reach — weekly rollup (last 2 weeks)</h6>
      <p class="text-muted small mb-1">
        Written by <code>ripple:tune</code> each week. Guard-rail band:
        &minus;10% to +50% vs each group's own baseline (ripple-thresholds).
        Empty until the first tune run.
      </p>
      <b-table-simple hover responsive small>
        <b-thead>
          <b-tr>
            <b-th>Period start</b-th>
            <b-th>Metric</b-th>
            <b-th>Value</b-th>
            <b-th>Sample size</b-th>
          </b-tr>
        </b-thead>
        <b-tbody>
          <b-tr v-for="(m, ix) in liveMetrics" :key="ix">
            <b-td class="text-nowrap">{{ m.period_start }}</b-td>
            <b-td>
              <code>{{ m.metric }}</code>
            </b-td>
            <b-td>{{ m.value }}</b-td>
            <b-td>{{ m.sample_size }}</b-td>
          </b-tr>
          <b-tr v-if="!liveMetrics.length">
            <b-td colspan="4" class="text-muted">No rollup data yet.</b-td>
          </b-tr>
        </b-tbody>
      </b-table-simple>

      <!-- §16.3 Cross-group reach -->
      <h6 class="mt-3">Cross-group reach (last 30 days)</h6>
      <p class="text-muted small mb-1">
        Fraction of post appearances that were rippled in by the engine to a
        group the poster was not a member of, and how many of those were
        subsequently approved.
      </p>
      <b-table-simple small>
        <b-tbody>
          <b-tr>
            <b-th>Post appearances (total)</b-th>
            <b-td>{{ crossGroupSummary.total }}</b-td>
          </b-tr>
          <b-tr>
            <b-th>Rippled-in appearances</b-th>
            <b-td>{{ crossGroupSummary.rippled_in }}</b-td>
          </b-tr>
          <b-tr>
            <b-th>Cross-group %</b-th>
            <b-td>{{
              crossGroupSummary.cross_group_pct != null
                ? crossGroupSummary.cross_group_pct.toFixed(1) + '%'
                : '—'
            }}</b-td>
          </b-tr>
          <b-tr>
            <b-th>Approval rate (rippled-in)</b-th>
            <b-td>{{
              crossGroupSummary.rippled_in > 0
                ? crossGroupSummary.approval_rate.toFixed(1) + '%'
                : '—'
            }}</b-td>
          </b-tr>
        </b-tbody>
      </b-table-simple>

      <!-- §16.4 Timing / capture from offline simulator -->
      <h6 class="mt-3">Timing &amp; capture — latest simulator week</h6>
      <p class="text-muted small mb-1">
        From <code>rippling_algorithm_metrics</code> ('all' group). Capture rate
        = repliers notified before they replied / total repliers. Empty until
        the first simulator run.
      </p>
      <div v-if="captureSummary.week_start">
        <b-table-simple small>
          <b-tbody>
            <b-tr>
              <b-th>Week</b-th>
              <b-td class="text-nowrap">{{ captureSummary.week_start }}</b-td>
            </b-tr>
            <b-tr>
              <b-th>Curve</b-th>
              <b-td>
                <code>{{ captureSummary.curve }}</code>
              </b-td>
            </b-tr>
            <b-tr>
              <b-th>Pairs total</b-th>
              <b-td>{{ captureSummary.pairs_total }}</b-td>
            </b-tr>
            <b-tr>
              <b-th>In-time</b-th>
              <b-td>{{ captureSummary.pairs_in_time }}</b-td>
            </b-tr>
            <b-tr>
              <b-th>Late</b-th>
              <b-td>{{ captureSummary.pairs_late }}</b-td>
            </b-tr>
            <b-tr>
              <b-th>Capture rate</b-th>
              <b-td>{{ captureSummary.capture_rate.toFixed(1) }}%</b-td>
            </b-tr>
            <b-tr>
              <b-th>Reply p50</b-th>
              <b-td>{{ captureSummary.reply_p50_hours.toFixed(1) }}h</b-td>
            </b-tr>
            <b-tr>
              <b-th>Reply p75</b-th>
              <b-td>{{ captureSummary.reply_p75_hours.toFixed(1) }}h</b-td>
            </b-tr>
          </b-tbody>
        </b-table-simple>
      </div>
      <p v-else class="text-muted small">No simulator data yet.</p>

      <!-- §15 / §16.5 Held-reply friction -->
      <h6 class="mt-3">Held external replies — status breakdown</h6>
      <p class="text-muted small mb-1">
        External (email / TrashNothing) replies held because the post had not
        yet rippled to the replier's location. Avg hold duration shown for
        released rows.
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
import api from '~/api'

const runtimeConfig = useRuntimeConfig()
const apiInstance = api(runtimeConfig)

const loading = ref(true)
const error = ref(null)
const totals = ref([])
const recent = ref([])
const hotspots = ref([])
const proposedParams = ref([])
// §16 rollout-health metrics
const liveMetrics = ref([])
const heldReplySummary = ref([])
const crossGroupSummary = ref({
  period_days: 30,
  rippled_in: 0,
  total: 0,
  cross_group_pct: 0,
  approval_rate: 0,
})
const captureSummary = ref({
  week_start: '',
  curve: '',
  pairs_total: 0,
  pairs_in_time: 0,
  pairs_late: 0,
  capture_rate: 0,
  reply_p50_hours: 0,
  reply_p75_hours: 0,
})
// Headline reply KPIs (per-day series for the line charts)
const replyRate = ref([])
const replySource = ref([])
const replyDistance = ref([])

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
    const result = await apiInstance.rippling.fetchMetrics()
    totals.value = result?.totals || []
    recent.value = result?.recent || []
    hotspots.value = result?.hotspots || []
    proposedParams.value = result?.proposed_params || []
    liveMetrics.value = result?.live_metrics || []
    heldReplySummary.value = result?.held_reply_summary || []
    if (result?.cross_group_summary) {
      crossGroupSummary.value = result.cross_group_summary
    }
    if (result?.capture_summary) {
      captureSummary.value = result.capture_summary
    }
    replyRate.value = result?.reply_rate_36h || []
    replySource.value = result?.reply_source_split || []
    replyDistance.value = result?.reply_distance_median || []
  } catch (e) {
    error.value = e.message || 'Unknown error'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchMetrics()
})

defineExpose({ fetchMetrics })
</script>
