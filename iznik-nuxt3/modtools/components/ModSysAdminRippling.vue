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
const hotspots = ref([])
const proposedParams = ref([])

async function fetchMetrics() {
  loading.value = true
  error.value = null
  try {
    const result = await apiInstance.rippling.fetchMetrics()
    totals.value = result?.totals || []
    recent.value = result?.recent || []
    hotspots.value = result?.hotspots || []
    proposedParams.value = result?.proposed_params || []
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
