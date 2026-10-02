<template>
  <div>
    <h4>History</h4>
    <p v-if="!history?.length" class="text-muted">No lockdowns yet.</p>
    <table v-else class="table table-sm">
      <thead>
        <tr>
          <th>When</th>
          <th>What happened</th>
          <th>By</th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="row in rows"
          :key="row.id"
          data-testid="lockdown-history-row"
        >
          <td class="text-nowrap">{{ dateshort(row.created) }}</td>
          <td>{{ row.what }}</td>
          <td>{{ row.by }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { dateshort } from '~/composables/useTimeFormat'
import { LOCKDOWN_AREAS } from '~/modtools/utils/lockdownAreas'

// plans/active/2026-09-27-lockdown-switch.md section 11.11: the last 50 rows
// from GET /modtools/lockdown/history, newest first, each said in words.
// lockdowns is append-only, so what a row changed is the difference from the
// row before it in the same lockdown.
const props = defineProps({
  history: {
    type: Array,
    default: () => [],
  },
})

function labels(keys) {
  return LOCKDOWN_AREAS.filter((a) => keys.includes(a.key))
    .map((a) => a.label)
    .join(', ')
}

function describe(row, prev) {
  // A notice set after closing is another inactive row, so only the first
  // inactive row is the close itself.
  if (!row.active && (!prev || prev.active)) {
    return 'Closed' + (row.endnote ? ': ' + row.endnote : '')
  }
  if (row.active && (!prev || row.id === row.incidentid)) {
    return 'Pressed' + (row.reason ? ': ' + row.reason : '')
  }

  const now = row.surfaces ?? {}
  const before = prev.surfaces ?? {}
  const keys = LOCKDOWN_AREAS.map((a) => a.key)
  const lifted = keys.filter((k) => before[k] && !now[k])
  const held = keys.filter((k) => !before[k] && now[k])
  const parts = []
  if (lifted.length) parts.push('Lifted ' + labels(lifted))
  if (held.length) parts.push('Held again ' + labels(held))
  if ((row.notice || '') !== (prev.notice || '')) {
    parts.push(row.notice ? 'Member notice set' : 'Member notice removed')
  }
  return parts.length ? parts.join('; ') : 'No change'
}

const rows = computed(() =>
  (props.history ?? []).map((row, i) => {
    // Newest first, so the row before this one is the next in the list - but
    // only if it belongs to the same lockdown.
    const older = props.history[i + 1]
    const prev = older && older.incidentid === row.incidentid ? older : null
    return {
      id: row.id,
      created: row.created,
      what: describe(row, prev),
      by: (!row.active && row.endedbyname) || row.changedbyname || '-',
    }
  })
)
</script>
