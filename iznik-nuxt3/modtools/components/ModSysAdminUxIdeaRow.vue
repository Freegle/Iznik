<template>
  <div class="ux-idea">
    <button
      type="button"
      class="ux-idea-row"
      :class="{ open, 'with-funnel': showFunnel }"
      :aria-expanded="open ? 'true' : 'false'"
      :aria-controls="controls || undefined"
      @click="emit('toggle')"
    >
      <span class="caret" aria-hidden="true" />
      <span class="score"
        ><span class="score-label">Score </span
        ><span class="score-value">{{ score }}</span></span
      >
      <span class="type badge" :class="typeClass">{{ typeText }}</span>
      <span v-if="showFunnel" class="funnel text-muted">{{ funnel }}</span>
      <span class="title">{{ idea.title }}</span>
      <span class="status badge" :class="statusClass">{{ statusText }}</span>
    </button>

    <div v-if="open" :id="controls || undefined" class="ux-idea-detail">
      <section v-if="change" class="change">
        <h6 class="part">What changes</h6>
        <p class="mb-2">{{ change }}</p>
      </section>

      <section class="why">
        <h6 class="part">Why</h6>
        <p class="mb-2">{{ idea.rationale }}</p>
      </section>

      <section class="expect">
        <h6 class="part">What we expect</h6>
        <p class="mb-2">{{ idea.expected }}</p>
      </section>

      <section class="evidence">
        <h6 class="part">Evidence</h6>
        <p v-if="!lines.length && !files.shown.length" class="none mb-2">
          The idea generator kept no figures for this one.
        </p>
        <ul v-else class="list-unstyled mb-2">
          <li v-for="l in lines" :key="l.key" :class="`line-${l.key}`">
            {{ l.label }}: {{ l.text }}
          </li>
          <li v-if="files.shown.length" class="line-files">
            Files:
            <span class="file-list">{{ files.shown.join(', ') }}</span>
            <span v-if="files.more" class="more-files"
              >, and {{ files.more }} more</span
            >
          </li>
        </ul>
      </section>

      <section v-if="problemTitle" class="problem">
        <h6 class="part">The problem it answers</h6>
        <p class="mb-2">
          <span class="problem-title">{{ problemTitle }}</span>
          <template v-if="problemLink">
            <a class="problem-link ms-2" :href="problemHref" @click="goProblem"
              >See what members said</a
            >
          </template>
        </p>
      </section>

      <section v-if="idea.experiment" class="experiment">
        <h6 class="part">The experiment it became</h6>
        <p class="mb-2">
          <span class="experiment-title">{{ idea.experiment.title }}</span>
          <span
            class="experiment-status badge ms-2 bg-light text-dark border"
            >{{ experimentText }}</span
          >
          <template v-if="experimentLink">
            <a
              class="experiment-link ms-2"
              :href="experimentHref"
              @click="goExperiment"
              >Open the experiment</a
            >
          </template>
        </p>
      </section>

      <p class="source small text-muted mb-0">{{ sourceText }}</p>
    </div>
  </div>
</template>

<script setup>
// One idea on one line: its score, whether it is a fix or an experiment, its title and its status.
// Pressing it opens the reasons below it: what changes, why, what we expect, the evidence, the member
// problem it answers and the experiment it became. The two links go to other sections of the tab, and are drawn
// only when those sections are there.
import { computed, inject } from 'vue'
import { flowShort } from '~/modtools/composables/useUxFlows'
import { isPlainClick } from '~/modtools/composables/useUxFormat'
import { uxSections } from '~/modtools/composables/useUxSections'
import {
  evidenceLines,
  experimentWord,
  filesOf,
  ideaSourceLabel,
  scoreText,
  statusWord,
  typeWord,
} from '~/modtools/composables/useUxIdeaLabels'

const props = defineProps({
  // One idea of the ideas response.
  idea: { type: Object, required: true },
  // Whether its reasons are showing.
  open: { type: Boolean, default: false },
  // The id of the block this button opens.
  controls: { type: String, default: '' },
  // Whether to name the funnel on the row, for a list that is not about one funnel.
  showFunnel: { type: Boolean, default: false },
})
const emit = defineEmits(['toggle', 'navigate'])

// The tab gives every section a way to get the address of a change, for links that can be copied.
const hrefFor = inject('uxHrefFor', () => '#')

const score = computed(() => scoreText(props.idea.score))
const typeText = computed(() => typeWord(props.idea.type))
const statusText = computed(() => statusWord(props.idea.status))
const funnel = computed(() => flowShort(props.idea.flow))
const sourceText = computed(() => ideaSourceLabel(props.idea.source))

const typeClass = computed(() =>
  props.idea.type === 'fix' ? 'ux-fix' : 'bg-light text-dark border'
)
const statusClass = computed(
  () =>
    ({
      open: 'bg-light text-dark border',
      scheduled: 'ux-blue',
      running: 'ux-blue',
      done: 'ux-green',
      rejected: 'bg-secondary',
    })[props.idea.status] || 'bg-secondary'
)

const evidence = computed(() =>
  props.idea.evidence && typeof props.idea.evidence === 'object'
    ? props.idea.evidence
    : {}
)
const lines = computed(() => evidenceLines(evidence.value))
// What the member would see or do differently, as the generator wrote it. It is kept apart from the
// reason, so each is printed under its own heading.
const change = computed(() =>
  typeof evidence.value.change === 'string' ? evidence.value.change.trim() : ''
)
const files = computed(() => filesOf(evidence.value))

// The member problem's own title when it is tied to one, else the generator's title for the problem
// it found.
const problemTitle = computed(
  () => props.idea.problem?.title || props.idea.problem_title || ''
)

// The registry says which sections there are. A link to one that is not there would go nowhere.
const registered = (key) => uxSections().some((s) => s.key === key)
const problemLink = computed(
  () => registered('members') && props.idea.problem_id
)
const experimentLink = computed(
  () => registered('experiments') && props.idea.experiment?.id
)
const experimentText = computed(() => experimentWord(props.idea.experiment))

const problemPatch = () => ({
  section: 'members',
  problem: String(props.idea.problem_id),
})
const experimentPatch = () => ({
  section: 'experiments',
  exp: String(props.idea.experiment.id),
})
const problemHref = computed(() => hrefFor(problemPatch()))
const experimentHref = computed(() => hrefFor(experimentPatch()))

function goProblem(e) {
  if (!isPlainClick(e)) return
  e.preventDefault()
  emit('navigate', problemPatch())
}
function goExperiment(e) {
  if (!isPlainClick(e)) return
  e.preventDefault()
  emit('navigate', experimentPatch())
}
</script>

<style scoped lang="scss">
// One button for the whole row. assets/css/buttons.scss keeps every button on one line with
// !important, which would run a long title off the right edge of a phone.
.ux-idea-row {
  display: grid;
  grid-template-columns: 1.25rem 5.5rem 6.5rem minmax(0, 1fr) 6.5rem;
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

  // The funnel's name, when the row has one, sits between the type and the title.
  &.with-funnel {
    grid-template-columns: 1.25rem 5.5rem 6.5rem 7rem minmax(0, 1fr) 6.5rem;
  }

  // Two lines below 768 pixels: the chips, then the title.
  @media (max-width: 767.98px) {
    grid-template-columns: 1.25rem auto auto minmax(0, 1fr);
    grid-template-areas:
      'caret score type status'
      'title title title title';

    &.with-funnel {
      grid-template-columns: 1.25rem auto auto minmax(0, 1fr);
      grid-template-areas:
        'caret score type status'
        'funnel funnel funnel funnel'
        'title title title title';
    }

    .caret {
      grid-area: caret;
    }
    .score {
      grid-area: score;
    }
    .type {
      grid-area: type;
    }
    .funnel {
      grid-area: funnel;
    }
    .title {
      grid-area: title;
    }
    .status {
      grid-area: status;
      justify-self: start;
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

// The number is bold and the word beside it is plain, so the number is what the eye finds.
.score {
  font-variant-numeric: tabular-nums;
}

.score-label {
  color: #495057;
  font-size: 0.8rem;
}

.score-value {
  font-weight: 700;
}

.type,
.status {
  justify-self: start;
}

.title {
  font-weight: 600;
  overflow-wrap: anywhere;
}

.ux-idea-detail {
  margin: 0.25rem 0 0 0.25rem;
  padding: 0.5rem 0.75rem;
  border-left: 3px solid #61ae24;
  background: #fff;
  overflow-wrap: anywhere;
}

.part {
  margin: 0.25rem 0 0.15rem;
  font-size: 0.85rem;
  font-weight: 700;
}

// The ModTools theme makes Bootstrap's success green, #61ae24, and forces it on .bg-success, so white
// text on it is 2.8 to 1 where small text needs 4.5 to 1. Tried is a darker green (5.4 to 1), being
// tried is blue (5.8 to 1) and a fix is a dark red. None uses a bg- class, so the theme cannot
// change them.
.ux-green {
  background-color: #2a7a0a;
  color: #fff;
}

.ux-blue {
  background-color: #0b5ed7;
  color: #fff;
}

.ux-fix {
  background-color: #a52a2a;
  color: #fff;
}

// A badge keeps to one line by default. Its size is set, because 0.75em inside the smaller text of a
// line would be about 10 pixels.
.badge {
  font-size: 0.75rem;
  white-space: normal;
  text-align: left;
  vertical-align: baseline;
}
</style>
