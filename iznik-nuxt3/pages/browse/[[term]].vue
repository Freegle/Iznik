<template>
  <client-only v-if="me">
    <b-container fluid class="p-0 p-xl-2">
      <h1 class="visually-hidden">Browse items</h1>
      <b-row class="m-0">
        <b-col cols="0" lg="3" class="p-0 pe-1">
          <VisibleWhen :at="['lg', 'xl', 'xxl']">
            <SidebarLeft
              ad-unit-path="/22794232631/freegle_home_left"
              ad-div-id="div-gpt-ad-1693235056629-0"
            />
          </VisibleWhen>
        </b-col>
        <b-col cols="12" md="10" offset-md="1" lg="6" offset-lg="0" class="p-0">
          <AppUpdateAvailable v-if="mobileStore.isApp" />
          <MicroVolunteering />
          <div>
            <GlobalMessage />
            <ExpectedRepliesWarning
              v-if="me && me.expectedreplies"
              :count="me.expectedreplies"
              :chats="me.expectedchats"
            />
          </div>
          <div v-if="initialBounds">
            <NoticeMessage
              v-if="noMessagesNoLocation"
              variant="warning"
              class="mb-2"
            >
              There are no posts in this area at the moment. You can check back
              later, or use the controls below.
            </NoticeMessage>
            <NoticeMessage v-else-if="messagesOnMapCount === 0" class="mb-2">
              <div v-if="searchTerm">
                We couldn't find any posts matching your search. You can check
                back later, or use the controls below or adjust your filters to
                show posts from further away.
              </div>
              <div v-else>
                We couldn't find any posts to show. You can check back later, or
                use the controls below or adjust your filters to show posts from
                further away.
              </div>
            </NoticeMessage>
            <NoticeMessage v-if="placeSuggestion" variant="info" class="mb-2">
              <p class="mb-2">
                <strong>{{ searchTerm }}</strong> looks like a place. Would you
                like to see items being given away near
                {{ placeSuggestion.name }}?
              </p>
              <b-button variant="primary" @click="searchNearPlace">
                Show items near {{ placeSuggestion.name }}
              </b-button>
            </NoticeMessage>
            <NoticeMessage
              v-if="browseView === 'nearby' && !hasLocation"
              variant="warning"
            >
              <p class="fw-bold">
                What's your postcode? We'll show you posts nearby.
              </p>
              <PostCode @selected="savePostcode" />
            </NoticeMessage>
            <PostFilters
              v-model:force-show-filters="forceShowFilters"
              v-model:selected-group="selectedGroup"
              v-model:selected-type="selectedType"
              v-model:selected-sort="selectedSort"
              v-model:selected-max-distance="selectedMaxDistance"
              v-model:search="searchTerm"
              class="mt-2 mt-md-0"
            />
            <PostMapAndList
              :key="'map-' + bump"
              v-model:messages-on-map-count="messagesOnMapCount"
              v-model:search="searchTerm"
              v-model:selected-group="selectedGroup"
              v-model:selected-type="selectedType"
              v-model:selected-sort="selectedSort"
              :selected-max-distance="selectedMaxDistance"
              :initial-bounds="initialBounds"
              force-messages
              group-info
              :show-many="false"
              can-hide
              browse-search
            />
          </div>
          <about-me-modal
            v-if="showAboutMeModal"
            :review="reviewAboutMe"
            @hidden="showAboutMeModal = false"
          />
          <BirthdayModal
            v-if="showBirthdayModal && birthdayGroup"
            v-model="showBirthdayModal"
            :group-age="birthdayGroup.age"
            :group-name="birthdayGroup.namefull"
            :group-id="birthdayGroup.id"
            @close="onBirthdayModalClose"
            @donation-success="onBirthdayDonationSuccess"
            @donation-click="onBirthdayDonationClick"
          />
        </b-col>
        <b-col cols="0" md="2" lg="3" class="p-0 ps-1">
          <div class="d-flex justify-content-end">
            <VisibleWhen
              :not="['xs', 'sm', 'md', 'lg']"
              class="position-fixed"
              style="right: 5px"
            >
              <ExternalDa
                ad-unit-path="/22794232631/freegle_home"
                max-height="600px"
                max-width="300px"
                div-id="div-gpt-ad-1691925450433-0"
                class="mt-2"
                :jobs="false"
              />
            </VisibleWhen>
          </div>
        </b-col>
      </b-row>
    </b-container>
  </client-only>
</template>
<script setup>
import dayjs from 'dayjs'
import { defineAsyncComponent } from 'vue'
import Wkt from 'wicket'
import { useMessageStore } from '~/stores/message'
import { useLocationStore } from '~/stores/location'
import NoticeMessage from '~/components/NoticeMessage'
import { loadLeaflet } from '~/composables/useMap'
import { buildHead } from '~/composables/useBuildHead'
import VisibleWhen from '~/components/VisibleWhen'
import { useMiscStore } from '~/stores/misc'
import { useMobileStore } from '~/stores/mobile'
import { useAuthStore } from '~/stores/auth'
import { useGroupStore } from '~/stores/group'
import { useMe } from '~/composables/useMe'
import { useNearbyStore } from '~/stores/nearby'
import { BROWSE_DISTANCE_UNLIMITED } from '~/constants'
import PostFilters from '~/components/PostFilters'
import SidebarLeft from '~/components/SidebarLeft'
import PostCode from '~/components/PostCode'
import ExternalDa from '~/components/ExternalDa'
import Api from '~/api'
import {
  ref,
  computed,
  watch,
  onMounted,
  onUnmounted,
  useRoute,
  useRouter,
} from '#imports'

// Async components
const MicroVolunteering = defineAsyncComponent(() =>
  import('~/components/MicroVolunteering.vue')
)
const PostMapAndList = defineAsyncComponent(() =>
  import('~/components/PostMapAndList')
)
const GlobalMessage = defineAsyncComponent(() =>
  import('~/components/GlobalMessage')
)
const AboutMeModal = defineAsyncComponent(() =>
  import('~/components/AboutMeModal')
)
const ExpectedRepliesWarning = defineAsyncComponent(() =>
  import('~/components/ExpectedRepliesWarning')
)
const BirthdayModal = defineAsyncComponent(() =>
  import('~/components/BirthdayModal')
)

// Page meta
definePageMeta({
  layout: 'login',
  alias: ['/communities'],
})

// Setup
const route = useRoute()
const router = useRouter()
const runtimeConfig = useRuntimeConfig()
const miscStore = useMiscStore()
const mobileStore = useMobileStore()
const authStore = useAuthStore()
const groupStore = useGroupStore()
const nearbyStore = useNearbyStore()
const messageStore = useMessageStore()
const locationStore = useLocationStore()
const api = Api(runtimeConfig)

// State
const initialBounds = ref(null)
const bump = ref(1)
const showAboutMeModal = ref(false)
const reviewAboutMe = ref(false)
const messagesOnMapCount = ref(null)

// When an item search finds nothing but the term is actually a place (e.g.
// "Hertfordshire", "London", a postcode), offer to browse items near there
// instead of a dead-end. Resolved via /location/resolve; only ever set when the
// search returned zero, so item-words-that-are-also-places never false-trigger.
const placeSuggestion = ref(null)
const selectedGroup = ref(0)
const selectedType = ref('All')
const selectedSort = ref('Unseen')
const selectedMaxDistance = ref(BROWSE_DISTANCE_UNLIMITED)
const forceShowFilters = ref(false)
const lastCountUpdate = ref(0)
const updatingCount = ref(false)
const searchTerm = ref(route.params.term)
const showBirthdayModal = ref(false)
const birthdayGroup = ref(null)

// Debug flag for testing birthday modal
const debugBirthdayModal = computed(() => {
  return route.query.debugbirthday === '1'
})

// Use me and myGroups computed properties from useMe composable for consistency
const { me, myGroups } = useMe()

// Rippling-out relevance ordering + distance slider (#D/#E): unlike selectedSort/
// selectedType (which only get updated via PostFilters' emit, so a persisted
// non-default value doesn't apply until the member touches the control), we
// initialise this from settings straight away so a saved distance preference is
// honoured on the very first render of the feed.
watch(
  () => me.value?.settings?.browseMaxDistance,
  (newVal) => {
    selectedMaxDistance.value = newVal ?? BROWSE_DISTANCE_UNLIMITED
  },
  { immediate: true }
)

// Same fix for sort and post-type: initialise them from the saved settings straight
// away (mirroring PostFilters' own getters, which read settings.browseSort/browseType).
// Without this they sat at their 'Unseen'/'All' defaults until PostFilters emitted on a
// manual change, so a member whose saved sort is e.g. "Closest" (browseSort='Nearby')
// saw the default Unseen/relevance order on first load and the feed ignored their sort
// preference until they re-picked it. PostFilters is also lazily mounted inside the
// collapsed Map & Filters panel, so its getter didn't even run until the panel was opened.
watch(
  () => me.value?.settings?.browseSort,
  (newVal) => {
    selectedSort.value = newVal || 'Unseen'
  },
  { immediate: true }
)
watch(
  () => me.value?.settings?.browseType,
  (newVal) => {
    selectedType.value = newVal || 'All'
  },
  { immediate: true }
)

const browseView = computed(() => {
  return me.value?.settings?.browseView
    ? me.value.settings.browseView
    : 'nearby'
})

const noMessagesNoLocation = computed(() => {
  return messagesOnMapCount.value === 0 && !me.value?.settings?.mylocation
})

// Do we know where the member is? There's no per-user isochrone/reach polygon on
// the client any more to use as a proxy for this, so check their location directly.
const hasLocation = computed(() => {
  return !!(me.value && (me.value.lat || me.value.lng))
})

// Methods
function myGroup(id) {
  return groupStore.get(id)
}

async function calculateInitialMapBounds() {
  if (process.client) {
    if (browseView.value === 'nearby') {
      if (me.value) {
        // The initial bounds for the map are determined from the nearby messages once
        // we've fetched them, so start that fetch now to display the list rapidly.
        try {
          await nearbyStore.fetchMessages(true)
          initialBounds.value = nearbyStore.bounds
        } catch (e) {
          // If this fails revert to a default view.
        }
      }
    } else {
      initialBounds.value = nearbyStore.bounds
    }

    if (!initialBounds.value) {
      // Either we have no nearby messages yet, or we're showing our groups. Use the bounding
      // box of the group that our own location is within.
      let mylat = null
      let mylng = null

      let swlat = null
      let swlng = null
      let nelat = null
      let nelng = null

      if (me.value && (me.value.lat || me.value.lng)) {
        mylat = me.value.lat
        mylng = me.value.lng

        for (const g of myGroups.value) {
          if (g.bbox) {
            try {
              await loadLeaflet()
              const wkt = new Wkt.Wkt()
              wkt.read(g.bbox)
              const obj = wkt.toObject()

              if (obj?.getBounds) {
                const thisbounds = obj.getBounds()
                const thissw = thisbounds.getSouthWest()
                const thisne = thisbounds.getNorthEast()

                if (
                  mylat >= thissw.lat &&
                  mylat <= thisne.lat &&
                  mylng >= thissw.lng &&
                  mylng <= thisne.lng
                ) {
                  swlat = (thissw.lat + thisne.lat) / 2
                  swlng = thissw.lng
                  nelat = (thissw.lat + thisne.lat) / 2
                  nelng = thisne.lng
                }
              }
            } catch (e) {
              console.error(
                'Failed to parse group bounding box',
                e?.message,
                g.bbox
              )
            }
          }
        }
      }

      let bounds = null

      if (
        swlat !== null &&
        swlng !== null &&
        nelat !== null &&
        nelng !== null
      ) {
        bounds = [
          [swlat, swlng],
          [nelat, nelng],
        ]
      } else if (me.value && mylat !== null && mylng !== null) {
        // We're not a member of any groups, but at least we know where we are. Centre there, and then let
        // the map zoom to somewhere sensible.
        bounds = [
          [mylat - 0.01, mylng - 0.01],
          [mylat + 0.01, mylng + 0.01],
        ]
      } else if (myGroups.value?.length > 0 && myGroups.value[0].bbox) {
        // We have groups but no location. Use the first group's bounds as a fallback.
        try {
          await loadLeaflet()
          const wkt = new Wkt.Wkt()
          wkt.read(myGroups.value[0].bbox)
          const obj = wkt.toObject()

          if (obj?.getBounds) {
            const thisbounds = obj.getBounds()
            const thissw = thisbounds.getSouthWest()
            const thisne = thisbounds.getNorthEast()
            bounds = [
              [thissw.lat, thissw.lng],
              [thisne.lat, thisne.lng],
            ]
          }
        } catch (e) {
          console.error('Failed to parse fallback group bounds', e?.message)
        }
      } else {
        // We aren't a member of any groups and we don't know where we are. This can happen, but it's rare.
        // Send them to the explore page to pick somewhere.
        router.push('/explore')
      }

      if (bounds) {
        initialBounds.value = bounds
      }
    }
  }
}

async function savePostcode(pc) {
  const settings = me.value.settings

  if (!settings?.mylocation || settings?.mylocation.id !== pc.id) {
    settings.mylocation = pc
    await authStore.saveAndGet({
      settings,
    })

    // Now that we know a new location, refresh the nearby feed and re-fit the map to it.
    await nearbyStore.fetchMessages(true)
    initialBounds.value = nearbyStore.bounds
    incBump()
  }
}

function incBump() {
  bump.value++
}

// If the current search returned no posts, check whether the term is really a
// place name and, if so, offer to browse items near it.
async function checkPlaceSuggestion() {
  placeSuggestion.value = null
  const term = (searchTerm.value || '').toString().trim()
  if (!term || messagesOnMapCount.value !== 0) {
    return
  }
  const loc = await locationStore.resolve(term)
  // Guard against a race: only apply if the search state hasn't changed since.
  if (
    loc &&
    messagesOnMapCount.value === 0 &&
    (searchTerm.value || '').toString().trim() === term
  ) {
    placeSuggestion.value = { name: loc.name, lat: loc.lat, lng: loc.lng }
  }
}

// Re-centre the browse on the suggested place and drop the text search, so the
// member sees items being given away in that area.
async function searchNearPlace() {
  const p = placeSuggestion.value
  if (!p) {
    return
  }
  await loadLeaflet()
  const d = 0.15 // ~10 miles either side of the place centre
  initialBounds.value = [
    [p.lat - d, p.lng - d],
    [p.lat + d, p.lng + d],
  ]
  placeSuggestion.value = null
  searchTerm.value = ''
  incBump()
}

// Re-check whenever the result count or the search term changes.
watch([messagesOnMapCount, searchTerm], () => {
  checkPlaceSuggestion()
})

async function handleScroll() {
  // If we are scrolling down the browse window then we want to update our count, but only every few seconds.
  if (
    !updatingCount.value &&
    me.value &&
    lastCountUpdate.value < new Date().getTime() - 5000
  ) {
    lastCountUpdate.value = new Date().getTime()
    updatingCount.value = true
    await messageStore.fetchCount(
      me.value.settings?.browseView,
      me.value.settings?.browseMaxDistance,
      false
    )
    updatingCount.value = false
  }
}

async function fetchMe(force = false) {
  return await authStore.fetchUser(force)
}

async function checkForBirthdays() {
  if (!me.value) return

  // Debug mode - show modal with mock data
  if (debugBirthdayModal.value) {
    await showDebugBirthdayModal()
    return
  }

  if (!myGroups.value?.length) return

  const today = dayjs()
  const lastAppeal = me.value.settings?.lastbirthdayappeal

  // Only check if we haven't shown an appeal in the last 31 days
  if (lastAppeal && dayjs().diff(dayjs(lastAppeal), 'days') < 31) {
    return
  }

  // Check if user has been a member for at least 31 days for any group
  for (const group of myGroups.value) {
    if (!group.founded) continue

    const memberSince = dayjs(group.mysettings?.added || group.added)
    const daysSinceMember = today.diff(memberSince, 'days')

    // Skip if not a member for at least 31 days
    if (daysSinceMember < 31) continue

    // Check if today is the group's anniversary
    const founded = dayjs(group.founded)
    if (founded.format('MM-DD') === today.format('MM-DD')) {
      const groupAge = Math.floor(today.diff(founded, 'years', true))
      if (groupAge > 0) {
        await showBirthdayModalForGroup(group, groupAge)
        return // Show only one birthday modal at a time
      }
    }
  }
}

async function showDebugBirthdayModal() {
  console.log('Debug: Showing birthday modal with random user group')

  if (!myGroups.value?.length) {
    console.log('Debug: No groups available for user')
    return
  }

  // Pick a random group from user's groups
  const randomIndex = Math.floor(Math.random() * myGroups.value.length)
  const randomGroup = myGroups.value[randomIndex]

  // Use the actual group data but with a mock age for demonstration
  const mockAge = Math.floor(Math.random() * 15) + 1 // Random age between 1-15 years

  console.log(
    `Debug: Using group "${randomGroup.namefull}" with mock age ${mockAge}`
  )

  await showBirthdayModalForGroup(randomGroup, mockAge)
}

async function showBirthdayModalForGroup(group, groupAge) {
  birthdayGroup.value = {
    ...group,
    age: groupAge,
  }

  // Record when we show the appeal (on modal open) - skip in debug mode
  if (!debugBirthdayModal.value && me.value?.settings) {
    const settings = { ...me.value.settings }
    settings.lastbirthdayappeal = dayjs().toISOString()

    await authStore.saveAndGet({
      settings,
    })
  }

  // Record A/B test shown event - skip in debug mode
  if (!debugBirthdayModal.value) {
    await api.bandit.shown({
      uid: 'birthdayappeal',
      variant: 'modal',
    })
  }

  showBirthdayModal.value = true
}

function onBirthdayModalClose() {
  showBirthdayModal.value = false
}

async function onBirthdayDonationSuccess() {
  console.log('Birthday donation successful!')
  // Record A/B test chosen event for successful donation
  await api.bandit.chosen({
    uid: 'birthdayappeal',
    variant: 'modal',
  })
}

async function onBirthdayDonationClick(amount) {
  console.log('Birthday donation clicked:', amount)
  // Record A/B test chosen event for donation attempt
  await api.bandit.chosen({
    uid: 'birthdayappeal',
    variant: 'modal',
  })
}

// Watchers
watch(
  me,
  async (newVal, oldVal) => {
    if (newVal && !oldVal && process.client) {
      await loadLeaflet()
      calculateInitialMapBounds()
      bump.value++
    }
  },
  { immediate: true }
)

watch(noMessagesNoLocation, (newVal) => {
  if (newVal) {
    // Make sure the filters are showing.
    forceShowFilters.value = true
  }
})

// When the filters change, just re-render the whole map and list.
watch(searchTerm, () => {
  incBump()
})

watch(selectedGroup, async (newVal) => {
  if (newVal > 0) {
    // We want to show the group's map.
    const g = myGroup(newVal)

    if (g?.bbox) {
      await loadLeaflet()
      const wkt = new Wkt.Wkt()
      wkt.read(g.bbox)
      const obj = wkt.toObject()

      if (obj?.getBounds) {
        const bounds = obj.getBounds()
        const swlat = bounds.getSouthWest().lat
        const swlng = bounds.getSouthWest().lng
        const nelat = bounds.getNorthEast().lat
        const nelng = bounds.getNorthEast().lng

        initialBounds.value = [
          [swlat, swlng],
          [nelat, nelng],
        ]
      }
    }
  }

  incBump()
})

watch(selectedType, () => {
  incBump()
})

watch(browseView, () => {
  calculateInitialMapBounds()
  incBump()
})

// Lifecycle hooks
onMounted(async () => {
  if (me.value) {
    window.addEventListener('scroll', handleScroll)
    const lastask = miscStore?.get('lastaboutmeask')
    const now = new Date().getTime()

    if (!lastask || now - lastask > 90 * 24 * 60 * 60 * 1000) {
      // Not asked too recently.
      await fetchMe(true)

      if (me.value) {
        if (!me.value.aboutme || !me.value.aboutme.text) {
          // We have not yet provided one.
          const daysago = dayjs().diff(dayjs(me.value.added), 'days')

          if (daysago > 7) {
            // Nudge to ask people to to introduce themselves.
            showAboutMeModal.value = true
          }
        } else {
          const monthsago = dayjs().diff(
            dayjs(me.value.aboutme.timestamp),
            'months'
          )

          if (monthsago >= 6) {
            // Old. Ask them to review it.
            showAboutMeModal.value = true
            reviewAboutMe.value = true
          }
        }
      }
    }

    if (showAboutMeModal.value) {
      useMiscStore().set({
        key: 'lastaboutmeask',
        value: now,
      })
    }

    // Check for group birthdays after about me modal logic
    setTimeout(() => {
      checkForBirthdays()
    }, 1000) // Small delay to ensure groups are loaded
  }

  // Also get all the groups. This allows us to suggest other groups to join from within the map.
  // Doing this now slows down the load, but reduces flicker.
  await groupStore.fetch()
})

onUnmounted(() => {
  window.removeEventListener('scroll', handleScroll)
})

// Page head
useHead(
  buildHead(route, runtimeConfig, 'Browse', 'See OFFERs and WANTEDs', null, {
    class: 'overflow-y-scroll',
  })
)

// We want this to be our next home page.
const existingHomepage = miscStore.get('lasthomepage')

if (existingHomepage !== 'mygroups') {
  miscStore.set({
    key: 'lasthomepage',
    value: 'mygroups',
  })
}
</script>
<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'bootstrap/scss/mixins/_breakpoints';

/* Constrain maximum width on very wide monitors to prevent excessive whitespace */
.container-fluid {
  @include media-breakpoint-up(xxl) {
    max-width: 1920px;
    margin-left: auto;
    margin-right: auto;
  }
}

.selection__wrapper {
  background-color: $color-blue--x-light;
  border: 1px solid #bee5eb;
  border-radius: var(--radius-sm, 0.375rem);
}

.typeSelect {
  max-width: 33%;
}
</style>
