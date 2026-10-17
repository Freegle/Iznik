<template>
  <div v-if="message">
    <div v-if="editreview" class="d-inline">
      <ModMessageButton
        v-if="!modsHeld"
        :messageid="message.id"
        :groupid="groupid"
        variant="primary"
        icon="check"
        approveedits
        label="Accept Edit"
      />
      <ModMessageButton
        v-if="!modsHeld"
        :messageid="message.id"
        :groupid="groupid"
        variant="danger"
        icon="times"
        revertedits
        label="Reject Edit"
      />
      <ModMessageButton
        v-if="modMessagingAllowed && !modsHeld"
        :messageid="message.id"
        :groupid="groupid"
        variant="primary"
        icon="envelope"
        leave
        label="Blank Reply"
      />
    </div>
    <div v-else-if="pending || spam" class="d-inline">
      <!-- Approve, Reject, Delete and the standard messages act on every community in
           groupids when there is more than one; Hold, Release and Delete as Spam stay on
           this community. -->
      <ModMessageButton
        v-if="canApprove"
        :messageid="message.id"
        :groupid="groupid"
        :groupids="groupids"
        variant="primary"
        icon="check"
        approve
        label="Approve"
      />
      <ModMessageButton
        v-if="!modsHeld"
        :messageid="message.id"
        :groupid="groupid"
        :groupids="groupids"
        :is-home-group="isHomeGroup"
        :can-message-poster="messagesPoster"
        :no-member-message="!modMessagingAllowed"
        variant="warning"
        icon="times"
        reject
        label="Reject"
      />
      <!-- Where this community may write to the poster, Reject composes a message, so
           Delete is the way to take the post off quietly. It acts on this copy only
           (Discourse 9808/867). -->
      <ModMessageButton
        v-if="(isHomeGroup || messagesPoster) && !modsHeld"
        :messageid="message.id"
        :groupid="groupid"
        :groupids="groupids"
        variant="danger"
        icon="trash-alt"
        delete
        label="Delete"
      />
      <ModMessageButton
        v-if="!heldByOnThisGroup && !modsHeld"
        :messageid="message.id"
        :groupid="groupid"
        variant="warning"
        icon="pause"
        hold
        label="Hold"
      />
      <ModMessageButton
        v-else-if="heldByOnThisGroup && !modsHeld"
        :messageid="message.id"
        :groupid="groupid"
        variant="success"
        icon="play"
        release
        label="Release"
      />
      <ModMessageButton
        v-if="isHomeGroup && !modsHeld"
        :messageid="message.id"
        :groupid="groupid"
        variant="danger"
        icon="ban"
        spam
        label="Delete as Spam"
      />
    </div>
    <div v-else-if="approved" class="d-inline">
      <ModMessageButton
        v-if="messagesPoster && modMessagingAllowed && !modsHeld"
        :messageid="message.id"
        :groupid="groupid"
        variant="primary"
        icon="envelope"
        leave
        label="Blank Reply"
      />
      <!-- The Check queue: one post looked at, or pulled back for a decision. Hidden while
           the moderators surface is held, like the other buttons that change what members see. -->
      <template v-if="oversight && oversightRejectable && !modsHeld">
        <SpinButton
          data-testid="oversight-checked"
          variant="white"
          class="m-1"
          icon-name="check"
          label="Checked"
          :flex="false"
          @handle="markChecked"
        />
        <SpinButton
          data-testid="oversight-pullback"
          variant="warning"
          class="m-1"
          icon-name="reply"
          label="Pull back"
          :flex="false"
          :disabled="pullingBack"
          @handle="askPullBack"
        />
      </template>
      <ModMessageButton
        v-if="(isHomeGroup || messagesPoster) && !modsHeld"
        :messageid="message.id"
        :groupid="groupid"
        variant="danger"
        icon="trash-alt"
        delete
        label="Delete"
      />
      <ModMessageButton
        v-if="isHomeGroup && !modsHeld"
        :messageid="message.id"
        :groupid="groupid"
        variant="danger"
        icon="ban"
        spam
        label="Delete as Spam"
      />
      <!-- Outcomes are facts about the whole post, so they belong to the poster or to the
           moderators of the group it was posted on. The server refuses them from a group
           the post merely rippled into, so do not offer them there (Discourse 10102). -->
      <SpinButton
        v-if="
          isHomeGroup &&
          message.type === 'Offer' &&
          !message.outcomes?.length &&
          !modsHeld
        "
        variant="white"
        class="m-1"
        icon-name="check"
        label="Mark as TAKEN"
        confirm
        :flex="false"
        @handle="outcome($event, 'Taken')"
      />
      <SpinButton
        v-if="
          isHomeGroup &&
          message.type === 'Wanted' &&
          !message.outcomes?.length &&
          !modsHeld
        "
        variant="white"
        class="m-1"
        icon-name="check"
        label="Mark as RECEIVED"
        confirm
        :flex="false"
        @handle="outcome($event, 'Received')"
      />
      <SpinButton
        v-if="isHomeGroup && !message.outcomes?.length && !modsHeld"
        variant="white"
        class="m-1"
        icon-name="trash-alt"
        label="Mark as Withdrawn"
        confirm
        :flex="false"
        @handle="outcome($event, 'Withdrawn')"
      />
    </div>
    <NoticeMessage
      v-if="oversightNote"
      variant="warning"
      class="mt-1 mb-1"
      role="alert"
      data-testid="oversight-note"
    >
      {{ oversightNote }}
    </NoticeMessage>
    <ConfirmModal
      v-if="showPullBack"
      title="Pull this post back to Pending?"
      confirm-label="Pull back"
      @confirm="pullBack"
      @hidden="showPullBack = false"
    >
      <p>
        This takes the post off the live site and puts it back in Pending, held
        by you. You can then approve, edit or reject it like any other pending
        post.
      </p>
      <p>
        On another community it is on, the post goes back to that community's
        Pending queue too, unless one of its moderators has already approved or
        checked it.
      </p>
      <p class="mb-0">
        Spreading to further communities is paused until you decide. If you
        approve the post again it carries on spreading; if you reject it,
        spreading ends. The member is not told.
      </p>
    </ConfirmModal>
    <div v-if="!editreview && !modsHeld" class="d-lg-inline">
      <ModMessageButton
        v-for="stdmsg in filtered"
        :key="stdmsg.id"
        :variant="variant(stdmsg)"
        :icon="icon(stdmsg)"
        :label="stdmsg.title"
        :stdmsgid="stdmsg.id"
        :messageid="message.id"
        :groupid="groupid"
        :groupids="pending || spam ? groupids : null"
        :is-home-group="isHomeGroup"
        :can-message-poster="messagesPoster"
        :autosend="Boolean(stdmsg.autosend && allowAutoSend)"
      />
      <b-button
        v-if="rareToShow && !showRare"
        variant="white"
        class="mb-1"
        @click="showRare = true"
      >
        <v-icon icon="caret-down" /> +{{ rareToShow }}...
      </b-button>
    </div>
    <client-only>
      <div
        v-if="modMessagingAllowed && !modsHeld"
        class="mt-1 mb-1 d-flex flex-wrap"
      >
        <OurToggle
          v-model="allowAutoSend"
          :height="30"
          :width="150"
          :font-size="14"
          :sync="true"
          class="me-1"
          :labels="{ checked: 'Allow autosend', unchecked: 'Edit first' }"
          variant="modgreen"
        />
        <div class="small text-muted mt-1">
          Standard messages can be configured to send in a single click. This
          toggle temporarily disables that so you can edit first.
        </div>
      </div>
    </client-only>
  </div>
</template>
<script setup>
import { ref, computed, watch } from 'vue'
import { useMessageStore } from '~/stores/message'
import { useModConfigStore } from '~/stores/modconfig'
import { copyStdMsgs, icon, variant } from '~/composables/useStdMsgs'
import { useLockdown } from '~/modtools/composables/useLockdown'
import { useAutoapproveTrial } from '~/modtools/composables/useAutoapproveTrial'
import { useModMe } from '~/composables/useModMe'

const props = defineProps({
  messageid: {
    type: Number,
    required: true,
  },
  modconfigid: {
    type: Number,
    required: false,
    default: null,
  },
  editreview: {
    type: Boolean,
    required: false,
    default: false,
  },
  cantpost: {
    type: Boolean,
    required: false,
    default: false,
  },
  groupid: {
    type: Number,
    required: false,
    default: null,
  },
  // Every community a pending post's Approve, Reject and Delete act on at once, groupid
  // first; null for this community alone. See modtools/composables/multiGroupModeration.js.
  groupids: {
    type: Array,
    required: false,
    default: null,
  },
  // Whether this is the post's home/origin group. Removal is per-group in the API, but a
  // rippled-in group's moderators get the scoped, silent version of it (and Delete as
  // Spam, which is a judgement on the poster, not on the copy, stays with the home
  // group). Defaults true so non-rippling contexts are unaffected.
  // Whether this community may write to the poster about the post (canMessagePosterRow).
  // Null means not known, which falls back to isHomeGroup.
  canMessagePoster: {
    type: [Boolean, null],
    required: false,
    default: null,
  },
  isHomeGroup: {
    type: Boolean,
    required: false,
    default: true,
  },
  // False for a TN post whose poster never joined Freegle. Every action that would send
  // them something - Blank Reply, the standard messages, a Reject with an explanation -
  // is withdrawn; Approve, Delete and Hold are not. Defaults true so ordinary posts are
  // untouched. See the Go modmessaging package for the server-side half.
  modMessagingAllowed: {
    type: Boolean,
    required: false,
    default: true,
  },
  // Set to true ONLY from the Check oversight page: shows Checked and Pull back for Approved
  // posts. Pull back puts the post back in Pending, held by this moderator, and the card turns
  // into the usual Pending card so the moderator approves, edits or rejects it as normal.
  oversight: {
    type: Boolean,
    required: false,
    default: false,
  },
  // The community the Check page is filtered to, 0 for all of mine: Checked marks the post
  // looked at for that scope, as the page's bulk button does.
  checkGroupid: {
    type: Number,
    required: false,
    default: 0,
  },
})

const { modsHeld } = useLockdown()
const { isTrialGroup } = useAutoapproveTrial()
const { checkWork } = useModMe()

const messageStore = useMessageStore()
const modConfigStore = useModConfigStore()
const modconfig = computed(
  () => modConfigStore.configsById?.[props.modconfigid]
)

const message = computed(() => messageStore.byId(props.messageid))

watch(
  () => props.messageid,
  async (newVal) => {
    if (newVal && !messageStore.byId(newVal)) {
      await messageStore.fetch(newVal)
    }
  },
  { immediate: true }
)

const showRare = ref(false)
const allowAutoSend = ref(true)

// heldby is per-group (messages_groups.heldby); a hold on a DIFFERENT group this
// post also rippled to must not swap Hold for Release on the copy being
// administered here (Discourse 9970/2).
const heldByOnThisGroup = computed(() => {
  const groups = message.value?.groups || []
  const gid = props.groupid || groups[0]?.groupid
  const g = groups.find((grp) => parseInt(grp.groupid) === parseInt(gid))
  return g?.heldby || null
})

// A post that rippled to several groups has one row per group, each with its own
// collection. The buttons describe the copy being administered (props.groupid), not
// whichever other group still has the post waiting: with the old any-group reading, an
// Approved copy showed the Pending buttons while a neighbour's copy was still Pending, and
// a Delete there was refused by the server as "no longer pending" (Discourse 10102). With
// no group in context, fall back to any row, as before.
function hasCollection(coll) {
  const groups = message.value?.groups || []
  const scoped = props.groupid
    ? groups.filter((g) => parseInt(g.groupid) === parseInt(props.groupid))
    : groups
  return scoped.some((g) => g.collection === coll)
}

const pending = computed(() => {
  return hasCollection('Pending')
})

// The post's home community sent it back to pending, so a rippled-in copy cannot be approved
// until they approve theirs. groups[].locked_by_home is the effective lock, so it is already 0
// once the home copy is approved; the server refuses the approval as well.
const lockedByHome = computed(() => {
  const groups = message.value?.groups || []
  const gid = props.groupid || groups[0]?.groupid
  const g = groups.find((grp) => parseInt(grp.groupid) === parseInt(gid))
  return parseInt(g?.locked_by_home) === 1
})

const canApprove = computed(() => !props.cantpost && !lockedByHome.value)

const approved = computed(() => {
  return hasCollection('Approved')
})

// The oversight Reject pulls back a copy that published itself, so it is offered only on a
// copy that matches the Check list: Approved by nobody (approvedby null), posted directly
// here, on a community in the trial. A copy a neighbouring community's moderator approved by
// hand was never in Check and must not be pulled back from there, even when the same card
// shows both copies of a TrashNothing cross-post.
const oversightRejectable = computed(() => {
  const groups = message.value?.groups || []
  const gid = props.groupid || groups[0]?.groupid
  const g = groups.find((grp) => parseInt(grp.groupid) === parseInt(gid))
  return (
    !!g &&
    g.collection === 'Approved' &&
    !g.approvedby &&
    !parseInt(g.rippled_in) &&
    isTrialGroup(parseInt(gid))
  )
})

// Spam-collection messages are surfaced in the Pending review queue (Go API,
// Discourse #9654). They need the same moderation actions as Pending — without
// this they'd render with no action buttons at all (only the autosend toggle),
// leaving mods unable to approve/reject/delete them.
const spam = computed(() => {
  return hasCollection('Spam')
})

// Whether this community may write to the poster: its home, or one the post rippled into
// where the poster is an ordinary member (Discourse 10239). Unknown falls back to home.
const messagesPoster = computed(() =>
  props.canMessagePoster === null ? props.isHomeGroup : props.canMessagePoster
)

// Where this community may not write to the poster - a copy the post merely rippled into,
// whose poster has nothing to do with it - a standard message whose only effect is to
// write to the freegler has nothing to do, and the server refuses it (Discourse 10102).
// Offer only the ones that act on this group's own copy. Approving and holding are still
// available as plain buttons, they just carry no note.
const validActions = computed(() => {
  // The standard messages we show depend on the valid ones for this type of message.
  if (pending.value || spam.value) {
    if (!messagesPoster.value) {
      return ['Reject', 'Delete', 'Edit']
    }

    const ret = ['Reject', 'Leave', 'Delete', 'Edit', 'Hold Message']
    if (canApprove.value) {
      ret.push('Approve')
    }
    return ret
  } else if (approved.value) {
    if (!messagesPoster.value) {
      return ['Delete Approved Message', 'Edit']
    }

    return ['Leave Approved Message', 'Delete Approved Message', 'Edit']
  }

  return []
})

const stdmsgs = computed(() => {
  if (modconfig.value) {
    return copyStdMsgs(modconfig.value)
  } else {
    return []
  }
})

const filterByAction = computed(() => {
  if (!props.modMessagingAllowed) {
    return []
  }

  if (modconfig.value) {
    return stdmsgs.value.filter((stdmsg) => {
      return validActions.value.includes(stdmsg.action)
    })
  }

  return []
})

const filtered = computed(() => {
  if (modconfig.value) {
    return filterByAction.value.filter((stdmsg) => {
      return showRare.value || !parseInt(stdmsg.rarelyused)
    })
  }

  return []
})

const rareToShow = computed(() => {
  return filterByAction.value.length - filtered.value.length
})

function outcome(callback, type) {
  messageStore.updateMT({
    action: 'Outcome',
    id: props.messageid,
    outcome: type,
  })
  if (callback) callback()
}

// Check page only. What happened, said on the card when it did not go to plan: there is
// nowhere else for it, and a card that vanished with no word is how a mistake goes unnoticed.
const oversightNote = ref('')
const showPullBack = ref(false)
const pullingBack = ref(false)

const DID_NOT_GO_THROUGH = "That didn't go through. Try again in a moment."

// "Checked": the post leaves the Check queue and so does the card.
async function markChecked(callback) {
  oversightNote.value = ''
  try {
    await messageStore.markCheckedPost(props.messageid, props.checkGroupid)
    await checkWork(true)
  } catch (e) {
    oversightNote.value = DID_NOT_GO_THROUGH
  } finally {
    if (callback) callback()
  }
}

// "Pull back": ask first, because it withdraws the post from the live site on every community
// that has not looked at it. The button stops spinning as soon as the question is up.
function askPullBack(callback) {
  showPullBack.value = true
  if (callback) callback()
}

// The post goes back to Pending via markChecked({reject:true}) and stays in the list as a
// Pending card.
async function pullBack() {
  oversightNote.value = ''
  pullingBack.value = true
  try {
    const pulled = await messageStore.rejectFromOversight(
      props.messageid,
      props.groupid
    )
    if (!pulled) {
      oversightNote.value =
        'Nothing was pulled back: this post has changed since you loaded the page. It is shown as it is now.'
    }
  } catch (e) {
    oversightNote.value = DID_NOT_GO_THROUGH
  } finally {
    pullingBack.value = false
  }
  // The Check and Pending counts moved either way.
  await checkWork(true)
}
</script>
