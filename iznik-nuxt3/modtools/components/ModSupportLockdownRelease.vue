<template>
  <div data-testid="lockdown-release">
    <h4>Releasing</h4>
    <p class="small text-muted">
      When an area is lifted, what it held goes through the usual checks
      straight away. Watch "Still held" count down to nothing.
    </p>
    <table class="table table-sm w-auto">
      <thead>
        <tr>
          <th></th>
          <th class="text-end">Still held</th>
          <th class="text-end">Released</th>
          <th class="text-end">Dropped by the usual checks</th>
          <th class="text-end">Waiting for a moderator</th>
          <th class="text-end">Already gone</th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="row in rows"
          :key="row.key"
          :data-testid="'lockdown-release-' + row.key"
        >
          <td>{{ row.label }}</td>
          <td
            class="text-end"
            :class="{ 'fw-bold': row.held > 0 }"
            :data-testid="'lockdown-release-held-' + row.key"
          >
            {{ row.held }}
          </td>
          <td class="text-end">{{ row.released }}</td>
          <td class="text-end">{{ row.rejected }}</td>
          <td class="text-end">{{ row.review }}</td>
          <td class="text-end">{{ row.gone }}</td>
        </tr>
      </tbody>
    </table>
    <p data-testid="lockdown-release-email">
      Emails waiting to send: <strong>{{ emailQueued }}</strong>
      <span class="small text-muted">
        (the whole send queue, updated every minute)
      </span>
    </p>
    <p
      v-if="drained"
      class="text-success fw-bold"
      data-testid="lockdown-release-done"
    >
      Nothing is still held, and the send queue is empty.
    </p>
  </div>
</template>

<script setup>
import { computed } from 'vue'

// plans/active/2026-09-27-lockdown-switch.md section 11.11: watching what was
// held drain, while the lockdown is on and after it closes. From stats.release
// (per kind: held, released, rejected, review, gone) and stats.queue.email.
const props = defineProps({
  stats: {
    type: Object,
    default: null,
  },
})

const KINDS = [
  { key: 'chat', label: 'Chat messages' },
  { key: 'post', label: 'Posts' },
  { key: 'chitchat', label: 'ChitChat posts' },
]

const rows = computed(() =>
  KINDS.map((k) => {
    const r = props.stats?.release?.[k.key] ?? {}
    return {
      ...k,
      held: r.held ?? 0,
      released: r.released ?? 0,
      rejected: r.rejected ?? 0,
      review: r.review ?? 0,
      gone: r.gone ?? 0,
    }
  })
)

const emailQueued = computed(() => props.stats?.queue?.email ?? 0)

const drained = computed(
  () => rows.value.every((r) => r.held === 0) && emailQueued.value === 0
)
</script>
