<template>
  <div :class="{ 'mb-2': !showFilters }">
    <h2 class="visually-hidden">Post Filters</h2>
    <b-collapse v-model="showFilters" class="p-2 bg-primary-subtle">
      <div variant="info" class="filters mb-2">
        <div class="group">
          <GroupSelect
            v-if="me"
            v-model="group"
            label="Show posts from:"
            all
            all-my
            custom-name="-- Nearby --"
            :custom-val="-1"
          />
        </div>
        <div class="type">
          <label for="typeOptions">Show these posts:</label>
          <b-form-select
            id="typeOptions"
            v-model="type"
            :options="typeOptions"
          />
        </div>
        <div v-if="showDistanceSlider" class="distance">
          <label for="distanceSlider">How far away:</label>
          <RangeSlider
            id="distanceSlider"
            v-model="sliderValue"
            :min="0.5"
            :max="feedMax"
            :step="0.5"
            left-label="Nearer"
            right-label="Further"
            aria-label="Maximum distance"
            @change="onSliderChange"
          />
        </div>
        <div class="sort mb-2">
          <label for="sortOptions">Sort by:</label>
          <b-form-select
            id="sortOptions"
            v-model="sort"
            :options="sortOptions"
            class="shrink"
          />
        </div>
        <div class="close d-flex justify-content-end">
          <b-button
            variant="link"
            title="Hide map and post filters"
            class="noborder close text-dark"
            @click="showFilters = false"
          >
            <v-icon icon="times" />
          </b-button>
        </div>
      </div>
      <!-- Rippling-out (#1): the catchment is worked out automatically and ripples
           out over time. The distance slider above only narrows which of those
           already-reaching posts are shown - it isn't a manual travel-time control. -->
      <div v-if="browseView === 'nearby'" class="nearby-help">
        <p class="help-text d-none d-md-block mt-0">
          We show posts near you first, then gradually further away.
          <a href="#" @click.prevent="whichPostsModal?.show()">
            How does this work?
          </a>
          ·
          <nuxt-link no-prefetch to="/settings">Change postcode</nuxt-link>
        </p>
      </div>
      <hr />
      <div class="d-flex justify-content-around mt-2">
        <b-input-group class="shrink">
          <b-form-input
            v-model="search"
            type="text"
            placeholder="Search posts"
            autocomplete="off"
            class="flex-shrink-1"
            size="lg"
            @keyup.enter.exact="doSearch"
          />
          <slot name="append">
            <b-button variant="secondary" title="Search" @click="doSearch">
              <v-icon icon="search" />
            </b-button>
          </slot>
        </b-input-group>
      </div>
    </b-collapse>
    <div v-if="!showFilters" class="d-flex justify-content-between">
      <b-input-group class="shrink">
        <b-form-input
          v-model="search"
          type="text"
          placeholder="Search posts"
          autocomplete="off"
          class="flex-shrink-1"
          size="lg"
          @keyup.enter.exact="doSearch"
        />
        <slot name="append">
          <b-button variant="secondary" title="Search" @click="doSearch">
            <v-icon icon="search" />
          </b-button>
        </slot>
      </b-input-group>
      <div class="position-relative d-inline-block ms-2">
        <b-button
          variant="white"
          title="Show map and post filters"
          @click="showFilters = true"
        >
          <div class="d-flex">
            <div class="d-none d-md-block">Map & Filters</div>
            <v-icon icon="sliders" class="ms-md-2 align-self-center" />
            <v-icon icon="map" class="ms-1 align-self-center" />
          </div>
        </b-button>
        <b-badge
          v-if="hasNonDefaultFilters"
          variant="danger"
          class="filters-active-badge"
          title="Filters are active"
        />
      </div>
    </div>
    <WhichPostsModal ref="whichPostsModal" />
  </div>
</template>
<script setup>
import { useMiscStore } from '~/stores/misc'
import { useMessageStore } from '~/stores/message'
import { useNearbyStore } from '~/stores/nearby'
import { ref, watch } from '#imports'
import { useAuthStore } from '~/stores/auth'
import { useMe } from '~/composables/useMe'
import { BROWSE_DISTANCE_UNLIMITED } from '~/constants'
import RangeSlider from '~/components/RangeSlider.vue'
import WhichPostsModal from '~/components/WhichPostsModal.vue'

const props = defineProps({
  selectedGroup: {
    type: Number,
    default: 0,
  },
  selectedType: {
    type: String,
    default: 'All',
  },
  selectedSort: {
    type: String,
    default: 'Unseen',
  },
  selectedMaxDistance: {
    type: Number,
    default: BROWSE_DISTANCE_UNLIMITED,
  },
  forceShowFilters: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits([
  'update:search',
  'update:selectedGroup',
  'update:selectedType',
  'update:selectedSort',
  'update:selectedMaxDistance',
])

// Filters should always start closed - users can expand them if needed
const showFilters = ref(false)

watch(
  () => props.forceShowFilters,
  (newVal) => {
    showFilters.value = newVal
  },
  {
    immediate: true,
  }
)

watch(
  showFilters,
  (newVal) => {
    const miscStore = useMiscStore()

    // If we're showing the filters, we want to show the map.  Otherwise we don't.
    // It's a smell that the map is not in this component, but the restructuring to make that happen is quite
    // large
    miscStore.set({
      key: 'hidepostmap',
      value: !newVal,
    })
  },
  {
    immediate: true,
  }
)

// User
const { me } = useMe()
const authStore = useAuthStore()
const messageStore = useMessageStore()
const nearbyStore = useNearbyStore()

// Modal for the shared "which posts do I see?" explainer (#K). Always mounted (not
// v-if-gated) so its ref is available as soon as the button is clicked, matching the
// pattern used for RipplingExplanationModal elsewhere.
const whichPostsModal = ref(null)

// Refetch the unseen-count badge so it tracks whatever the feed is currently showing.
function refetchCount() {
  if (me.value) {
    messageStore.fetchCount(
      me.value.settings?.browseView,
      me.value.settings?.browseMaxDistance,
      false
    )
  }
}

// Search
const search = ref('')

function doSearch() {
  if (search.value) {
    emit('update:search', search.value)
  }
}

watch(search, (newVal, oldVal) => {
  if (!newVal && oldVal) {
    // Search box cleared - trigger search.
    emit('update:search', '')
  }
})

// Selected group.  We have a special case for the 'nearby' group, which is -1.
const browseView = computed(() => me.value?.settings?.browseView || 'nearby')
const group = ref(browseView.value === 'nearby' ? -1 : 0)

watch(
  () => props.selectedGroup,
  (newVal) => {
    group.value = newVal
  }
)

watch(group, async (newVal) => {
  const settings = me.value?.settings

  if (newVal === -1) {
    // Special case for nearby.
    settings.browseView = 'nearby'

    await authStore.saveAndGet({
      settings,
    })

    emit('update:selectedGroup', 0)

    // We do this so that UpToDate doesn't show an old count.
    refetchCount()
  } else if (newVal === 0) {
    // Special case for all my groups.
    settings.browseView = 'mygroups'

    await authStore.saveAndGet({
      settings,
    })

    // We do this so that UpToDate doesn't show an old count.
    refetchCount()

    emit('update:selectedGroup', 0)
  } else {
    emit('update:selectedGroup', newVal)
  }
})

// Selected type
const typeOptions = [
  {
    value: 'All',
    text: '-- OFFERs & WANTEDs --',
    selected: true,
  },
  {
    value: 'Offer',
    text: 'Just OFFERs',
  },
  {
    value: 'Wanted',
    text: 'Just WANTEDs',
  },
]

// Rippling-out relevance ordering + distance slider (#I): post type is now sticky,
// mirroring sort/browseView below - stored in settings.browseType (default 'All').
const type = ref(me.value?.settings?.browseType || 'All')

watch(
  () => props.selectedType,
  (newVal) => {
    type.value = newVal
  }
)

watch(type, async (newVal) => {
  const settings = me.value?.settings

  if (settings) {
    settings.browseType = newVal

    await authStore.saveAndGet({
      settings,
    })
  }

  emit('update:selectedType', newVal)
})

// Sort

// Rippling-out (#1): "New to you" (unseen and newly-visible first, then the rippling
// relevance order) is the default, plus "Newest posted" and "Closest" (nearest-first,
// internal value 'Nearby' for backwards compatibility with sortMessages) options.
const sortOptions = [
  { value: 'Unseen', text: 'New to you', selected: true },
  { value: 'Newest', text: 'Newest posted' },
  { value: 'Nearby', text: 'Closest' },
]

const sort = computed({
  get() {
    return me.value?.settings?.browseSort || 'Unseen'
  },
  async set(val) {
    const settings = me.value?.settings
    settings.browseSort = val

    await authStore.saveAndGet({
      settings,
    })

    emit('update:selectedSort', val)
  },
})

// Distance slider (#D)
//
// No fixed cap: the slider's right end is scaled to the farthest post distance in the
// currently-loaded feed (with a floor so it's still usable on a tiny/empty feed). The
// far-right ("Further") position stores BROWSE_DISTANCE_UNLIMITED rather than that
// feed max, so the server's own reach limit keeps governing and newly-arriving distant
// posts keep showing without the client capping them out.
const FEED_MAX_FLOOR = 2

const feedMax = computed(() => {
  const distances = (nearbyStore.messageList || [])
    .map((m) => m.distance)
    .filter((d) => typeof d === 'number' && isFinite(d))

  const dataMax = distances.length
    ? Math.max(FEED_MAX_FLOOR, Math.ceil(Math.max(...distances)))
    : FEED_MAX_FLOOR

  // The server PRE-FILTERS the feed to the member's saved browseMaxDistance (so the feed
  // matches the unread count). That means once a narrow distance is saved, the farthest loaded
  // post - and hence dataMax - collapses down to roughly that distance, so the thumb (positioned
  // at the saved distance) would pin to the far right, indistinguishable from "Further"/unlimited.
  // A member who pulled the slider in then came back saw a SMALL blue coverage area but the thumb
  // at maximum (Discourse 9844). Keep headroom above a finite saved distance so a narrow setting
  // still reads as narrow, with room to widen. When the feed is NOT pre-filtered (dataMax already
  // exceeds the saved distance) the Math.max leaves the true feed extent untouched.
  const saved = me.value?.settings?.browseMaxDistance
  const savedFinite =
    typeof saved === 'number' && saved > 0 && saved < BROWSE_DISTANCE_UNLIMITED
      ? saved
      : 0
  const withHeadroom = savedFinite
    ? Math.max(savedFinite + 1, Math.ceil(savedFinite * 1.25))
    : 0

  return Math.max(FEED_MAX_FLOOR, dataMax, withHeadroom)
})

// Distance is meaningless without a known location.
const hasLocation = computed(() => {
  return !!(me.value && (me.value.lat || me.value.lng))
})

// Shown in every "Show posts from" view (nearby, all-my-groups, a single group), not just
// Nearby: the mygroups feed now carries a per-post distance server-side too, so the slider
// narrows whichever view is active. It only needs a known location to measure from.
const showDistanceSlider = computed(() => {
  return hasLocation.value
})

const maxDistance = computed({
  get() {
    return me.value?.settings?.browseMaxDistance ?? BROWSE_DISTANCE_UNLIMITED
  },
  async set(val) {
    const settings = me.value?.settings
    settings.browseMaxDistance = val

    await authStore.saveAndGet({
      settings,
    })

    emit('update:selectedMaxDistance', val)

    // Keep the unseen-count badge in step with the filtered feed.
    refetchCount()
  },
})

// Where the thumb should sit for a given stored distance/feed-max pair: at the far
// right when unlimited (or when the stored value is no longer less than the current
// feed max, e.g. the feed has shrunk since it was saved).
function sliderPositionFor(distance, max) {
  return distance === BROWSE_DISTANCE_UNLIMITED || distance >= max
    ? max
    : distance
}

// Local slider position, separate from `maxDistance`. RangeSlider emits
// update:modelValue (bound here) on every drag tick for an instant visual, and a
// separate `change` event only on release/keyup - so we only save + refetch the count
// once the member has settled on a value, not on every tick of the drag.
const sliderValue = ref(sliderPositionFor(maxDistance.value, feedMax.value))

watch([maxDistance, feedMax], ([newDistance, newMax]) => {
  sliderValue.value = sliderPositionFor(newDistance, newMax)
})

function onSliderChange(val) {
  maxDistance.value = val >= feedMax.value ? BROWSE_DISTANCE_UNLIMITED : val
}

// "Filters active" badge (#G): lights for ANY control that differs from its default -
// not just narrowing ones - so members always have a quick visual cue that the feed
// isn't showing the plain default view.
const hasNonDefaultFilters = computed(() => {
  return (
    group.value !== -1 ||
    sort.value !== 'Unseen' ||
    type.value !== 'All' ||
    maxDistance.value !== BROWSE_DISTANCE_UNLIMITED
  )
})
</script>
<style scoped lang="scss">
@import 'assets/css/_color-vars.scss';
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'bootstrap/scss/mixins/_breakpoints';

.shrink {
  width: unset;
}

.noborder {
  border: none !important;
  border-color: $color-white !important;
}

// Let Bootstrap handle input-group radius (first/last child logic).
// Only override standalone controls outside input-groups.
:deep(.form-select),
:deep(.form-control:not(.input-group .form-control)),
:deep(select),
:deep(input:not(.input-group input)) {
  border-radius: var(--radius-sm, 0.375rem) !important;
}

:deep(.btn:not(.input-group .btn)) {
  border-radius: var(--radius-sm, 0.375rem) !important;
}

// Compact labels for mobile
.filters label {
  font-size: 0.85rem;
  font-weight: 600;
  margin-bottom: 0.25rem;
  color: $color-gray--darker;
}

.filters {
  display: grid;

  grid-template-columns: 1fr 3rem;
  grid-template-rows: min-content min-content min-content min-content;
  grid-column-gap: 10px;
  grid-row-gap: 10px;

  @include media-breakpoint-up(md) {
    grid-template-columns: 2fr 1fr 3rem;
    grid-template-rows: min-content min-content min-content;
  }

  .group {
    grid-column: 1 / 2;
    grid-row: 1 / 2;
  }

  .type {
    grid-column: 1 / 2;
    grid-row: 2 / 3;

    @include media-breakpoint-up(md) {
      grid-column: 2 / 3;
      grid-row: 1 / 2;
    }
  }

  /* Distance slider sits to the left of Sort, on the same row on desktop; on mobile
     it stacks above Sort (reads top-to-bottom in the same left-to-right order). */
  .distance {
    grid-column: 1 / 2;
    grid-row: 3 / 4;

    @include media-breakpoint-up(md) {
      grid-column: 1 / 2;
      grid-row: 3 / 4;
    }
  }

  .sort {
    grid-column: 1 / 2;
    grid-row: 4 / 5;

    @include media-breakpoint-up(md) {
      grid-column: 2 / 3;
      grid-row: 3 / 4;
    }
  }

  .close {
    grid-column: 2 / 3;
    grid-row: 1 / 2;
    background-color: transparent !important;

    @include media-breakpoint-up(md) {
      grid-column: 3 / 4;
    }
  }

  .nearby-help {
    grid-column: 1 / 3;
    grid-row: 4 / 5;

    @include media-breakpoint-up(md) {
      grid-column: 1 / 4;
      grid-row: 3 / 4;
    }
  }
}

// Help text - hidden on mobile
.help-text {
  font-size: 0.8rem;
  color: var(--color-gray-600);
  margin-top: 0.5rem;
  margin-bottom: 0;
}

/* "Filters active" indicator on the collapsed "Map & Filters" button - a small red
   dot, styled consistently with the navbar's count badges. */
.filters-active-badge {
  position: absolute;
  top: -4px;
  right: -4px;
  width: 10px;
  height: 10px;
  min-width: 10px;
  padding: 0;
  border-radius: 50%;
}
</style>
