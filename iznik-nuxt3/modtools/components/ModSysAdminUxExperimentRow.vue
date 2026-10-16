<template>
  <button
    type="button"
    class="ux-exp-row"
    :class="{ open }"
    :aria-expanded="open ? 'true' : 'false'"
    :aria-controls="controls || undefined"
    @click="emit('toggle')"
  >
    <span class="caret" aria-hidden="true" />
    <span class="status badge" :class="statusClass">{{ status }}</span>
    <span class="title">{{ experiment.title }}</span>
    <span class="funnel text-muted">{{ funnel }}</span>
    <span class="line">
      <span class="line-text">{{ line }}</span>
      <span v-if="worry" class="watch badge bg-warning text-dark ms-1">
        Watch: {{ worry.label }}
      </span>
      <span
        v-if="experiment.cleanup_overdue"
        class="overdue badge bg-danger ms-1"
      >
        Clean up the code
      </span>
      <span v-if="waiting" class="waiting badge bg-info text-dark ms-1">
        Waiting for the batch
      </span>
    </span>
  </button>
</template>

<script setup>
// One experiment on one line: a word for its status, its title, its funnel, and a sentence about how
// it is doing or how it ended. Pressing it opens its details. The batch's own sentences name a
// funnel by its title and never by its key.
import { computed } from 'vue'
import { flowShort } from '~/modtools/composables/useUxFlows'
import {
  estateOf,
  lineOf,
  readOf,
  statusWord,
  worryOf,
} from '~/modtools/composables/useUxExperimentRead'

const props = defineProps({
  // One experiment of the experiments response.
  experiment: { type: Object, required: true },
  // Whether its details are showing.
  open: { type: Boolean, default: false },
  // A request for this experiment has been sent and the batch has not answered yet.
  waiting: { type: Boolean, default: false },
  // The id of the block this button opens.
  controls: { type: String, default: '' },
})
const emit = defineEmits(['toggle'])

const status = computed(() => statusWord(props.experiment))
const funnel = computed(() => flowShort(props.experiment.flow))
// The watch chip says what the line would, so the line leaves it out.
const line = computed(() => lineOf(props.experiment, { watch: false }))
// Only a running experiment is watched: a finished one has been decided already.
const worry = computed(() =>
  estateOf(props.experiment) === 'running'
    ? worryOf(readOf(props.experiment))
    : null
)

const statusClass = computed(
  () =>
    ({
      running: 'ux-blue',
      draft: 'bg-light text-dark border',
      adopted: 'ux-green',
      closed: 'ux-green',
      reverted: 'bg-danger',
    })[props.experiment.status] || 'bg-secondary'
)
</script>

<style scoped lang="scss">
// One button for the whole row. assets/css/buttons.scss keeps every button on one line with
// !important, which would run a long title and line off the right edge of a phone.
.ux-exp-row {
  display: grid;
  grid-template-columns: 1.25rem 8.5rem minmax(0, 2fr) 6.5rem minmax(0, 3fr);
  align-items: baseline;
  gap: 0.25rem 0.75rem;
  width: 100%;
  padding: 0.5rem 0.5rem;
  border: 1px solid #dee2e6;
  border-radius: 0.25rem;
  background: #fff;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
  white-space: normal !important;

  &:hover {
    background: #f8f9fa;
  }

  &.open {
    border-color: #61ae24;
    background: #f6fbf2;
  }

  // Three lines below 768 pixels: the status and funnel, then the title, then the result.
  @media (max-width: 767.98px) {
    grid-template-columns: 1.25rem auto minmax(0, 1fr);
    grid-template-areas:
      'caret status funnel'
      'title title title'
      'line line line';

    .caret {
      grid-area: caret;
    }
    .status {
      grid-area: status;
    }
    .funnel {
      grid-area: funnel;
    }
    .title {
      grid-area: title;
    }
    .line {
      grid-area: line;
    }
  }
}

.caret::before {
  content: '\25B8';
  display: inline-block;
  transition: transform 0.15s;
}

.open .caret::before {
  transform: rotate(90deg);
}

.status {
  justify-self: start;
}

.title {
  font-weight: 600;
  overflow-wrap: anywhere;
}

.line {
  font-size: 0.875rem;
  overflow-wrap: anywhere;
}

// The ModTools theme makes Bootstrap's success green, #61ae24, and forces it on .bg-success, so white
// text on it is 2.8 to 1 where small text needs 4.5 to 1. Kept is a darker green (5.4 to 1) and
// running is blue (5.8 to 1). Neither uses a bg- class, so the theme cannot change them.
.ux-green {
  background-color: #2a7a0a;
  color: #fff;
}

.ux-blue {
  background-color: #0b5ed7;
  color: #fff;
}

// A badge keeps to one line by default. These hold a sentence's worth of words. Its size is set,
// because 0.75em inside the smaller text of the line would be about 10 pixels.
.badge {
  font-size: 0.75rem;
  white-space: normal;
  text-align: left;
  vertical-align: baseline;
}
</style>
