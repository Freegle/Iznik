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
              v-if="!hasLocation"
              variant="warning"
            >
              <p class="fw-bold">
                What's your postcode? We'll show you posts nearby.
              </p>
              <PostCode @selected="savePostcode" />
            </NoticeMessage>
            <PostFilters
              v-model:force-show-filters="forceShowFilters"
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
              v-model:selected-type="selectedType"
              v-model:selected-sort="selectedSort"
              :selected-max-distance="selectedMaxDistance"
              :initial-bounds="initialBounds"
              force-messages
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
import { useMessageStore } from '~/stores/message'
import { useLocationStore } from '~/stores/location'
import NoticeMessage from '~/components/NoticeMessage'
import { loadLeaflet } from '~/composables/useMap'
import { buildHead } from '~/composables/useBuildHead'
import VisibleWhen from '~/components/VisibleWhen'
import { useMiscStore } from '~/stores/misc'
import { useMobileStore } from '~/stores/mobile'
import { useAuthStore } from '~/stores/auth'
import { useMe } from '~/composables/useMe'
import { useNearbyStore } from '~/stores/nearby'
import { BROWSE_DISTANCE_UNLIMITED } from '~/constants'
import PostFilters from '~/components/PostFilters'
import SidebarLeft from '~/components/SidebarLeft'
import PostCode from '~/components/PostCode'
import ExternalDa from '~/components/ExternalDa'
import {
  ref,
  computed,
  watch,
  onMounted,
  onUnmounted,
  useRoute,
} from '#imports'

// Async components
const MicroVolunteering = defineAsyncComponent(
  () => import('~/components/MicroVolunteering.vue')
)
const PostMapAndList = defineAsyncComponent(
  () => import('~/components/PostMapAndList')
)
const GlobalMessage = defineAsyncComponent(
  () => import('~/components/GlobalMessage')
)
const AboutMeModal = defineAsyncComponent(
  () => import('~/components/AboutMeModal')
)
const ExpectedRepliesWarning = defineAsyncComponent(
  () => import('~/components/ExpectedRepliesWarning')
)

// Page meta
definePageMeta({
  layout: 'login',
  alias: ['/communities'],
})

// Setup
const route = useRoute()
const runtimeConfig = useRuntimeConfig()
const miscStore = useMiscStore()
const mobileStore = useMobileStore()
const authStore = useAuthStore()
const nearbyStore = useNearbyStore()
const messageStore = useMessageStore()
const locationStore = useLocationStore()

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
const selectedType = ref('All')
const selectedSort = ref('Unseen')
const selectedMaxDistance = ref(BROWSE_DISTANCE_UNLIMITED)
const forceShowFilters = ref(false)
const lastCountUpdate = ref(0)
const updatingCount = ref(false)
const searchTerm = ref(route.params.term)

// Use me computed property from useMe composable for consistency
const { me } = useMe()

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

const noMessagesNoLocation = computed(() => {
  return messagesOnMapCount.value === 0 && !me.value?.settings?.mylocation
})

// Do we know where the member is? There's no per-user isochrone/reach polygon on
// the client any more to use as a proxy for this, so check their location directly.
const hasLocation = computed(() => {
  return !!(me.value && (me.value.lat || me.value.lng))
})

async function calculateInitialMapBounds() {
  if (import.meta.client) {
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

    if (!initialBounds.value && me.value && (me.value.lat || me.value.lng)) {
      // We don't have nearby messages yet, but we know where the member is.
      // Centre there, and then let the map zoom to somewhere sensible.
      const mylat = me.value.lat
      const mylng = me.value.lng

      initialBounds.value = [
        [mylat - 0.01, mylng - 0.01],
        [mylat + 0.01, mylng + 0.01],
      ]
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
    await messageStore.fetchCount(me.value.settings?.browseMaxDistance, false)
    updatingCount.value = false
  }
}

async function fetchMe(force = false) {
  return await authStore.fetchUser(force)
}

// Watchers
watch(
  me,
  async (newVal, oldVal) => {
    if (newVal && !oldVal && import.meta.client) {
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

watch(selectedType, () => {
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
  }
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

if (existingHomepage !== 'browse') {
  miscStore.set({
    key: 'lasthomepage',
    value: 'browse',
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
