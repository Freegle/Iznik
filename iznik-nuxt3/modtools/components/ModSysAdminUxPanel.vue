<template>
  <section class="ux-panel" :class="collapsible ? 'mb-2' : 'mb-4'">
    <h4 v-if="collapsible" class="ux-panel-heading mb-1">
      <button
        type="button"
        class="ux-panel-toggle"
        :aria-expanded="isOpen ? 'true' : 'false'"
        :aria-controls="bodyId"
        @click="emit('toggle')"
      >
        {{ title }}
      </button>
    </h4>
    <h4 v-else>{{ title }}</h4>
    <!-- Notes say what the server did with the filters. They stay when the panel is closed, since a
         closed line such as "Least used" would otherwise read as filtered when it is not. -->
    <p v-for="(note, i) in notes" :key="i" class="ux-note small mb-1">
      {{ note }}
    </p>
    <div :id="bodyId">
      <template v-if="isOpen">
        <p class="text-muted">{{ explanation }}</p>
        <div v-if="loading" class="text-center py-3">
          <b-spinner small class="me-2" />
          Loading...
        </div>
        <NoticeMessage v-else-if="error" variant="danger" class="mb-3">
          {{ error }}
        </NoticeMessage>
        <div v-else-if="missing" class="text-muted py-3">
          {{ NOT_SWITCHED_ON }}
        </div>
        <div v-else-if="empty" class="text-muted py-3">
          {{ emptyText }}
        </div>
        <slot v-else />
      </template>
      <p v-else class="ux-summary text-muted mb-0">{{ closedLine }}</p>
    </div>
  </section>
</template>

<script setup>
import { computed, useId } from 'vue'
import { NOT_SWITCHED_ON } from '~/modtools/composables/useUxSlot'

const props = defineProps({
  title: { type: String, required: true },
  explanation: { type: String, required: true },
  loading: { type: Boolean, default: false },
  error: { type: String, default: null },
  // The server has no route for this yet (HTTP 404 or 501). Not a fault.
  missing: { type: Boolean, default: false },
  empty: { type: Boolean, default: false },
  emptyText: { type: String, default: 'No data for the selected period.' },
  // A panel that opens and closes: a heading button, and one line while it is closed.
  collapsible: { type: Boolean, default: false },
  // The closed line. While loading it reads "Loading...", and when empty it reads the empty text.
  summary: { type: String, default: '' },
  // Held open whatever the person chose (Data health, while a check fails).
  forceOpen: { type: Boolean, default: false },
  // Plain sentences under the heading, open or closed.
  notes: { type: Array, default: () => [] },
  // Whether the person opened it. The parent keeps this, because it lives in the address.
  open: { type: Boolean, default: false },
})

const emit = defineEmits(['toggle'])

const bodyId = `ux-panel-${useId()}`

// A failed panel always shows why, open or not.
const isOpen = computed(
  () => !props.collapsible || props.open || props.forceOpen || !!props.error
)

const closedLine = computed(() => {
  if (props.loading) return 'Loading...'
  if (props.missing) return NOT_SWITCHED_ON
  if (props.empty) return props.emptyText
  return props.summary
})
</script>

<style scoped lang="scss">
.ux-panel-toggle {
  display: inline-flex;
  align-items: baseline;
  gap: 0.35rem;
  padding: 0;
  border: 0;
  background: none;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
  // assets/css/buttons.scss keeps every button on one line with !important.
  white-space: normal !important;

  &::before {
    content: '\25B8';
    display: inline-block;
    width: 1em;
    transition: transform 0.15s;
  }

  &[aria-expanded='true']::before {
    transform: rotate(90deg);
  }
}

.ux-note {
  color: #495057;
}
</style>
