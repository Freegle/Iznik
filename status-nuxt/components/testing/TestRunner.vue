<script setup lang="ts">
import { testConfigs, type TestType } from '~/types/test'
import { useTestStore } from '~/stores/tests'

const props = defineProps<{
  testType: TestType
}>()

const testStore = useTestStore()

const config = computed(() => testConfigs[props.testType])
const state = computed(() => testStore.getTestState(props.testType))

const filter = ref('')
const showLogs = ref(false)

const statusClass = computed(() => {
  switch (state.value.status) {
    case 'running': return 'running'
    case 'completed': return 'online'
    case 'failed': return 'offline'
    default: return 'unknown'
  }
})

const progressPercent = computed(() => {
  const { total, completed } = state.value.progress
  if (total === 0) return 0
  return Math.round((completed / total) * 100)
})

const progressVariant = computed(() => {
  const { failed } = state.value.progress
  if (failed > 0) return 'bg-danger'
  return 'bg-success'
})

const handleRun = () => {
  testStore.runTests(props.testType, filter.value || undefined)
  showLogs.value = true
}

watch(() => state.value.status, (newStatus, oldStatus) => {
  if (oldStatus === 'running' && (newStatus === 'completed' || newStatus === 'failed')) {
    showLogs.value = false
  }
})

const openReport = () => {
  if (state.value.reportUrl) {
    window.open(state.value.reportUrl, '_blank')
  }
}
</script>

<template>
  <div :class="['service', 'test-suite', statusClass]">
    <div class="test-info">
      <div class="service-title">{{ config.name }}</div>
      <div class="service-description">{{ config.description }}</div>

      <div class="service-status">
        {{ state.status === 'running' ? 'Running...' : state.status === 'completed' ? 'Passed' : state.status === 'failed' ? 'Failed' : 'Ready' }}
      </div>

      <!-- Progress bar when running -->
      <div v-if="state.status === 'running'" class="test-progress">
        <div class="progress">
          <div
            :class="['progress-bar', progressVariant]"
            role="progressbar"
            :style="{ width: progressPercent + '%' }"
          />
        </div>
        <div class="small mt-1">
          {{ state.progress.completed }}/{{ state.progress.total }}
          ({{ state.progress.passed }} passed<span v-if="state.progress.failed > 0" class="text-danger">, {{ state.progress.failed }} failed</span>)
        </div>
        <div v-if="state.progress.current" class="small text-muted mt-1">
          Running: {{ state.progress.current }}
        </div>
      </div>

      <!-- Logs viewer -->
      <div v-if="showLogs" class="test-logs">
        <pre v-if="state.logs">{{ state.logs }}</pre>
        <div v-else class="text-muted">No logs yet</div>
      </div>

      <!-- Status message -->
      <div v-if="state.message" class="small mt-2" :class="state.status === 'failed' ? 'text-danger' : 'text-muted'">
        {{ state.message }}
      </div>
    </div>

    <div class="test-actions">
      <button
        class="run-test-btn"
        :disabled="state.status === 'running'"
        @click="handleRun"
      >
        {{ state.status === 'running' ? 'Running...' : 'Run Tests' }}
      </button>

      <input
        v-model="filter"
        type="text"
        class="filter-input"
        :placeholder="config.filterPlaceholder"
        :disabled="state.status === 'running'"
      >

      <button
        v-if="config.hasReport && state.reportUrl"
        class="action-button"
        @click="openReport"
      >
        View Report
      </button>

      <button
        class="action-button"
        @click="showLogs = !showLogs"
      >
        {{ showLogs ? 'Hide Logs' : 'Show Logs' }}
      </button>
    </div>
  </div>
</template>
