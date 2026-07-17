<template>
  <div class="recommendations">
    <p class="text-muted">
      Are the <strong>"more like this"</strong> recommendations worth showing?
      For each recommendation surface this tracks the funnel — how many cards
      were shown (impressions), how many were opened (clicks), and how many of
      those opens led to a reply within 7 days. The holdout compares reply
      behaviour of the 10% of members who never see recommendations against
      everyone else.
    </p>

    <div class="d-flex align-items-center mb-3">
      <label class="me-2 mb-0">Period</label>
      <b-form-select
        v-model="days"
        :options="dayOptions"
        style="width: auto"
        @change="fetchStats"
      />
      <b-spinner v-if="loading" small class="ms-3" />
    </div>

    <NoticeMessage v-if="error" variant="danger" class="mb-3">
      {{ error }}
    </NoticeMessage>

    <template v-else>
      <div class="source-grid mb-4">
        <b-card
          v-for="s in sources"
          :key="s.source"
          :title="sourceLabel(s.source)"
          class="source-card"
        >
          <div class="metric-row">
            <div class="metric">
              <div class="metric__value">
                {{ s.impressions.toLocaleString() }}
              </div>
              <div class="metric__label">Impressions</div>
            </div>
            <div class="metric">
              <div class="metric__value">{{ s.clicks.toLocaleString() }}</div>
              <div class="metric__label">Clicks</div>
            </div>
            <div class="metric">
              <div class="metric__value">{{ formatPct(s.ctr) }}</div>
              <div class="metric__label">CTR</div>
            </div>
            <div class="metric">
              <div class="metric__value">
                {{ s.attributedReplies.toLocaleString() }}
              </div>
              <div class="metric__label">Replies (7d)</div>
            </div>
          </div>
        </b-card>
      </div>

      <h3 class="h5">Holdout comparison</h3>
      <p class="text-muted small">
        The 10% holdout never sees recommendations. If recommendations help,
        members who see them should reply at least as often as the holdout.
      </p>
      <b-table-simple small responsive class="holdout-table">
        <b-thead>
          <b-tr>
            <b-th>Cohort</b-th>
            <b-th class="text-end">Active members</b-th>
            <b-th class="text-end">Replies</b-th>
            <b-th class="text-end">Replies / member</b-th>
          </b-tr>
        </b-thead>
        <b-tbody>
          <b-tr>
            <b-td>Shown recommendations</b-td>
            <b-td class="text-end">{{
              holdout.shownUsers?.toLocaleString()
            }}</b-td>
            <b-td class="text-end">{{
              holdout.shownReplies?.toLocaleString()
            }}</b-td>
            <b-td class="text-end">{{
              formatNum(holdout.shownRepliesPerUser)
            }}</b-td>
          </b-tr>
          <b-tr>
            <b-td>Holdout (no recommendations)</b-td>
            <b-td class="text-end">{{
              holdout.holdoutUsers?.toLocaleString()
            }}</b-td>
            <b-td class="text-end">
              {{ holdout.holdoutReplies?.toLocaleString() }}
            </b-td>
            <b-td class="text-end">
              {{ formatNum(holdout.holdoutRepliesPerUser) }}
            </b-td>
          </b-tr>
        </b-tbody>
      </b-table-simple>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRuntimeConfig } from '#imports'
import api from '~/api'

const runtimeConfig = useRuntimeConfig()
const apiInstance = api(runtimeConfig)

const days = ref(30)
const dayOptions = [
  { value: 7, text: 'Last 7 days' },
  { value: 30, text: 'Last 30 days' },
  { value: 90, text: 'Last 90 days' },
]

const loading = ref(false)
const error = ref(null)
const sources = ref([])
const holdout = ref({})

const SOURCE_LABELS = {
  similar_posts: 'More like this (post page)',
  wanted_match: 'Matching offers (when posting a wanted)',
}
function sourceLabel(s) {
  return SOURCE_LABELS[s] || s
}

function formatPct(v) {
  return (v || 0).toFixed(1) + '%'
}
function formatNum(v) {
  return (v || 0).toFixed(2)
}

async function fetchStats() {
  loading.value = true
  error.value = null
  try {
    const result = await apiInstance.recommendations.fetchStats(days.value)
    sources.value = result?.sources || []
    holdout.value = result?.holdout || {}
  } catch (e) {
    error.value = e.message || 'Unknown error'
  } finally {
    loading.value = false
  }
}

onMounted(fetchStats)

defineExpose({ fetchStats, sources, holdout })
</script>

<style scoped lang="scss">
.source-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 1rem;
}

.metric-row {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
}

.metric {
  min-width: 4.5rem;
}

.metric__value {
  font-size: 1.4rem;
  font-weight: 700;
  line-height: 1.1;
}

.metric__label {
  font-size: 0.8rem;
  color: var(--color-gray-600, #6c757d);
}
</style>
