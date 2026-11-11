<template>
  <div data-testid="lockdown-release">
    <h4>Releasing</h4>
    <p
      v-if="caughtUp"
      class="text-success fw-bold"
      data-testid="lockdown-release-done"
    >
      Caught up: everything held has gone through the usual checks.
    </p>
    <p v-else class="small text-muted">
      What was held goes through the usual checks, oldest first. This fills up
      as it catches up.
    </p>
    <div
      v-for="row in rows"
      :key="row.key"
      class="mb-2"
      :data-testid="'lockdown-release-' + row.key"
    >
      <div>
        {{ row.label }}:
        <span :data-testid="'lockdown-release-progress-' + row.key">
          {{ row.done }} of {{ row.total }} gone through
        </span>
      </div>
      <b-progress
        :value="row.done"
        :max="row.total || 1"
        :variant="row.done === row.total ? 'success' : 'primary'"
        height="0.5rem"
        style="max-width: 30rem"
      />
    </div>
    <p v-if="showEmail" class="mt-2" data-testid="lockdown-release-email">
      Emails waiting to send: <strong>{{ emailQueued }}</strong>
      <span class="small text-muted">
        (all mail, not only what the lockdown held; updated every minute)
      </span>
    </p>
  </div>
</template>

<script setup>
import { computed } from 'vue'

// plans/active/2026-09-27-lockdown-switch.md section 11.11: once an area is
// lifted, what matters is when its backlog has caught up. An area that held
// nothing has nothing to show. From stats.release
// (per kind: held, and what became of the rest) and stats.queue.email. Only
// lifted areas are shown; with no surfaces given (after a close) every area
// counts as lifted.
const props = defineProps({
  stats: {
    type: Object,
    default: null,
  },
  surfaces: {
    type: Object,
    default: null,
  },
})

const KINDS = [
  { key: 'chat', surface: 'chat', label: 'Chat messages' },
  { key: 'post', surface: 'posts', label: 'Posts' },
  { key: 'chitchat', surface: 'chitchat', label: 'ChitChat posts' },
]

const lifted = (surface) => !props.surfaces || !props.surfaces[surface]

const rows = computed(() =>
  KINDS.filter((k) => lifted(k.surface))
    .map((k) => {
      const r = props.stats?.release?.[k.key] ?? {}
      const held = r.held ?? 0
      const total =
        held +
        (r.released ?? 0) +
        (r.rejected ?? 0) +
        (r.review ?? 0) +
        (r.gone ?? 0)
      return { ...k, total, done: total - held }
    })
    .filter((r) => r.total > 0)
)

const showEmail = computed(() => lifted('email'))

const emailQueued = computed(() => props.stats?.queue?.email ?? 0)

const caughtUp = computed(() => rows.value.every((r) => r.done === r.total))
</script>
