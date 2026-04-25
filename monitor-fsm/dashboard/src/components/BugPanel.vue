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
        </tr>
      </thead>
      <tbody>
        <template v-for="(group, featureArea) in groupedBugs" :key="featureArea">
          <tr class="table-light">
            <td colspan="4" class="text-muted fst-italic small">
              {{ featureArea }}
            </td>
          </tr>
          <tr v-for="bug in group" :key="`${bug.topic}-${bug.post}`">
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
              <span :title="bug.excerpt || bug.topic_title || ''">
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
  props.bugs.filter(bug => ['open', 'investigating', 'fix-queued'].includes(bug.state))
)

const groupedBugs = computed(() => {
  const groups: Record<string, BugRow[]> = {}
  for (const bug of activeBugs.value) {
    const area = (bug as any).group_key || bug.feature_area || 'Uncategorised'
    if (!groups[area]) groups[area] = []
    groups[area].push(bug)
  }
  return groups
})

function truncate(text: string | null, length: number): string {
  if (!text) return '—'
  return text.length > length ? text.substring(0, length) + '...' : text
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

.spin {
  animation: spin 1s linear infinite;
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}
</style>
