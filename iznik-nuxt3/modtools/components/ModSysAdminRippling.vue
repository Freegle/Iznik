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
          :options="cohortPctOptions('Reply rate (%)')"
          style="width: 100%; height: 300px"
        />
        <p v-if="replyRateChart" class="text-muted small fst-italic mt-1 mb-0">
          The dashed tail is still settling - the most recent posts haven't had
          a full 36h to get a reply yet.
        </p>
        <p v-if="!replyRateChart" class="text-muted small">No data yet.</p>
      </div>

      <div class="mb-3">
        <strong class="small">Are posts getting more replies?</strong>
        <p class="text-muted small mb-1">
          Mean number of replies a post gets within 36h.
          <span class="text-success fw-bold">Good:</span> rippled-out posts
          average more replies each than home-only - reach is deepening
          interest, not just turning 0-reply posts into 1.
        </p>
        <GChart
          v-if="repliesPerPostChart"
          type="LineChart"
          :data="repliesPerPostChart"
          :options="cohortPctOptions('Mean replies / post')"
          style="width: 100%; height: 300px"
        />
        <p
          v-if="repliesPerPostChart"
          class="text-muted small fst-italic mt-1 mb-0"
        >
          The dashed tail is still settling - the most recent posts haven't had
          a full 36h to gather replies yet.
        </p>
        <p v-if="!repliesPerPostChart" class="text-muted small">No data yet.</p>
      </div>

      <div class="mb-3">
        <strong class="small">Where do replies come from?</strong>
        <p class="text-muted small mb-1">
          Each reply is attributed at reply time to the channel that led the
          replier to the post. <span class="fw-bold">Greens are rippling</span>
          (we mailed them / it rippled into their group / reach-fed browse);
          teal is your own established members; greys couldn't be credited
          either way (local non-members, search, deep links).
          <span class="text-success fw-bold">Good:</span> a real green share -
          the lift is coming from reach, and the split says which ripple channel
          earns it.
        </p>
        <p v-if="!attributionChannelsAvailable" class="text-warning small mb-1">
          This database doesn't have the graded-attribution columns yet
          (migration pending), so only the durable channels are derived here:
          location-based channels (reach-fed browse, local non-members) show as
          unknown.
        </p>
        <GChart
          v-if="replySourceChart"
          type="AreaChart"
          :data="replySourceChart"
          :options="channelStackOptions()"
          style="width: 100%; height: 340px"
        />
        <p v-else class="text-muted small">
          No data yet — accrues from reply-time capture
          (<code>rippling_reply_attribution</code>).
        </p>

        <div v-if="clientSourceSummary.length" class="mt-2">
          <strong class="small">What the app says (cross-check)</strong>
          <p class="text-muted small mb-1">
            The surface the replier's own app reported sending the reply from.
            Client-supplied, so it never feeds the attribution above - but the
            two should broadly agree.
          </p>
          <b-table-simple hover responsive small class="w-auto">
            <b-thead>
              <b-tr>
                <b-th>Surface</b-th>
                <b-th>Replies</b-th>
              </b-tr>
            </b-thead>
            <b-tbody>
              <b-tr v-for="(s, ix) in clientSourceSummary" :key="ix">
                <b-td>
                  <code>{{ s.source }}</code>
                </b-td>
                <b-td>{{ s.count }}</b-td>
              </b-tr>
            </b-tbody>
          </b-table-simple>
        </div>
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
          :options="cohortKmOptions()"
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
          :options="cohortPctOptions('Taken/received (%)')"
          style="width: 100%; height: 300px"
        />
        <p v-if="takenRateChart" class="text-muted small fst-italic mt-1 mb-0">
          The dashed tail is still settling - recent posts haven't had time to
          be collected yet.
        </p>
        <p v-if="!takenRateChart" class="text-muted small">No data yet.</p>
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
const repliesPerPost = ref([])
const replySource = ref([])
const replyDistance = ref([])
const takenRate = ref([])
// Per-group KPI filter (results differ a lot by place; 0 = all groups).
const groupOptions = ref([])
const groupFilter = ref(0)
// Client-reported reply surfaces (advisory cross-check of the attribution chart) and
// whether this DB has the graded-attribution columns yet (location channels pending).
const clientSourceSummary = ref([])
const attributionChannelsAvailable = ref(true)

const COHORT_HEADER = (allLabel) => [
  'Date',
  allLabel,
  { role: 'certainty', type: 'boolean' },
  'Home-only',
  { role: 'certainty', type: 'boolean' },
  'Rippled-out',
  { role: 'certainty', type: 'boolean' },
]

const replyRateChart = computed(() => {
  if (!replyRate.value.length) return null
  const rows = [...replyRate.value].reverse().map((r) => {
    const certain = !r.provisional
    return [
      new Date(r.day),
      r.reply_pct,
      certain,
      r.home_pct,
      certain,
      r.ripple_pct,
      certain,
    ]
  })
  return [COHORT_HEADER('All offers'), ...rows]
})
const repliesPerPostChart = computed(() => {
  if (!repliesPerPost.value.length) return null
  const rows = [...repliesPerPost.value].reverse().map((r) => {
    const certain = !r.provisional
    return [
      new Date(r.day),
      r.mean_replies,
      certain,
      r.home_mean,
      certain,
      r.ripple_mean,
      certain,
    ]
  })
  return [COHORT_HEADER('All offers'), ...rows]
})
// Attribution channels in stack order (bottom -> top): non-ripple first, then the
// ripple family as one block of greens so its share reads at a glance.
const CHANNELS = [
  { key: 'home', label: 'Home members' },
  { key: 'organic_local', label: 'Local non-members' },
  { key: 'unknown', label: 'Unknown' },
  { key: 'ripple_group', label: 'Rippled into their group' },
  { key: 'ripple_notified', label: 'Ripple mail' },
  { key: 'ripple_reach', label: 'Reach-fed browse' },
]

const replySourceChart = computed(() => {
  if (!startDate.value || !endDate.value) return null
  if (!replySource.value.length) return null
  // Zero-fill the whole filter range so the composition spans the same axis as the
  // other charts; a day with no replies stacks to nothing (a gap), which is honest.
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
    rows.push([d, ...CHANNELS.map((c) => (r ? r[c.key] || 0 : 0))])
  }
  return [['Date', ...CHANNELS.map((c) => c.label)], ...rows]
})
const replyDistanceChart = computed(() => {
  if (!replyDistance.value.length) return null
  const rows = [...replyDistance.value]
    .reverse()
    .map((r) => [
      new Date(r.day),
      r.median_km,
      true,
      r.home_median_km,
      true,
      r.ripple_median_km,
      true,
    ])
  // Distance is a median over replies, not a count of offers, so label the "all" line accordingly.
  return [COHORT_HEADER('All replies'), ...rows]
})
const takenRateChart = computed(() => {
  if (!takenRate.value.length) return null
  const rows = [...takenRate.value].reverse().map((r) => {
    const certain = !r.provisional
    return [
      new Date(r.day),
      r.taken_pct,
      certain,
      r.home_pct,
      certain,
      r.ripple_pct,
      certain,
    ]
  })
  return [COHORT_HEADER('All offers'), ...rows]
})

// Shared x-axis so EVERY chart spans the same filter date range with the same
// date ticks. Without this each chart auto-scaled to its own data: a single-point
// chart collapsed to one repeated date ("23 Jun" all along) and dense charts
// dropped their labels entirely. viewWindow pins the range; explicit ticks force
// a consistent, readable set of date labels (~8 max) across all charts.
function dateTicks() {
  if (!startDate.value || !endDate.value) return undefined
  const start = new Date(startDate.value)
  const end = new Date(endDate.value)
  if (isNaN(start) || isNaN(end) || end < start) return undefined
  const days = Math.round((end - start) / 86400000)
  const step = Math.max(1, Math.ceil((days + 1) / 8))
  const ticks = []
  for (let i = 0; i <= days; i += step) {
    const d = new Date(start)
    d.setDate(d.getDate() + i)
    ticks.push(d)
  }
  return ticks
}
function dateHAxis() {
  const h = { title: 'Date', format: 'dd MMM' }
  if (startDate.value && endDate.value) {
    const min = new Date(startDate.value)
    const max = new Date(endDate.value)
    if (!isNaN(min) && !isNaN(max) && max >= min) {
      h.viewWindow = { min, max }
      const ticks = dateTicks()
      if (ticks && ticks.length) h.ticks = ticks
    }
  }
  return h
}

// Percent-stacked area of the attribution channels. Colors validated (dataviz six
// checks, light surface): the two greys are DELIBERATELY low-chroma - they are the
// "couldn't credit either way" buckets - and the ripple family is one green hue,
// lightness-stepped, so the total green band = the ripple share. Legend + tooltips
// carry exact identities/values (the greys/light green sit below the 3:1 fill
// contrast line, which is acceptable for large stacked fills with those two reliefs).
function channelStackOptions() {
  return {
    isStacked: 'percent',
    legend: { position: 'bottom' },
    chartArea: { width: '85%', height: '65%' },
    vAxis: { title: 'Share of replies', format: 'percent' },
    hAxis: dateHAxis(),
    areaOpacity: 0.85,
    series: {
      0: { color: '#1592a6' }, // Home members — teal (matches the cohort charts)
      1: { color: '#6c757d' }, // Local non-members — grey (deliberately neutral)
      2: { color: '#98a2ab' }, // Unknown — light grey (deliberately neutral)
      3: { color: '#28a745' }, // Rippled into their group — green
      4: { color: '#146c43' }, // Ripple mail — dark green
      5: { color: '#45b463' }, // Reach-fed browse — light green
    },
    animation: { startup: true, duration: 400, easing: 'out' },
  }
}

function cohortPctOptions(vTitle) {
  return {
    curveType: 'function',
    legend: { position: 'bottom' },
    chartArea: { width: '85%', height: '65%' },
    vAxis: { title: vTitle, viewWindow: { min: 0 }, format: '#.#' },
    hAxis: dateHAxis(),
    series: {
      0: { color: '#6c757d' }, // All — grey
      1: { color: '#17a2b8' }, // Home-only — teal
      2: { color: '#28a745' }, // Rippled-out — green
    },
    animation: { startup: true, duration: 400, easing: 'out' },
  }
}
function cohortKmOptions() {
  return {
    curveType: 'function',
    legend: { position: 'bottom' },
    chartArea: { width: '85%', height: '65%' },
    vAxis: { title: 'Median km', viewWindow: { min: 0 }, format: '#.#' },
    hAxis: dateHAxis(),
    series: {
      0: { color: '#6c757d' },
      1: { color: '#17a2b8' },
      2: { color: '#fd7e14' }, // Rippled-out distance — orange
    },
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
    repliesPerPost.value = result?.replies_per_post || []
    replySource.value = result?.reply_source_split || []
    clientSourceSummary.value = result?.client_source_summary || []
    attributionChannelsAvailable.value =
      result?.attribution_channels_available !== false
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

defineExpose({
  fetchMetrics,
  groupFilter,
  onGroupChange,
  onFilterFetch,
})
</script>
