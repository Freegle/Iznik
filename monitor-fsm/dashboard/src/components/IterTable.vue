<template>
  <div class="iter-table">
    <div v-if="loading" class="spinner-border" role="status">
      <span class="visually-hidden">Loading...</span>
    </div>

    <table v-else class="table table-sm">
      <thead>
        <tr>
          <th>ID</th>
          <th>Started</th>
          <th>Duration</th>
          <th>Outcome</th>
          <th>Steps</th>
          <th>PRs Created</th>
          <th>Note</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="iter in sortedIterations" :key="iter.id">
          <td><strong>#{{ iter.id }}</strong></td>
          <td>{{ formatDateTime(iter.started_at) }}</td>
          <td>{{ formatDuration(iter.started_at, iter.ended_at) }}</td>
          <td>
            <OutcomeBadge :outcome="iter.outcome" :is-running="!iter.ended_at" />
          </td>
          <td>{{ iter.steps_used !== null ? iter.steps_used : '—' }}</td>
          <td>{{ iter.prs_created !== null ? iter.prs_created : '—' }}</td>
          <td class="note-cell">{{ iter.note || '—' }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { IterRow } from '../types'
import OutcomeBadge from './OutcomeBadge.vue'

interface Props {
  iterations: IterRow[]
  loading: boolean
}

const props = defineProps<Props>()

const formatDateTime = (dateString: string): string => {
  const date = new Date(dateString)
  return date.toLocaleDateString('en-GB') + ' ' + date.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
}

const formatDuration = (startStr: string, endStr: string | null): string => {
  const start = new Date(startStr)
  const end = endStr ? new Date(endStr) : new Date()
  const ms = end.getTime() - start.getTime()
  const totalSeconds = Math.floor(ms / 1000)
  const hours = Math.floor(totalSeconds / 3600)
  const minutes = Math.floor((totalSeconds % 3600) / 60)
  const seconds = totalSeconds % 60

  if (hours > 0) {
    return `${hours}h ${minutes}m ${seconds}s`
  } else if (minutes > 0) {
    return `${minutes}m ${seconds}s`
  } else {
    return `${seconds}s`
  }
}

const sortedIterations = computed(() => {
  return [...props.iterations].sort((a, b) => b.id - a.id).slice(0, 20)
})
</script>

<style scoped>
.note-cell {
  max-width: 250px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
