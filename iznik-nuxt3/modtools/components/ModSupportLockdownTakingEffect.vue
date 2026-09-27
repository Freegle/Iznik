<template>
  <div data-testid="lockdown-taking-effect">
    <h5>Taking effect</h5>
    <p data-testid="lockdown-taking-effect-api">
      <v-icon icon="check" class="text-success" /> API: within
      {{ apiDelaySeconds }} seconds
    </p>
    <p
      v-for="loop in loops"
      :key="loop.key"
      :data-testid="'lockdown-taking-effect-' + loop.key"
      :class="{ 'text-warning': isStale(loop) }"
    >
      <template v-if="loop.ack?.caughtup">
        <v-icon icon="check" class="text-success" /> {{ loop.label }}: took
        {{ loop.ack.seconds }} seconds
      </template>
      <template v-else-if="isStale(loop)">
        <v-icon icon="triangle-exclamation" /> {{ loop.label }}: Has not picked
        this up. The loop may be stopped or stuck. Check the batch host.
      </template>
      <template v-else>
        <b-spinner small /> {{ loop.label }}: waiting
      </template>
    </p>
    <p
      data-testid="lockdown-leaked"
      :class="{ 'text-danger fw-bold': leakedTotal > 0 }"
    >
      Sent since the press: {{ leakedTotal }}
    </p>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'

// plans/active/2026-09-27-lockdown-switch.md section 11.6: shown while the
// presser is watching a press (or any later change) take effect. API applies
// the hold in the request path itself, so it's shown as a fact, not a
// live-polled status; each batch loop's line comes from stats.acks, keyed by
// loop name, and turns amber if the change is more than two minutes old and
// still has no ack - measured against a locally ticking clock so the amber
// state can appear between stats polls, not only when a new poll lands.
const props = defineProps({
  stats: {
    type: Object,
    required: false,
    default: null,
  },
})

// Order matches plan section 11.6's loop list.
const loopDefs = [
  { key: 'chat-process', label: 'Chat processing' },
  { key: 'content-check', label: 'Content check' },
  { key: 'auto-approve', label: 'Auto-approve' },
  { key: 'mail-spool', label: 'Mail spool' },
  { key: 'mail-loops', label: 'Mail loops' },
  { key: 'background-tasks', label: 'Background tasks' },
  { key: 'push', label: 'Push' },
  { key: 'triage', label: 'Triage' },
]

const apiDelaySeconds = computed(() => props.stats?.api?.delayseconds ?? 5)

function ackFor(key) {
  return (props.stats?.acks ?? []).find((a) => a.loop === key) ?? null
}

const loops = computed(() =>
  loopDefs.map((l) => ({ ...l, ack: ackFor(l.key) }))
)

// Ticks every second purely so a loop can flip to amber live, without
// waiting for the next stats poll.
const now = ref(Date.now())
let clock = null
onMounted(() => {
  clock = setInterval(() => {
    now.value = Date.now()
  }, 1000)
})
onUnmounted(() => {
  if (clock) {
    clearInterval(clock)
    clock = null
  }
})

function isStale(loop) {
  if (loop.ack?.caughtup) return false
  const changedat = props.stats?.changedat
  if (!changedat) return false
  const changedMs = new Date(changedat).getTime()
  if (Number.isNaN(changedMs)) return false
  return now.value - changedMs > 120000
}

const leaked = computed(() => props.stats?.leaked ?? {})
const leakedTotal = computed(() =>
  Object.values(leaked.value).reduce(
    (sum, v) => sum + (typeof v === 'number' ? v : 0),
    0
  )
)
</script>
