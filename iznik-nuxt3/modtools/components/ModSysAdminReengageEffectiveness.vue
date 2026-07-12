<template>
  <div class="reengage-effectiveness">
    <p class="text-muted">
      How well do reengagement emails bring lapsed members back? Lapsed users
      are sent a reengagement email in stages; this tracks the funnel from
      <strong>send</strong> through <strong>open</strong> and
      <strong>click</strong> to an actual <strong>reengagement</strong> (a
      post or reply within the tracked window), and compares the treatment
      arms against the <strong>control</strong> (holdout) arm to measure the
      real lift.
    </p>

    <ModEmailDateFilter
      :loading="emailTrackingStore.reengageLoading"
      fetch-label="Fetch"
      default-preset="30days"
      @fetch="onFilterFetch"
    />

    <!-- Error -->
    <NoticeMessage
      v-if="emailTrackingStore.reengageError"
      variant="danger"
      class="mb-3"
    >
      {{ emailTrackingStore.reengageError }}
    </NoticeMessage>

    <!-- Loading -->
    <div v-if="emailTrackingStore.reengageLoading" class="text-center py-4">
      <b-spinner small class="me-2" />
      Loading reengagement effectiveness...
    </div>

    <!-- Results -->
    <template v-else-if="emailTrackingStore.hasReengageStats">
      <!-- Funnel -->
      <h3 class="ms-2 mt-2">Funnel</h3>
      <b-table-simple hover responsive small class="mb-4">
        <b-thead>
          <b-tr>
            <b-th>Stage</b-th>
            <b-th>Count</b-th>
            <b-th>Rate (of sent)</b-th>
            <b-th></b-th>
          </b-tr>
        </b-thead>
        <b-tbody>
          <b-tr v-for="step in funnelSteps" :key="step.label">
            <b-td>{{ step.label }}</b-td>
            <b-td>{{ step.count.toLocaleString() }}</b-td>
            <b-td>{{ pct(step.rate) }}</b-td>
            <b-td class="bar-cell">
              <div class="bar-track">
                <div
                  class="bar-fill"
                  :style="{ width: Math.min(100, step.rate) + '%' }"
                />
              </div>
            </b-td>
          </b-tr>
        </b-tbody>
      </b-table-simple>

      <!-- Per-stage breakdown -->
      <h3 class="ms-2 mt-2">By stage</h3>
      <p class="text-muted small ms-2">
        Each stage is one day's onboarding tip (day 1-5) sent to a new member;
        later stages go to people still early in their first week.
      </p>
      <b-table-simple hover responsive small class="mb-4">
        <b-thead>
          <b-tr>
            <b-th>Stage</b-th>
            <b-th>Sent</b-th>
            <b-th>Opened</b-th>
            <b-th>Open rate</b-th>
            <b-th>Clicked</b-th>
            <b-th>Click rate</b-th>
            <b-th>Reengaged</b-th>
            <b-th>Reengage rate</b-th>
          </b-tr>
        </b-thead>
        <b-tbody>
          <b-tr v-for="s in byStage" :key="s.stage">
            <b-td>Stage {{ s.stage }}</b-td>
            <b-td>{{ (s.sent || 0).toLocaleString() }}</b-td>
            <b-td>{{ (s.opened || 0).toLocaleString() }}</b-td>
            <b-td>{{ pct(s.openRate) }}</b-td>
            <b-td>{{ (s.clicked || 0).toLocaleString() }}</b-td>
            <b-td>{{ pct(s.clickRate) }}</b-td>
            <b-td>{{ (s.reengaged || 0).toLocaleString() }}</b-td>
            <b-td><strong>{{ pct(s.reengageRate) }}</strong></b-td>
          </b-tr>
          <b-tr v-if="!byStage.length">
            <b-td colspan="8" class="text-muted">No stage data.</b-td>
          </b-tr>
        </b-tbody>
      </b-table-simple>

      <!-- Per-arm comparison -->
      <h3 class="ms-2 mt-2">By experiment arm</h3>
      <p class="text-muted small ms-2">
        The <strong>control</strong> arm is a holdout that gets no
        reengagement email, so it never opens or clicks - it's there purely
        to measure the reengagement rate you'd see anyway. The key number is
        the <strong>lift</strong>: how much higher each treatment arm's
        reengagement rate is than control's.
      </p>
      <b-table-simple hover responsive small class="mb-4">
        <b-thead>
          <b-tr>
            <b-th>Arm</b-th>
            <b-th>Sent</b-th>
            <b-th>Opened</b-th>
            <b-th>Clicked</b-th>
            <b-th>Reengaged</b-th>
            <b-th>Reengage rate</b-th>
            <b-th>Lift vs control</b-th>
          </b-tr>
        </b-thead>
        <b-tbody>
          <b-tr v-for="a in byArm" :key="a.arm">
            <b-td>{{ armLabel(a.arm) }}</b-td>
            <b-td>{{ (a.sent || 0).toLocaleString() }}</b-td>
            <b-td>{{ (a.opened || 0).toLocaleString() }}</b-td>
            <b-td>{{ (a.clicked || 0).toLocaleString() }}</b-td>
            <b-td>{{ (a.reengaged || 0).toLocaleString() }}</b-td>
            <b-td>
              <strong :class="armRateClass(a)">{{
                pct(a.reengageRate)
              }}</strong>
            </b-td>
            <b-td>
              <span v-if="a.lift === null" class="text-muted">baseline</span>
              <span v-else :class="liftClass(a.lift)">{{
                formatLift(a.lift)
              }}</span>
            </b-td>
          </b-tr>
          <b-tr v-if="!byArm.length">
            <b-td colspan="7" class="text-muted">No arm data.</b-td>
          </b-tr>
        </b-tbody>
      </b-table-simple>

      <!-- Per-segment breakdown -->
      <h3 class="ms-2 mt-2">By segment</h3>
      <b-table-simple hover responsive small class="mb-2">
        <b-thead>
          <b-tr>
            <b-th>Segment</b-th>
            <b-th>Sent</b-th>
            <b-th>Reengaged</b-th>
            <b-th>Reengage rate</b-th>
            <b-th></b-th>
          </b-tr>
        </b-thead>
        <b-tbody>
          <b-tr v-for="s in bySegment" :key="s.segment">
            <b-td>{{ segmentLabel(s.segment) }}</b-td>
            <b-td>{{ (s.sent || 0).toLocaleString() }}</b-td>
            <b-td>{{ (s.reengaged || 0).toLocaleString() }}</b-td>
            <b-td>{{ pct(s.reengageRate) }}</b-td>
            <b-td class="bar-cell">
              <div class="bar-track">
                <div
                  class="bar-fill"
                  :style="{ width: Math.min(100, s.reengageRate) + '%' }"
                />
              </div>
            </b-td>
          </b-tr>
          <b-tr v-if="!bySegment.length">
            <b-td colspan="5" class="text-muted">No segment data.</b-td>
          </b-tr>
        </b-tbody>
      </b-table-simple>
    </template>

    <!-- Empty -->
    <div
      v-else-if="!emailTrackingStore.reengageError"
      class="text-muted text-center py-4"
    >
      <p>No reengagement data available for the selected period.</p>
      <p class="small">Try widening the date range.</p>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useEmailTrackingStore } from '~/modtools/stores/emailtracking'
import ModEmailDateFilter from '~/modtools/components/ModEmailDateFilter.vue'

const emailTrackingStore = useEmailTrackingStore()

const startDate = ref('')
const endDate = ref('')

function rate(numerator, denominator) {
  return denominator > 0 ? (numerator / denominator) * 100 : 0
}

function pct(v) {
  return (v || 0).toFixed(1) + '%'
}

const ARM_LABELS = {
  a: 'Arm A',
  b: 'Arm B',
  control: 'Control (holdout)',
}
function armLabel(arm) {
  return ARM_LABELS[arm] || arm
}

const SEGMENT_LABELS = {
  offer: 'Offer',
  wanted: 'Wanted',
  replier: 'Replier',
  other: 'Other',
}
function segmentLabel(segment) {
  return SEGMENT_LABELS[segment] || segment
}

function formatLift(lift) {
  const sign = lift >= 0 ? '+' : ''
  return `${sign}${lift.toFixed(1)}pp`
}

function liftClass(lift) {
  return lift >= 0 ? 'text-success' : 'text-danger'
}

function armRateClass(arm) {
  if (arm.arm === 'control' || arm.lift === null) return ''
  return arm.lift >= 0 ? 'text-success' : 'text-danger'
}

// Funnel: Sent -> Opened -> Clicked -> Reengaged, each step's rate measured
// against Sent (the top of the funnel), not the previous step.
const funnelSteps = computed(() => {
  const f = emailTrackingStore.reengageStats?.funnel
  if (!f) return []
  const sent = f.sent || 0
  return [
    { label: 'Sent', count: f.sent || 0 },
    { label: 'Opened', count: f.opened || 0 },
    { label: 'Clicked', count: f.clicked || 0 },
    { label: 'Reengaged', count: f.reengaged || 0 },
  ].map((step) => ({ ...step, rate: rate(step.count, sent) }))
})

const byStage = computed(() =>
  (emailTrackingStore.reengageStats?.byStage || []).map((s) => ({
    ...s,
    openRate: rate(s.opened, s.sent),
    clickRate: rate(s.clicked, s.sent),
    reengageRate: rate(s.reengaged, s.sent),
  }))
)

// Control never opens/clicks (it gets no email) - it exists purely to
// establish the baseline reengagement rate that would happen anyway. The lift
// for each treatment arm is its reengagement rate minus that baseline.
const byArm = computed(() => {
  const rows = emailTrackingStore.reengageStats?.byArm || []
  const control = rows.find((r) => r.arm === 'control')
  const controlRate = control ? rate(control.reengaged, control.sent) : 0
  return rows.map((r) => {
    const reengageRate = rate(r.reengaged, r.sent)
    return {
      ...r,
      openRate: rate(r.opened, r.sent),
      clickRate: rate(r.clicked, r.sent),
      reengageRate,
      lift: r.arm === 'control' ? null : reengageRate - controlRate,
    }
  })
})

const bySegment = computed(() =>
  (emailTrackingStore.reengageStats?.bySegment || []).map((s) => ({
    ...s,
    reengageRate: rate(s.reengaged, s.sent),
  }))
)

function fetchData() {
  emailTrackingStore.fetchReengageEffectiveness({
    start: startDate.value,
    end: endDate.value,
  })
}

function onFilterFetch({ start, end }) {
  startDate.value = start
  endDate.value = end
  fetchData()
}

// Exposed for unit testing.
defineExpose({
  funnelSteps,
  byStage,
  byArm,
  bySegment,
  pct,
  armLabel,
  segmentLabel,
  formatLift,
  liftClass,
  armRateClass,
  fetchData,
  onFilterFetch,
})
</script>

<style scoped lang="scss">
.reengage-effectiveness {
  .bar-cell {
    min-width: 140px;
  }

  .bar-track {
    background: #e9ecef;
    border-radius: 4px;
    height: 10px;
    overflow: hidden;
    width: 100%;
  }

  .bar-fill {
    background: #17a2b8;
    height: 100%;
  }
}
</style>
