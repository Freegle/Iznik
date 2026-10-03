<template>
  <div>
    <div class="text--small">
      <client-only>
        <span :title="message.arrival" class="time">
          {{ arrivalago }}
        </span>
      </client-only>
      <client-only>
        <b-button
          v-if="showSummaryDetails"
          variant="link"
          :to="'/message/' + message.id"
          class="text-faded text-decoration-none p-0 ms-2"
          size="xs"
        >
          #{{ message.id }}
        </b-button>
      </client-only>
      <span v-if="modinfo">
        via {{ source }},
        <span v-if="message.fromip">
          from IP
          <span v-if="message.fromip.length > 16">
            hash {{ message.fromip }}
          </span>
          <span v-else> address {{ message.fromip }} </span>
          <span v-if="message.fromcountry">
            in
            <span
              :class="
                message.fromcountry === 'United Kingdom' ? '' : 'text-danger'
              "
              >{{ message.fromcountry }}.</span
            >
          </span>
        </span>
        <span v-else> IP unavailable. </span>
      </span>
      <span v-if="approvedby && showSummaryDetails" class="text-faded small">
        Approved by {{ approvedby }}
      </span>
    </div>
    <div
      v-if="
        modinfo &&
        message.postings &&
        message.postings.length &&
        message.postings[0].date !== message.date
      "
      class="small"
    >
      <span v-if="!today">
        First posted on {{ datetime(message.postings[0].date) }}
      </span>
    </div>
  </div>
</template>
<script setup>
import dayjs from 'dayjs' // MT
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { useAuthStore } from '~/stores/auth'
import { useUserStore } from '~/stores/user'
import { useMessageStore } from '~/stores/message'
import { timeago } from '~/composables/useTimeFormat'
import { useMiscStore } from '~/stores/misc'
import { useMe } from '~/composables/useMe'

const props = defineProps({
  id: {
    type: Number,
    required: true,
  },
  summary: {
    type: Boolean,
    required: false,
    default: false,
  },
  displayMessageLink: {
    // MT
    type: Boolean,
    required: false,
    default: false,
  },
  modinfo: {
    type: Boolean,
    required: false,
    default: false,
  },
})

const messageStore = useMessageStore()
const authStore = useAuthStore()
const userStore = useUserStore()
const miscStore = useMiscStore()
const { mod } = useMe()

// Get access to miscStore breakpoint
const { breakpoint } = storeToRefs(miscStore)

// Fetch any approving mod when component is created
const me = authStore.user

if (
  me &&
  (me.systemrole === 'Moderator' ||
    me.systemrole === 'Support' ||
    me.systemrole === 'Admin')
) {
  // A message has one moderation decision, not one per group
  // (messages.approvedby). Fetch the approving mod. Might fail, e.g.
  // network, but we don't much mind if it does - we'd just not show them.
  const currentMessage = messageStore.byId(props.id)
  const approver = currentMessage?.approvedby

  if (approver) {
    const approverId = Number.isInteger(approver) // MT
      ? approver
      : approver.id
    userStore.fetch(approverId)
  }
}

// Computed properties
const message = computed(() => {
  return messageStore?.byId(props.id)
})

const showSummaryDetails = computed(() => {
  return (
    !props.summary || (breakpoint.value !== 'xs' && breakpoint.value !== 'sm')
  )
})

const approvedby = computed(() => {
  let result = ''

  if (mod.value) {
    const approver = message.value?.approvedby

    if (approver) {
      // Handle both Go API (numeric ID) and PHP API (object with displayname)
      if (Number.isInteger(approver)) {
        // Go API returns numeric ID - look up in userStore
        const user = userStore.byId(approver)
        result = user?.displayname || ''
      } else {
        // PHP API returns object with displayname
        result = approver.displayname
      }
    }
  }

  return result
})

const arrivalago = computed(() => {
  return timeago(message.value?.arrival, true)
})

const today = computed(() => {
  // MT
  return dayjs(message.value.date).isSame(dayjs(), 'day')
})

const source = computed(() => {
  // MT
  if (
    message.value.source === 'Email' &&
    message.value.fromaddr &&
    message.value.fromaddr.includes('trashnothing.com')
  ) {
    return 'TrashNothing'
  } else if (message.value.sourceheader === 'Freegle App') {
    return 'Freegle Mobile App'
  } else if (message.value.source === 'Platform') {
    return 'Freegle website'
  } else {
    return message.value.source
  }
})
</script>
<style scoped lang="scss">
@import 'bootstrap/scss/_functions';
@import 'bootstrap/scss/_variables';
@import 'bootstrap/scss/mixins/_breakpoints';

.time {
  font-size: 0.75rem;

  @include media-breakpoint-up(md) {
    font-size: 1rem;
  }
}
</style>
