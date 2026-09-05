<template>
  <div v-if="message">
    <div v-if="editreview" class="d-inline">
      <ModMessageButton
        :messageid="message.id"
        :groupid="groupid"
        variant="primary"
        icon="check"
        approveedits
        label="Accept Edit"
      />
      <ModMessageButton
        :messageid="message.id"
        :groupid="groupid"
        variant="danger"
        icon="times"
        revertedits
        label="Reject Edit"
      />
      <ModMessageButton
        :messageid="message.id"
        :groupid="groupid"
        variant="primary"
        icon="envelope"
        leave
        label="Blank Reply"
      />
    </div>
    <div v-else-if="pending || spam" class="d-inline">
      <ModMessageButton
        v-if="!cantpost"
        :messageid="message.id"
        :groupid="groupid"
        variant="primary"
        icon="check"
        approve
        label="Approve"
      />
      <ModMessageButton
        :messageid="message.id"
        :groupid="groupid"
        :is-home-group="isHomeGroup"
        variant="warning"
        icon="times"
        reject
        label="Reject"
      />
      <ModMessageButton
        v-if="isHomeGroup"
        :messageid="message.id"
        :groupid="groupid"
        variant="danger"
        icon="trash-alt"
        delete
        label="Delete"
      />
      <ModMessageButton
        v-if="!heldByOnThisGroup"
        :messageid="message.id"
        :groupid="groupid"
        variant="warning"
        icon="pause"
        hold
        label="Hold"
      />
      <ModMessageButton
        v-else
        :messageid="message.id"
        :groupid="groupid"
        variant="success"
        icon="play"
        release
        label="Release"
      />
      <ModMessageButton
        v-if="isHomeGroup"
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
        v-if="isHomeGroup"
        :messageid="message.id"
        :groupid="groupid"
        variant="primary"
        icon="envelope"
        leave
        label="Blank Reply"
      />
      <SpinButton
        v-if="oversight && approved"
        variant="warning"
        class="m-1"
        icon-name="times"
        label="Reject (back to Pending)"
        confirm
        :flex="false"
        @handle="rejectFromOversight"
      />
      <ModMessageButton
        v-if="isHomeGroup"
        :messageid="message.id"
        :groupid="groupid"
        variant="danger"
        icon="trash-alt"
        delete
        label="Delete"
      />
      <ModMessageButton
        v-if="isHomeGroup"
        :messageid="message.id"
        :groupid="groupid"
        variant="danger"
        icon="ban"
        spam
        label="Delete as Spam"
      />
      <SpinButton
        v-if="message.type === 'Offer' && !message.outcomes?.length"
        variant="white"
        class="m-1"
        icon-name="check"
        label="Mark as TAKEN"
        confirm
        :flex="false"
        @handle="outcome($event, 'Taken')"
      />
      <SpinButton
        v-if="message.type === 'Wanted' && !message.outcomes?.length"
        variant="white"
        class="m-1"
        icon-name="check"
        label="Mark as RECEIVED"
        confirm
        :flex="false"
        @handle="outcome($event, 'Received')"
      />
      <SpinButton
        v-if="!message.outcomes?.length"
        variant="white"
        class="m-1"
        icon-name="trash-alt"
        label="Mark as Withdrawn"
        confirm
        :flex="false"
        @handle="outcome($event, 'Withdrawn')"
      />
    </div>
    <div v-if="!editreview" class="d-lg-inline">
      <ModMessageButton
        v-for="stdmsg in filtered"
        :key="stdmsg.id"
        :variant="variant(stdmsg)"
        :icon="icon(stdmsg)"
        :label="stdmsg.title"
        :stdmsgid="stdmsg.id"
        :messageid="message.id"
        :groupid="groupid"
        :is-home-group="isHomeGroup"
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
      <div class="mt-1 mb-1 d-flex flex-wrap">
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
  // Whether this is the post's home/origin group. Removal is per-group in the API, but a
  // rippled-in group's moderators get the scoped, silent version of it (and Delete as
  // Spam, which is a judgement on the poster, not on the copy, stays with the home
  // group). Defaults true so non-rippling contexts are unaffected.
  isHomeGroup: {
    type: Boolean,
    required: false,
    default: true,
  },
  // Set to true ONLY from the checked/trusted oversight pages: shows a "Reject (back to Pending)"
  // button for Approved posts so a mod can pull an auto-published post back into Pending via the
  // markChecked endpoint. Not shown in the regular Approved view.
  oversight: {
    type: Boolean,
    required: false,
    default: false,
  },
})

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

function hasCollection(coll) {
  let ret = false

  if (message.value?.groups) {
    message.value.groups.forEach((group) => {
      if (group.collection === coll) {
        ret = true
      }
    })
  }

  return ret
}

const pending = computed(() => {
  return hasCollection('Pending')
})

const approved = computed(() => {
  return hasCollection('Approved')
})

// Spam-collection messages are surfaced in the Pending review queue (Go API,
// Discourse #9654). They need the same moderation actions as Pending — without
// this they'd render with no action buttons at all (only the autosend toggle),
// leaving mods unable to approve/reject/delete them.
const spam = computed(() => {
  return hasCollection('Spam')
})

// On a copy the post merely rippled into, a standard message whose only effect is to
// write to the freegler has nothing to do: correspondence about a post belongs to the
// community it was posted on, and the server refuses it (Discourse 10102). Offer only
// the ones that act on this group's own copy. Approving and holding are still available
// as plain buttons, they just carry no note.
const validActions = computed(() => {
  // The standard messages we show depend on the valid ones for this type of message.
  if (pending.value || spam.value) {
    if (!props.isHomeGroup) {
      return ['Reject', 'Delete', 'Edit']
    }

    const ret = ['Reject', 'Leave', 'Delete', 'Edit', 'Hold Message']
    if (!props.cantpost) {
      ret.push('Approve')
    }
    return ret
  } else if (approved.value) {
    if (!props.isHomeGroup) {
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

// Oversight Reject button (checked/trusted pages only): send the post back to Pending via
// markChecked({reject:true}) and drop it from the local store so it leaves the oversight list.
async function rejectFromOversight(callback) {
  await messageStore.rejectFromOversight(props.messageid, props.groupid)
  if (callback) callback()
}
</script>
