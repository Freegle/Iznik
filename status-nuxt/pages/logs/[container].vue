<script setup lang="ts">
const route = useRoute()
const container = computed(() => route.params.container as string)

const logs = ref('')
const isLoading = ref(true)
const error = ref<string | null>(null)
const lines = ref(100)

const fetchLogs = async () => {
  isLoading.value = true
  error.value = null

  try {
    const response = await $fetch<{ logs: string }>('/api/logs', {
      query: {
        container: container.value,
        lines: lines.value,
      },
    })
    logs.value = response.logs
  }
  catch (err) {
    error.value = err instanceof Error ? err.message : 'Failed to fetch logs'
  }
  finally {
    isLoading.value = false
  }
}

const refresh = () => {
  fetchLogs()
}

onMounted(() => {
  fetchLogs()
})

watch(container, () => {
  fetchLogs()
})
</script>

<template>
  <div>
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
        <NuxtLink to="/" class="btn btn-outline-secondary btn-sm me-2">
          ← Back
        </NuxtLink>
        <h1 class="d-inline h4 mb-0">Logs: {{ container }}</h1>
      </div>
      <div class="d-flex gap-2 align-items-center">
        <BFormSelect v-model="lines" size="sm" style="width: auto;">
          <option :value="50">50 lines</option>
          <option :value="100">100 lines</option>
          <option :value="500">500 lines</option>
          <option :value="1000">1000 lines</option>
        </BFormSelect>
        <BButton variant="primary" size="sm" @click="refresh">
          Refresh
        </BButton>
      </div>
    </div>

    <BAlert v-if="error" variant="danger" show>
      {{ error }}
    </BAlert>

    <div v-if="isLoading" class="text-center py-4">
      <BSpinner />
      <p class="mt-2">Loading logs...</p>
    </div>

    <div v-else class="log-viewer">
      <pre class="bg-dark text-light p-3 rounded" style="max-height: 70vh; overflow-y: auto;">{{ logs || 'No logs available' }}</pre>
    </div>
  </div>
</template>
