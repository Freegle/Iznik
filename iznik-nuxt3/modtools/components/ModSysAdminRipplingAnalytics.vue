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

      <b-row class="g-3">
        <b-col cols="12" md="6" lg="3">
          <div class="kpi-card">
            <strong class="small d-block mb-1">Getting a reply?</strong>
            <GChart
              type="PieChart"
              :data="repliedPie"
              :options="pieOptions(['#28a745', '#ced4da'])"
              style="width: 100%; height: 150px"
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
              :options="pieOptions(['#146c43', '#ced4da'])"
              style="width: 100%; height: 150px"
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

const loading = ref(true)
const error = ref(null)
const startDate = ref('')
const endDate = ref('')
const stratum = ref('all')
const s1 = ref(null)

const stratumLabel = computed(() =>
  stratum.value === 'all' ? 'all-density' : stratum.value
)

const repliedPie = computed(() => {
  if (!s1.value) return null
  return [
    ['Outcome', 'Offers'],
    ['Got a reply', s1.value.replied],
    ['Silent', s1.value.posts - s1.value.replied],
  ]
})
const takenPie = computed(() => {
  if (!s1.value) return null
  return [
    ['Outcome', 'Offers'],
    ['Marked taken', s1.value.taken],
    ['Not marked', s1.value.posts - s1.value.taken],
  ]
})

function pct(v) {
  return (v || 0).toFixed(1) + '%'
}
function pieOptions(colors) {
  return {
    legend: { position: 'none' },
    pieHole: 0.55,
    chartArea: { width: '90%', height: '85%' },
    colors,
    pieSliceText: 'none',
    backgroundColor: 'transparent',
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
  min-height: 180px;
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
