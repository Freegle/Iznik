<template>
  <div class="pr-panel">
    <div class="d-flex align-items-center justify-content-between mb-3">
      <h5 class="mb-0">
        PRs
        <span class="badge bg-secondary ms-2">{{ prs.length }}</span>
      </h5>
      <div class="d-flex gap-2">
        <small v-if="lastRefreshed" class="text-muted">{{ lastRefreshed }}</small>
        <button class="btn btn-outline-secondary btn-sm" @click="refresh" :disabled="loading">
          <i class="bi bi-arrow-clockwise" :class="{ 'spin': loading }"></i>
        </button>
      </div>
    </div>

    <div v-if="loading" class="spinner-border spinner-border-sm" role="status">
      <span class="visually-hidden">Loading...</span>
    </div>

    <div v-else-if="prs.length === 0" class="alert alert-info small">
      No open PRs.
    </div>

    <table v-else class="table table-sm table-hover">
      <thead>
        <tr>
          <th style="width: 55px;">PR</th>
          <th>Title</th>
          <th style="width: 120px;">Status</th>
          <th style="width: 55px;">Age</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="pr in prs" :key="pr.number">
          <td class="fw-bold">
            <a :href="pr.url" target="_blank" rel="noopener" class="text-decoration-none">
              #{{ pr.number }}
            </a>
          </td>
          <td class="small" style="max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
            {{ pr.title }}
          </td>
          <td>
            <div class="d-flex flex-column gap-1">
              <span :class="['badge', combinedStatusClass(pr)]">
                {{ combinedStatusLabel(pr) }}
              </span>
              <div v-if="pr.ciStatus === 'red' && pr.failedChecks.length > 0" class="small text-danger lh-sm">
                <div v-for="check in pr.failedChecks" :key="check" class="text-truncate" style="max-width: 110px;" :title="check">
                  {{ check }}
                </div>
              </div>
            </div>
          </td>
          <td class="small text-muted">
            {{ formatAge(pr.createdAt) }}
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup lang="ts">
import { defineProps } from 'vue'
import type { PrLive } from '../types'

defineProps<{
  prs: PrLive[]
  loading: boolean
  lastRefreshed: string | null
}>()

const emit = defineEmits<{
  refresh: []
}>()

// Single combined status: what matters right now?
// Running → nothing else matters yet
// Failed → CI fix needed
// Needs rebase → CI passed but conflict (FSM handles)
// Ready → good to merge
type CombinedStatus = 'running' | 'failed' | 'needs-rebase' | 'needs-review' | 'ready'

function combinedStatus(pr: PrLive): CombinedStatus {
  if (pr.mergeStateStatus === 'UNSTABLE' || pr.ciStatus === 'pending' || pr.ciStatus === 'unknown') return 'running'
  if (pr.ciStatus === 'red') return 'failed'
  if (pr.mergeStateStatus === 'DIRTY') return 'needs-rebase'
  if (pr.mergeStateStatus === 'BLOCKED') return 'needs-review'
  if (pr.mergeStateStatus === 'CLEAN' || pr.mergeStateStatus === 'HAS_HOOKS') return 'ready'
  return 'running'
}

function combinedStatusClass(pr: PrLive): string {
  const s = combinedStatus(pr)
  if (s === 'running') return 'bg-secondary'
  if (s === 'failed') return 'bg-danger'
  if (s === 'ready') return 'bg-success'
  return 'bg-warning text-dark' // needs-rebase / needs-review
}

function combinedStatusLabel(pr: PrLive): string {
  switch (combinedStatus(pr)) {
    case 'running': return 'CI running'
    case 'failed': return 'CI failed'
    case 'needs-rebase': return 'Needs rebase'
    case 'needs-review': return 'Needs review'
    case 'ready': return 'Ready'
  }
}

function formatAge(date: string): string {
  const now = new Date()
  const created = new Date(date)
  const seconds = Math.floor((now.getTime() - created.getTime()) / 1000)

  if (seconds < 60) return 'now'
  const minutes = Math.floor(seconds / 60)
  if (minutes < 60) return `${minutes}m`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `${hours}h`
  const days = Math.floor(hours / 24)
  return `${days}d`
}

function refresh() {
  emit('refresh')
}
</script>

<style scoped>
.pr-panel {
  background: white;
  padding: 1rem;
  border-radius: 0.25rem;
  border: 1px solid #dee2e6;
}

.table {
  margin-bottom: 0;
  font-size: 0.875rem;
}

.spin {
  animation: spin 1s linear infinite;
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}
</style>
