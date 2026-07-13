<template>
  <div>
    <div
      v-for="group in displayGroups"
      :key="'message-' + message.id + '-' + group.id"
      class="text--small"
    >
      <client-only>
        <span :title="group.arrival" class="time"
          >{{ grouparrivalago(group.arrival) }}
          <span v-if="showSummaryDetails">on </span>
        </span>
      </client-only>
      <v-icon
        v-if="showSummaryDetails && parseInt(group.groupid) === postHomeGroupId"
        icon="home"
        class="me-1 text-muted"
        title="Home community (where this was originally posted)"
      />
      <nuxt-link
        v-if="group.groupid in groups && showSummaryDetails"
        no-prefetch
        :to="'/explore/' + groups[group.groupid].exploreLink + '?noguard=true'"
        :title="'Click to view ' + groups[group.groupid].namedisplay"
      >
        {{ groups[group.groupid].namedisplay }}
      </nuxt-link>
      <client-only>
        <b-button
          v-if="showSummaryDetails"
          variant="link"
          :to="
            modinfo && group.groupid
              ? '/messages/' +
                (['Pending', 'PendingOther', 'Spam'].includes(group.collection)
                  ? 'pending'
                  : 'approved') +
                '/' +
                group.groupid +
                '/' +
                message.id
              : '/message/' + message.id
          "
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
      <span
        v-if="approvedByFor(group) && showSummaryDetails"
        class="text-faded small"
      >
        Approved by {{ approvedByFor(group) }}
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
        First posted on {{ message.postings[0].namedisplay }} on
        {{ datetime(message.postings[0].date) }}
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
import { useGroupStore } from '~/stores/group'
import { timeago } from '~/composables/useTimeFormat'
import { useMiscStore } from '~/stores/misc'
import { useMe } from '~/composables/useMe'
import { homeGroupFirst, homeGroupId } from '~/composables/rippleStatus'

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
  // When set (mod multi-group view), show only this group's arrival line instead of
  // listing every group the post is on - the mod administers one group's copy at a time.
  onlyGroupid: {
    type: Number,
    required: false,
    default: null,
  },
})

// The groups to list: just the current group when onlyGroupid is set, else all of them.
// `message` is a computed declared below; the closure resolves it at access time.
const displayGroups = computed(() => {
  const groups = message.value?.groups || []
  if (props.onlyGroupid) {
    return groups.filter((g) => parseInt(g.groupid) === props.onlyGroupid)
  }
  // Home/origin group first, so it leads the list of communities the post appears on.
  return homeGroupFirst(groups)
})

// The post's home/origin group id, used to mark it with a home icon in the list.
const postHomeGroupId = computed(() => homeGroupId(message.value?.groups || []))

const groupStore = useGroupStore()
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
  // Fetch any approving mod. No need to wait.
  const currentMessage = messageStore.byId(props.id)

  if (currentMessage?.groups) {
    // Might fail, e.g. network, but we don't much mind if it does - we'd just not show the approving mod.
    for (const group of currentMessage.groups) {
      if (group?.approvedby) {
        const approver = Number.isInteger(group.approvedby) // MT
          ? group.approvedby
          : group.approvedby.id
        userStore.fetch(approver)
      }
    }
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

// Approving mod for one specific group row, never borrowed from a sibling group on the
// same message - a rippled-in copy is auto-approved (approvedby is null) independently of
// whatever human approval happened on the post's origin group, so each row must only ever
// reflect its own group's approvedby (Discourse 9890: a rippled-in copy was mis-shown as
// approved by the origin group's moderator).
function approvedByFor(group) {
  if (!mod.value || !group?.approvedby) return ''

  // Handle both Go API (numeric ID) and PHP API (object with displayname)
  if (Number.isInteger(group.approvedby)) {
    // Go API returns numeric ID - look up in userStore
    const user = userStore.byId(group.approvedby)
    return user?.displayname || ''
  }
  // PHP API returns object with displayname
  return group.approvedby.displayname
}

const groups = computed(() => {
  const ret = {}

  message.value?.groups.forEach((g) => {
    const thegroup = groupStore?.get(g.groupid)

    if (thegroup) {
      ret[g.groupid] = thegroup

      // Better to link to the group by name if possible to avoid nuxt generate creating explore pages for the
      // id variants.
      ret[g.groupid].exploreLink = thegroup ? thegroup.nameshort : g.groupid
    }
  })

  return ret
})

// Each row in the v-for is a specific group, so show that group's own
// arrival time rather than collapsing every row onto the first group.
function grouparrivalago(arrival) {
  return timeago(arrival, true)
}

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
