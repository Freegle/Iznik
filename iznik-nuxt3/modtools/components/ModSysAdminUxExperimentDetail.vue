<template>
  <div :id="blockId" class="ux-exp-detail small">
    <!-- The batch's refusals name an experiment by this, not by its title. -->
    <p v-if="experiment.name" class="short-name text-muted mb-2">
      Short name: {{ experiment.name }}
    </p>
    <section class="mb-2">
      <h6 class="ux-exp-h">What changed</h6>
      <p class="mb-0">{{ experiment.change }}</p>
    </section>
    <section class="mb-2">
      <h6 class="ux-exp-h">Why we tried it</h6>
      <p class="mb-0">{{ experiment.hypothesis }}</p>
    </section>
    <section class="mb-2">
      <h6 class="ux-exp-h">What we expected</h6>
      <p class="mb-0">{{ experiment.expected }}</p>
    </section>

    <section v-if="!isDraft" class="how mb-2">
      <h6 class="ux-exp-h">
        {{ running ? 'How it is going' : 'How it ended' }}
      </h6>
      <ModSysAdminUxExperimentReadout :experiment="experiment" />
      <p v-if="holdout" class="holdout text-muted mt-1 mb-1">{{ holdout }}</p>
      <p
        v-if="experiment.cleanup_overdue"
        class="overdue text-danger mt-1 mb-1"
      >
        Adopted but its switch is still in the code: cleanup overdue.
      </p>
      <!-- Only the paragraph of a read taken since the last start. A restarted experiment has none
           until its first new hour, so the text of the run before is never shown as current. -->
      <template v-if="experiment.latest_read">
        <p class="mt-1 mb-0">
          <button
            type="button"
            class="full-toggle btn btn-link btn-sm p-0"
            :aria-expanded="showFull ? 'true' : 'false'"
            @click="showFull = !showFull"
          >
            {{ showFull ? 'Hide the full read' : 'Show the full read' }}
          </button>
        </p>
        <p v-if="showFull" class="full-read mt-1 mb-0">
          {{ experiment.latest_read.summary }}
        </p>
      </template>
    </section>

    <div class="actions mb-2">
      <template v-if="pending">
        <button
          type="button"
          class="requested btn btn-sm btn-outline-secondary"
          disabled
        >
          {{ REQUESTED_LABEL[pending] }}
        </button>
        <span v-if="pending === 'stop'" class="ms-2 text-muted">
          The batch applies it within a few minutes; this card changes when it
          has.
        </span>
        <span v-if="tooLong" class="not-applied d-block text-danger mt-1">
          Not applied yet. The batch may not be running.
        </span>
      </template>
      <template v-else-if="confirmingStop">
        <span class="me-2">
          Stop "{{ experiment.title }}" now? Everyone goes back to the old
          version.
        </span>
        <button
          type="button"
          class="confirm-stop btn btn-sm btn-danger me-1"
          :disabled="!!busy"
          @click="actions.act(experiment, 'stop')"
        >
          Yes, stop it
        </button>
        <button
          type="button"
          class="cancel btn btn-sm btn-outline-secondary"
          @click="actions.cancelStop()"
        >
          Keep it running
        </button>
      </template>
      <button
        v-else-if="running"
        type="button"
        class="stop btn btn-sm btn-outline-danger"
        @click="actions.askToStop(experiment)"
      >
        Stop now
      </button>
      <!-- A disabled button takes no pointer, so its title is never shown, and a phone has no hover.
           The reason is written beside the button as well, and tied to it for a screen reader. -->
      <template v-else-if="canStart">
        <button
          type="button"
          class="start btn btn-sm btn-outline-primary"
          :disabled="!!busy || !!startTitle"
          :title="startTitle || undefined"
          :aria-describedby="startTitle ? startReasonId : undefined"
          @click="actions.act(experiment, 'start')"
        >
          {{ isDraft ? 'Start' : 'Start again' }}
        </button>
        <span
          v-if="startTitle"
          :id="startReasonId"
          class="start-reason ms-2 text-muted"
          >{{ startTitle }}.</span
        >
      </template>
      <template v-else-if="experiment.status === 'adopted'">
        <button
          type="button"
          class="close btn btn-sm btn-outline-secondary"
          :disabled="!!busy"
          :title="closeTitle"
          :aria-describedby="closeNote ? closeNoteId : undefined"
          @click="actions.act(experiment, 'close')"
        >
          Mark as cleaned up
        </button>
        <span
          v-if="closeNote"
          :id="closeNoteId"
          class="close-note ms-2 text-muted"
          >{{ closeNote }}</span
        >
      </template>
      <div v-if="problem" class="problem text-danger mt-1">{{ problem }}</div>
    </div>

    <section v-if="watched" class="mb-2">
      <h6 class="ux-exp-h">Other funnels we watched</h6>
      <p class="mb-0">
        {{
          watched.length
            ? watched.join(', ') + '.'
            : 'None. Only this funnel is watched.'
        }}
      </p>
    </section>

    <section class="dates mb-2">
      <h6 class="ux-exp-h">Dates</h6>
      <p v-if="created" class="mb-0">Created: {{ created }}</p>
      <p v-if="started" class="mb-0">Started: {{ started }}</p>
      <p v-if="running && ends" class="mb-0">Ends: {{ ends }}</p>
      <p v-if="decided" class="mb-0">Decided: {{ decided }}</p>
      <p v-if="duration" class="mb-0">
        {{ running ? 'Running for' : 'Ran for' }} {{ duration }}.
      </p>
    </section>

    <!-- An address that does not start with https:// is shown as text. It could be a javascript:
         address, which would run when a moderator clicked it. -->
    <section v-if="hasLinks" class="links mb-2">
      <h6 class="ux-exp-h">Links</h6>
      <p class="mb-0">
        <template v-if="experiment.pr_url">
          <a
            v-if="prLink"
            :href="prLink"
            target="_blank"
            rel="noopener"
            class="me-3"
            >The code change</a
          >
          <span v-else class="me-3"
            >The code change: {{ experiment.pr_url }}</span
          >
        </template>
        <template v-if="experiment.cleanup_pr_url">
          <a
            v-if="cleanupLink"
            :href="cleanupLink"
            target="_blank"
            rel="noopener"
            >The cleanup</a
          >
          <span v-else>The cleanup: {{ experiment.cleanup_pr_url }}</span>
        </template>
      </p>
    </section>

    <ModSysAdminUxExperimentChannel :experiment="experiment" />

    <component
      :is="piece"
      v-for="(piece, i) in hosted"
      :key="i"
      :experiment="experiment"
    />
  </div>
</template>

<script setup>
// One experiment opened: what it changes and why, how it is going or how it ended, what else was
// watched, the dates, the links, and the buttons that start, stop or close it. Starting, stopping
// and closing go to the batch through useUxExperimentActions; it applies the rules and may refuse.
import { computed, ref } from 'vue'
import { hostedFor } from '~/modtools/composables/useUxSections'
import { flowTitle } from '~/modtools/composables/useUxFlows'
import { REQUESTED_LABEL } from '~/modtools/composables/useUxExperimentActions'
import {
  durationWord,
  holdoutText,
  safeLink,
  sharedSlotWords,
  slotOf,
  stampWord,
  watchedFunnels,
} from '~/modtools/composables/useUxExperimentRead'
import ModSysAdminUxExperimentChannel from '~/modtools/components/ModSysAdminUxExperimentChannel.vue'
import ModSysAdminUxExperimentReadout from '~/modtools/components/ModSysAdminUxExperimentReadout.vue'

const props = defineProps({
  // One experiment of the experiments response.
  experiment: { type: Object, required: true },
  // Every experiment the store holds, not only the ones on show. Start is refused while another
  // change runs in the same slot, and the batch checks all of them. A slot is the funnel for a change
  // to the site, and its own for one decided on the server.
  experiments: { type: Array, default: () => [] },
  // The cap of running experiments is reached.
  full: { type: Boolean, default: false },
  // The pending requests and refusals (useUxExperimentActions).
  actions: { type: Object, required: true },
})

// Other parts of the tab add pieces here without this component knowing them.
const hosted = hostedFor('experiment')

const showFull = ref(false)

const blockId = computed(() => `ux-exp-${props.experiment.id}`)
const isDraft = computed(() => props.experiment.status === 'draft')
const running = computed(() => props.experiment.status === 'running')
const canStart = computed(() =>
  ['draft', 'stopped', 'inconclusive', 'reverted'].includes(
    props.experiment.status
  )
)

const pending = computed(() => props.actions.pending(props.experiment))
const tooLong = computed(() => props.actions.pendingTooLong(props.experiment))
const problem = computed(() => props.actions.problem(props.experiment))
const busy = computed(() => props.actions.busy[props.experiment.id])
const confirmingStop = computed(() =>
  props.actions.isConfirmingStop(props.experiment)
)

// Why Start is off. The batch refuses at the cap first, so that reason comes first, and a refusal
// would otherwise come back up to a minute after the click.
const startTitle = computed(() => {
  if (props.full) return 'Already at the cap of running experiments'
  const slot = slotOf(props.experiment)
  const another = props.experiments.some(
    (e) =>
      e.status === 'running' &&
      slotOf(e) === slot &&
      e.id !== props.experiment.id
  )
  if (!another) return ''
  // A change to the site shares its funnel. One decided on the server shares what it changes, such as an email
  // type. "Slot" is not a word staff read: the section already uses it for the cap on running experiments.
  return slot === props.experiment.flow
    ? 'Another change is running on this funnel'
    : sharedSlotWords(props.experiment)
})

const startReasonId = computed(
  () => `ux-exp-${props.experiment.id}-start-reason`
)

const closeTitle = computed(() =>
  props.experiment.holdout_until
    ? 'Say that the switch has been removed from the code. This also ends the holdout.'
    : 'Say that the switch has been removed from the code'
)

// The same warning in words anyone can read. While the holdout lasts, closing ends the daily
// comparison with the old version.
const closeNoteId = computed(() => `ux-exp-${props.experiment.id}-close-note`)
const closeNote = computed(() =>
  props.experiment.holdout_until
    ? 'This also ends the daily comparison with the old version.'
    : ''
)

const holdout = computed(() =>
  props.experiment.status === 'adopted' && props.experiment.holdout_until
    ? holdoutText(props.experiment)
    : ''
)

// The other funnels it was set up to watch, as titles. Null when it does not say.
const watched = computed(() => {
  const names = watchedFunnels(props.experiment)
  return names ? names.map(flowTitle) : null
})

const created = computed(() => stampWord(props.experiment.created_at))
const started = computed(() => stampWord(props.experiment.started_at))
const ends = computed(() => stampWord(props.experiment.ends_at))
const decided = computed(() => stampWord(props.experiment.decided_at))
const duration = computed(() =>
  props.experiment.started_at ? durationWord(props.experiment.hours) : ''
)

const prLink = computed(() => safeLink(props.experiment.pr_url))
const cleanupLink = computed(() => safeLink(props.experiment.cleanup_pr_url))
const hasLinks = computed(
  () => !!(props.experiment.pr_url || props.experiment.cleanup_pr_url)
)
</script>

<style scoped lang="scss">
.ux-exp-detail {
  padding: 0.75rem 0.75rem 0.5rem;
  border: 1px solid #61ae24;
  border-top: 0;
  border-radius: 0 0 0.25rem 0.25rem;
  background: #fff;
}

.ux-exp-h {
  margin-bottom: 0.15rem;
  font-size: 0.8rem;
  font-weight: 700;
  text-transform: none;
  color: #495057;
}

// The paragraph the batch wrote, as it wrote it. Vue escapes it, so it is text and never html.
.full-read {
  white-space: pre-line;
}

.full-toggle {
  font-size: inherit;
}

.actions button {
  // assets/css/buttons.scss keeps every button on one line; a sentence must wrap on a phone.
  white-space: normal !important;
}
</style>
