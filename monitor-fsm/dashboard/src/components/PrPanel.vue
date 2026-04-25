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
          <th style="width: 60px;">PR</th>
          <th>Title</th>
          <th style="width: 100px;">CI Status</th>
          <th style="width: 90px;">Merge State</th>
          <th style="width: 70px;">Age</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="pr in prs" :key="pr.number">
          <td class="fw-bold">
            <a :href="pr.url" target="_blank" rel="noopener" class="text-decoration-none">
              #{{ pr.number }}
            </a>
          </td>
          <td class="small" style="max-width: 250px; overflow: hidden; text-overflow: ellipsis;">
            {{ pr.title }}
          </td>
          <td>
            <div class="d-flex flex-column gap-1">
              <span :class="['badge', ciStatusBadgeClass(pr.ciStatus)]">
                <i :class="ciStatusIcon(pr.ciStatus)" class="me-1"></i>
                {{ pr.ciStatus }}
              </span>
              <div v-if="pr.failedChecks.length > 0" class="small text-danger">
                <div v-for="check in pr.failedChecks" :key="check" class="text-truncate">
                  {{ check }}
                </div>
              </div>
            </div>
          </td>
          <td>
            <span :class="['badge', mergeStateBadgeClass(pr.mergeStateStatus)]">
              {{ mergeStateLabel(pr.mergeStateStatus) }}
            </span>
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

function ciStatusBadgeClass(status: string): string {
  switch (status) {
    case 'green': return 'bg-success'
    case 'red': return 'bg-danger'
    case 'pending': return 'bg-warning text-dark'
    default: return 'bg-secondary'
  }
}

function ciStatusIcon(status: string): string {
  switch (status) {
    case 'green': return 'bi bi-check-circle'
    case 'red': return 'bi bi-x-circle'
    case 'pending': return 'bi bi-hourglass-split'
    default: return 'bi bi-question-circle'
  }
}

function mergeStateBadgeClass(status: string): string {
  switch (status) {
    case 'CLEAN': case 'HAS_HOOKS': return 'bg-success'
    case 'DIRTY': return 'bg-danger'
    case 'BLOCKED': return 'bg-warning text-dark'
    case 'BEHIND': case 'UNSTABLE': return 'bg-info text-dark'
    default: return 'bg-secondary'
  }
}

function mergeStateLabel(status: string): string {
  switch (status) {
    case 'CLEAN': case 'HAS_HOOKS': return 'Ready'
    case 'DIRTY': return 'Conflict'
    case 'BLOCKED': return 'Blocked'
    case 'BEHIND': return 'Behind'
    case 'UNSTABLE': return 'CI running'
    case 'UNKNOWN': return '?'
    default: return status
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
