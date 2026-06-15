<template>
  <div>
    <h3>Moderation &amp; auto-approve analytics</h3>
    <p class="text-muted">
      How posts were handled over the period, and whether auto-approving is
      working — the rate at which auto-published posts later needed a moderator,
      compared with the moderator's verdict on the quality-check sample.
    </p>

    <div class="d-flex flex-wrap align-items-end gap-2 mb-3">
      <b-form-group label="From" class="mb-0">
        <b-form-input v-model="startDate" type="date" />
      </b-form-group>
      <b-form-group label="To" class="mb-0">
        <b-form-input v-model="endDate" type="date" />
      </b-form-group>
      <b-button variant="primary" :disabled="loading" @click="fetchStats">
        {{ loading ? 'Loading…' : 'Update' }}
      </b-button>
    </div>

    <NoticeMessage v-if="error" variant="danger">
      Couldn't load moderation stats: {{ error }}
    </NoticeMessage>

    <div v-else-if="stats">
      <!-- Flow -->
      <b-card class="mb-3" header="Where posts went">
        <b-table-simple small responsive>
          <b-tbody>
            <b-tr>
              <b-td>Posts arrived</b-td>
              <b-td class="text-end fw-bold">{{ stats.arrived }}</b-td>
              <b-td />
            </b-tr>
            <b-tr>
              <b-td>Approved by a moderator</b-td>
              <b-td class="text-end">{{ stats.manualApproved }}</b-td>
              <b-td class="text-muted">{{ pct(stats.manualApproved, stats.arrived) }}</b-td>
            </b-tr>
            <b-tr>
              <b-td>Rejected/deleted by a moderator</b-td>
              <b-td class="text-end">{{ stats.manualRejected }}</b-td>
              <b-td class="text-muted">{{ pct(stats.manualRejected, stats.arrived) }}</b-td>
            </b-tr>
            <b-tr>
              <b-td>Auto-approved (Checked delay + 48h fallback)</b-td>
              <b-td class="text-end">{{ stats.autoApproved }}</b-td>
              <b-td class="text-muted">{{ pct(stats.autoApproved, stats.arrived) }}</b-td>
            </b-tr>
            <b-tr>
              <b-td>Went live immediately (Trusted members)</b-td>
              <b-td class="text-end">{{ stats.trusted }}</b-td>
              <b-td class="text-muted">{{ pct(stats.trusted, stats.arrived) }}</b-td>
            </b-tr>
          </b-tbody>
        </b-table-simple>
      </b-card>

      <!-- Auto-approve quality -->
      <b-card class="mb-3" header="Did auto-approving go wrong?">
        <b-table-simple small responsive>
          <b-tbody>
            <b-tr>
              <b-td>Posts auto-approved (Checked + fallback)</b-td>
              <b-td class="text-end fw-bold">{{ stats.autoApproved }}</b-td>
              <b-td />
            </b-tr>
            <b-tr>
              <b-td>…a moderator later marked checked</b-td>
              <b-td class="text-end">{{ stats.autoModChecked }}</b-td>
              <b-td class="text-muted">{{ pct(stats.autoModChecked, stats.autoApproved) }} reviewed</b-td>
            </b-tr>
            <b-tr :variant="laterActionedVariant">
              <b-td>…later rejected/deleted/edited/held after going live</b-td>
              <b-td class="text-end fw-bold">{{ stats.autoLaterActioned }}</b-td>
              <b-td class="fw-bold">{{ autoErrorRate }} needed intervention</b-td>
            </b-tr>
          </b-tbody>
        </b-table-simple>
        <small class="text-muted">
          "Needed intervention" counts auto-published posts that a moderator (or a
          user-triggered removal) acted on afterwards — the closest signal we have
          for "auto-approve let a bad one through". A precise user-report figure
          isn't tracked separately.
        </small>
      </b-card>

      <!-- Quality-check sample -->
      <b-card class="mb-3" header="Quality-check sample (manual cross-check)">
        <b-table-simple small responsive>
          <b-tbody>
            <b-tr>
              <b-td>Posts held back for a manual quality check</b-td>
              <b-td class="text-end fw-bold">{{ stats.qualitySampled }}</b-td>
              <b-td />
            </b-tr>
            <b-tr>
              <b-td>…a moderator then rejected/deleted</b-td>
              <b-td class="text-end fw-bold">{{ stats.qualitySampleBad }}</b-td>
              <b-td class="fw-bold">{{ sampleBadRate }} were bad</b-td>
            </b-tr>
          </b-tbody>
        </b-table-simple>
      </b-card>

      <!-- Justification -->
      <b-card header="Is auto-approving working?" :border-variant="verdictVariant">
        <p class="mb-1">
          Of the posts a moderator manually checked (the sample),
          <strong>{{ sampleBadRate }}</strong> were bad. Of the posts we
          auto-published, <strong>{{ autoErrorRate }}</strong> later needed a
          moderator.
        </p>
        <p class="mb-0">{{ verdict }}</p>
      </b-card>
    </div>

    <NoticeMessage v-else-if="loading" variant="info">
      Loading…
    </NoticeMessage>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import dayjs from 'dayjs'
import { useMessageStore } from '@/stores/message'

const messageStore = useMessageStore()

const endDate = ref(dayjs().format('YYYY-MM-DD'))
const startDate = ref(dayjs().subtract(30, 'day').format('YYYY-MM-DD'))
const stats = ref(null)
const loading = ref(false)
const error = ref(null)

function pct(num, denom) {
  if (!denom) return '—'
  return ((100 * num) / denom).toFixed(1) + '%'
}

// Rate of auto-published posts that later needed intervention.
const autoErrorRate = computed(() =>
  stats.value ? pct(stats.value.autoLaterActioned, stats.value.autoApproved) : '—'
)
// Rate of quality-sample posts a mod then rejected.
const sampleBadRate = computed(() =>
  stats.value ? pct(stats.value.qualitySampleBad, stats.value.qualitySampled) : '—'
)

const autoErrorRateNum = computed(() => {
  if (!stats.value || !stats.value.autoApproved) return 0
  return (100 * stats.value.autoLaterActioned) / stats.value.autoApproved
})
const sampleBadRateNum = computed(() => {
  if (!stats.value || !stats.value.qualitySampled) return null
  return (100 * stats.value.qualitySampleBad) / stats.value.qualitySampled
})

const laterActionedVariant = computed(() =>
  autoErrorRateNum.value > 5 ? 'warning' : ''
)

// Compare the auto-publish error rate against the manually-checked sample's
// bad rate to judge whether auto-approving is missing problems.
const verdictVariant = computed(() => {
  if (sampleBadRateNum.value === null) return 'secondary'
  if (sampleBadRateNum.value <= autoErrorRateNum.value + 1) return 'success'
  if (sampleBadRateNum.value <= autoErrorRateNum.value + 5) return 'warning'
  return 'danger'
})

const verdict = computed(() => {
  if (sampleBadRateNum.value === null) {
    return 'No quality-check sample in this period — set a quality-check percentage on some groups to start cross-checking.'
  }
  const diff = sampleBadRateNum.value - autoErrorRateNum.value
  if (diff <= 1) {
    return 'Auto-approving looks safe: the manual sample finds about as few bad posts as we catch after auto-publishing.'
  }
  if (diff <= 5) {
    return 'Worth watching: the manual sample is catching somewhat more bad posts than we catch after auto-publishing — some may be slipping through unnoticed.'
  }
  return 'Concern: the manual sample catches many more bad posts than we catch after auto-publishing, suggesting auto-approve is letting problems through that nobody notices. Consider tightening the checks or raising the quality-check percentage.'
})

async function fetchStats() {
  loading.value = true
  error.value = null
  try {
    stats.value = await messageStore.fetchModerationStats({
      start: startDate.value,
      end: endDate.value,
    })
  } catch (e) {
    error.value = e?.message || 'request failed'
  } finally {
    loading.value = false
  }
}

onMounted(fetchStats)

defineExpose({ startDate, endDate, stats, fetchStats, pct })
</script>
