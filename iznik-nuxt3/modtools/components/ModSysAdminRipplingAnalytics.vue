<template>
  <div class="mb-4">
    <p class="text-muted small mb-2">
      <strong>Where we are as a platform.</strong> Reply and reuse rates are
      over <em>rippled-out</em> offers in the window. "Reached" counts
      <strong>active freeglers</strong> — members who have used Freegle in the
      last ~6 months — inside each post's drive-time reach. Travel distance is
      real <strong>drive-time</strong> (not straight-line), computed live from a
      sample of posts, so it carries a small margin. "Taken" is an underestimate
      — it only counts posts explicitly marked taken, and much reuse is never
      marked.
    </p>

    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
      <ModEmailDateFilter
        :loading="loading"
        fetch-label="Fetch"
        default-preset="30days"
        @fetch="onFilterFetch"
      />
      <b-form-radio-group
        v-model="stratum"
        size="sm"
        buttons
        button-variant="outline-primary"
        :disabled="loading"
        @change="fetchAnalytics"
      >
        <b-form-radio value="all">All</b-form-radio>
        <b-form-radio value="rural">Rural</b-form-radio>
        <b-form-radio value="suburban">Suburban</b-form-radio>
        <b-form-radio value="dense">Dense</b-form-radio>
      </b-form-radio-group>
    </div>

    <div v-if="loading" class="text-center py-4">
      <b-spinner />
      <p class="text-muted small mt-2 mb-0">
        Computing live — sampling drive-times from the routing graph…
      </p>
    </div>
    <div v-else-if="error" class="text-danger">Failed to load: {{ error }}</div>
    <div v-else-if="s1">
      <p class="text-muted small mb-3">
        {{ s1.posts.toLocaleString() }} rippled-out offers in this
        {{ stratumLabel }} window.
      </p>

      <!-- Section 1 - KPIs -->
      <b-row class="g-3">
        <b-col cols="12" md="6" lg="3">
          <div class="kpi-card">
            <strong class="small d-block mb-1">Getting a reply?</strong>
            <GChart
              type="PieChart"
              :data="repliedPie"
              :options="pieOptions"
              style="width: 100%; height: 140px"
            />
            <div class="kpi-figure">{{ pct(s1.replied_pct) }}</div>
            <div class="kpi-label">of offers get a reply</div>
          </div>
        </b-col>
        <b-col cols="12" md="6" lg="3">
          <div class="kpi-card">
            <strong class="small d-block mb-1">Getting taken?</strong>
            <GChart
              type="PieChart"
              :data="takenPie"
              :options="pieOptions"
              style="width: 100%; height: 140px"
            />
            <div class="kpi-figure">{{ pct(s1.taken_pct) }}</div>
            <div class="kpi-label">marked taken (underestimate)</div>
          </div>
        </b-col>
        <b-col cols="12" md="6" lg="3">
          <div class="kpi-card d-flex flex-column justify-content-center">
            <div class="kpi-figure-lg">{{ s1.mean_replies.toFixed(2) }}</div>
            <div class="kpi-label">mean replies per offer</div>
            <hr class="my-2" />
            <div class="kpi-figure-lg">
              {{ Math.round(s1.mean_freeglers_reached).toLocaleString() }}
            </div>
            <div class="kpi-label">active freeglers reached (mean)</div>
          </div>
        </b-col>
        <b-col cols="12" md="6" lg="3">
          <div class="kpi-card d-flex flex-column justify-content-center">
            <template v-if="s1.reply_drive_min.available">
              <div class="kpi-figure-lg">
                {{ s1.reply_drive_min.mean_min.toFixed(1)
                }}<small class="fs-6"> min</small>
              </div>
              <div class="kpi-label">mean reply travel (drive-time)</div>
              <div class="text-muted small mt-1">
                ±{{ s1.reply_drive_min.ci_half_min.toFixed(1) }} min · sample of
                {{ s1.reply_drive_min.n_replies.toLocaleString() }} replies
              </div>
            </template>
            <template v-else>
              <div class="kpi-label">
                No drive-time sample (routing unavailable).
              </div>
            </template>
          </div>
        </b-col>
      </b-row>

      <!-- Section 2 - trends, one small chart per metric -->
      <h5 class="mt-4">Trends</h5>
      <p class="text-muted small mb-2">
        Each headline number over time, by post arrival day.
      </p>
      <b-row class="g-3">
        <b-col v-for="t in trendCharts" :key="t.title" cols="12" md="6" lg="4">
          <div class="kpi-card">
            <strong class="small d-block mb-1">{{ t.title }}</strong>
            <GChart
              v-if="t.data"
              type="LineChart"
              :data="t.data"
              :options="miniLineOptions(t.color, t.format)"
              style="width: 100%; height: 180px"
            />
            <p v-else class="text-muted small mb-0">No data.</p>
            <p v-if="t.note" class="text-muted small fst-italic mb-0">
              {{ t.note }}
            </p>
          </div>
        </b-col>
      </b-row>

      <!-- Section 3 - is rippling helping? -->
      <h5 class="mt-4">Is rippling out helping?</h5>
      <p v-if="s3" class="text-muted small mb-2">
        Rippling shows offers to people beyond the origin group. The takes they
        produce are <strong>additive</strong> — the honest question is how many.
        Per reply they convert worse (they're further away, so less likely to
        follow through), but that isn't the point; a rippled take is reuse that
        the origin group alone wouldn't have delivered.
      </p>

      <div v-if="s3" class="kpi-card helping mb-3">
        <div class="kpi-figure-lg">
          {{ pct(s3.contribution_low_pct) }} –
          {{ pct(s3.contribution_high_pct) }}
        </div>
        <div class="kpi-label mb-1">
          of the {{ s3.takers.toLocaleString() }} completed takes on these
          offers are down to rippling.
        </div>
        <div class="text-muted small">
          <strong>Floor</strong> — {{ s3.rescued_takes.toLocaleString() }} takes
          rescued from silence (posts with <em>no</em> local reply at all, so
          without rippling they'd have gone nowhere). <strong>Ceiling</strong> —
          {{ s3.rippled_takers.toLocaleString() }} takes by people reached only
          via rippling (some of those posts might have found a local taker
          anyway). The truth sits between.
        </div>
      </div>

      <b-row v-if="s3" class="g-3">
        <b-col cols="12" md="6" lg="3">
          <div class="kpi-card">
            <strong class="small d-block mb-1">Replies via rippling</strong>
            <GChart
              type="PieChart"
              :data="rippledRepliesPie"
              :options="pieOptions"
              style="width: 100%; height: 130px"
            />
            <div class="kpi-figure">{{ pct(s3.rippled_replies_pct) }}</div>
            <div class="kpi-label">
              of {{ s3.replies.toLocaleString() }} replies
            </div>
          </div>
        </b-col>
        <b-col cols="12" md="6" lg="3">
          <div class="kpi-card">
            <strong class="small d-block mb-1">Takers via rippling</strong>
            <GChart
              type="PieChart"
              :data="rippledTakersPie"
              :options="pieOptions"
              style="width: 100%; height: 130px"
            />
            <div class="kpi-figure">{{ pct(s3.rippled_takers_pct) }}</div>
            <div class="kpi-label">
              of {{ s3.takers.toLocaleString() }} takers
            </div>
          </div>
        </b-col>
        <b-col cols="12" md="6" lg="3">
          <div class="kpi-card d-flex flex-column justify-content-center">
            <strong class="small d-block mb-2">Reply → take</strong>
            <div>
              <span class="kpi-figure-lg">{{ pct(s3.home_conv_pct) }}</span>
              <span class="kpi-label"> home members</span>
            </div>
            <div>
              <span class="kpi-figure-lg text-success">{{
                pct(s3.rippled_conv_pct)
              }}</span>
              <span class="kpi-label"> rippled-out</span>
            </div>
            <div class="text-muted small mt-1">
              Rippled converts lower per reply — expected, they travel further —
              but every one is additive.
            </div>
          </div>
        </b-col>
        <b-col cols="12" md="6" lg="3">
          <div class="kpi-card d-flex flex-column justify-content-center">
            <template v-if="s3.ripple_drive_min.available">
              <div class="kpi-figure-lg">
                {{ s3.ripple_drive_min.mean_min.toFixed(1)
                }}<small class="fs-6"> min</small>
              </div>
              <div class="kpi-label">
                mean travel of a rippled-out reply (drive-time)
              </div>
              <div class="text-muted small mt-1">
                ±{{ s3.ripple_drive_min.ci_half_min.toFixed(1) }} min · sample
                of
                {{ s3.ripple_drive_min.n_replies.toLocaleString() }}
              </div>
            </template>
            <template v-else>
              <div class="kpi-label">No rippled-out drive-time sample.</div>
            </template>
          </div>
        </b-col>
      </b-row>
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

const POS = '#28a745' // shared "positive outcome" green across every pie
const NEG = '#ced4da' // shared neutral grey for the remainder

const loading = ref(true)
const error = ref(null)
const startDate = ref('')
const endDate = ref('')
const stratum = ref('all')
const s1 = ref(null)
const s2 = ref({ kpis: [], drive_time: [] })
const s3 = ref(null)

const stratumLabel = computed(() =>
  stratum.value === 'all' ? 'all-density' : stratum.value
)

function pct(v) {
  return (v || 0).toFixed(1) + '%'
}
const pieOptions = {
  legend: { position: 'none' },
  pieHole: 0.55,
  chartArea: { width: '90%', height: '85%' },
  colors: [POS, NEG],
  pieSliceText: 'none',
  backgroundColor: 'transparent',
}

const repliedPie = computed(() =>
  s1.value
    ? [
        ['Outcome', 'Offers'],
        ['Got a reply', s1.value.replied],
        ['Silent', s1.value.posts - s1.value.replied],
      ]
    : null
)
const takenPie = computed(() =>
  s1.value
    ? [
        ['Outcome', 'Offers'],
        ['Marked taken', s1.value.taken],
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

// One small line chart per metric, so no single graph is overloaded.
function series(rows, key, mult = 1) {
  if (!rows || !rows.length) return null
  return [
    ['Date', 'v'],
    ...rows.map((r) => [new Date(r.day), (r[key] || 0) * mult]),
  ]
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
      note: 'Sample-based — small per-day counts, so read the shape not the wiggles.',
    },
  ]
})
function miniLineOptions(color) {
  return {
    curveType: 'function',
    legend: { position: 'none' },
    chartArea: { width: '82%', height: '72%' },
    vAxis: { viewWindow: { min: 0 }, format: '#.#' },
    hAxis: { format: 'dd MMM', textStyle: { fontSize: 10 } },
    series: { 0: { color } },
  }
}

async function fetchAnalytics() {
  loading.value = true
  error.value = null
  try {
    const result = await apiInstance.rippling.fetchAnalytics(
      stratum.value,
      startDate.value,
      endDate.value
    )
    s1.value = result?.section1 || null
    s2.value = result?.section2 || { kpis: [], drive_time: [] }
    s3.value = result?.section3 || null
  } catch (e) {
    error.value = e.message || 'Unknown error'
  } finally {
    loading.value = false
  }
}

function onFilterFetch({ start, end }) {
  startDate.value = start || ''
  endDate.value = end || ''
  fetchAnalytics()
}

defineExpose({ fetchAnalytics, stratum, onFilterFetch })
</script>

<style scoped lang="scss">
.kpi-card {
  border: 1px solid #e4e6e1;
  border-radius: 12px;
  padding: 14px 16px;
  height: 100%;
  min-height: 170px;
}
.kpi-card.helping {
  min-height: 0;
  border-color: #28a745;
  background: rgba(40, 167, 69, 0.05);
}
.kpi-figure {
  font-size: 1.9rem;
  font-weight: 750;
  line-height: 1.1;
  margin-top: 6px;
  font-variant-numeric: tabular-nums;
}
.kpi-figure-lg {
  font-size: 2.1rem;
  font-weight: 750;
  line-height: 1.1;
  font-variant-numeric: tabular-nums;
}
.kpi-label {
  font-size: 0.82rem;
  color: #55605a;
}
</style>
