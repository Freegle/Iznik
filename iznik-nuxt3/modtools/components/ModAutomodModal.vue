<template>
  <b-modal
    ref="modal"
    title="Why automated review decided this"
    size="md"
    hide-footer
  >
    <div v-if="automod">
      <p class="mb-2">
        <strong>{{ summary }}</strong>
      </p>
      <p v-if="detail" class="small text-muted mb-3">{{ detail }}</p>
      <p
        v-if="feedbackError"
        class="small text-danger mb-2"
        role="alert"
        data-testid="automod-feedback-error"
      >
        {{ feedbackError }}
      </p>
      <div
        v-for="(node, index) in automod.path"
        :key="index"
        class="automod-node"
        :class="
          isDeciding(index)
            ? 'automod-node--deciding border-start border-3 border-warning ps-2 mb-3'
            : 'mb-2 small'
        "
      >
        <div class="d-flex justify-content-between align-items-baseline">
          <span :class="{ 'fw-bold': isDeciding(index) }">{{
            node.question
          }}</span>
          <span class="ms-2 text-nowrap">
            <strong>{{ answerText(node) }}</strong>
            <template v-if="!couldNotAnswer(node)">
              <span v-if="feedbackSent[node.node]" class="small text-muted ms-1"
                >Thanks, noted
                <b-button
                  variant="link"
                  size="sm"
                  class="p-0 ms-1 small align-baseline"
                  data-testid="automod-undo-feedback"
                  :disabled="sending[node.node]"
                  @click="unsendFeedback(node)"
                >
                  (undo)
                </b-button>
              </span>
              <b-button
                v-else
                v-b-tooltip.hover
                variant="link"
                size="sm"
                class="p-0 ms-1 small align-baseline"
                title="This step is wrong"
                data-testid="automod-wrong"
                :aria-label="'This step is wrong: ' + node.question"
                :disabled="sending[node.node]"
                @click="sendFeedback(node)"
              >
                wrong?
              </b-button>
            </template>
          </span>
        </div>
        <div v-if="showConfidence(node)" class="text-muted">
          {{ Math.round(node.p * 100) }}% sure, holds above
          {{ Math.round(node.threshold * 100) }}%
        </div>
        <!-- A quote from the post for a question the model answered. Anything else - a finding
             from Freegle's records, or a note the chart wrote about its own step - is said
             plainly, so it is not read as the member's words. -->
        <div v-if="node.evidence && isQuote(node)" class="fst-italic">
          "{{ node.evidence }}"
        </div>
        <div v-else-if="node.evidence" class="text-muted">
          {{ node.evidence }}
        </div>
        <div v-if="isDeciding(index)" class="small text-muted">
          <template v-if="couldNotAnswer(node)">
            This question could not be answered, so the post waits for a
            moderator.
          </template>
          <template v-else>
            This step decided the outcome. Answered {{ answeredBy(node) }}.
          </template>
        </div>
      </div>
    </div>
  </b-modal>
</template>
<script setup>
// Full detail behind an automated review decision: the questions the flowchart
// asked, in order, what it decided at each one, and how sure it was. Always
// mounted (behind the line that owns it) and shown only when triggered via
// its exposed show().
import { computed, reactive, ref, watch } from 'vue'
import { useMessageStore } from '@/stores/message'
import { useOurModal } from '@/composables/useOurModal'

const props = defineProps({
  msgid: {
    type: Number,
    default: null,
  },
  groupid: {
    type: Number,
    default: null,
  },
  automod: {
    type: Object,
    default: null,
  },
})

const messageStore = useMessageStore()

const { modal, show, hide } = useOurModal({ autoShow: false })

// A review that could not run, or a community that has moderators check every post, is
// recorded as a hold with its own end node but is not a decision about the post: the chart
// asked no question, or could not finish asking.
const unavailable = computed(() => props.automod?.end === 'UNAVAILABLE')
const noReview = computed(() => props.automod?.end === 'GROUP_MODERATES_ALL')

// One line saying what happened, before the detail.
const summary = computed(() => {
  const a = props.automod
  if (!a) return ''
  if (unavailable.value) {
    return 'Automated review could not run, so it made no decision about this post. While a post waits for a moderator it tries again.'
  }
  if (noReview.value) {
    return 'Automated review did not look at this post. This community has a moderator check every post, so there was nothing for it to decide.'
  }
  const shadow = a.mode === 'shadow'
  if (a.verdict === 'hold') {
    return (shadow ? 'Would hold: ' : 'Held: ') + a.reason
  }
  return shadow
    ? 'Would approve: no question below needed a moderator.'
    : 'Approved: no question below needed a moderator.'
})

const ranAt = computed(() => {
  if (!props.automod?.created) return null
  return new Date(props.automod.created).toLocaleString()
})

// What the small print says. The questions were only asked if there is a path, and a record
// written without running the chart has no version to name.
const detail = computed(() => {
  const a = props.automod
  if (!a) return ''
  const parts = []
  if (a.path?.length) {
    parts.push(
      'The questions below were asked in this order; the first answered yes holds a post.'
    )
  }
  const run = []
  if (a.version) run.push('Chart version ' + a.version)
  if (ranAt.value) run.push('ran ' + ranAt.value)
  if (run.length) parts.push(run.join(' · '))
  return parts.join(' ')
})

// A hold stops at the first question answered yes, so the last node on a held
// post's path is the one that held it. An approved post passed every question and
// has no single deciding node.
const decidingIndex = computed(() => {
  const path = props.automod?.path
  if (props.automod?.verdict !== 'hold' || !path || !path.length) return -1
  return path.length - 1
})

function isDeciding(index) {
  return index === decidingIndex.value
}

// A step the service could not answer is recorded as a "yes" with full confidence, so that
// the post is held. It was not an answer, and is not shown as one.
function couldNotAnswer(node) {
  return node.model === 'unavailable'
}

function answerText(node) {
  if (couldNotAnswer(node)) return 'Could not answer'
  return node.answer === 'yes' ? 'Yes' : 'No'
}

function showConfidence(node) {
  return (
    node.kind === 'text' &&
    node.p !== null &&
    node.p !== undefined &&
    !couldNotAnswer(node)
  )
}

// Only a question the model answered carries a quote from the post, and it carries a
// confidence. A question skipped because the community's rules settle it has a note instead.
function isQuote(node) {
  return showConfidence(node)
}

function answeredBy(node) {
  return node.kind === 'fact' ? "from Freegle's records" : 'by ' + node.model
}

// Keyed by node id, not by position in the path, and seeded from automod.feedback - the
// nodes this moderator has already marked, which the server sends back - so a mark
// survives a reload and cannot be posted twice from here.
const feedbackSent = reactive({})
const sending = reactive({})
const feedbackError = ref('')

watch(
  () => props.automod?.feedback,
  (marked) => {
    for (const node of marked || []) {
      feedbackSent[node] = true
    }
  },
  { immediate: true }
)

// A failed request has already been reported by the API layer. The moderator is told it did
// not go through and the step keeps whatever the server holds.
async function changeMark(node, send, marked) {
  const id = node.node
  if (sending[id]) return
  sending[id] = true
  feedbackError.value = ''
  try {
    await send({
      msgid: props.msgid,
      groupid: props.groupid,
      node: id,
    })
    feedbackSent[id] = marked
  } catch (e) {
    feedbackError.value = "That didn't go through. Try again in a moment."
  } finally {
    sending[id] = false
  }
}

// {already: true} means the server had it from this moderator already, which is the same
// thing as far as the moderator is concerned: it is marked.
async function sendFeedback(node) {
  if (feedbackSent[node.node]) return
  await changeMark(node, messageStore.postAutomodFeedback, true)
}

async function unsendFeedback(node) {
  if (!feedbackSent[node.node]) return
  await changeMark(node, messageStore.deleteAutomodFeedback, false)
}

defineExpose({
  summary,
  detail,
  modal,
  show,
  hide,
  ranAt,
  decidingIndex,
  isDeciding,
  feedbackSent,
  sendFeedback,
  unsendFeedback,
})
</script>
