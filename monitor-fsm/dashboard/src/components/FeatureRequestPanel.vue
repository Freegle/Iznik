<template>
  <div class="fr-panel">
    <div class="d-flex align-items-center justify-content-between mb-3">
      <h5 class="mb-0">
        Feature Requests
        <span class="badge bg-info text-dark ms-2">{{ requests.length }}</span>
      </h5>
      <button class="btn btn-outline-secondary btn-sm" @click="refresh" :disabled="loading">
        <i class="bi bi-arrow-clockwise" :class="{ 'spin': loading }"></i>
      </button>
    </div>

    <div v-if="loading" class="spinner-border spinner-border-sm" role="status">
      <span class="visually-hidden">Loading...</span>
    </div>

    <div v-else-if="requests.length === 0" class="alert alert-info small">
      No feature requests tracked.
    </div>

    <table v-else class="table table-sm table-hover">
      <thead>
        <tr>
          <th style="width: 75px;">ID</th>
          <th style="width: 100px;">Reporter</th>
          <th>Summary</th>
          <th style="width: 60px;"></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="req in requests" :key="`${req.topic}-${req.post}`">
          <td class="text-muted font-monospace" style="font-size: 0.75em; white-space: nowrap;">
            {{ req.topic }}/{{ req.post }}
          </td>
          <td>
            <a
              :href="`https://discourse.ilovefreegle.org/t/${req.topic}/${req.post}`"
              target="_blank"
              rel="noopener"
              class="text-decoration-none"
            >
              {{ req.reporter || 'Unknown' }}
            </a>
          </td>
          <td style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 0; width: 100%;">
            <span :title="req.excerpt || req.topic_title || ''">
              {{ req.excerpt || req.topic_title || '—' }}
            </span>
          </td>
          <td class="text-end">
            <button
              class="btn btn-outline-secondary btn-xs"
              title="Dismiss — mark as off-topic"
              @click="dismiss(req)"
            >✕</button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup lang="ts">
import type { BugRow } from '../types'

const props = defineProps<{
  requests: BugRow[]
  loading: boolean
}>()

const emit = defineEmits<{
  refresh: []
}>()

async function dismiss(req: BugRow) {
  if (!confirm(`Dismiss feature request ${req.topic}/${req.post}?`)) return
  await fetch(`/api/bugs/${req.topic}/${req.post}/state`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ state: 'off-topic', reason: 'Dismissed by human' }),
  })
  emit('refresh')
}

function refresh() {
  emit('refresh')
}
</script>

<style scoped>
.fr-panel {
  background: white;
  padding: 1rem;
  border-radius: 0.25rem;
  border: 1px solid #dee2e6;
}

.table {
  margin-bottom: 0;
}

.btn-xs {
  padding: 0.1rem 0.35rem;
  font-size: 0.75rem;
  line-height: 1.2;
  border-radius: 0.2rem;
}

.spin {
  animation: spin 1s linear infinite;
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}
</style>
