<template>
  <div class="ux-questions">
    <p v-if="store.questions.missing" class="not-switched-on text-muted">
      {{ NOT_SWITCHED_ON }}
    </p>
    <NoticeMessage
      v-else-if="store.questions.error"
      variant="danger"
      class="mb-3"
    >
      {{ store.questions.error }}
    </NoticeMessage>
    <div v-else-if="!store.questions.data" class="loading text-center py-3">
      <b-spinner small class="me-2" />
      Loading...
    </div>
    <p v-else-if="!questions.length" class="none text-muted">
      No questions have been asked yet.
    </p>

    <template v-else>
      <p v-if="openMissing" class="not-in-list small text-muted mb-2">
        That question is not in the list.
      </p>
      <ul class="list-unstyled mb-0">
        <li v-for="q in questions" :key="q.prompt_id" class="mb-2">
          <button
            type="button"
            class="question-row"
            :class="{ open: isOpen(q) }"
            :aria-expanded="isOpen(q) ? 'true' : 'false'"
            :aria-controls="`ux-question-${q.prompt_id}`"
            @click="toggle(q)"
          >
            <span class="caret" aria-hidden="true" />
            <span class="text">{{ q.question }}</span>
            <span class="kind badge">{{ kindLabel(q) }}</span>
            <span class="answered">{{ q.responses }} answered</span>
            <span
              class="status badge"
              :class="q.status === 'open' ? 'ux-blue' : 'ux-grey'"
              >{{ q.status === 'open' ? 'Open' : 'Closed' }}</span
            >
          </button>
          <div
            v-if="isOpen(q)"
            :id="`ux-question-${q.prompt_id}`"
            class="question-body"
          >
            <ModSysAdminUxQuestionResult :question="q" :decoy="decoy" />
          </div>
        </li>
      </ul>
    </template>
  </div>
</template>

<script setup>
// The questions that were asked, one line each, newest first. Opening a line shows how it did. Which
// one is open is in the address (ux_question), so a copied link opens it.
import { computed } from 'vue'
import { useUxSurveysStore } from '~/modtools/stores/uxSurveys'
import { NOT_SWITCHED_ON } from '~/modtools/composables/useUxSlot'
import { kindLabel } from '~/modtools/composables/useUxAnswers'
import ModSysAdminUxQuestionResult from '~/modtools/components/ModSysAdminUxQuestionResult.vue'

const props = defineProps({
  // The id of the open question, as the address holds it, or an empty string.
  open: { type: String, default: '' },
})
const emit = defineEmits(['navigate'])

const store = useUxSurveysStore()

const questions = computed(() => store.questions.data?.questions || [])

const isOpen = (q) => String(q.prompt_id) === props.open
const openMissing = computed(
  () => !!props.open && !questions.value.some(isOpen)
)

// Across the decoy questions, how many said Yes to something Freegle does not do. Each real question
// shows it beside its own figures, so a reader can allow for how readily the members who answered say
// yes. It is worked out from the same list, so it counts what is on screen.
const decoy = computed(() => {
  const decoys = questions.value.filter(
    (q) => q.kind === 'awareness' && q.decoy && (q.responses || 0) > 0
  )
  return {
    questions: decoys.length,
    answers: decoys.reduce((t, q) => t + (q.responses || 0), 0),
    yes: decoys.reduce((t, q) => t + (q.yes || 0), 0),
  }
})

function toggle(q) {
  emit('navigate', { question: isOpen(q) ? undefined : String(q.prompt_id) })
}
</script>

<style scoped lang="scss">
// The list is narrower than the window by the room the ModTools menu takes, and the menu is open when
// the page loads, at every width. So how a row is laid out depends on the width of the list, and not on
// the width of the window.
.ux-questions {
  container-type: inline-size;
  container-name: ux-questions;
}

// The question, then its kind, how many answered and its state, on a second line.
@mixin stacked-row {
  grid-template-columns: 1.25rem auto auto minmax(0, 1fr);
  grid-template-areas:
    'caret text text text'
    '. kind status answered';

  .caret {
    grid-area: caret;
  }
  .text {
    grid-area: text;
  }
  .kind {
    grid-area: kind;
  }
  .status {
    grid-area: status;
  }
  .answered {
    grid-area: answered;
  }
}

// One button for the whole line. assets/css/buttons.scss keeps every button on one line with
// !important, which would run a long question off the right edge of a phone.
.question-row {
  display: grid;
  grid-template-columns: 1.25rem minmax(0, 1fr) auto auto auto;
  align-items: baseline;
  gap: 0.25rem 0.75rem;
  width: 100%;
  padding: 0.5rem;
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

  // A browser that does not know container queries goes by the width of the window instead.
  @media (max-width: 767.98px) {
    @include stacked-row;
  }
}

@container ux-questions (max-width: 46rem) {
  .question-row {
    @include stacked-row;
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

.text {
  font-weight: 600;
  overflow-wrap: anywhere;
}

.answered {
  font-size: 0.875rem;
  white-space: nowrap;
}

.question-body {
  padding: 0.5rem 0.5rem 0.25rem 1.75rem;
}

// A badge keeps to one line by default. The size is set, because 0.75em inside smaller text would be
// about 10 pixels.
.badge {
  font-size: 0.75rem;
  white-space: normal;
  vertical-align: baseline;
}

.kind {
  border: 1px solid #adb5bd;
  background: #f8f9fa;
  color: #343a40;
}

// The ModTools theme makes Bootstrap's success green and forces it on .bg-success, so white text on
// it fails the 4.5 to 1 that small text needs. Open is blue (5.8 to 1) and closed is grey (6.1 to 1).
// Neither uses a bg- class, so the theme cannot change them.
.ux-blue {
  background-color: #0b5ed7;
  color: #fff;
}

.ux-grey {
  background-color: #5c636a;
  color: #fff;
}
</style>
