<template>
  <div>
    <h4>History</h4>
    <p v-if="!history?.length" class="text-muted">No previous lockdowns.</p>
    <table v-else class="table table-sm">
      <thead>
        <tr>
          <th>Reason</th>
          <th>Started</th>
          <th>Ended</th>
          <th>End note</th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="h in history"
          :key="h.incidentid"
          data-testid="lockdown-history-row"
        >
          <td>{{ h.reason }}</td>
          <td>#{{ h.startedby }}, {{ dateshort(h.startedat) }}</td>
          <td>{{ h.endedbyname }}, {{ dateshort(h.endedat) }}</td>
          <td>{{ h.endnote || '-' }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup>
import { dateshort } from '~/composables/useTimeFormat'

// plans/active/2026-09-27-lockdown-switch.md section 10.9/10.10/11.2: the
// last 50 rows from GET /modtools/lockdown/history, newest first (the API's
// job to order - this just renders what it's given).
defineProps({
  history: {
    type: Array,
    default: () => [],
  },
})
</script>
