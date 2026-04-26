<template>
  <div class="bug-table">
    <div v-if="loading" class="spinner-border" role="status">
      <span class="visually-hidden">Loading...</span>
    </div>

    <table v-else class="table table-sm">
      <thead>
        <tr>
          <th>Reporter</th>
          <th>Excerpt</th>
          <th>State</th>
          <th>PR</th>
          <th>First Seen</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <template v-for="(group, featureArea) in groupedBugs" :key="featureArea">
          <tr class="table-light">
            <th :colspan="6" class="text-muted fst-italic">{{ featureArea }}</th>
          </tr>
          <tr v-for="bug in group" :key="`${bug.topic}-${bug.post}`">
            <td>
              <a
                :href="`https://discourse.ilovefreegle.org/t/${bug.topic}/${bug.post}`"
                target="_blank"
                rel="noopener"
              >
                {{ bug.reporter || 'Unknown' }}
              </a>
            </td>
            <td class="excerpt-cell">{{ truncate(bug.excerpt, 120) }}</td>
            <td>
              <StateBadge :state="bug.state" />
            </td>
            <td>
              <a
                v-if="bug.pr_number"
                :href="`https://github.com/Freegle/Iznik/pull/${bug.pr_number}`"
                target="_blank"
                rel="noopener"
              >
                #{{ bug.pr_number }}
              </a>
              <span v-else class="text-muted">—</span>
            </td>
            <td>{{ formatDate(bug.first_seen_at) }}</td>
            <td>
              <div class="btn-group btn-group-sm" role="group">
                <button
                  type="button"
                  class="btn btn-outline-secondary dropdown-toggle"
                  data-bs-toggle="dropdown"
                  aria-expanded="false"
                >
                  Set state
                </button>
                <ul class="dropdown-menu">
                  <li>
                    <a
                      href="#"
                      class="dropdown-item"
                      @click.prevent="setBugState(bug, 'open')"
                    >
                      Open
                    </a>
                  </li>
                  <li>
                    <a
                      href="#"
                      class="dropdown-item"
                      @click.prevent="setBugState(bug, 'investigating')"
                    >
                      Investigating
                    </a>
                  </li>
                  <li>
                    <a
                      href="#"
                      class="dropdown-item"
                      @click.prevent="setBugState(bug, 'fix-queued')"
                    >
                      Fix-Queued
                    </a>
                  </li>
                  <li>
                    <a
                      href="#"
                      class="dropdown-item"
                      @click.prevent="setBugState(bug, 'deferred')"
                    >
                      Deferred
                    </a>
                  </li>
                  <li>
                    <a
                      href="#"
                      class="dropdown-item"
                      @click.prevent="setBugState(bug, 'fixed')"
                    >
                      Fixed
                    </a>
                  </li>
                </ul>
              </div>
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
import { setBugState as apiSetBugState } from '../composables/useApi'
import StateBadge from './StateBadge.vue'

interface Props {
  bugs: BugRow[]
  loading: boolean
}

const props = defineProps<Props>()
const emit = defineEmits<{ (e: 'bug-state-change'): void }>()

const truncate = (text: string | null, length: number): string => {
  if (!text) return '—'
  return text.length > length ? text.substring(0, length) + '...' : text
}

const formatDate = (dateString: string): string => {
  const date = new Date(dateString)
  return date.toLocaleDateString('en-GB') + ' ' + date.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
}

const filteredBugs = computed(() => {
  const now = new Date()
  const sevenDaysAgo = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000)

  return props.bugs.filter((bug) => {
    // Show open/investigating/fix-queued
    if (['open', 'investigating', 'fix-queued'].includes(bug.state)) return true
    // Show recently fixed (last 7 days)
    if (bug.state === 'fixed' && bug.fixed_at) {
      const fixedDate = new Date(bug.fixed_at)
      return fixedDate >= sevenDaysAgo
    }
    return false
  })
})

const groupedBugs = computed(() => {
  const groups: Record<string, BugRow[]> = {}

  filteredBugs.value.forEach((bug) => {
    const area = bug.feature_area || 'Uncategorised'
    if (!groups[area]) {
      groups[area] = []
    }
    groups[area].push(bug)
  })

  // Sort groups: put Uncategorised at the end
  const sorted: Record<string, BugRow[]> = {}
  Object.keys(groups)
    .sort((a, b) => {
      if (a === 'Uncategorised') return 1
      if (b === 'Uncategorised') return -1
      return a.localeCompare(b)
    })
    .forEach((key) => {
      sorted[key] = groups[key]
    })

  return sorted
})

const setBugState = async (bug: BugRow, state: string) => {
  try {
    await apiSetBugState(bug.topic, bug.post, state)
    emit('bug-state-change')
  } catch (error) {
    console.error('Failed to set bug state:', error)
    alert('Failed to set bug state')
  }
}
</script>

<style scoped>
.excerpt-cell {
  max-width: 300px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
