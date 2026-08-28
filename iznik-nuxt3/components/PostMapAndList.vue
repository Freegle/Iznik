<template>
  <div>
    <h2 class="visually-hidden">Map of offers and wanteds</h2>
    <PostMap
      v-if="initialBounds"
      v-model:ready="mapready"
      v-model:bounds="bounds"
      v-model:show-groups="showGroups"
      v-model:moved="mapMoved"
      v-model:zoom="zoom"
      v-model:centre="centre"
      v-model:loading="loading"
      :show-isochrones="showIsochrones"
      :initial-bounds="initialBounds"
      :height-fraction="heightFraction"
      :min-zoom="minZoom"
      :max-zoom="maxZoom"
      :post-zoom="10"
      :force-messages="forceMessages"
      :type="selectedType"
      :search="search"
      :show-many="showMany"
      :groupid="selectedGroup"
      :region="region"
      :can-hide="canHide"
      :isochrone-override="isochroneOverride"
      :authorityid="authorityid"
      :selected-max-distance="selectedMaxDistance"
      :browse-search="browseSearch"
      @searched="searched"
      @messages="messagesChanged($event)"
      @groups="groupsChanged($event)"
      @idle="$emit('idle', $event)"
    />
    <div v-observe-visibility="mapVisibilityChanged" />
    <div class="rest">
      <div
        v-if="showClosestGroups && closestGroups?.length && !mapHidden"
        class="mb-1 border p-2 bg-white"
      >
        <h2 class="visually-hidden">Nearby communities</h2>
        <div class="d-flex flex-wrap justify-content-center gap-2">
          <JoinWithConfirm
            v-for="g in closestGroups"
            :id="g.id"
            :key="'group-' + g.id"
            :name="g.namedisplay"
            size="sm"
            variant="primary"
          />
        </div>
      </div>
      <div v-if="showGroups" class="bg-white pt-3">
        <div v-if="showRegions">
          <div class="d-flex flex-wrap justify-content-center pb-4">
            <div v-for="r in regions" :key="r" class="p-0 mt-2 ms-2 me-2">
              <b-button variant="secondary" :to="'/explore/region/' + r">
                {{ r }}
              </b-button>
            </div>
          </div>
        </div>
        <div v-if="showGroupList">
          <h2 class="visually-hidden">List of communities</h2>
          <AdaptiveMapGroup
            v-for="groupid in groupids"
            :id="groupid"
            :key="'adaptivegroup-' + groupid"
          />
        </div>
        <p
          class="text-center mt-2 header--size5 text--medium-large-highlight community__text"
        >
          <!-- eslint-disable-next-line -->
          Need help? Go <nuxt-link no-prefetch to="/help">here</nuxt-link>.
        </p>
        <p
          v-if="showStartMessage"
          class="text-center mt-2 header--size5 text--medium-large-highlight community__text"
        >
          <!-- eslint-disable-next-line -->
          If there's no community for your area, would you like to start one?
          <ExternalLink href="mailto:newgroups@ilovefreegle.org"
            >Get in touch!</ExternalLink
          >
        </p>
      </div>
      <div v-else>
        <NoticeMessage v-if="noneFound">
          <p>
            Sorry, we didn't find anything. Things come and go quickly, though,
            so you could try later. Or you could:
          </p>
          <GiveAsk class="bg-info" />
        </NoticeMessage>
        <div
          v-else-if="!postsVisible && messagesOnMap?.length"
          class="d-flex justify-content-center mt-1 mb-1"
        >
          <NoticeMessage variant="info">
            <v-icon icon="angle-double-down" class="pulsate" />
            Scroll down to see
            <span v-if="search"
              >results for "<strong>{{ search }}</strong
              >"</span
            ><span v-else>the posts</span>.
            <v-icon icon="angle-double-down" class="pulsate" />
          </NoticeMessage>
        </div>
        <h2 class="visually-hidden">List of wanteds and offers</h2>
        <MessageList
          v-if="updatedMessagesOnMap || messagesOnMap.length"
          :key="'messagelist-' + infiniteId"
          v-model:visible="postsVisible"
          v-model:none="noneFound"
          :search="search"
          show-counts-unseen
          :selected-group="selectedGroup"
          :selected-type="selectedType"
          :selected-sort="selectedSort"
          :messages-for-list="filteredMessages"
          :loading="loading"
          :jobs="jobs"
          :first-seen-message="firstSeenMessage"
        />
      </div>
    </div>
  </div>
</template>
<script setup>
import { ref, computed, watch, defineAsyncComponent } from 'vue'
import { useGroupStore } from '~/stores/group'
import { useAuthStore } from '~/stores/auth'
import { useMiscStore } from '~/stores/misc'
import { getDistance } from '~/composables/useMap'
import {
  filterMessagesByDistance,
  browseSliderMinuteCheck,
} from '~/composables/useDistance'
import { sortBrowseMessages } from '~/composables/useMessageSort'
import {
  roadDistance,
  roadAnswersVersion,
} from '~/composables/useDriveDistance'
import { MAX_MAP_ZOOM, BROWSE_DISTANCE_UNLIMITED } from '~/constants'
import { useMessageStore } from '~/stores/message'
import { useNearbyStore } from '~/stores/nearby'

import JoinWithConfirm from '~/components/JoinWithConfirm'
import MessageList from '~/components/MessageList'
const AdaptiveMapGroup = defineAsyncComponent(() => import('./MapGroup'))
const ExternalLink = defineAsyncComponent(() => import('./ExternalLink'))
const NoticeMessage = defineAsyncComponent(() => import('./NoticeMessage'))
const GiveAsk = defineAsyncComponent(() => import('./GiveAsk'))
const PostMap = defineAsyncComponent(() => import('~/components/PostMap'))

const props = defineProps({
  initialBounds: {
    type: Array,
    required: true,
  },
  startOnGroups: {
    type: Boolean,
    required: false,
    default: false,
  },
  forceMessages: {
    type: Boolean,
    required: false,
    default: false,
  },
  initialGroupIds: {
    type: Array,
    required: false,
    default() {
      return []
    },
  },
  region: {
    type: String,
    required: false,
    default: null,
  },
  showStartMessage: {
    type: Boolean,
    required: false,
    default: false,
  },
  jobs: {
    type: Boolean,
    required: false,
    default: false,
  },
  minZoom: {
    type: Number,
    required: false,
    default: 5,
  },
  maxZoom: {
    type: Number,
    required: false,
    default: MAX_MAP_ZOOM,
  },
  showMany: {
    type: Boolean,
    required: false,
    default: true,
  },
  canHide: {
    type: Boolean,
    required: false,
    default: false,
  },
  search: {
    type: String,
    required: false,
    default: null,
  },
  selectedType: {
    type: String,
    required: false,
    default: 'All',
  },
  selectedGroup: {
    type: Number,
    required: false,
    default: 0,
  },
  selectedSort: {
    type: String,
    required: false,
    default: 'Unseen',
  },
  showClosestGroups: {
    type: Boolean,
    required: false,
    default: true,
  },
  isochroneOverride: {
    type: Object,
    required: false,
    default: null,
  },
  authorityid: {
    type: Number,
    required: false,
    default: null,
  },
  // Set by the Browse page: searches pass browse=1 so the server scopes the
  // search universe to the member's browse feed for their current filters
  // (see PostMap's prop of the same name).
  browseSearch: {
    type: Boolean,
    required: false,
    default: false,
  },
  // Rippling-out relevance ordering + distance slider: the member's current maximum
  // distance preference (miles), or BROWSE_DISTANCE_UNLIMITED (the default) to defer to
  // the server's own reach limit. Filtered locally against each post's blurred
  // `distance` field for an instant response as the slider moves.
  selectedMaxDistance: {
    type: Number,
    required: false,
    default: BROWSE_DISTANCE_UNLIMITED,
  },
})

const emit = defineEmits([
  'update:selectedGroup',
  'update:messagesOnMapCount',
  'idle',
])

// Store instances
const miscStore = useMiscStore()
const groupStore = useGroupStore()
const authStore = useAuthStore()
const messageStore = useMessageStore()
const nearbyStore = useNearbyStore()
const me = computed(() => authStore.user)

// Refs from setup
const showGroups = ref(props.startOnGroups)
const groupids = ref(props.initialGroupIds)

// Data properties
const heightFraction = ref(4)
const loading = ref(false)
const bounds = ref(null)
const zoom = ref(null)
const centre = ref(null)
const mapready = ref(import.meta.server)
const mapVisible = ref(true)
const postsVisible = ref(true)
const mapMoved = ref(false)
const updatedMessagesOnMap = ref(null)
const firstSeenMessage = ref(null)
const infiniteId = ref(+new Date())
const noneFound = ref(false)
const lastFilteredIds = ref(null)
// Lock in the sort order once messages are loaded. This prevents the list from
// jumping around as messages are marked as seen. Only re-sort when the actual
// set of message IDs changes (new messages arrive or messages are removed).
const lockedSortOrder = ref(null)

// Computed properties
const browseView = computed(() => {
  return me.value?.settings?.browseView
    ? me.value.settings.browseView
    : 'nearby'
})

// Whether PostMap should use its "nearby" data path (the server-computed reach feed). The
// name is historical - there's no longer a per-user isochrone POLYGON for plain nearby
// browsing (reach is worked out server-side) - but this flag still selects the nearby feed
// in PostMap.getMessages, so it must be true for the nearby view, not just for an explicit
// polygon override (e.g. the fixed Essex boundary on the Essex landing page).
const showIsochrones = computed(() => {
  return !!props.isochroneOverride || browseView.value === 'nearby'
})

const mapHidden = computed(() => {
  return miscStore?.get('hidepostmap')
})

const messagesOnMap = computed({
  get() {
    if (updatedMessagesOnMap.value !== null) {
      // We have been told by the map to show a specific set of messages.
      return updatedMessagesOnMap.value
    } else {
      // See if we have some from the nearby feed, which we will have fetched in browse/index.
      return nearbyStore?.messageList ?? []
    }
  },
  set(newVal) {
    updatedMessagesOnMap.value = newVal
  },
})

const regions = computed(() => {
  const regions = []

  try {
    const allGroups = groupStore?.summaryList

    for (const ix in allGroups) {
      const group = allGroups[ix]

      if (group.region && !regions.includes(group.region)) {
        regions.push(group.region)
      }
    }

    regions.sort()
  } catch (e) {
    console.error('Exception', e)
  }

  return regions
})

const messagesForList = computed(() => {
  let msgs = []

  msgs = sortedMessagesOnMap.value

  if (props.selectedGroup) {
    msgs = msgs.filter((m) => m.groupid === props.selectedGroup)
  }

  // Distance slider: the feed already returns the full reach set, so this is a local,
  // instant filter rather than a refetch. Posts with no distance (e.g. an older feed
  // response before the API returned it) always pass, so we don't hide anything on a
  // stale/partial response. Shared with PostMap's own marker/coverage filtering so the
  // list and the map can never disagree about which posts are within range.
  msgs = filterMessagesByDistance(
    msgs,
    props.selectedMaxDistance,
    browseSliderMinuteCheck()
  )

  return msgs
})

const filteredMessages = computed(() => {
  let ret = []

  if (!props.search) {
    ret = messagesForList.value
  } else {
    // We are searching.
    const messages = messagesForList.value

    messages.forEach((message) => {
      if (message) {
        // Pass whether the message has been freegled, which in this case is returned as the outcomes in the
        // message.
        let successful = false

        if (message.outcomes && message.outcomes.length) {
          for (const outcome of message.outcomes) {
            if (outcome.outcome === 'Taken' || outcome.outcome === 'Received') {
              successful = true
            }
          }
        }

        message.successful = successful

        if (
          !message.deleted &&
          (!message.outcomes || message.outcomes.length === 0)
        ) {
          ret.push(message)
        }
      }
    })
  }

  return ret
})

// Helper function to sort messages. Delegates to the pure sortBrowseMessages, which
// computes each message's distance and arrival sort key ONCE (a Schwartzian transform).
// "Closest" now orders by the server's per-post distance (the value shown on each badge),
// so the map centre is no longer needed here.
function sortMessages(messages) {
  // The list we sort here is the nearby-feed SUMMARY, which does not carry visibleSince - the
  // full message fetched for each card does. Without this the badge and the order came from
  // different clocks again: cards showed 16, 8, 10, 5 days while the sort quietly ordered them
  // 28 Jul, 26 Jul, 25 Jul, 25 Jul by the original arrival. Only old, rippled or reposted posts
  // diverge, which is why the top of the feed looked right and the tail did not.
  const enriched = (messages || []).map((m) => {
    const full = messageStore.byId(m?.id)
    let out = m
    if (!m?.visibleSince && full?.visibleSince) {
      out = { ...out, visibleSince: full.visibleSince }
    }
    // The distance badge shows road miles for the FULL record's blurred
    // coordinates; the feed summary carries a DIFFERENT blurred point (it is
    // blurred from the spatial row, not the message row), up to a few hundred
    // metres away - enough to swap neighbouring cards. "Closest" must order
    // by the same number the badges print, so prefer the full record's
    // coordinates once it has loaded.
    if (full?.lat != null) {
      out = out === m ? { ...m } : out
      out.lat = full.lat
      out.lng = full.lng
      out.roadCoords = true
      if (full.roadmiles != null) {
        out.roadmiles = full.roadmiles
      }
    }
    return out
  })

  return sortBrowseMessages(
    enriched,
    props.selectedSort,
    // Only the full record's coordinates: the summary carries a DIFFERENT
    // blurred point, and ordering by its road miles would disagree with the
    // badges (which print the full record's) and cause an extra visible
    // reshuffle when the records load.
    (m) => {
      if (m?.roadmiles != null) return m.roadmiles
      return m?.roadCoords
        ? (roadDistance(m.lat, m.lng).value?.miles ?? null)
        : null
    }
  )
}

const sortedMessagesOnMap = computed(() => {
  if (!messagesOnMap.value) {
    return []
  }

  const messages = messagesOnMap.value

  // If we have a locked sort order, use it to maintain stable positions
  if (lockedSortOrder.value) {
    const messageMap = new Map(messages.map((m) => [m.id, m]))
    return lockedSortOrder.value
      .filter((id) => messageMap.has(id))
      .map((id) => messageMap.get(id))
  }

  // No locked order yet - return freshly sorted messages
  return sortMessages(messages)
})

const showRegions = computed(() => {
  // We want to show the regions if we're zoomed out, or for SSR = SEO.
  return import.meta.server || zoom.value < 7
})

const showGroupList = computed(() => {
  // We want to show the list of groups for SSR = SEO, or if we are not showing the regions (because we're
  // zoomed out)
  return import.meta.server || !showRegions.value
})

const closestGroups = computed(() => {
  const ret = []
  const distances = {}

  if (centre.value) {
    const allGroups = groupStore.summaryList

    for (const ix in allGroups) {
      const group = allGroups[ix]

      if (group) {
        // See if the group is showing in the map area.
        if (
          bounds.value.contains([group.lat, group.lng]) ||
          ((group.altlat || group.altlng) &&
            bounds.value.contains([group.altlat, group.altlng]))
        ) {
          // Are we already a member?
          const member = authStore.member(group.id)

          if (!member) {
            // Visible group?
            if (group.onmap && group.publish) {
              // How far away?
              distances[group.id] = getDistance(
                [centre.value.lat, centre.value.lng],
                [group.lat, group.lng]
              )

              // Allowed to show?
              if (
                !group.showjoin ||
                distances[group.id] <= group.showjoin * 1609.34
              ) {
                ret.push(group)
              } else if (group.altlat || group.altlng) {
                // A few groups have two centres because they are large.
                distances[group.id] = getDistance(
                  [centre.value.lat, centre.value.lng],
                  [group.altlat, group.altlng]
                )

                if (distances[group.id] <= group.showjoin * 1609.34) {
                  ret.push(group)
                }
              }
            }
          }
        }
      }
    }

    ret.sort((a, b) => {
      return distances[a.id] - distances[b.id]
    })
  }

  return ret.slice(0, 3)
})

// Watchers
// Update the locked sort order when the set of message IDs changes, and ALSO
// when a batch of road-distance answers arrives: the first sort of a fresh
// feed runs before the (batched, async) road distances are back, so a lock
// taken then has frozen crow-flies order under road-mile badges - "Closest"
// showed 10 miles above 9 (the sort and the badges disagreed). Re-sorting on
// roadAnswersVersion converges the order onto what the badges say, then goes
// quiet: once answers are cached, re-sorting an unchanged set with unchanged
// keys is a no-op (Array.sort is stable), so positions stay stable exactly
// as the lock intends.
watch(
  [messagesOnMap, roadAnswersVersion],
  ([newMessages], [oldMessages, oldVersion]) => {
    if (!newMessages?.length) {
      lockedSortOrder.value = null
      return
    }

    const currentIds = new Set(newMessages.map((m) => m.id))

    const needsUpdate =
      !lockedSortOrder.value ||
      // Road answers can only refine the DISTANCE order; under any other
      // sort a re-lock would instead pick up unseen-flag changes and move
      // posts the member has just read - the very reshuffle the lock exists
      // to prevent.
      (roadAnswersVersion.value !== oldVersion &&
        props.selectedSort === 'Nearby') ||
      lockedSortOrder.value.length !== currentIds.size ||
      !lockedSortOrder.value.every((id) => currentIds.has(id))

    if (needsUpdate) {
      const sorted = sortMessages(newMessages)
      const ids = sorted.map((m) => m.id)
      // Only replace the lock when the ORDER actually changed: each answered
      // road-distance batch triggers this watcher, and blindly assigning a
      // fresh array re-rendered the whole grid (flicker, scroll jumping to
      // the top) even when every card was already in the right place.
      const same =
        lockedSortOrder.value &&
        lockedSortOrder.value.length === ids.length &&
        lockedSortOrder.value.every((id, i) => id === ids[i])
      if (!same) {
        // Deliberately loud: every one of these is a full list re-render, and
        // they are only expected when the feed SET changes (load, search, map
        // move) - never repeatedly during a settled page.
        console.log(
          '[browse] sort order re-locked:',
          !lockedSortOrder.value
            ? 'initial'
            : lockedSortOrder.value.length !== ids.length
              ? `set size ${lockedSortOrder.value.length} -> ${ids.length}`
              : 'order changed (road answers arrived)'
        )
        lockedSortOrder.value = ids
      }
    }
  },
  { immediate: true }
)

watch(
  () => nearbyStore.messageList,
  (newList) => {
    if (updatedMessagesOnMap.value && newList?.length) {
      const unseenMap = new Map(newList.map((m) => [m.id, m.unseen]))
      let changed = false

      updatedMessagesOnMap.value.forEach((m) => {
        const newUnseen = unseenMap.get(m.id)
        if (newUnseen !== undefined && m.unseen !== newUnseen) {
          m.unseen = newUnseen
          changed = true
        }
      })

      if (changed) {
        updatedMessagesOnMap.value = [...updatedMessagesOnMap.value]
      }
    }
  },
  { deep: true }
)

watch(
  filteredMessages,
  (newVal) => {
    // We want to save the first message we have seen so that we show a message when we have scrolled down to it.
    // We want that message to stay there until the page is reloaded, even as we read the messages and the seen
    // state of the messages changes.
    if (firstSeenMessage.value === null) {
      for (const message of newVal) {
        if (!message.unseen) {
          firstSeenMessage.value = message.id
          break
        }
      }
    }

    // Only reset the infinite scroll when the actual list of message IDs changes,
    // not when other properties (like unseen status) change. This prevents the
    // scroll position from being lost when messages are marked as seen.
    const newIds = JSON.stringify(newVal.map((m) => m.id).sort())
    if (lastFilteredIds.value !== newIds) {
      // The SET of posts changed (map move, search, feed refresh) - reset the
      // infinite scroll. A pure reorder of the same set (road distances
      // refining the Closest order) must NOT reset: bumping the :key
      // remounts the whole list, which collapses it for a frame and clamps
      // the member's scroll position back to the top.
      lastFilteredIds.value = newIds
      infiniteId.value++
    }
  },
  { immediate: true }
)

// Reset the locked sort order when the sort option changes
watch(
  () => props.selectedSort,
  () => {
    lockedSortOrder.value = null
  }
)

// Methods
function messagesChanged(messages) {
  if (messages) {
    let changed = false

    if (!messages || !messagesOnMap.value) {
      changed = true
    } else {
      // Sorted compare: only a change in WHICH posts are shown is a new
      // list. A same-set reorder must not remount the list (see the
      // filtered-ids watcher above for why).
      const oldids = messagesOnMap.value.map((m) => m.id).sort()
      const newids = messages.map((m) => m.id).sort()

      if (JSON.stringify(oldids) !== JSON.stringify(newids)) {
        changed = true
      }
    }

    if (changed) {
      messagesOnMap.value = messages
      infiniteId.value++
    }

    emit('update:messagesOnMapCount', messagesOnMap.value.length)
  }
}

function groupsChanged(groupidsParam) {
  groupids.value = groupidsParam
}

function mapVisibilityChanged(visible) {
  mapVisible.value = visible
}

function searched() {
  // When we've searched on a place, we want to reset the selected group otherwise we won't show anything.
  emit('update:selectedGroup', 0)
}
</script>
<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'bootstrap/scss/mixins/_breakpoints';
@import 'assets/css/_color-vars.scss';

.postcode {
  position: absolute;
  top: 0px;
  right: 0px;
  z-index: 20000;
}

.community__text {
  /* Need to override the h2 as it has higher specificity */
  color: $color-gray--darker !important;
}

.shrink {
  width: unset;
}

.dense {
  .btn {
    max-width: 300px;
    text-overflow: ellipsis;
  }
}
</style>
