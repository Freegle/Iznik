<template>
  <div class="p-2">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h5 class="mb-0">App Debug Log</h5>
      <b-button size="sm" variant="outline-danger" @click="clear">Clear</b-button>
    </div>
    <div class="build-info text-muted small mb-2">
      Build: {{ buildDate }}
    </div>
    <div v-if="logs.length === 0" class="text-muted small">
      No log entries yet.
    </div>
    <div v-else ref="logContainer" class="log-container font-monospace small">
      <div
        v-for="(entry, idx) in logs"
        :key="idx"
        :class="['log-entry', 'py-1', 'border-bottom', levelClass(entry.level)]"
      >
        <span class="text-muted me-2">{{ entry.timestamp.replace('T', ' ').replace(/\.\d+Z$/, '') }}</span>
        <span :class="['badge', 'me-2', badgeClass(entry.level)]">{{ entry.level }}</span>
        <span class="log-message">{{ entry.message }}</span>
      </div>
    </div>
  </div>
</template>
<script setup>
import { useDebugStore } from '@/stores/debug'

const debugStore = useDebugStore()
const runtimeConfig = useRuntimeConfig()

const logs = computed(() => debugStore.getLogs)
const buildDate = runtimeConfig.public.BUILD_DATE || 'unknown'

const logContainer = ref(null)

function clear() {
  debugStore.clear()
}

function levelClass(level) {
  if (level === 'ERROR') return 'log-error'
  if (level === 'WARN') return 'log-warn'
  return ''
}

function badgeClass(level) {
  if (level === 'ERROR') return 'bg-danger'
  if (level === 'WARN') return 'bg-warning text-dark'
  if (level === 'DEBUG') return 'bg-secondary'
  return 'bg-info text-dark'
}

watch(
  () => logs.value.length,
  async () => {
    await nextTick()
    if (logContainer.value) {
      logContainer.value.scrollTop = logContainer.value.scrollHeight
    }
  }
)
</script>
<style scoped>
.log-container {
  max-height: calc(100vh - 160px);
  overflow-y: auto;
  word-break: break-word;
}
.log-error {
  background-color: #fff5f5;
}
.log-warn {
  background-color: #fffbf0;
}
.log-message {
  white-space: pre-wrap;
}
</style>
