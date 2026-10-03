<template>
  <div>
    <div
      v-if="fromuser"
      class="grey p-2 clickme minwidth"
      :title="'Click to view profile for ' + fromuser.displayname"
    >
      <div class="d-flex align-content-center">
        <ProfileImage
          v-if="fromuser?.profile"
          :image="fromuser.profile.paththumb"
          class="me-1 inline"
          is-thumbnail
          size="md"
          @click="showProfileModal"
        />
        <div class="d-flex flex-column order-0" @click="showProfileModal">
          <span class="text-success fw-bold">{{ fromuser?.displayname }}</span>
          <span
            v-if="fromuser?.info?.openoffers + fromuser?.info?.openwanteds > 0"
            class="text--small"
            @click="showProfileModal"
          >
            <span v-if="fromuser.info.openoffers" class="text-success">
              {{ openOfferPlural }}
            </span>
            <span v-if="fromuser.info.openoffers && fromuser.info.openwanteds">
              &bull;
            </span>
            <span v-if="fromuser.info.openwanteds" class="text-success">
              {{ openWantedPlural }}
            </span>
          </span>
        </div>
        <nuxt-link
          v-if="message.interacted"
          no-prefetch
          :to="'/chats/' + message.interacted"
          class="fw-bold"
          title="You've chatted to this freegler before.  Click here to view Chat."
        >
          <v-icon icon="link" /> Connected before
        </nuxt-link>
      </div>
      <SupporterInfo v-if="fromuser?.supporter" class="d-inline" />
      <div
        v-if="milesaway"
        :title="milesIsRoad ? DISTANCE_TOOLTIP_ROAD : DISTANCE_TOOLTIP"
        class="align-middle"
        @click="showProfileModal"
      >
        About {{ milesPlural }} away<span v-if="milesIsRoad"> by road</span>
      </div>
      <div class="d-flex flex-wrap align-items-center">
        <span class="small text-muted" :title="message.arrival">{{
          arrivalago
        }}</span>
      </div>
    </div>
    <LazyProfileModal
      v-if="showProfile && message && fromuser"
      :id="fromuser.id"
      @hidden="showProfile = false"
    />
  </div>
</template>
<script setup>
import { ref, computed } from 'vue'
import pluralize from 'pluralize'
import { milesAway } from '~/composables/useDistance'
import { DISTANCE_TOOLTIP, DISTANCE_TOOLTIP_ROAD } from '~/constants'
import { roadDistance, roadMilesRounded } from '~/composables/useDriveDistance'
import { useUserStore } from '~/stores/user'
import ProfileImage from '~/components/ProfileImage'
import { useMessageStore } from '~/stores/message'
import { timeago } from '~/composables/useTimeFormat'
import { useMe } from '~/composables/useMe'

const props = defineProps({
  id: {
    type: Number,
    default: 0,
  },
})

const messageStore = useMessageStore()
const userStore = useUserStore()
const { me } = useMe()

const showProfile = ref(false)

// Fetch user data
const currentMessage = messageStore.byId(props.id)
if (currentMessage) {
  userStore.fetch(currentMessage.fromuser)
}

// Computed properties
const message = computed(() => {
  return messageStore?.byId(props.id)
})

const fromuser = computed(() => {
  return message.value?.fromuser
    ? userStore?.byId(message.value?.fromuser)
    : null
})

const roadDist = computed(() => {
  if (message.value?.roadmins != null) {
    // Shipped with the message fetch itself (server-side batched call).
    return { mins: message.value.roadmins, miles: message.value.roadmiles }
  }
  if (!message.value?.lat) {
    return null
  }
  return roadDistance(message.value.lat, message.value.lng).value
})

const milesaway = computed(() => {
  const road = roadDist.value
  if (road?.miles != null) {
    return roadMilesRounded(road.miles)
  }
  return milesAway(me?.lat, me?.lng, message.value?.lat, message.value?.lng)
})

const milesPlural = computed(() => {
  return pluralize('mile', milesaway.value, true)
})

const milesIsRoad = computed(() => roadDist.value?.miles != null)

const openOfferPlural = computed(() => {
  return message.value && fromuser.value && fromuser.value.info
    ? pluralize('open OFFER', fromuser.value.info.openoffers, true)
    : null
})

const openWantedPlural = computed(() => {
  return message.value && fromuser.value && fromuser.value.info
    ? pluralize('open WANTED', fromuser.value.info.openwanteds, true)
    : null
})

const arrivalago = computed(() => {
  return timeago(message.value?.arrival, true)
})

// Methods
function showProfileModal(e) {
  // If either modifier key is held when clicking, open the profile accordingly
  if (e.shiftKey || e.ctrlKey) {
    e.preventDefault()
    e.stopPropagation()
    // Handle the ctrl key (new tab)
    if (e.ctrlKey) {
      window.open('/profile/' + currentMessage.fromuser, '_BLANK')
      return
    }
    // Handle the shift key (new window)
    if (e.shiftKey) {
      window.open('/profile/' + currentMessage.fromuser, '_NEW')
      return
    }
  }

  showProfile.value = true
}
</script>
<style scoped lang="scss">
.grey {
  background-color: $color-gray--lighter;
}

.minwidth {
  min-width: 250px;
}
</style>
