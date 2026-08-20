<template>
  <div v-if="initialBounds">
    <div v-if="!mapHidden">
      <div
        ref="mapcont"
        :style="'height: ' + mapHeight + 'px'"
        class="w-100 position-relative mb-1"
      >
        <div class="mapbox">
          <l-map
            ref="map"
            v-model:bounds="bounds"
            v-model:center="center"
            v-model:zoom="zoom"
            :style="'width: 100%; height: ' + mapHeight + 'px'"
            :min-zoom="minZoom"
            :max-zoom="maxZoom"
            :options="mapOptions"
            @ready="ready"
            @update:bounds="idle"
            @zoomend="idle"
            @moveend="idle"
            @dragend="dragEnd"
          >
            <l-tile-layer :url="osmtile()" :attribution="attribution()" />
            <div v-if="showMessages">
              <ClusterMarker
                v-if="messagesForMap.length"
                :markers="messagesForMap"
                :map="mapObject"
                tag="post"
                @click="clusterClick"
              />
              <ClusterMarker
                v-if="!moved"
                :markers="secondaryMessagesForMap"
                :map="mapObject"
                tag="post"
                css-class="fadedMarker"
                @click="clusterClick"
              />
              <l-marker
                v-if="me?.settings?.mylocation && (me.lat || me.lng)"
                :lat-lng="[me.lat, me.lng]"
                @click="goHome"
              >
                <l-icon>
                  <BrowseHomeIcon />
                </l-icon>
                <l-tooltip>
                  This is where your postcode is. You can change your postcode
                  from Settings.
                </l-tooltip>
              </l-marker>
            </div>
            <div v-else-if="showGroups">
              <GroupMarker
                v-for="g in groupsInBounds"
                :key="'marker-' + g.id + '-' + zoom"
                :group="g"
                :size="largeGroupMarkers ? 'rich' : 'poor'"
              />
            </div>
            <!-- Coverage hull of the posts currently shown. View-agnostic: it adapts to the
                 distance slider on BOTH the nearby and "all my communities" views, so it is no
                 longer gated on showIsochrones (which is nearby-only). -->
            <l-geo-json
              v-if="coverageGeoJSON"
              :geojson="coverageGeoJSON"
              :options="isochroneOptions"
            />
            <!-- Explicit WKT overrides (e.g. the fixed Essex boundary) are a nearby/override-only
                 concept, so they stay gated. -->
            <div v-if="showIsochrones">
              <l-geo-json
                v-for="g in isochroneGEOJSONs"
                :key="'isochrone' + g.id"
                :geojson="g.json"
                :options="isochroneOptions"
              />
            </div>
          </l-map>
        </div>
      </div>
    </div>
  </div>
</template>
<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useRuntimeConfig } from 'nuxt/app'
import 'vue3-draggable-resizable/dist/Vue3DraggableResizable.css'
import cloneDeep from 'lodash.clonedeep'
import { storeToRefs } from 'pinia'
import Wkt from 'wicket'
import { LGeoJson, LTooltip } from '@vue-leaflet/vue-leaflet'
import GroupMarker from './GroupMarker'
import BrowseHomeIcon from './BrowseHomeIcon'
import ClusterMarker from './ClusterMarker'
import { useGroupStore } from '~/stores/group'
import { useMessageStore } from '~/stores/message'
import {
  calculateMapHeight,
  loadLeaflet,
  attribution,
  osmtile,
} from '~/composables/useMap'
import { useMiscStore } from '~/stores/misc'
import { useNearbyStore } from '~/stores/nearby'
import { useAuthorityStore } from '~/stores/authority'
import { useAuthStore } from '~/stores/auth'
import 'leaflet-control-geocoder/dist/Control.Geocoder.css'
import '~/assets/css/gesture-handling.css'
import { useMe } from '~/composables/useMe'
import {
  smoothGeoJSON,
  buildCoverageGeoJSON,
} from '~/composables/useReachPolygon'
import { useReachOverlay } from '~/composables/useReachOverlay'
import {
  isWithinDistance,
  filterMessagesByDistance,
} from '~/composables/useDistance'
import { distinctGroupIds } from '~/composables/useMessageDedup'
import { BROWSE_DISTANCE_UNLIMITED, ISOCHRONE_COLOR } from '~/constants'

const props = defineProps({
  showIsochrones: {
    type: Boolean,
    required: false,
    default: false,
  },
  initialBounds: {
    type: Array,
    required: true,
  },
  heightFraction: {
    type: Number,
    required: false,
    default: 3,
  },
  minZoom: {
    type: Number,
    required: false,
    default: 5,
  },
  maxZoom: {
    type: Number,
    required: false,
    default: 15,
  },
  postZoom: {
    type: Number,
    required: false,
    default: 10,
  },
  forceMessages: {
    type: Boolean,
    required: false,
    default: false,
  },
  groupid: {
    type: Number,
    required: false,
    default: null,
  },
  type: {
    type: String,
    required: false,
    default: 'All',
  },
  search: {
    type: String,
    required: false,
    default: null,
  },
  showMany: {
    type: Boolean,
    required: false,
    default: true,
  },
  region: {
    type: String,
    required: false,
    default: null,
  },
  canHide: {
    type: Boolean,
    required: false,
    default: false,
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
  // Personal distance preference (miles). BROWSE_DISTANCE_UNLIMITED (the
  // default) means "no client-side limit" - show everything the reach feed
  // returned. Anything smaller filters the map markers (and the coverage hull)
  // to posts within that distance, so the map tracks the Browse slider.
  selectedMaxDistance: {
    type: Number,
    required: false,
    default: BROWSE_DISTANCE_UNLIMITED,
  },
  // True when this map serves the Browse page. Searches then pass browse=1 so the
  // server scopes the search universe to exactly the member's browse feed for their
  // current filters (reach for Nearby, their groups otherwise) plus their distance
  // slider and sort (Discourse 9933). Explore/place/region pages leave this false
  // and keep viewport/group-scoped search without the member's personal filters.
  browseSearch: {
    type: Boolean,
    required: false,
    default: false,
  },
})

const { myGroups, myGroupsBoundingBox, myGroupIds } = useMe()

const emit = defineEmits([
  'update:ready',
  'update:showGroups',
  'update:bounds',
  'update:zoom',
  'update:centre',
  'update:loading',
  'update:moved',
  'groups',
  'messages',
  'idle',
  'minzoom',
  'searched',
])

const miscStore = useMiscStore()
const groupStore = useGroupStore()
const messageStore = useMessageStore()
const nearbyStore = useNearbyStore()
const authorityStore = useAuthorityStore()
const authStore = useAuthStore()
const me = authStore.user

// Data properties as refs
const messageList = ref([])
const secondaryMessageList = ref([])
const moved = ref(false)
const mapObject = ref(null)
const manyToShow = ref(20)
const shownMany = ref(false)
const lastBounds = ref(null)
const lastBoundsFetch = ref(null)

// The last search we actually asked the server for, and what it gave back.
//
// getMessages() is driven by several watchers, and one of them fires on a timer the
// member never sees: the navbar polls the unseen count every 60s, MessageList reloads
// the feed whenever that count rises, and nearbyBounds returns a fresh array each time,
// so the bounds watcher runs on every reload whether or not the bounds moved. While a
// search is showing, that re-asked the same question about once a minute - measured on a
// live search, 36 identical queries over 35 minutes, every one returning the same 14
// results (Discourse 10001/10). messageStore.search() empties the store before the new
// answer arrives, so each repeat tore down the list the member was reading.
//
// A search still runs whenever anything about it changes - the term, the filters, the
// area, the sort - because all of that is in the key. What it will not do is ask the
// same question twice in a row.
const lastSearchKey = ref(null)
const lastSearchResult = ref(null)
const zoom = ref(5)
const destroyed = ref(false)
const mapIdle = ref(0)
const center = ref(null)
const bounds = ref(null)
const map = ref(null)
const mapcont = ref(null)

// The bounding box of the nearby messages we've fetched, so we can fit the map around them.
const { bounds: nearbyBounds } = storeToRefs(useNearbyStore())

// Computed properties
const mapHeight = computed(() => {
  return calculateMapHeight(props.heightFraction)
})

const mapOptions = computed(() => {
  return {
    zoomControl: true,
    dragging: process.client && window?.L?.Browser?.mobile,
    touchZoom: true,
    scrollWheelZoom: false,
    bounceAtZoomLimits: true,
    gestureHandling: true,
  }
})

const mapHidden = computed(() => {
  return props.canHide && miscStore?.get('hidepostmap')
})

const showMessages = computed(() => {
  // We're zoomed in far enough or we're forcing ourselves to show them (but not so that it's silly)
  return (
    mapIdle.value > 0 &&
    (zoom.value >= props.postZoom || (props.forceMessages && zoom.value >= 7))
  )
})

const showGroups = computed(() => {
  // Don't show until the map has been idle - there is an issue with markers not destroying properly which this
  // provokes.
  return mapIdle.value > 0 && !showMessages.value
})

const groups = computed(() => {
  // Distinct groupids in first-appearance order (O(n) via a Set rather than the previous
  // includes()-in-loop).
  return distinctGroupIds(messageList.value)
})

const largeGroupMarkers = computed(() => {
  // Can't get this to look sane.
  return false
})

const allGroups = computed(() => {
  return groupStore?.summaryList
})

const groupsInBounds = computed(() => {
  const ret = []

  try {
    // Reference map idle so that we recalc.
    const groups = mapIdle.value ? allGroups.value : []
    const boundsObj = mapObject.value ? mapObject.value.getBounds() : null

    if (!process.client && boundsObj) {
      // SSR - return all for SEO.
      for (const ix in groups) {
        const group = groups[ix]

        if (
          group.onmap &&
          (!props.region ||
            group.region.trim().toLowerCase() ===
              props.region.trim().toLowerCase())
        ) {
          ret.push(group)
        }
      }
    } else if (boundsObj) {
      for (const ix in groups) {
        const group = groups[ix]

        if (group.lat || group.lng) {
          try {
            if (
              group.onmap &&
              group.publish &&
              boundsObj.contains([group.lat, group.lng]) &&
              (!props.region ||
                props.region.toLowerCase() === group.region.toLowerCase())
            ) {
              ret.push(group)
            }
          } catch (e) {
            console.log('Problem group', e)
          }
        }
      }
    }
  } catch (e) {
    console.log('Groups in bounds exception', e)
  }

  const sorted = ret.sort((a, b) => {
    return a.namedisplay
      .toLowerCase()
      .localeCompare(b.namedisplay.toLowerCase())
  })

  return sorted
})

// Posts narrowed by the member's distance slider (selectedMaxDistance).
// BROWSE_DISTANCE_UNLIMITED = show everything the reach feed returned. This is
// what the map markers and the coverage hull are drawn from, so the map tracks
// the slider the same way the list does.
const distanceFilteredMessages = computed(() => {
  return filterMessagesByDistance(messageList.value, props.selectedMaxDistance)
})

const messagesForMap = computed(() => {
  return mapObject.value && distanceFilteredMessages.value.length
    ? distanceFilteredMessages.value
    : []
})

// The member's real drive-time reach, traced over the road network by the routing server and
// published by the distance slider (see useReachOverlay). Null on pages with no slider, when
// there's no known location, or if routing was unavailable.
const { reachGeoJSON } = useReachOverlay()

// A smoothed convex hull enclosing the posts currently shown, as an indication
// of the area covered. The true reach is travel-time-based (not a simple
// radius), so this is only an approximation - but it shrinks as the slider is
// pulled in, giving a visual sense of coverage. See buildCoverageGeoJSON for why
// this is an outward-rounded hull rather than Chaikin smoothing.
const hullGeoJSON = computed(() => {
  // Drawn for BOTH the nearby and "all my communities" views (not gated on showIsochrones,
  // which is nearby-only). It is just a hull of the posts currently shown, so it adapts to the
  // distance slider the same way on either view. Falls back to null (no polygon) when there
  // aren't enough points, which buildCoverageGeoJSON handles.
  const points = messagesForMap.value
    .filter((m) => m.lat != null || m.lng != null)
    .map((m) => [m.lng, m.lat])
  return buildCoverageGeoJSON(points)
})

// Prefer the real reach; fall back to the hull. The hull only ever answered "where did the
// posts we happen to have land", which drifts with whatever is currently for offer and says
// nothing about travel time. The reach answers the question the slider actually asks, so it
// wins whenever we have it.
const coverageGeoJSON = computed(() => {
  return reachGeoJSON.value || hullGeoJSON.value
})

const isochrones = computed(() => {
  // There's no longer a per-user isochrone polygon to fall back on - reach is worked
  // out server-side and just returns nearby posts. The only polygon we can draw is
  // an explicit override, e.g. the fixed Essex boundary on the Essex landing page.
  return props.isochroneOverride ? [props.isochroneOverride] : []
})

const isochroneGEOJSONs = computed(() => {
  const ret = []

  isochrones.value.forEach((i) => {
    const wkt = new Wkt.Wkt()
    try {
      wkt.read(i.polygon)
      // Apply Chaikin smoothing so the reach boundary matches the rippling
      // explorer's rounded appearance (3 iterations, same as the mod tool).
      ret.push({
        id: i.id,
        json: smoothGeoJSON(wkt.toJson()),
      })
    } catch (e) {
      console.log('WKT error', location, e)
    }
  })

  return ret
})

const isochroneOptions = computed(() => {
  // Faded fill so post pins remain clearly visible; soft border echoes the
  // rippling explorer's reach polygon style.
  //
  // The BORDER carries the shape, so it is nearly opaque. These settings were tuned for the
  // old post hull, which was a small compact ring sitting right among the pins - there, a
  // 0.5-opacity line read fine. The real travel-time reach is far bigger and more ragged,
  // its boundary sits out among busy OSM tiles rather than next to the posts, and at 0.5 it
  // disappeared into them entirely (checked in a browser: the overlay was drawing correctly
  // and was simply not visible). The fill stays low for the same reason it always was.
  return {
    fillColor: ISOCHRONE_COLOR,
    fill: true,
    fillOpacity: 0.12,
    color: ISOCHRONE_COLOR,
    weight: 2,
    opacity: 0.9,
  }
})

const messageIds = computed(() => {
  return new Set(distanceFilteredMessages.value.map((m) => m.id))
})

const secondaryMessagesForMap = computed(() => {
  const withinDistance = (m) =>
    isWithinDistance(m.distance, props.selectedMaxDistance)

  if (secondaryMessageList.value?.length > 200) {
    // So many posts that the precise numbers no longer matter that much.  So return all the ones we have fetched
    // rather than spend CPU on filtering (which is a significant issue on slow browsers).
    return secondaryMessageList.value.filter(withinDistance)
  } else {
    // Return anything relevant we have fetched which is not already in the primary one.
    return secondaryMessageList.value.filter((m) => {
      return (
        withinDistance(m) &&
        !messageIds.value.has(m.id) &&
        (!props.groupid || m.groupid === props.groupid) &&
        (props.type === 'All' || m.type === props.type)
      )
    })
  }
})
// Watchers
watch(bounds, (newVal, oldVal) => {
  if (!showGroups.value) {
    getMessages()
  }
})

watch(showGroups, (newVal) => {
  if (!newVal && !props.authorityid) {
    getMessages()
  }
})

watch(zoom, (newVal) => {
  if (newVal < props.postZoom && !props.forceMessages) {
    emit('update:showGroups', true)
  } else {
    emit('update:showGroups', false)
  }
})

// Fit the map to the currently-shown (distance-filtered) markers, with a little
// padding, so the map frames what's actually visible - and zooms in as the
// distance slider is pulled in. No-op if the map isn't ready or nothing has
// coordinates.
function fitToShownMarkers() {
  if (!mapObject.value) return
  const latlngs = messagesForMap.value
    .filter((m) => m.lat != null || m.lng != null)
    .map((m) => [m.lat, m.lng])
  if (!latlngs.length) return
  try {
    mapObject.value.fitBounds(new window.L.LatLngBounds(latlngs), {
      padding: [40, 40],
      maxZoom: props.postZoom + 3,
    })
  } catch (e) {
    // This happens when leaflet is destroyed.
    console.log('Ignore fitToShownMarkers exception', e)
  }
}

watch(nearbyBounds, () => {
  // Frame the map around the nearby messages we've fetched (with padding).
  fitToShownMarkers()
  getMessages()
})

// Re-fit whenever the set of shown posts changes - the distance slider narrowing
// or widening, or new data arriving - so the map always frames what's on screen.
// Keyed on the count + bounds of the shown set and debounced, so the map settles
// once after any churn rather than fighting itself mid-update.
let fitDebounce = null
watch(
  () => {
    const m = messagesForMap.value
    if (!m.length) return '0'
    let latMin = Infinity
    let latMax = -Infinity
    let lngMin = Infinity
    let lngMax = -Infinity
    for (const p of m) {
      if (p.lat != null) {
        latMin = Math.min(latMin, p.lat)
        latMax = Math.max(latMax, p.lat)
      }
      if (p.lng != null) {
        lngMin = Math.min(lngMin, p.lng)
        lngMax = Math.max(lngMax, p.lng)
      }
    }
    return `${m.length}:${latMin.toFixed(2)}:${latMax.toFixed(
      2
    )}:${lngMin.toFixed(2)}:${lngMax.toFixed(2)}`
  },
  () => {
    if (fitDebounce) clearTimeout(fitDebounce)
    fitDebounce = setTimeout(fitToShownMarkers, 200)
  }
)

watch(
  groups,
  (newval) => {
    emit('groups', newval)
  },
  { immediate: true }
)

watch(
  () => props.type,
  () => {
    lastBounds.value = null
    lastSearchKey.value = null

    if (zoom.value >= props.postZoom || props.search) {
      getMessages()
    }
  }
)

watch(
  () => props.search,
  () => {
    lastBounds.value = null
    lastSearchKey.value = null
    getMessages()
  }
)

watch(
  () => props.groupid,
  (groupid) => {
    lastBounds.value = null
    lastSearchKey.value = null

    if (groupid) {
      // Use the bounding box for the group.
      const group = myGroup(groupid)
      console.log('Got group', group)

      if (group.bbox) {
        const wkt = new Wkt.Wkt()
        try {
          wkt.read(group.bbox)
          const obj = wkt.toObject()
          const thisbounds = obj.getBounds()
          const sw = thisbounds.getSouthWest()
          const ne = thisbounds.getNorthEast()

          const latLngBounds = new window.L.LatLngBounds([
            [sw.lat, sw.lng],
            [ne.lat, ne.lng],
          ]).pad(0.1)

          // For reasons I don't understand, leaflet throws errors if we don't make these local here.
          const swlat = latLngBounds.getSouthWest().lat
          const swlng = latLngBounds.getSouthWest().lng
          const nelat = latLngBounds.getNorthEast().lat
          const nelng = latLngBounds.getNorthEast().lng

          mapObject.value.flyToBounds([
            [swlat, swlng],
            [nelat, nelng],
          ])

          moved.value = true
        } catch (e) {
          console.log('WKT error', location, e)
        }
      }
    }
  }
)

watch(groupsInBounds, (newval) => {
  emit(
    'groups',
    groupsInBounds.value.map((g) => g.id)
  )
})
function myGroup(groupId) {
  return groupStore.list?.[groupId] || {}
}

// Lifecycle hooks
onMounted(async () => {
  if (mapHidden.value) {
    // Say we're ready so the parent can crack on.
    emit('update:ready', true)

    // Fetch the messages.
    getMessages()
  }

  await loadLeaflet()
})

onBeforeUnmount(() => {
  destroyed.value = true
  if (markerFixInterval.value) {
    clearInterval(markerFixInterval.value)
  }
})

const markerFixInterval = ref(null)

function fixDefaultMarkers() {
  if (!mapcont.value) return

  // Find any default Leaflet marker icons and replace with our custom icon
  const defaultMarkers = mapcont.value.querySelectorAll(
    'img[src*="marker-icon"]'
  )

  defaultMarkers.forEach((img) => {
    img.src = '/mapmarker.gif'
    img.style.width = '15px'
    img.style.height = '19px'
    img.style.marginLeft = '-7px'
    img.style.marginTop = '-19px'
  })

  // Stop checking once no default markers found for a while
  if (defaultMarkers.length === 0) {
    markerFixCount.value++
    if (markerFixCount.value > 10) {
      clearInterval(markerFixInterval.value)
      markerFixInterval.value = null
    }
  } else {
    markerFixCount.value = 0
  }
}

const markerFixCount = ref(0)

function startMarkerFix() {
  if (!markerFixInterval.value) {
    markerFixCount.value = 0
    markerFixInterval.value = setInterval(fixDefaultMarkers, 500)
  }
}

// Methods
async function ready() {
  emit('update:ready', true)
  mapObject.value = map.value.leafletObject

  if (process.client && mapObject.value) {
    try {
      mapObject.value.fitBounds(props.initialBounds)

      // Start checking for and fixing default markers
      startMarkerFix()

      const runtimeConfig = useRuntimeConfig()

      const { Geocoder } = await import('leaflet-control-geocoder/src/control')
      const { Photon } = await import(
        'leaflet-control-geocoder/src/geocoders/photon'
      )

      new Geocoder({
        placeholder: 'Search for a place...',
        defaultMarkGeocode: false,
        geocoder: new Photon({
          geocodingQueryParams: {
            bbox: '-7.57216793459, 49.959999905, 1.68153079591, 58.6350001085',
          },
          nameProperties: [
            'name',
            'street',
            'suburb',
            'hamlet',
            'town',
            'city',
          ],
          serviceUrl: runtimeConfig.public.GEOCODE,
        }),
        collapsed: false,
      })
        .on('markgeocode', async function (e) {
          if (e && e.geocode && e.geocode.bbox) {
            // Empty out the query box so that the dropdown closes.  Note that "this" is the control object,
            // which is why this isn't in a separate method.
            console.log('Search for place', e)
            this.moved = true
            this.setQuery('')

            // If we don't find anything at this location we will want to zoom out.
            shownMany.value = false

            // For some reason we need to take a copy of the latlng bounds in the event before passing it to
            // flyToBounds.
            const flyTo = e.geocode.bbox
            const L = await import('leaflet/dist/leaflet-src.esm')
            const newBounds = new L.LatLngBounds(
              new L.LatLng(flyTo.getSouthWest().lat, flyTo.getSouthWest().lng),
              new L.LatLng(flyTo.getNorthEast().lat, flyTo.getNorthEast().lng)
            )
            // Move the map to the location we've found.
            map.value.leafletObject.flyToBounds(newBounds)
            emit('searched')
          }
        })
        .addTo(mapObject.value)
    } catch (e) {
      // This is usually caused by leaflet.
      console.log('Ignore leaflet exception', e)
    }
  }
}
function clusterClick() {
  moved.value = true
  idle()
}

function idle() {
  mapIdle.value++

  try {
    if (mapObject.value) {
      // We need to update the parent about our zoom level and whether we are showing the posts or groups.
      const newBounds = mapObject.value.getBounds().toBBoxString()

      if (newBounds !== lastBounds.value) {
        lastBounds.value = newBounds

        if (showMessages.value) {
          getMessages()
        }
      }

      emit('update:bounds', mapObject.value.getBounds())
      emit('update:zoom', mapObject.value.getZoom())
      emit('update:centre', mapObject.value.getCenter())
      emit('idle', mapObject.value)
    }
  } catch (e) {
    console.error('Error in map idle', e)
  }
}

// Ask the server for a search, unless it is the same question we just asked. See
// lastSearchKey above for why this is worth guarding.
async function searchOnce(params) {
  const key = JSON.stringify(params)

  if (key === lastSearchKey.value && lastSearchResult.value) {
    return lastSearchResult.value
  }

  const results = await messageStore.search(params)
  lastSearchKey.value = key
  lastSearchResult.value = results

  return results
}

async function getMessages() {
  let messages = []
  secondaryMessageList.value = []

  emit('update:loading', true)

  let bounds = new window.L.LatLngBounds(props.initialBounds)

  if (mapObject.value) {
    // Get the messages from the server which are in the bounds of the map.
    bounds = mapObject.value.getBounds()

    if (mapObject.value.getZoom() < props.minZoom) {
      // The parent may replace us with something else at this point, e.g. with a group map.  But maybe not.
      // Their call.
      emit('minzoom', mapObject.value.getZoom())
    }
  }

  const swlat = bounds.getSouthWest().lat
  const swlng = bounds.getSouthWest().lng
  const nelat = bounds.getNorthEast().lat
  const nelng = bounds.getNorthEast().lng
  let ret = null

  // Nearby (reach) view: the reachable set is worked out server-side from the member's
  // location and does NOT change as they pan or zoom the map. So always show exactly that
  // reach feed and never fetch by map bounds - a bounds fetch would surface posts outside
  // the member's reach, which they can see but can't reply to. This also means we skip the
  // "not many showing, zoom out and refetch" padding below, which was the source of far,
  // unreachable posts leaking into the nearby list. Search within nearby is handled in the
  // showIsochrones branch further down (it intersects the reach feed with a bounds search).
  if (props.showIsochrones && !props.search && (me?.lat || me?.lng)) {
    console.log('GetMessages - nearby reach feed')
    const nearby = await nearbyStore.fetchMessages()
    if (nearby && !destroyed.value) {
      messageList.value = nearby
      emit('messages', messageList.value)
    }
    emit('update:loading', false)
    return cloneDeep(nearby || [])
  }

  if (moved.value) {
    // The map has been moved.
    if (props.search) {
      // Search within the bounds of the map.
      console.log('GetMessages - moved, search within map bounds')
      ret = await searchOnce({
        messagetype: props.type,
        search: props.search,
        swlat,
        swlng,
        nelat,
        nelng,
      })
    } else {
      // Just fetch the bounds of the map.
      console.log('GetMessages - moved, fetch within map bounds')
      ret = await messageStore.fetchInBounds(swlat, swlng, nelat, nelng)
    }
  } else if (props.groupid) {
    // We have been asked to show a specific group.
    if (props.search) {
      // So search within that group. On the Browse page, browse=1 additionally applies
      // the member's distance slider and sort so results match their filtered feed.
      console.log('GetMessages - search on specific group')
      ret = await searchOnce({
        messagetype: props.type,
        search: props.search,
        groupids: [props.groupid],
        ...(props.browseSearch ? { browse: 1 } : {}),
      })
    } else {
      // Just fetch that the messages on that group.
      console.log('GetMessages - fetch on specific group')
      ret = await messageStore.fetchMyGroups(props.groupid)

      if (!mapHidden.value) {
        // Fetch all the messages in the map bounds too, so that we can show others as secondary.
        // No need to bother if the map isn't showing - they don't appear in the post list.
        secondaryMessageList.value = await messageStore.fetchInBounds(
          swlat,
          swlng,
          nelat,
          nelng
        )
      }
    }
  } else if (props.authorityid) {
    // We are trying to show posts within a specific authority
    console.log('Get messages within authority')
    ret = await authorityStore.fetchMessages(props.authorityid)

    // Don't fetch the other messages - this may return so many it's too much load on the client.
  } else if (props.showIsochrones) {
    // We are trying to show posts nearby - the reach-based feed the server computes
    // from the member's location. There's no client-side polygon any more, so the
    // gate is simply whether we know where the member is.
    if (me?.lat || me?.lng) {
      // We know where the member is, so ask the server for their nearby feed.
      if (props.search) {
        // Search within the nearby feed: browse=1 makes the server scope the search
        // universe to exactly the member's reach feed (plus their distance slider and
        // sort), so this is a single call. The old approach - fetch the feed, run a
        // separate map-bounds search, intersect client-side - lost in-feed matches
        // whenever the capped viewport search filled up with out-of-feed posts
        // (Discourse 9933).
        console.log('GetMessages - search in nearby feed')
        ret = await searchOnce({
          messagetype: props.type,
          search: props.search,
          browse: 1,
        })
      }
      // The non-search nearby case is handled by the early reach-feed return above, so
      // there's nothing to do here for it - we never fetch by map bounds for nearby.
    } else if (myGroups.value?.length) {
      // We don't know where the member is, so use the bounding boxes of the groups we are in.
      const groupbounds = myGroupsBoundingBox.value

      if (props.search) {
        console.log('GetMessages - search within group bounds')
        ret = await searchOnce({
          messagetype: props.type,
          search: props.search,
          swlat: groupbounds[0][0],
          swlng: groupbounds[0][1],
          nelat: groupbounds[1][0],
          nelng: groupbounds[1][1],
        })
      } else {
        // Just fetch the messages within those bounds.    This will show a bit more than the strict
        // "all my groups" option, but not as much as we might show using the map bounds.
        console.log(
          'GetMessages - fetch in group bounds',
          JSON.stringify(groupbounds)
        )

        if (lastBoundsFetch.value !== JSON.stringify(groupbounds)) {
          lastBoundsFetch.value = JSON.stringify(groupbounds)

          ret = await messageStore.fetchInBounds(
            groupbounds[0][0],
            groupbounds[0][1],
            groupbounds[1][0],
            groupbounds[1][1],
            props.groupid
          )
        } else {
          console.log('Already fetched that.')
        }
      }
    } else if (props.search) {
      // We have no location and no groups.  Do nothing - we expect code elsewhere to prompt for a location.
      // Search within the bounds of the map.
      console.log(
        'GetMessages - no location, no groups, search within map bounds'
      )
      ret = await searchOnce({
        messagetype: props.type,
        search: props.search,
        swlat,
        swlng,
        nelat,
        nelng,
      })
    } else {
      // Just fetch the bounds of the map.
      console.log(
        'GetMessages - no location, no groups, fetch within map bounds'
      )
      ret = await messageStore.fetchInBounds(swlat, swlng, nelat, nelng)
    }
  } else if (myGroups.value?.length) {
    if (props.search) {
      if (props.browseSearch) {
        // Browse "All my communities": the member's groups ARE the universe, and browse=1
        // applies their distance slider and sort server-side. No bounds - a bounding box
        // over scattered groups both leaks other groups' posts and clips nothing useful.
        console.log(
          'GetMessages - browse search across my communities',
          myGroupIds
        )
        ret = await searchOnce({
          messagetype: props.type,
          search: props.search,
          groupids: myGroupIds,
          browse: 1,
        })
      } else {
        const groupbounds = myGroupsBoundingBox.value

        console.log(
          'GetMessages - some groups, search within group bounds',
          groupbounds,
          myGroupIds
        )
        ret = await searchOnce({
          messagetype: props.type,
          search: props.search,
          swlat: groupbounds[0][0],
          swlng: groupbounds[0][1],
          nelat: groupbounds[1][0],
          nelng: groupbounds[1][1],
          groupids: myGroupIds,
        })
      }
    } else {
      // We have groups, so fetch the messages in those groups.
      console.log('GetMessages - some groups, fetch groups')
      ret = await messageStore.fetchMyGroups()

      // Get the messages in the map bounds too, so that we can show others as secondary.
      secondaryMessageList.value = await messageStore.fetchInBounds(
        swlat,
        swlng,
        nelat,
        nelng
      )
    }
  } else {
    // We have no groups, so fetch the messages in the map bounds.
    console.log('GetMessages - no groups, fetch in map bounds')
    ret = await messageStore.fetchInBounds(swlat, swlng, nelat, nelng)
  }

  if (ret && !destroyed.value) {
    messages = ret
  }

  if (messages?.length) {
    if (props.groupid) {
      messages = messages.filter((m) => {
        return m.groupid === props.groupid
      })
    }

    if (props.type !== 'All') {
      messages = messages.filter((m) => {
        return m.type === props.type
      })
    }
  }

  let countInBounds = 0

  messages.forEach((m) => {
    if (swlat <= m.lat && m.lat <= nelat && swlng <= m.lng && m.lng <= nelng) {
      countInBounds++
    }
  })

  if (props.isochroneOverride) {
    // Don't want to autozoom out in this case - stay where we're put.
    shownMany.value = true
  } else if (countInBounds >= manyToShow.value) {
    // We have seen lots, so we don't need to do the auto zoom out thing now.
    shownMany.value = true
  } else if (
    !props.search &&
    props.showMany &&
    countInBounds < manyToShow.value &&
    !shownMany.value &&
    mapObject.value
  ) {
    // If we haven't got more than 1 message at this zoom level, zoom out.  That means we'll always show at
    // least something.  This is useful when we search for a specific place. Guard on mapObject.value:
    // getMessages() is async and can resolve after the component has been torn down (the map object is
    // then null), so re-check before dereferencing it here.
    const currzoom = mapObject.value.getZoom()
    if (currzoom > props.minZoom) {
      console.log(
        'Not enough showing, zoom out',
        countInBounds,
        manyToShow.value,
        currzoom,
        props.minZoom
      )
      mapObject.value.setZoom(currzoom - 1)
      moved.value = true
    } else {
      shownMany.value = true
    }
  }

  messageList.value = messages || []
  emit('messages', messageList.value)
  emit('update:loading', false)

  return cloneDeep(messages)
}

async function goHome() {
  await loadLeaflet()

  if (me.lat || me.lng) {
    mapObject.value.flyTo(new window.L.LatLng(me.lat, me.lng))
  }
}

function dragEnd(e) {
  moved.value = true
  emit('update:moved', true)
  idle()
}
</script>
<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'bootstrap/scss/mixins/_breakpoints';

/* Hide default Leaflet markers until our fix replaces them */
:deep(img[src*='marker-icon']) {
  display: none !important;
}

.mapbox {
  width: 100%;
  top: 0px;
  left: 0;
  border: 1px solid $color-gray--light;
  border-radius: var(--radius-md, 0.5rem);
  overflow: hidden;
}

:deep(.leaflet-control-geocoder) {
  right: 30px;
}

@media screen and (max-width: 360px) {
  :deep(.leaflet-control-geocoder-form input) {
    max-width: 200px;
  }
}

@include media-breakpoint-up(md) {
  :deep(.leaflet-control-geocoder-form input) {
    height: calc(1.25em + 1rem + 2px);
    padding: 0.5rem 1rem;
    font-size: 1rem !important;
    line-height: 1.25;
    border-radius: var(--radius-sm, 0.375rem);
  }
}

:deep(.handle) {
  content: url('data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABmJLR0QA/wD/AP+gvaeTAAAACXBIWXMAAA7DAAAOwwHHb6hkAAAAB3RJTUUH5AoLCyYQDowQNQAAAHRJREFUOMtjYBhMQJ6BgSGLgYHhP5TPzMDA4AXlG0DFuBkYGDKQ1KAAmGZ9KB+muY6BgUEYqjkaKuaAzYD/DAwM6mg212Cx2Z2QV5A1CxBjMwMWP9PXZuQwIMtmGDAj12ZkQJbNyJrJtpmBEpuRA9GBYcgBALMUJBS9QtP6AAAAAElFTkSuQmCC');
}

:deep(.top) {
  z-index: 1000 !important;
}

.pauto {
  pointer-events: auto;
}

:deep(.fadedMarker) {
  filter: grayscale(100%);
  z-index: -1 !important;

  &.icon,
  .icon {
    border: 5px solid $color-gray--light;
    opacity: 0.5;
  }
}
</style>
