<template>
  <div class="d-inline">
    <div class="position-relative d-inline-block">
      <SpinButton
        :variant="variant"
        :spinclass="spinclass"
        :icon-name="icon"
        :label="label"
        :flex="false"
        class="mb-1 me-1 d-inline-block"
        icon-class="pe-1"
        :disabled="disabled"
        :confirm="confirmButton"
        @handle="click"
      />
      <v-icon
        v-if="autosend"
        icon="chevron-circle-right"
        title="Autosend - configured to send immediately without edit"
        class="autosend"
      />
    </div>
    <ConfirmModal
      v-if="showDeleteModal"
      ref="deleteConfirm"
      :title="'Delete: ' + message?.subject"
      @confirm="deleteConfirmed"
      @hidden="showDeleteModal = false"
    />
    <ConfirmModal
      v-if="showSpamModal"
      ref="spamConfirm"
      :title="'Mark as Spam: ' + message?.subject"
      @confirm="spamConfirmed"
      @hidden="showSpamModal = false"
    />
    <ModStdMessageModal
      v-if="showStdMsgModal"
      ref="stdmodal"
      :stdmsgid="stdmsgId"
      :stdmsgaction="stdmsgAction"
      :messageid="message?.id"
      :groupid="groupid"
      :autosend="autosend"
      @hidden="showStdMsgModal = false"
    />
    <ConfirmModal
      v-if="showRejectNoMsgModal"
      ref="rejectNoMsgConfirm"
      title="Stop this post appearing on your community"
      @confirm="rejectFromGroupConfirmed"
      @hidden="showRejectNoMsgModal = false"
    >
      <template #default>
        <p>
          This post first appeared on another community and rippled in to yours.
          Rejecting here just stops it appearing on
          <strong>{{ groupName || 'your community' }}</strong> - it stays on the
          community where it was first posted.
        </p>
        <p class="mb-0">
          The freegler won't be told, because they don't need to know unless it's
          rejected on their home community. So there's no message to send.
        </p>
      </template>
    </ConfirmModal>
  </div>
</template>
<script setup>
// SEE WORK EXPLANATION IN useModMessages.js

import { ref, computed, nextTick, watch } from 'vue'
import { useMessageStore } from '~/stores/message'
import { useStdmsgStore } from '~/stores/stdmsg'
import { useUserStore } from '~/stores/user'
import { useModMe } from '~/composables/useModMe'

const props = defineProps({
  messageid: {
    type: Number,
    required: true,
  },
  stdmsgid: {
    type: Number,
    required: false,
    default: null,
  },
  variant: {
    type: String,
    required: true,
  },
  label: {
    type: String,
    required: true,
  },
  icon: {
    type: String,
    required: true,
  },
  disabled: {
    type: Boolean,
    required: false,
    default: false,
  },
  approve: {
    type: Boolean,
    required: false,
    default: false,
  },
  delete: {
    type: Boolean,
    required: false,
    default: false,
  },
  hold: {
    type: Boolean,
    required: false,
    default: false,
  },
  holdMessage: {
    type: Boolean,
    required: false,
    default: false,
  },
  release: {
    type: Boolean,
    required: false,
    default: false,
  },
  reject: {
    type: Boolean,
    required: false,
    default: false,
  },
  leave: {
    type: Boolean,
    required: false,
    default: false,
  },
  spam: {
    type: Boolean,
    required: false,
    default: false,
  },
  approveedits: {
    type: Boolean,
    required: false,
    default: false,
  },
  revertedits: {
    type: Boolean,
    required: false,
    default: false,
  },
  autosend: {
    type: Boolean,
    required: false,
    default: false,
  },
  groupid: {
    type: Number,
    required: false,
    default: null,
  },
  // Whether the group being moderated is the post's home/origin group. On a
  // rippled-in (non-home) group a Reject just removes the post from this group
  // and sends no message to the freegler, so we skip the compose modal. Defaults
  // to false (NOT home) so a call site that forgets to wire this prop fails
  // CLOSED into the safe "no message" confirm rather than silently composing and
  // sending a message the server discards (Discourse 9862/13).
  isHomeGroup: {
    type: Boolean,
    required: false,
    default: false,
  },
})

const messageStore = useMessageStore()
const stdmsgStore = useStdmsgStore()
const userStore = useUserStore()
const { checkWorkDeferGetMessages } = useModMe()

const message = computed(() => messageStore.byId(props.messageid))

const fromUserId = computed(() => {
  const fu = message.value?.fromuser
  if (!fu) return null
  return typeof fu === 'object' ? fu.id : fu
})

function refreshFromUser() {
  if (fromUserId.value) {
    userStore.fetch(fromUserId.value, true)
  }
}

watch(
  () => props.messageid,
  (newVal) => {
    if (newVal && !messageStore.byId(newVal)) {
      messageStore.fetch(newVal)
    }
  },
  { immediate: true }
)

const stdmodal = ref(null)

const showDeleteModal = ref(false)
const showStdMsgModal = ref(false)
const showSpamModal = ref(false)
const showRejectNoMsgModal = ref(false)
const stdmsgId = ref(null)
const stdmsgAction = ref(null)

// Use contextual groupid prop if provided, otherwise fall back to first group.
const groupid = computed(() => {
  if (props.groupid) return props.groupid

  if (message.value && message.value.groups && message.value.groups.length) {
    return message.value.groups[0].groupid
  }

  return null
})

const groupName = computed(() => {
  const g = message.value?.groups?.find(
    (grp) => parseInt(grp.groupid) === parseInt(groupid.value)
  )
  return g?.namedisplay || null
})

const spinclass = computed(() => {
  if (props.variant === 'primary') {
    // Primary buttons have "success" (green) class.
    return 'success'
  }

  return null
})

const confirmButton = computed(() => {
  // We confirm any actions on held messages, except where we have a separate confirm.
  return message.value?.heldby && !props.spam && !props.delete
})

async function approveIt() {
  await messageStore.approve(message.value.id, groupid.value)
  refreshFromUser()
  checkWorkDeferGetMessages()
}

function deleteIt() {
  showDeleteModal.value = true
}

async function deleteConfirmed() {
  await messageStore.delete({
    id: message.value.id,
    groupid: groupid.value,
  })
  refreshFromUser()
  checkWorkDeferGetMessages()
}

async function spamConfirmed() {
  await messageStore.spam({
    id: message.value.id,
    groupid: groupid.value,
  })
  refreshFromUser()
  checkWorkDeferGetMessages()
}

async function rejectFromGroupConfirmed() {
  // Rippled-in (non-home) reject: just remove the post from this group, with no
  // message to the freegler (the server suppresses it anyway - they only need to
  // hear about a rejection on their home community). Target the group THIS
  // BUTTON was clicked for (its own groupid prop) - never fall back to
  // message.groups[0] - so a multi-group rippled-in post can't have its reject
  // land on the wrong (possibly home) group, which would wrongly notify the
  // poster (Discourse 9862/13).
  await messageStore.reject(message.value.id, props.groupid, '', null, '')
  refreshFromUser()
  checkWorkDeferGetMessages()
}

async function holdIt() {
  await messageStore.hold({
    id: message.value.id,
    groupid: groupid.value,
  })
  checkWorkDeferGetMessages()
}

async function releaseIt() {
  await messageStore.release({
    id: message.value.id,
    groupid: groupid.value,
  })
  checkWorkDeferGetMessages()
}

async function approveEdits() {
  await messageStore.approveedits({
    id: message.value.id,
  })
  checkWorkDeferGetMessages()
}

async function revertEdits() {
  await messageStore.revertedits({
    id: message.value.id,
  })
  checkWorkDeferGetMessages()
}

async function click(callback) {
  if (props.approve) {
    // Standard approve button - no modal.
    await approveIt()
  } else if (props.delete) {
    // Standard delete button - no modal.
    await deleteIt()
  } else if (props.hold) {
    // Standard hold button - no modal.
    await holdIt()
  } else if (props.release) {
    // Standard release button - no modal.
    await releaseIt()
  } else if (props.spam) {
    // Standard spam button.
    showSpamModal.value = true
  } else if (props.approveedits) {
    await approveEdits()
  } else if (props.revertedits) {
    await revertEdits()
  } else {
    // We want to show a modal.
    stdmsgId.value = null
    stdmsgAction.value = null

    if (props.stdmsgid) {
      // We have a standard message. Fetch it into the store so both the guard
      // below and the compose modal can read its real action/content.
      await stdmsgStore.fetch(props.stdmsgid)
    }

    // Resolve whether this click might REJECT. A standard message can itself be
    // configured with action 'Reject' (e.g. a canned "Reject: Duplicate"), not
    // just the dedicated Reject button - so the same rippled-in guard must apply
    // to it. Read the action back via the store GETTER (not fetch()'s resolved
    // value, which isn't a proven contract). Fail CLOSED: if the action can't be
    // positively resolved to something other than Reject (store miss/race ->
    // undefined), treat it as though it might be a Reject rather than falling
    // through to compose-and-send - losing a mod's message is worse than an
    // extra confirm click (Discourse 9862/13).
    const resolvedStdmsgAction = props.stdmsgid
      ? stdmsgStore.byId(props.stdmsgid)?.action
      : undefined
    const mightReject =
      props.reject ||
      (Boolean(props.stdmsgid) &&
        (resolvedStdmsgAction === 'Reject' ||
          resolvedStdmsgAction === undefined))

    if (mightReject && !props.isHomeGroup) {
      // Rippled-in reject: confirm a no-message removal instead of composing one.
      showRejectNoMsgModal.value = true
      if (callback) callback()
      return
    }

    if (props.reject) {
      stdmsgAction.value = 'Reject'
    } else if (props.leave) {
      stdmsgAction.value = 'Leave'
    } else if (props.stdmsgid) {
      stdmsgId.value = props.stdmsgid
    }

    showStdMsgModal.value = true
    stdmodal.value?.show()
    await nextTick()
    stdmodal.value?.fillin()
  }
  if (callback) callback()
}
</script>
<style scoped lang="scss">
.autosend {
  right: 4px;
  bottom: 0px;
  position: absolute;
  color: $color-purple;
}
</style>
