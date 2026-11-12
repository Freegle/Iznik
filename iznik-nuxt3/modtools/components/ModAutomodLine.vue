<template>
  <div
    v-if="lineText"
    class="automod-line small text-muted d-flex align-items-center flex-wrap"
  >
    <!-- The robot and the words are one item, so a long reason on a narrow card wraps
         beside the icon instead of leaving it alone on a row. -->
    <span class="automod-line__text">
      <v-icon icon="robot" class="me-1" />{{ lineText }}
    </span>
    <b-button
      v-if="automod"
      variant="link"
      size="sm"
      class="p-0 ms-1 align-baseline automod-line__why"
      @click="modal?.show()"
    >
      Why?
    </b-button>
    <!-- Announced politely when it changes, so marking or taking back a mark is not silent
         for a screen reader. -->
    <span
      v-if="canFlagPublished"
      class="ms-2 automod-line__flag"
      aria-live="polite"
    >
      <span
        v-if="publishedFlagged"
        class="automod-line__flagged"
        data-testid="automod-flagged"
      >
        Noted: should not have published
        <b-button
          variant="link"
          size="sm"
          class="p-0 ms-1 align-baseline automod-line__undo"
          data-testid="automod-unflag-published"
          :disabled="flagging"
          @click="unflagPublished"
        >
          (undo)
        </b-button>
      </span>
      <b-button
        v-else
        variant="link"
        size="sm"
        class="p-0 align-baseline automod-line__wrong"
        data-testid="automod-flag-published"
        :disabled="flagging"
        @click="flagPublished"
      >
        Should not have published
      </b-button>
    </span>
    <span
      v-if="feedbackError"
      class="ms-2 text-danger automod-line__error"
      data-testid="automod-feedback-error"
      role="alert"
    >
      {{ feedbackError }}
    </span>
    <ModAutomodModal
      v-if="automod"
      ref="modal"
      :msgid="message?.id"
      :groupid="groupid"
      :automod="automod"
    />
  </div>
</template>
<script setup>
// One muted line under a post showing what the automated review flowchart
// decided (or would have decided, in shadow mode) about it, with a link to
// the full path behind that decision. Renders nothing at all for
// communities not in the automod trial. On such a community a Pending post the
// review has not reached yet says it is waiting.
import { computed, ref } from 'vue'
import { useAutomod } from '@/modtools/composables/useAutoapproveTrial'
import { useMessageStore } from '@/stores/message'
import ModAutomodModal from '@/modtools/components/ModAutomodModal.vue'

const props = defineProps({
  message: {
    type: Object,
    default: null,
  },
  groupid: {
    type: Number,
    default: null,
  },
  pending: {
    type: Boolean,
    default: false,
  },
})

const { isAutomodGroup } = useAutomod()
const messageStore = useMessageStore()

const group = computed(() => {
  const groups = props.message?.groups
  if (!groups || !groups.length) return null

  return groups.find((g) => parseInt(g.groupid) === props.groupid) || null
})

const automod = computed(() => group.value?.automod || null)

const eligible = computed(
  () => isAutomodGroup(props.groupid) && !!automod.value
)

// The review did not run (service down, timeout): the batch records a hold with this end
// node so the post waits for a moderator, and retries. That is not a decision, so it must
// not read like one.
const unavailable = computed(() => automod.value?.end === 'UNAVAILABLE')

// A community that has moderators check every post is given no decision: the batch records
// the setting once, as a hold with this end node, so the card can say so. The chart did not
// look at the post, so that must not read like a hold either.
const noReview = computed(() => automod.value?.end === 'GROUP_MODERATES_ALL')

// A Pending post on an automod community that the batch has not decided yet. The batch only
// reviews a direct copy that nobody holds and nothing has flagged, so for any other Pending
// copy no decision is coming and the card says nothing.
const waitingForDecision = computed(() => {
  const g = group.value
  if (!props.pending || automod.value || !g) return false
  if (!isAutomodGroup(props.groupid)) return false

  return (
    !parseInt(g.rippled_in) &&
    !g.heldby &&
    !g.spamreason &&
    !props.message?.spamreason
  )
})

const lineText = computed(() => {
  if (waitingForDecision.value)
    return 'Automated review: waiting for a decision'
  if (!eligible.value) return ''

  const a = automod.value

  if (props.pending) {
    if (unavailable.value) {
      return 'Automated review could not run; waiting for a moderator (it will retry)'
    }

    if (noReview.value) {
      return 'No automated review: this community has every post checked by a moderator'
    }

    if (a.mode === 'shadow') {
      return a.verdict === 'approve'
        ? 'Automated review: would approve'
        : `Automated review: would hold - ${a.reason}`
    }

    // Approved by the chart but picked for the quality-check sample: it waits for a
    // moderator's verdict with no countdown, and the card has to say why.
    if (a.verdict === 'approve' && a.qualitysample) {
      return 'Automated review: approved, but held back as a quality-check sample for a moderator'
    }

    if (a.verdict === 'approve') {
      // The promise is the countdown's. The API gives a copy no autoapproveat when it is
      // held, was pulled back, was edited after the decision or the member's status has
      // changed, and none of those publishes itself.
      return group.value?.autoapproveat
        ? 'Automated review: will publish after the wait'
        : 'Automated review approved this, but it is not counting down (held, pulled back or edited since)'
    }

    return `Automated review: holding - ${a.reason}`
  }

  const approvedby = group.value?.approvedby

  if (unavailable.value) {
    return approvedby
      ? 'Approved by a moderator - automated review could not run'
      : 'Published without a moderator; automated review could not run'
  }

  if (noReview.value) {
    return approvedby
      ? 'Approved by a moderator - no automated review, as this community has every post checked'
      : 'Published without a moderator; no automated review, as this community has every post checked'
  }

  // The sample is held back for a moderator's verdict and the 48-hour sweep is its backstop:
  // one nobody looked at goes live with no moderator behind it. The chart did not publish
  // it, so it is not "auto-approved by automated review" and its approval is not the chart's
  // to answer for.
  if (a.verdict === 'approve' && a.qualitysample && !approvedby) {
    return 'Quality-check sample: published by the 48-hour fallback with no moderator verdict'
  }

  if (a.verdict === 'approve' && a.mode !== 'shadow' && !approvedby) {
    return 'Auto-approved by automated review'
  }

  if (a.verdict === 'approve' && approvedby) {
    return 'Approved by a moderator - automated review agreed'
  }

  if (a.verdict === 'hold' && approvedby) {
    return `Approved by a moderator - automated review would have held: ${a.reason}`
  }

  // Live with nobody's approval on it, against a stored hold: the 48-hour fallback, or the
  // clock after a late review. This is the case the trial exists to count, so it is said
  // plainly rather than left blank.
  if (a.verdict === 'hold' && !approvedby) {
    return `Published without a moderator; automated review would have held: ${a.reason}`
  }

  if (a.mode === 'shadow' && a.verdict === 'approve' && !approvedby) {
    return 'Automated review would have approved'
  }

  return ''
})

// "Should not have published": one click on a post that went live by itself, recorded as
// feedback against the decision's end node, so a bad auto-approval is a single tap rather
// than a hunt through the path for a question to mark wrong (every answer on an approved
// post is "no"). Offered wherever the auto-approved line shows - the Check queue, and the
// Approved list the post moves on to once checked or after a week. Not on a quality-check
// sample the fallback published: nobody gave it the verdict it was held for, and the chart
// did not publish it.
const canFlagPublished = computed(
  () =>
    eligible.value &&
    !props.pending &&
    group.value?.collection === 'Approved' &&
    !group.value?.approvedby &&
    automod.value.verdict === 'approve' &&
    automod.value.mode !== 'shadow' &&
    !automod.value.qualitysample &&
    !unavailable.value &&
    !noReview.value
)

// The server lists the nodes this moderator has already marked (automod.feedback), so the
// state survives a reload and a second click is never a second row. localMark is what this
// moderator did on this screen since the list was loaded - true after a mark, false after
// taking it back - and wins over the list, which is only as fresh as the last load.
const localMark = ref(null)
const flagging = ref(false)
const feedbackError = ref('')
const publishedFlagged = computed(
  () =>
    localMark.value ??
    (automod.value?.feedback || []).includes(automod.value?.end)
)

function feedbackBody() {
  return {
    msgid: props.message?.id,
    groupid: props.groupid,
    node: automod.value.end,
  }
}

// A failed request has already been reported by the API layer. The moderator is told it did
// not go through and the screen keeps what the server holds.
async function changeMark(send, marked) {
  if (flagging.value) return
  flagging.value = true
  feedbackError.value = ''
  try {
    await send(feedbackBody())
    localMark.value = marked
  } catch (e) {
    feedbackError.value = "That didn't go through. Try again in a moment."
  } finally {
    flagging.value = false
  }
}

async function flagPublished() {
  if (publishedFlagged.value) return
  await changeMark(messageStore.postAutomodFeedback, true)
}

async function unflagPublished() {
  if (!publishedFlagged.value) return
  await changeMark(messageStore.deleteAutomodFeedback, false)
}

const modal = ref(null)

defineExpose({
  group,
  automod,
  eligible,
  lineText,
  modal,
  canFlagPublished,
  publishedFlagged,
  flagPublished,
  unflagPublished,
  waitingForDecision,
})
</script>
