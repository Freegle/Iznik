<template>
  <div class="rip-analytics mb-4">
    <p class="text-muted small mb-3">
      Over <em>rippled-out</em> offers in the window. Reply rate is measured
      <strong>within 36 hours</strong> (the settling window); the smaller figure
      is the eventual total. "Reached" counts
      <strong>active freeglers</strong> — members who used Freegle in the last
      ~6 months — inside each post's reach. Travel is real
      <strong>drive-time</strong>, sampled live so it carries a small margin.
      "Taken" is an underestimate — much reuse is never marked.
    </p>

    <div class="filter-row mb-3">
      <ModEmailDateFilter
        :loading="loading"
        fetch-label="Fetch"
        default-preset="30days"
        @fetch="onFilterFetch"
      />
      <div class="seg" role="group" aria-label="Density">
        <button
          v-for="opt in strata"
          :key="opt.v"
          type="button"
          class="seg-btn"
          :class="{ active: stratum === opt.v }"
          :disabled="loading"
          @click="setStratum(opt.v)"
        >
          {{ opt.l }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="text-center py-5">
      <b-spinner />
      <p class="text-muted small mt-2 mb-0">
        Computing live — sampling drive-times from the routing graph…
      </p>
    </div>
    <div v-else-if="error" class="text-danger">Failed to load: {{ error }}</div>
    <div v-else-if="s1">
      <p class="text-muted small mb-3">
        <strong>{{ s1.posts.toLocaleString() }}</strong> rippled-out offers in
        this {{ stratumLabel }} window.
      </p>

      <!-- Section 1 - KPI panels -->
      <div class="kpi-grid">
        <div class="panel">
          <div class="panel-title">Getting a reply?</div>
          <div class="panel-body">
            <GChart
              type="PieChart"
              :data="repliedPie"
              :options="pieOptions"
              style="width: 100%; height: 120px"
            />
            <div class="figure">{{ pct(s1.replied_36h_pct) }}</div>
            <div class="sub">reply within 36h</div>
            <div class="sub muted">
              {{ pct(s1.replied_ever_pct) }} eventually
            </div>
          </div>
        </div>

        <div class="panel">
          <div class="panel-title">Getting taken?</div>
          <div class="panel-body">
            <GChart
              type="PieChart"
              :data="takenPie"
              :options="pieOptions"
              style="width: 100%; height: 120px"
            />
            <div class="figure">{{ pct(s1.taken_pct) }}</div>
            <div class="sub">marked taken (underestimate)</div>
          </div>
        </div>

        <div class="panel">
          <div class="panel-title">Response depth</div>
          <div class="panel-body justify-content-center">
            <div class="figure">{{ s1.mean_replies.toFixed(2) }}</div>
            <div class="sub">mean replies per offer</div>
            <div class="divider"></div>
            <div class="figure">
              {{ Math.round(s1.mean_freeglers_reached).toLocaleString() }}
            </div>
            <div class="sub">active freeglers reached (mean)</div>
          </div>
        </div>

        <div class="panel">
          <div class="panel-title">Reply travel &amp; friction</div>
          <div class="panel-body justify-content-center">
            <template v-if="s1.reply_drive_min.available">
              <div class="figure">
                {{ s1.reply_drive_min.mean_min.toFixed(1) }}<small> min</small>
              </div>
              <div class="sub">mean reply drive-time</div>
              <div class="sub muted">
                ±{{ s1.reply_drive_min.ci_half_min.toFixed(1) }} · n =
                {{ s1.reply_drive_min.n_replies.toLocaleString() }}
              </div>
            </template>
            <div v-else class="sub">No drive-time sample.</div>
            <div class="divider"></div>
            <div
              class="figure"
              :class="{ 'text-warning': s1.held_replies_pct >= 15 }"
            >
              {{ pct(s1.held_replies_pct) }}
            </div>
            <div class="sub">of replies held (waiting for reach)</div>
            <div v-if="heldSourceBreakdown" class="sub muted">
              held now by source: {{ heldSourceBreakdown }}
            </div>
          </div>
        </div>
      </div>

      <!-- Section 2 - trends -->
      <h5 class="section-h">Trends</h5>
      <p class="text-muted small mb-2">
        Each headline number over time, by post arrival day.
      </p>
      <div class="kpi-grid trends">
        <div v-for="t in trendCharts" :key="t.title" class="panel">
          <div class="panel-title">{{ t.title }}</div>
          <div class="panel-body">
            <GChart
              v-if="t.data"
              type="AreaChart"
              :data="t.data"
              :options="areaOptions(t.color)"
              style="width: 100%; height: 170px"
            />
            <p v-else class="sub">No data.</p>
            <p v-if="t.note" class="sub muted mb-0">{{ t.note }}</p>
          </div>
        </div>
      </div>

      <!-- Section 3 - is rippling helping? -->
      <h5 class="section-h">Is rippling out helping?</h5>
      <p v-if="s3" class="text-muted small mb-2">
        Rippling shows offers to people beyond the origin group. The takes they
        produce are <strong>additive</strong> — the honest question is how many.
        Per reply they convert lower (further away, so less follow-through), but
        a rippled take is reuse the origin group alone wouldn't have delivered.
      </p>

      <div v-if="s3" class="helping-panel">
        <div class="figure-xl">
          {{ pct(s3.contribution_low_pct) }} –
          {{ pct(s3.contribution_high_pct) }}
        </div>
        <div class="sub mb-2">
          of the {{ s3.takers.toLocaleString() }} completed takes on these
          offers are down to rippling.
        </div>
        <div class="text-muted small">
          <strong>Floor</strong> — {{ s3.rescued_takes.toLocaleString() }} takes
          rescued from silence (posts with <em>no</em> local reply, which
          without rippling would have gone nowhere). <strong>Ceiling</strong> —
          {{ s3.rippled_takers.toLocaleString() }} takes by people reached only
          via rippling. The truth sits between.
        </div>
      </div>

      <div v-if="s3" class="kpi-grid mt-3">
        <div class="panel">
          <div class="panel-title">Replies via rippling</div>
          <div class="panel-body">
            <GChart
              type="PieChart"
              :data="rippledRepliesPie"
              :options="pieOptions"
              style="width: 100%; height: 110px"
            />
            <div class="figure">{{ pct(s3.rippled_replies_pct) }}</div>
            <div class="sub">of {{ s3.replies.toLocaleString() }} replies</div>
          </div>
        </div>
        <div class="panel">
          <div class="panel-title">Takers via rippling</div>
          <div class="panel-body">
            <GChart
              type="PieChart"
              :data="rippledTakersPie"
              :options="pieOptions"
              style="width: 100%; height: 110px"
            />
            <div class="figure">{{ pct(s3.rippled_takers_pct) }}</div>
            <div class="sub">of {{ s3.takers.toLocaleString() }} takers</div>
          </div>
        </div>
        <div class="panel">
          <div class="panel-title">Reply → take</div>
          <div class="panel-body justify-content-center">
            <div>
              <span class="figure">{{ pct(s3.home_conv_pct) }}</span>
              <span class="sub d-inline"> home</span>
            </div>
            <div>
              <span class="figure text-success">{{
                pct(s3.rippled_conv_pct)
              }}</span>
              <span class="sub d-inline"> rippled</span>
            </div>
            <div class="sub muted mt-1">
              Lower per reply (further away) — but additive.
            </div>
          </div>
        </div>
        <div class="panel">
          <div class="panel-title">Rippled-out travel</div>
          <div class="panel-body justify-content-center">
            <template v-if="s3.ripple_drive_min.available">
              <div class="figure">
                {{ s3.ripple_drive_min.mean_min.toFixed(1) }}<small> min</small>
              </div>
              <div class="sub">mean drive-time of a rippled-out reply</div>
              <div class="sub muted">
                ±{{ s3.ripple_drive_min.ci_half_min.toFixed(1) }} · n =
                {{ s3.ripple_drive_min.n_replies.toLocaleString() }}
              </div>
            </template>
            <div v-else class="sub">No sample.</div>
          </div>
        </div>
      </div>
      <p v-if="s3" class="text-muted small mt-2 mb-0">
        <span v-if="s3.client_instrumented_pct > 0">
          Client-instrumented cross-check:
          {{ s3.client_instrumented_pct.toFixed(1) }}% of replies.
        </span>
        <span v-else class="fst-italic">
          "Rippled-out" is derived server-side; the client-instrumented figure
          fills in once reply-provenance ships to production.
        </span>
      </p>

      <!-- Where do replies come from - attribution channels over time -->
      <h5 class="section-h">Where do replies come from?</h5>
      <p class="text-muted small mb-2">
        Each reply attributed at reply time to the channel that led the replier
        to the post. Greens are rippling; teal is your own established members;
        greys couldn't be credited either way.
      </p>
      <div class="panel">
        <div class="panel-body">
          <GChart
            v-if="replySourceChart"
            type="AreaChart"
            :data="replySourceChart"
            :options="channelStackOptions()"
            style="width: 100%; height: 320px"
          />
          <p v-else class="sub">
            No attribution data yet — accrues from reply-time capture.
          </p>
        </div>
      </div>

      <!-- Geographic hotspots -->
      <h5 class="section-h">Where to look — geographic hotspots</h5>
      <p class="text-muted small mb-2">
        Areas behaving unusually vs the rest of the network. Investigate any row
        flagged <b-badge variant="danger">alert</b-badge>.
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
            <b-td
              ><code>{{ h.metric }}</code></b-td
            >
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
    </div>
    <p v-else class="text-muted small">No data yet.</p>
  </div>
</template>

<script setup>
import { GChart } from 'vue-google-charts'
import ModEmailDateFilter from '~/modtools/components/ModEmailDateFilter.vue'
import api from '~/api'

const runtimeConfig = useRuntimeConfig()
const apiInstance = api(runtimeConfig)

const POS = '#28a745'
const NEG = '#e4e8e3'

const strata = [
  { v: 'all', l: 'All' },
  { v: 'rural', l: 'Rural' },
  { v: 'suburban', l: 'Suburban' },
  { v: 'dense', l: 'Dense' },
]

const loading = ref(true)
const error = ref(null)
const startDate = ref('')
const endDate = ref('')
const stratum = ref('all')
const s1 = ref(null)
const s2 = ref({ kpis: [], drive_time: [] })
const s3 = ref(null)
// Folded in from the retired dashboard: reply-source attribution + geographic hotspots
// (fetched in parallel from the metrics endpoint, which already computes them).
const replySource = ref([])
const hotspots = ref([])
const attributionCaptureFrom = ref('')
const heldBySource = ref([])

const stratumLabel = computed(() =>
  stratum.value === 'all' ? 'all-density' : stratum.value
)

// Currently-held replies broken down by origin channel (email / tn / web), for the friction
// panel - shows how many replies the new web reply-hold is capturing vs the email/TN path.
const heldSourceBreakdown = computed(() => {
  const by = {}
  for (const r of heldBySource.value) {
    if (r.status === 'held')
      by[r.source] = (by[r.source] || 0) + Number(r.count || 0)
  }
  return ['email', 'tn', 'web']
    .filter((s) => by[s])
    .map((s) => `${s} ${by[s].toLocaleString()}`)
    .join(' · ')
})

const CHANNELS = [
  { key: 'home', label: 'Home members' },
  { key: 'organic_local', label: 'Local non-members' },
  { key: 'unknown', label: 'Unknown' },
  { key: 'ripple_group', label: 'Rippled into their group' },
  { key: 'ripple_notified', label: 'Ripple mail' },
  { key: 'ripple_reach', label: 'Reach-fed browse' },
]
const replySourceChart = computed(() => {
  if (!startDate.value || !endDate.value || !replySource.value.length)
    return null
  const byDay = new Map(replySource.value.map((r) => [r.day, r]))
  const start = new Date(startDate.value)
  const end = new Date(endDate.value)
  if (isNaN(start) || isNaN(end) || end < start) return null
  const rows = []
  const days = Math.round((end - start) / 86400000)
  for (let i = 0; i <= days; i++) {
    const d = new Date(start)
    d.setDate(d.getDate() + i)
    const key = d.toISOString().slice(0, 10)
    const r = byDay.get(key)
    const marker =
      attributionCaptureFrom.value && key === attributionCaptureFrom.value
        ? 'Live capture from here'
        : null
    rows.push([d, marker, ...CHANNELS.map((c) => (r ? r[c.key] || 0 : 0))])
  }
  return [
    ['Date', { role: 'annotation' }, ...CHANNELS.map((c) => c.label)],
    ...rows,
  ]
})
function channelStackOptions() {
  return {
    isStacked: 'percent',
    legend: { position: 'bottom' },
    chartArea: { width: '85%', height: '62%' },
    vAxis: { format: 'percent', textStyle: { fontSize: 10 } },
    hAxis: { format: 'dd MMM', textStyle: { fontSize: 10 } },
    annotations: { style: 'line' },
    areaOpacity: 0.85,
    series: {
      0: { color: '#1592a6' },
      1: { color: '#6c757d' },
      2: { color: '#98a2ab' },
      3: { color: '#28a745' },
      4: { color: '#146c43' },
      5: { color: '#45b463' },
    },
  }
}

function pct(v) {
  return (v || 0).toFixed(1) + '%'
}
const pieOptions = {
  legend: { position: 'none' },
  pieHole: 0.6,
  chartArea: { width: '92%', height: '88%' },
  colors: [POS, NEG],
  pieSliceText: 'none',
  backgroundColor: 'transparent',
}

const repliedPie = computed(() =>
  s1.value
    ? [
        ['Outcome', 'Offers'],
        ['Replied 36h', s1.value.replied_36h],
        ['Not (yet)', s1.value.posts - s1.value.replied_36h],
      ]
    : null
)
const takenPie = computed(() =>
  s1.value
    ? [
        ['Outcome', 'Offers'],
        ['Taken', s1.value.taken],
        ['Not marked', s1.value.posts - s1.value.taken],
      ]
    : null
)
const rippledRepliesPie = computed(() =>
  s3.value
    ? [
        ['Source', 'Replies'],
        ['Via rippling', s3.value.rippled_replies],
        ['Own members', s3.value.replies - s3.value.rippled_replies],
      ]
    : null
)
const rippledTakersPie = computed(() =>
  s3.value
    ? [
        ['Source', 'Takers'],
        ['Via rippling', s3.value.rippled_takers],
        ['Own members', s3.value.takers - s3.value.rippled_takers],
      ]
    : null
)

function series(rows, key) {
  if (!rows || !rows.length) return null
  return [['Date', 'v'], ...rows.map((r) => [new Date(r.day), r[key] || 0])]
}
const trendCharts = computed(() => {
  const k = s2.value.kpis
  const dt = s2.value.drive_time
  return [
    { title: 'Reply rate (%)', data: series(k, 'replied_pct'), color: POS },
    { title: 'Taken rate (%)', data: series(k, 'taken_pct'), color: '#146c43' },
    {
      title: 'Mean replies / offer',
      data: series(k, 'mean_replies'),
      color: '#17a2b8',
    },
    {
      title: 'Active freeglers reached',
      data: series(k, 'mean_freeglers'),
      color: '#6c757d',
    },
    {
      title: 'Mean reply travel (min)',
      data:
        dt && dt.length
          ? [['Date', 'v'], ...dt.map((r) => [new Date(r.day), r.mean_min])]
          : null,
      color: '#fd7e14',
      note: 'Sample-based — read the shape, not the wiggles.',
    },
  ]
})
function areaOptions(color) {
  return {
    curveType: 'function',
    legend: { position: 'none' },
    chartArea: { width: '84%', height: '74%', backgroundColor: 'transparent' },
    vAxis: {
      viewWindow: { min: 0 },
      format: '#.#',
      gridlines: { color: '#eef0ec', count: 4 },
      minorGridlines: { count: 0 },
      textStyle: { fontSize: 10, color: '#8a938c' },
      baselineColor: '#e4e8e3',
    },
    hAxis: {
      format: 'dd MMM',
      gridlines: { color: 'transparent' },
      textStyle: { fontSize: 10, color: '#8a938c' },
      baselineColor: '#e4e8e3',
    },
    colors: [color],
    areaOpacity: 0.12,
    lineWidth: 2.5,
    backgroundColor: 'transparent',
  }
}

async function fetchAnalytics() {
  loading.value = true
  error.value = null
  try {
    const [result, metrics] = await Promise.all([
      apiInstance.rippling.fetchAnalytics(
        stratum.value,
        startDate.value,
        endDate.value
      ),
      apiInstance.rippling.fetchMetrics(0, startDate.value, endDate.value),
    ])
    s1.value = result?.section1 || null
    s2.value = result?.section2 || { kpis: [], drive_time: [] }
    s3.value = result?.section3 || null
    replySource.value = metrics?.reply_source_split || []
    hotspots.value = metrics?.hotspots || []
    attributionCaptureFrom.value = metrics?.attribution_capture_from || ''
    heldBySource.value = metrics?.held_reply_by_source || []
  } catch (e) {
    error.value = e.message || 'Unknown error'
  } finally {
    loading.value = false
  }
}
function setStratum(v) {
  if (loading.value || stratum.value === v) return
  stratum.value = v
  fetchAnalytics()
}
function onFilterFetch({ start, end }) {
  startDate.value = start || ''
  endDate.value = end || ''
  fetchAnalytics()
}

defineExpose({ fetchAnalytics, stratum, setStratum, onFilterFetch })
</script>

<style scoped lang="scss">
$green: #28a745;
$ink: #1a1c1a;
$muted: #6b756c;
$line: #e4e8e3;

.rip-analytics {
  --card-bg: #ffffff;
}

/* Toolbar: keep the (card-based) date filter and the density control on one line,
   vertically centred. The date filter is a bordered b-card with its own mb-3 - neutralise
   that here so it doesn't throw the centring off. */
.filter-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
}
.filter-row :deep(.filter-card) {
  margin-bottom: 0 !important;
}

/* Segmented density control - clear active highlight, height matched to the filter controls */
.seg {
  display: inline-flex;
  align-items: stretch;
  height: 38px;
  border: 1px solid $line;
  border-radius: 10px;
  overflow: hidden;
  background: #f6f7f4;
}
.seg-btn {
  display: inline-flex;
  align-items: center;
  border: 0;
  background: transparent;
  padding: 0 18px;
  font-size: 0.85rem;
  font-weight: 600;
  color: $muted;
  cursor: pointer;
  border-right: 1px solid $line;
  transition: background 0.12s, color 0.12s;
}
.seg-btn:last-child {
  border-right: 0;
}
.seg-btn:hover:not(:disabled):not(.active) {
  background: #edf3ee;
  color: $ink;
}
.seg-btn.active {
  background: $green;
  color: #fff;
}
.seg-btn:disabled {
  opacity: 0.55;
  cursor: default;
}

.section-h {
  margin-top: 2rem;
  padding-bottom: 0.4rem;
  border-bottom: 2px solid $line;
  font-weight: 700;
}

.kpi-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 14px;
}
.kpi-grid.trends {
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
}
@media (max-width: 900px) {
  .kpi-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
@media (max-width: 520px) {
  .kpi-grid {
    grid-template-columns: 1fr;
  }
}

.panel {
  background: var(--card-bg);
  border: 1px solid $line;
  border-radius: 14px;
  box-shadow: 0 1px 2px rgba(20, 30, 24, 0.04),
    0 6px 16px rgba(20, 30, 24, 0.05);
  overflow: hidden;
  display: flex;
  flex-direction: column;
}
.panel-title {
  background: #f4f7f4;
  border-bottom: 1px solid $line;
  padding: 8px 12px;
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: #46514a;
  text-align: center;
}
.panel-body {
  flex: 1;
  padding: 14px 14px 16px;
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
}
.figure {
  font-size: 1.85rem;
  font-weight: 760;
  line-height: 1.05;
  color: $ink;
  font-variant-numeric: tabular-nums;
  margin-top: 4px;
}
.figure small {
  font-size: 0.9rem;
  font-weight: 600;
  color: $muted;
}
.figure-xl {
  font-size: 2.4rem;
  font-weight: 780;
  line-height: 1;
  color: darken($green, 6%);
  font-variant-numeric: tabular-nums;
}
.sub {
  font-size: 0.8rem;
  color: $muted;
  line-height: 1.35;
}
.sub.muted {
  color: #99a29a;
  font-size: 0.75rem;
}
.sub.d-inline {
  display: inline;
}
.divider {
  width: 46px;
  height: 1px;
  background: $line;
  margin: 12px 0;
}

.helping-panel {
  border: 1px solid $green;
  background: linear-gradient(
    180deg,
    rgba(40, 167, 69, 0.07),
    rgba(40, 167, 69, 0.02)
  );
  border-radius: 14px;
  padding: 18px 20px;
  text-align: center;
}
</style>
