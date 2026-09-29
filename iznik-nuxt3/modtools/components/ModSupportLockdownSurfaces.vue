<template>
  <div>
    <h4>Areas</h4>
    <div
      v-for="area in LOCKDOWN_AREAS"
      :key="area.key"
      class="lockdown-area d-flex align-items-start gap-3 py-2 border-bottom"
      :data-testid="'lockdown-surface-' + area.key"
    >
      <div class="flex-grow-1">
        <div class="fw-bold">
          {{ area.label }}
          <b-badge
            :variant="surfaces[area.key] ? 'danger' : 'success'"
            class="ms-2"
            :data-testid="'lockdown-surface-status-' + area.key"
          >
            {{ surfaces[area.key] ? 'Held' : 'Running' }}
          </b-badge>
        </div>
        <div class="small text-muted">{{ area.description }}</div>
      </div>
      <b-button
        :variant="surfaces[area.key] ? 'primary' : 'outline-danger'"
        size="sm"
        class="text-nowrap"
        :disabled="busy"
        :data-testid="'lockdown-surface-button-' + area.key"
        @click="emit('set-surface', area.key, !surfaces[area.key])"
      >
        {{ surfaces[area.key] ? 'Lift' : 'Hold again' }}
      </b-button>
    </div>
  </div>
</template>

<script setup>
import { LOCKDOWN_AREAS } from '~/modtools/utils/lockdownAreas'

// plans/active/2026-09-27-lockdown-switch.md section 11.11: one row per area,
// in lifting order, saying exactly what is held and what still works, its
// status in words, and one button. Lifting an area releases everything it
// held straight away, through the ordinary checks.
defineProps({
  surfaces: {
    type: Object,
    default: () => ({}),
  },
  busy: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['set-surface'])
</script>
