<template>
  <div class="d-inline">
    <div class="position-relative d-inline-block">
      <SpinButton
        :variant="variant"
        :icon-name="icon"
        :label="label"
        class="mb-1"
        :spinclass="spinclass"
        :disabled="disabled"
        @handle="(callback) => guardHold(() => click(callback))"
      />
      <v-icon
        v-if="autosend"
        icon="chevron-circle-right"
        title="Autosend - configured to send immediately without edit"
        class="autosend"
      />
    </div>
    <NoticeMessage v-if="heldError" variant="warning" class="mt-1 mb-1">
      {{ heldError }}
    </NoticeMessage>
    <ConfirmModal
      v-if="showDeleteModal"
      ref="deleteConfirm"
      :title="'Delete: ' + (user ? user.displayname : '#' + userid)"
      @confirm="() => guardHold(deleteConfirmed)"
    />
    <ConfirmModal
      v-if="showSilentRemoveModal"
      ref="silentRemoveConfirm"
      title="Remove from your community"
      @confirm="() => guardHold(silentRemoveConfirmed)"
      @hidden="showSilentRemoveModal = false"
    >
      <template #default>
        <p class="mb-0">
          This member is only on your community because a post of theirs rippled
          in. Removing them here does not tell them anything, because they never
          joined - so there is no message to send.
        </p>
      </template>
    </ConfirmModal>
    <ModSpammerReport v-if="showSpamModal" ref="spamConfirm" :userid="userid" />
    <ModStdMessageModal
      v-if="showStdMsgModal"
      ref="stdmodal"
      :stdmsgid="stdmsgId"
      :stdmsgaction="stdmsgAction"
      :membershipid="membershipid"
      :autosend="autosend"
    />
  </div>
</template>
<script setup>
import { ref, computed, nextTick, watch } from 'vue'
import { useMemberStore } from '~/stores/member'
import { useHeldNotice } from '~/composables/useHeldNotice'
import { useUserStore } from '~/stores/user'
import { useSpammerStore } from '~/stores/spammer'
import { useStdmsgStore } from '~/stores/stdmsg'
import { useMe } from '~/composables/useMe'

const props = defineProps({
  userid: {
    type: Number,
    required: true,
  },
  groupid: {
    type: Number,
    required: false,
    default: null,
  },
  membershipid: {
    type: Number,
    required: false,
    default: null,
  },
  spammerid: {
    type: Number,
    required: false,
    default: null,
  },
  // The member's only tie to this group is a post of theirs that rippled in. Removing
  // them still happens; telling them does not, so there is no message to compose
  // (Discourse 10102).
  rippleOnly: {
    type: Boolean,
    required: false,
    default: false,
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
  delete: {
    type: Boolean,
    required: false,
    default: false,
  },
  release: {
    type: Boolean,
    required: false,
    default: false,
  },
  spamreport: {
    type: Boolean,
    required: false,
    default: false,
  },
  spamrequestremove: {
    type: Boolean,
    required: false,
    default: false,
  },
  spamremove: {
    type: Boolean,
    required: false,
    default: false,
  },
  spamconfirm: {
    type: Boolean,
    required: false,
    default: false,
  },
  spamsafelist: {
    type: Boolean,
    required: false,
    default: false,
  },
  reviewhold: {
    type: Boolean,
    required: false,
    default: false,
  },
  reviewrelease: {
    type: Boolean,
    required: false,
    default: false,
  },
  spamhold: {
    type: Boolean,
    required: false,
    default: false,
  },
  spamignore: {
    type: Boolean,
    required: false,
    default: false,
  },
  leave: {
    type: Boolean,
    required: false,
    default: false,
  },
  autosend: {
    type: Boolean,
    required: false,
    default: false,
  },
  reviewgroupid: {
    type: Number,
    required: false,
    default: null,
  },
})

const emit = defineEmits(['pressed'])

const memberStore = useMemberStore()
const userStore = useUserStore()
const spammerStore = useSpammerStore()
const { heldError, guardHold } = useHeldNotice()
const stdmsgStore = useStdmsgStore()
const { myid } = useMe()

const deleteConfirm = ref(null)
const spamConfirmRef = ref(null)
const stdmodal = ref(null)

const showDeleteModal = ref(false)
const showStdMsgModal = ref(false)
const showSilentRemoveModal = ref(false)

// Take a ripple-only member off this community with no message to them.
async function silentRemoveConfirmed() {
  await memberStore.delete({ id: props.userid, groupid: props.groupid })
}
const showSpamModal = ref(false)
const stdmsgId = ref(null)
const stdmsgAction = ref(null)

const user = computed(() => userStore.byId(props.userid))

watch(
  () => props.userid,
  (uid) => {
    if (uid && !userStore.byId(uid)) userStore.fetch(uid)
  },
  { immediate: true }
)

const spinclass = computed(() => {
  if (props.variant === 'primary') {
    return 'success'
  }

  return null
})

function approveIt() {
  alert('MMB memberStore.approve NOT DEFINED')
}

function deleteIt() {
  showDeleteModal.value = true
  deleteConfirm.value?.show()
}

function spamReport() {
  showSpamModal.value = true
  spamConfirmRef.value?.show()
}

async function spamConfirmAction() {
  await spammerStore.confirm({
    id: props.spammerid,
    userid: props.userid,
  })
}

async function spamRequestRemove() {
  try {
    await spammerStore.requestremove({
      id: props.spammerid,
      userid: props.userid,
    })
  } catch (e) {
    console.error('spamRequestRemove failed:', e)
    throw e
  }
}

async function spamRemove() {
  try {
    await spammerStore.remove({
      id: props.spammerid,
      userid: props.userid,
    })
  } catch (e) {
    console.error('spamRemove failed:', e)
    throw e
  }
}

async function spamSafelist() {
  await spammerStore.safelist({
    id: props.spammerid,
    userid: props.userid,
    myid: myid.value,
  })
}

async function spamHold() {
  await spammerStore.hold({
    id: props.spammerid,
    userid: props.userid,
    myid: myid.value,
  })
}

async function deleteConfirmed() {
  await memberStore.delete({
    id: props.userid,
    groupid: props.groupid,
  })
}

async function reviewHoldIt() {
  await memberStore.reviewHold({
    userid: props.userid,
    membershipid: props.membershipid,
    groupid: props.reviewgroupid ?? props.groupid,
  })
}

async function reviewReleaseIt() {
  await memberStore.reviewRelease({
    userid: props.userid,
    membershipid: props.membershipid,
    groupid: props.reviewgroupid ?? props.groupid,
  })
}

async function releaseIt() {
  await spammerStore.release({
    id: props.spammerid,
    userid: props.userid,
  })
}

async function click(callback) {
  try {
    if (props.approve) {
      await approveIt()
    } else if (props.delete) {
      await deleteIt()
    } else if (props.spamreport) {
      await spamReport()
    } else if (props.spamconfirm) {
      await spamConfirmAction()
    } else if (props.spamrequestremove) {
      await spamRequestRemove()
    } else if (props.spamremove) {
      await spamRemove()
    } else if (props.spamsafelist) {
      await spamSafelist()
    } else if (props.spamhold) {
      await spamHold()
    } else if (props.spamignore) {
      await memberStore.spamignore({
        userid: props.userid,
        groupid: props.groupid,
      })
    } else if (props.release) {
      console.log('Release')
      await releaseIt()
    } else if (props.reviewhold) {
      await reviewHoldIt()
    } else if (props.reviewrelease) {
      await reviewReleaseIt()
    } else {
      // We want to show a modal.
      stdmsgId.value = null
      stdmsgAction.value = null

      if (props.rippleOnly && props.stdmsgid) {
        // Only divert when the action is DEFINITIVELY a removal; an unresolvable standard
        // message falls through to the compose modal, and the server drops the message
        // either way, so failing this way cannot reach the member.
        const stdmsg = await stdmsgStore.fetch(props.stdmsgid)
        if (
          stdmsg?.action === 'Delete Approved Member' ||
          stdmsg?.action === 'Delete Member' ||
          stdmsg?.action === 'Reject'
        ) {
          showSilentRemoveModal.value = true
          return
        }
      }

      if (props.leave) {
        stdmsgAction.value = 'Leave Member'
      } else if (props.stdmsgid) {
        // We have a standard message.  Fetch it into the store.
        await stdmsgStore.fetch(props.stdmsgid)
        stdmsgId.value = props.stdmsgid
      }

      showStdMsgModal.value = true
      stdmodal.value?.show()
      await nextTick()
      stdmodal.value?.fillin()
    }
  } catch (e) {
    console.error('ModMemberButton action failed:', e)
  } finally {
    if (callback) callback()
  }
  emit('pressed')
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
