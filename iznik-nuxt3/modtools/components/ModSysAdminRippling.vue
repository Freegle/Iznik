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
      <h6>Totals (all time)</h6>
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
    </div>
  </div>
</template>

<script setup>
import api from '~/api'

const runtimeConfig = useRuntimeConfig()
const apiInstance = api(runtimeConfig)

const loading = ref(true)
const error = ref(null)
const totals = ref([])
const recent = ref([])

async function fetchMetrics() {
  loading.value = true
  error.value = null
  try {
    const result = await apiInstance.rippling.fetchMetrics()
    totals.value = result?.totals || []
    recent.value = result?.recent || []
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
