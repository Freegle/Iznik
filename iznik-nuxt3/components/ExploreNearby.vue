<template>
  <div class="exploreNearby">
    <h1 class="text-center header--size3 mt-3 mb-1">
      Find your local community
    </h1>
    <p class="text-center text-muted mb-3 px-3">
      Enter your town or postcode to see Freegle communities near you.
    </p>

    <div class="px-3 mb-3">
      <PlaceAutocomplete
        labeltext="Your town or postcode"
        labeltext-sr="Search for your town or postcode to find nearby Freegle communities"
        :value="placeName"
        size="lg"
        input-id="explorenearbyplace"
        @selected="placeSelected"
        @cleared="placeCleared"
      />
    </div>

    <div v-if="searched">
      <div v-if="nearby.length" class="bg-white">
        <h2 class="header--size5 text-center mb-2">
          <span v-if="placeName">Communities near {{ placeName }}</span>
          <span v-else>Communities near you</span>
        </h2>
        <MapGroup v-for="g in nearby" :id="g.id" :key="'nearby-' + g.id" />
      </div>
      <NoticeMessage v-else variant="info" class="m-3">
        <p class="mb-1">
          We couldn't find a Freegle community covering
          <strong v-if="placeName">{{ placeName }}</strong>
          <strong v-else>that area</strong> yet.
        </p>
        <p class="mb-0">
          <!-- eslint-disable-next-line -->
          Would you like to start one? <ExternalLink href="mailto:newgroups@ilovefreegle.org">Get in touch!</ExternalLink>
        </p>
      </NoticeMessage>
    </div>

    <div class="mt-2 border-top pt-3">
      <p class="text-center text-muted mb-2">Or browse by region:</p>
      <div class="d-flex flex-wrap justify-content-center pb-2">
        <div v-for="r in regions" :key="r" class="p-0 mt-2 ms-2 me-2">
          <b-button variant="secondary" :to="'/explore/region/' + r">
            {{ r }}
          </b-button>
        </div>
      </div>
    </div>

    <p
      class="text-center mt-2 header--size5 text--medium-large-highlight community__text"
    >
      <!-- eslint-disable-next-line -->
      Need help?  Go <nuxt-link no-prefetch to="/help">here</nuxt-link>.
    </p>
  </div>
</template>
<script setup>
import { ref, computed, defineAsyncComponent, onMounted } from 'vue'
import { useGroupStore } from '~/stores/group'
import { useMe } from '~/composables/useMe'
import { nearbyGroups } from '~/composables/useMap'
import PlaceAutocomplete from '~/components/PlaceAutocomplete'
import MapGroup from '~/components/MapGroup'

const NoticeMessage = defineAsyncComponent(() => import('./NoticeMessage'))
const ExternalLink = defineAsyncComponent(() => import('./ExternalLink'))

// How many nearby communities to list.
const NEARBY_LIMIT = 10

const groupStore = useGroupStore()
const { me, oneOfMyGroups } = useMe()

// The place the user has chosen (from the search box) or their own location.
const place = ref(null)
const searched = ref(false)

// Make sure we have the list of all groups to search through. No need to await -
// summaryList populates reactively and the nearby/regions computeds update when
// it arrives. (Avoiding top-level await keeps this mountable without Suspense.)
groupStore.fetch()

// If the user has a saved location, show nearby communities straight away.
onMounted(() => {
  if (!place.value && me.value && (me.value.lat || me.value.lng)) {
    place.value = {
      name: me.value.settings?.mylocation?.name || null,
      lat: me.value.lat,
      lng: me.value.lng,
    }
    searched.value = true
  }
})

const placeName = computed(() => place.value?.name || null)

const regions = computed(() => {
  const ret = []

  const allGroups = groupStore?.summaryList

  for (const ix in allGroups) {
    const group = allGroups[ix]

    if (group?.region && !ret.includes(group.region)) {
      ret.push(group.region)
    }
  }

  ret.sort()

  return ret
})

const nearby = computed(() => {
  if (!place.value) {
    return []
  }

  return nearbyGroups(place.value, groupStore.summaryList, {
    limit: NEARBY_LIMIT,
    isMember: (id) => oneOfMyGroups(id),
  })
})

function placeSelected(selected) {
  place.value = selected
  searched.value = true
}

function placeCleared() {
  place.value = null
  searched.value = false
}
</script>
<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'assets/css/_color-vars.scss';

.community__text {
  /* Need to override the h2 as it has higher specificity */
  color: $color-gray--darker !important;
}
</style>
