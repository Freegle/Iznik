<template>
  <div class="bug-panel">
    <div class="d-flex align-items-center justify-content-between mb-3">
      <h5 class="mb-0">
        Bugs
        <span class="badge bg-secondary ms-2">{{ activeBugs.length }}</span>
      </h5>
      <button class="btn btn-outline-secondary btn-sm" @click="refresh" :disabled="loading">
        <i class="bi bi-arrow-clockwise" :class="{ 'spin': loading }"></i>
      </button>
    </div>

    <div v-if="loading" class="spinner-border spinner-border-sm" role="status">
      <span class="visually-hidden">Loading...</span>
    </div>

    <div v-else-if="activeBugs.length === 0" class="alert alert-info small">
      No active bugs.
    </div>

    <table v-else class="table table-sm table-hover">
      <thead>
        <tr>
          <th style="width: 100px;">Reporter</th>
          <th>Summary</th>
          <th style="width: 80px;">State</th>
          <th style="width: 50px;">PR</th>
          <th v-if="hasHumanBugs" style="width: 130px;"></th>
        </tr>
      </thead>
      <tbody>
        <template v-for="(group, featureArea) in groupedBugs" :key="featureArea">
          <tr class="table-light">
            <td :colspan="hasHumanBugs ? 5 : 4" class="text-muted fst-italic small">
              {{ featureArea }}
            </td>
          </tr>
          <tr v-for="bug in group" :key="`${bug.topic}-${bug.post}`" :class="{ 'table-warning': bug.state === 'deferred' }">
            <td>
              <a
                :href="`https://discourse.ilovefreegle.org/t/${bug.topic}/${bug.post}`"
                target="_blank"
                rel="noopener"
                class="text-decoration-none"
              >
                {{ bug.reporter || 'Unknown' }}
              </a>
            </td>
            <td style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 0; width: 100%;">
              <span :title="bug.state === 'deferred' && bug.reason ? bug.reason : (bug.excerpt || bug.topic_title || '')">
                {{ bug.excerpt || bug.topic_title || '—' }}
              </span>
            </td>
            <td>
              <StateBadge :state="bug.state" />
            </td>
            <td>
              <a
                v-if="bug.pr_number"
                :href="`https://github.com/Freegle/Iznik/pull/${bug.pr_number}`"
                target="_blank"
                rel="noopener"
                class="text-decoration-none"
              >
                #{{ bug.pr_number }}
              </a>
              <span v-else class="text-muted small">—</span>
            </td>
            <td v-if="hasHumanBugs">
              <template v-if="bug.state === 'deferred'">
                <button
                  class="btn btn-outline-secondary btn-xs me-1"
                  title="Dismiss — remove from active bugs"
                  @click="dismiss(bug)"
                >✕</button>
                <button
                  class="btn btn-outline-primary btn-xs"
                  title="Link a PR — marks bug as fix-queued"
                  @click="promptLinkPr(bug)"
                >PR#</button>
              </template>
            </td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { BugRow } from '../types'
import StateBadge from './StateBadge.vue'

const props = defineProps<{
  bugs: BugRow[]
  loading: boolean
}>()

const emit = defineEmits<{
  refresh: []
}>()

const activeBugs = computed(() =>
  props.bugs.filter(bug => ['open', 'investigating', 'fix-queued', 'deferred'].includes(bug.state))
)

const hasHumanBugs = computed(() => activeBugs.value.some(b => b.state === 'deferred'))

const groupedBugs = computed(() => {
  const groups: Record<string, BugRow[]> = {}
  for (const bug of activeBugs.value) {
    const area = (bug as any).group_key || bug.feature_area || 'Uncategorised'
    if (!groups[area]) groups[area] = []
    groups[area].push(bug)
  }
  return groups
})

async function dismiss(bug: BugRow) {
  await fetch(`/api/bugs/${bug.topic}/${bug.post}/state`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ state: 'off-topic', reason: 'Dismissed by human' }),
  })
  emit('refresh')
}

async function promptLinkPr(bug: BugRow) {
  const input = window.prompt(`Link PR to bug ${bug.topic}/${bug.post}:\nEnter PR number:`)
  if (!input) return
  const prNumber = parseInt(input.trim(), 10)
  if (isNaN(prNumber) || prNumber <= 0) { alert('Invalid PR number'); return }
  await fetch(`/api/bugs/${bug.topic}/${bug.post}/link-pr`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ prNumber }),
  })
  emit('refresh')
}

function refresh() {
  emit('refresh')
}
</script>

<style scoped>
.bug-panel {
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
