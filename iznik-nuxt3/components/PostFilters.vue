<template>
  <div :class="{ 'mb-2': !showFilters }">
    <h2 class="visually-hidden">Post Filters</h2>
    <b-collapse v-model="showFilters" class="p-2 bg-primary-subtle">
      <!-- The panel's top-right corner, and deliberately OUTSIDE the filters grid. It used to be a
           cell in that grid, whose first row starts below the help text - so the close control could
           never reach the corner and instead sat halfway down, beside the "Show these posts"
           select, reading as part of that field rather than as the panel's dismiss. First in the
           flow so the float puts it in the corner. -->
      <b-button
        variant="link"
        title="Hide map and post filters"
        class="noborder panel-close text-dark"
        @click="showFilters = false"
      >
        <v-icon icon="times" />
      </b-button>
      <!-- How the nearby feed works - kept above the filters (and the "How far away"
           control) so the explanation reads before the controls. There's only one feed
           now (nearby), so this always shows. -->
      <div class="nearby-help">
        <p class="help-text mt-0">
          We show posts near you first, then gradually further away.
          <a href="#" @click.prevent="whichPostsModal?.show()">
            How does this work?
          </a>
          ·
          <nuxt-link no-prefetch to="/settings">Change postcode</nuxt-link>
        </p>
      </div>
      <div variant="info" class="filters mb-2">
        <div class="type">
          <label for="typeOptions">Show these posts:</label>
          <b-form-select
            id="typeOptions"
            v-model="type"
            :options="typeOptions"
          />
        </div>
        <div v-if="showDistanceSlider" class="distance">
          <span class="distance-label">How far away:</span>
          <DistanceSliders
            id-prefix="distanceSlider"
            with-polygon
            @persisted="onDistancePersisted"
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
      </div>
      <!-- Rippling-out (#1): the catchment is worked out automatically and ripples
           out over time. The distance slider above only narrows which of those
           already-reaching posts are shown - it isn't a manual travel-time control. -->
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
          size="lg"
          title="Show post filters"
          class="filters-button"
          @click="showFilters = true"
        >
          <div class="d-flex align-items-center">
            <v-icon icon="sliders" class="align-self-center" />
            <span class="ms-2">Filters</span>
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
import { ref, watch } from '#imports'
import { useAuthStore } from '~/stores/auth'
import { useMe } from '~/composables/useMe'
import { BROWSE_DISTANCE_UNLIMITED } from '~/constants'
import DistanceSliders from '~/components/DistanceSliders.vue'
import WhichPostsModal from '~/components/WhichPostsModal.vue'

const props = defineProps({
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

// Modal for the shared "which posts do I see?" explainer (#K). Always mounted (not
// v-if-gated) so its ref is available as soon as the button is clicked, matching the
// pattern used for RipplingExplanationModal elsewhere.
const whichPostsModal = ref(null)

// Refetch the unseen-count badge so it tracks whatever the feed is currently showing.
function refetchCount() {
  if (me.value) {
    messageStore.fetchCount(me.value.settings?.browseMaxDistance, false)
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
// mirroring sort below - stored in settings.browseType (default 'All').
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

// Distance slider (#D) - TIME-based.
//
// The slider is a travel-time budget in MINUTES (matching the reach system's drive-time isochrones),
// not miles - so the "Max X-Y miles by road" hint stays stable and meaningful instead of jumping as
// the feed reloads (Discourse 9808). The shared useReachDistance composable converts the chosen
// minutes to a crow-flies mile radius via real routing (location-aware, no hardcoded conversion) and
// persists both settings.browseMaxMinutes (source of truth) and settings.browseMaxDistance (the value
// this feed's fast Haversine filter reads). The far-right ("Further") stop stores
// BROWSE_DISTANCE_UNLIMITED so the server's own reach keeps governing.

// Distance is meaningless without a known location.
const hasLocation = computed(() => {
  return !!(me.value && (me.value.lat || me.value.lng))
})

// There's only one feed now (nearby), and it carries a per-post distance server-side,
// so the slider narrows it. It only needs a known location to measure from.
const showDistanceSlider = computed(() => {
  return hasLocation.value
})

// Read-only here (the composable owns the writes): the "filters active" badge below uses it to tell
// whether a distance limit is active.
const maxDistance = computed(
  () => me.value?.settings?.browseMaxDistance ?? BROWSE_DISTANCE_UNLIMITED
)

// The slider positions and their persistence live in DistanceSliders (and the shared composable
// behind it). When an INBOUND change is saved it re-emits the derived mile cap, so parent feeds
// re-filter, and refreshes the unseen count. Outbound changes do not fire this: they alter who sees
// this member's posts, which changes nothing about the feed they are looking at.
// with-polygon: these are the only /town/near calls the browse page makes, and the routing pass
// behind them also produces the reach OUTLINE the map shades. Asking here means the map does not
// route the same reach again.
function onDistancePersisted(miles) {
  emit('update:selectedMaxDistance', miles)
  refetchCount()
}

// "Filters active" badge (#G): lights for ANY control that differs from its default -
// not just narrowing ones - so members always have a quick visual cue that the feed
// isn't showing the plain default view.
const hasNonDefaultFilters = computed(() => {
  return (
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

/* Top-right of the panel, as the first thing in its content flow. FLOATED rather than absolutely
   positioned: BCollapse's root element does not inherit this component's scope attribute (checked
   in a browser - its attributes are id/class/is-nav/style, no data-v-*), so a scoped
   `position: relative` on it silently does not apply, the button's containing block becomes the
   viewport, and it lands behind the fixed navbar. A float needs no containing block, and it also
   reserves its own space so the help text beside it wraps instead of running underneath. */
.panel-close {
  float: right;
  margin: -0.25rem -0.25rem 0 0.5rem;
  background-color: transparent !important;
}

.filters {
  display: grid;

  /* No 3rem gutter column any more: the close button left the grid, and keeping its column
     reserved would indent every filter away from the panel edge for nothing. There's only one
     browse view now, so there's no "Show posts from" picker to share a row with either - just
     one column, stacked in DOM order (type, distance, sort) at every width. */
  grid-template-columns: 1fr;
  grid-template-rows: min-content min-content min-content;
  grid-column-gap: 10px;
  grid-row-gap: 10px;

  .type {
    grid-column: 1 / 2;
    grid-row: 1 / 2;
  }

  .distance {
    grid-column: 1 / 2;
    grid-row: 2 / 3;
    /* Grid cells default to min-width:auto, so the NearbyTowns single-line hint
       ("Max X-Y miles by road, e.g. ...") expanded this cell past its track and spilled
       out of the filter panel. min-width:0 lets the cell hold its track width so the hint
       ellipsis-truncates inside the panel instead of overflowing. */
    min-width: 0;
  }

  .sort {
    grid-column: 1 / 2;
    grid-row: 3 / 4;
  }

  /* No .close rule: the close button is positioned against the panel now, not laid out here.
     No .nearby-help rule either - that div is a SIBLING of .filters, never a child, so the
     placement it used to declare here never applied to anything. */
}

// Help text - the whole rippling explanation ("We show posts near you first ..." plus the
// "How does this work?" / "Change postcode" links) stays visible at every width. It was
// previously hidden on mobile, which lost the explanatory line members asked for (Discourse
// 9808, Neville #585/#600).
.help-text {
  font-size: 0.8rem;
  color: var(--color-gray-600);
  margin-top: 0.5rem;
  margin-bottom: 0;
}

// Group heading for the two distance sliders. A span, not a <label>: with the control split there
// are two inputs under it, and each carries its own aria-label. Styled to match the <label>s on the
// sibling filters so the panel still reads as one row of controls.
.distance-label {
  display: block;
  font-weight: 500;
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
