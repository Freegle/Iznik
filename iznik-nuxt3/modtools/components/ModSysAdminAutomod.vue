<template>
  <div>
    <b-form-group label="Period" class="mb-3">
      <b-form-select
        v-model="days"
        :options="dayOptions"
        style="width: auto"
        @change="fetchStats"
      />
    </b-form-group>
    <NoticeMessage v-if="error" variant="danger" class="mb-3">
      {{ error }}
    </NoticeMessage>
    <b-spinner v-if="loading" small />
    <div v-else-if="stats">
      <b-card class="mb-3" header="Automated review totals">
        <b-table-simple small responsive>
          <b-tbody>
            <b-tr>
              <b-td>Posts reviewed</b-td>
              <b-td class="text-end">{{ stats.total }}</b-td>
            </b-tr>
            <b-tr>
              <b-td>Shadow mode (not yet acting)</b-td>
              <b-td class="text-end">{{ stats.shadow?.count || 0 }}</b-td>
            </b-tr>
            <b-tr>
              <b-td>Acting (auto-approving)</b-td>
              <b-td class="text-end">{{ stats.approve?.count || 0 }}</b-td>
            </b-tr>
          </b-tbody>
        </b-table-simple>
      </b-card>
      <b-card class="mb-3" header="By decision">
        <b-table-simple small responsive>
          <b-thead>
            <b-tr>
              <b-th>Where it ended</b-th>
              <b-th>Verdict</b-th>
              <b-th class="text-end">Posts</b-th>
              <b-th class="text-end">Moderator disagreed</b-th>
              <b-th class="text-end">%</b-th>
            </b-tr>
          </b-thead>
          <b-tbody>
            <b-tr v-for="row in stats.byEnd" :key="row.end + '-' + row.verdict">
              <b-td>{{ row.end }}</b-td>
              <b-td>{{ row.verdict }}</b-td>
              <b-td class="text-end">{{ row.count }}</b-td>
              <b-td class="text-end">{{ row.modDisagreed }}</b-td>
              <b-td class="text-end text-muted">
                {{ pct(row.modDisagreed, row.count) }}
              </b-td>
            </b-tr>
          </b-tbody>
        </b-table-simple>
      </b-card>
      <b-card header="Steps flagged as wrong by moderators">
        <b-table-simple v-if="stats.feedback?.length" small responsive>
          <b-tbody>
            <b-tr v-for="row in stats.feedback" :key="row.node">
              <b-td>{{ row.node }}</b-td>
              <b-td class="text-end">{{ row.count }}</b-td>
            </b-tr>
          </b-tbody>
        </b-table-simple>
        <p v-else class="text-muted mb-0">No feedback in this period.</p>
      </b-card>
    </div>
  </div>
</template>
<script setup>
// SysAdmin panel showing how well the automated review flowchart's verdicts
// match what moderators actually decide - mounted next to the moderation
// stats panel it complements.
import { ref, onMounted } from 'vue'
import { useMessageStore } from '@/stores/message'

const messageStore = useMessageStore()

const days = ref(30)
const dayOptions = [
  { value: 7, text: 'Last 7 days' },
  { value: 30, text: 'Last 30 days' },
  { value: 90, text: 'Last 90 days' },
]

const stats = ref(null)
const loading = ref(false)
const error = ref(null)

function pct(num, denom) {
  if (!denom) return '-'
  return ((100 * num) / denom).toFixed(1) + '%'
}

async function fetchStats() {
  loading.value = true
  error.value = null
  try {
    stats.value = await messageStore.fetchAutomodAgreement({
      days: days.value,
    })
  } catch (e) {
    error.value = e?.message || 'request failed'
  } finally {
    loading.value = false
  }
}

onMounted(fetchStats)

defineExpose({ days, dayOptions, stats, loading, error, fetchStats, pct })
</script>
