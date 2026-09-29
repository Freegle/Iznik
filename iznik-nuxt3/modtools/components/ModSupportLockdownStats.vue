<template>
  <div data-testid="lockdown-stats">
    <h4>Held so far</h4>
    <table class="table table-sm w-auto">
      <tbody>
        <tr
          v-for="row in rows"
          :key="row.key"
          :data-testid="'lockdown-count-' + row.key"
        >
          <td>{{ row.label }}</td>
          <td class="text-end">{{ row.count }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup>
import { computed } from 'vue'

// plans/active/2026-09-27-lockdown-switch.md section 11.11: for each kind of
// thing held, how many so far this lockdown. Nothing else - what got out
// after the press is shown with "Taking effect".
const props = defineProps({
  stats: {
    type: Object,
    default: null,
  },
})

const LABELS = [
  { key: 'chat', label: 'Chat messages' },
  { key: 'post', label: 'Posts' },
  { key: 'chitchat', label: 'ChitChat posts' },
  { key: 'events', label: 'Events, volunteering, noticeboards and stories' },
  { key: 'email', label: 'Emails not sent' },
  { key: 'push', label: 'App notifications not sent' },
  { key: 'export', label: 'Downloads refused' },
  { key: 'refused', label: 'Moderator and member actions refused' },
]

const rows = computed(() =>
  LABELS.map((l) => ({ ...l, count: props.stats?.counts?.[l.key] ?? 0 }))
)
</script>
