<template>
  <div class="helper-status" :class="'helper-status--' + status" data-testid="helper-status">
    <div class="helper-status__left">
      <v-icon icon="robot" class="me-2" />
      <span class="helper-status__title">Freegle Helper</span>
      <b-badge :variant="statusVariant" class="ms-2" data-testid="helper-status-badge">
        <b-spinner v-if="pausing" small class="me-1" data-testid="pausing-spinner" />
        {{ statusLabel }}
      </b-badge>
      <span v-if="lastrun" class="helper-status__lastrun small text-muted ms-2">
        last checked {{ lastrun }}
      </span>
    </div>
    <div class="helper-status__actions">
      <b-button
        v-if="status === 'active'"
        variant="outline-secondary"
        size="sm"
        data-testid="helper-pause"
        @click="$emit('pause')"
      >
        <v-icon icon="pause" class="me-1" />Pause &amp; step in
      </b-button>
      <b-button
        v-else-if="status === 'paused'"
        variant="success"
        size="sm"
        data-testid="helper-resume"
        @click="$emit('resume')"
      >
        <v-icon icon="play" class="me-1" />Resume Helper
      </b-button>
      <span v-else class="small text-muted">Helper stopped</span>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  // The helper batch row, or null when the Helper hasn't been started.
  batch: { type: Object, default: null },
})

defineEmits(['pause', 'resume'])

const status = computed(() => props.batch?.status || 'inactive')

// A pause is only CONFIRMED once the driver's heartbeat (lastpolledat) has advanced
// past the moment we paused (pausedat) — i.e. the background loop has observed the
// pause and gone idle. Until then we're "Pausing…". If there's no heartbeat at all
// (lastpolledat null) there's no running driver to stop, so treat it as confirmed.
const pauseConfirmed = computed(() => {
  if (status.value !== 'paused') return false
  const paused = props.batch?.pausedat
  const polled = props.batch?.lastpolledat
  if (!polled) return true
  if (!paused) return true
  return new Date(polled).getTime() >= new Date(paused).getTime()
})

const pausing = computed(() => status.value === 'paused' && !pauseConfirmed.value)

const statusLabel = computed(() => {
  if (pausing.value) return 'Pausing… (Helper is finishing its current task)'
  switch (status.value) {
    case 'active':
      return 'Working on your replies'
    case 'paused':
      return 'Paused — Helper has stopped, you’re in control'
    case 'stopped':
      return 'Stopped'
    default:
      return 'Not started'
  }
})

const statusVariant = computed(() => {
  if (pausing.value) return 'warning'
  switch (status.value) {
    case 'active':
      return 'success'
    case 'paused':
      return 'secondary'
    default:
      return 'secondary'
  }
})

// Human-friendly "last checked" — the API gives an ISO timestamp.
const lastrun = computed(() => {
  const t = props.batch?.lastrunat
  if (!t) return ''
  const d = new Date(t)
  if (isNaN(d.getTime())) return ''
  return d.toLocaleString()
})

defineExpose({ status, statusLabel, statusVariant, lastrun, pausing, pauseConfirmed })
</script>

<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'assets/css/_color-vars.scss';

.helper-status {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 0.5rem;
  padding: 0.5rem 0.75rem;
  border: 1px solid $color-gray--lighter;
  border-radius: 6px;
  margin-bottom: 0.75rem;
  background-color: $color-gray--lighter;
}

.helper-status--paused {
  background-color: lighten(#ffc107, 35%);
}

.helper-status__left {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.25rem;
}

.helper-status__title {
  font-weight: 700;
}
</style>
