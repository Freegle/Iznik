<template>
  <div v-if="clusters && clusters.length">
    <div v-for="cluster in clusters" :key="'cluster-' + cluster.id">
      <l-marker
        v-if="cluster.properties"
        :lat-lng="[
          cluster.geometry.coordinates[1],
          cluster.geometry.coordinates[0],
        ]"
        :interactive="false"
        :title="cluster.properties"
        @click="clusterClick(cluster)"
      >
        <l-icon :class-name="cssClass">
          <ClusterIcon
            :count="cluster.properties ? cluster.properties.point_count : 1"
            :tag="tag"
            :class-name="'clear ' + cssClass"
          />
        </l-icon>
      </l-marker>
      <l-marker
        v-else
        :lat-lng="[
          cluster.geometry.coordinates[1],
          cluster.geometry.coordinates[0],
        ]"
        :interactive="false"
        :class-name="cssClass"
        @click="pointClick(cluster)"
      >
        <l-icon>
          <ClusterIcon
            :count="cluster.properties ? cluster.properties.point_count : 1"
            :tag="tag"
            :class-name="'clear ' + cssClass"
          />
        </l-icon>
      </l-marker>
    </div>
  </div>
</template>
<script setup>
import { computed, ref, watch, onBeforeUnmount } from 'vue'
import Supercluster from 'supercluster/dist/supercluster'
import ClusterIcon from './ClusterIcon'
import { MAX_MAP_ZOOM } from '~/constants'

const props = defineProps({
  // Array of { id, lat, lng }
  markers: {
    type: Array,
    required: true,
  },
  map: {
    type: Object,
    required: true,
  },
  radius: {
    type: Number,
    required: false,
    default: 60,
  },
  extent: {
    type: Number,
    required: false,
    default: 256,
  },
  minZoom: {
    type: Number,
    required: false,
    default: 0,
  },
  maxZoom: {
    type: Number,
    required: false,
    default: MAX_MAP_ZOOM,
  },
  minCluster: {
    type: Number,
    required: false,
    default: 10,
  },
  tag: {
    type: String,
    required: false,
    default: null,
  },
  cssClass: {
    type: String,
    required: false,
    default: '',
  },
})

const emit = defineEmits(['click'])

// Recompute clusters when the map zooms or pans. Supercluster returns different
// clusters per zoom/bbox, but the `clusters` computed reads getZoom()/getBounds()
// imperatively (non-reactive), so without this the clustering would freeze at the
// zoom it was first computed at (and wouldn't split/merge as you zoom or as the
// distance slider changes the framing). Bump a reactive epoch on the map's
// zoomend/moveend and read it in `clusters`.
const mapEpoch = ref(0)
let boundMap = null
function onMapChange() {
  mapEpoch.value++
}
watch(
  () => props.map,
  (m) => {
    // Guard on() / off(): a real Leaflet map has them, but test mocks (and any
    // non-Leaflet map object) may not, and we must not throw during setup.
    if (boundMap && typeof boundMap.off === 'function') {
      boundMap.off('zoomend moveend', onMapChange)
    }
    boundMap = null
    if (m && typeof m.on === 'function') {
      m.on('zoomend moveend', onMapChange)
      boundMap = m
      mapEpoch.value++
    }
  },
  { immediate: true }
)
onBeforeUnmount(() => {
  if (boundMap && typeof boundMap.off === 'function') {
    boundMap.off('zoomend moveend', onMapChange)
  }
  boundMap = null
})

const points = computed(() => {
  // Ensure markers at the exact same location don't sit precisely on top of each
  // other (so each can be seen/clicked when zoomed in): nudge each subsequent
  // duplicate at a location by a small, fixed offset.
  //
  // IMPORTANT: compute the nudged position into LOCAL variables and never mutate
  // the marker objects. props.markers are the same objects the list and store
  // hold, so writing marker.lat/marker.lng corrupts a post's real coordinates -
  // and because this is a computed that re-runs, the offset would ACCUMULATE on
  // every recompute, eventually flinging a post far from where it actually is.
  // (The previous version also did Math.max(nelat, ...), which snapped every
  // duplicate to the north edge of the map.)
  const ret = []
  const seen = {}

  if (props.map && props.markers) {
    props.markers.forEach((marker) => {
      if (marker.lat || marker.lng) {
        const key = marker.lat + '|' + marker.lng
        const already = seen[key] || 0
        seen[key] = already + 1

        const lat = marker.lat + already * 0.003
        const lng = marker.lng + already * 0.003

        ret.push({
          id: marker.id,
          type: 'Point',
          geometry: {
            coordinates: [lng, lat],
          },
        })
      }
    })
  }

  return ret
})

const index = computed(() => {
  // Generate the index.  It's immutable, so we need to generate a new index each time the points change.
  const idx = new Supercluster({
    radius: props.radius,
    extent: props.extent,
    maxZoom: props.maxZoom,
    minZoom: props.minZoom,
  })

  idx.load(points.value)

  return idx
})

const clusters = computed(() => {
  // Reading mapEpoch makes this recompute on map zoom/pan (see above).
  // eslint-disable-next-line no-unused-expressions
  mapEpoch.value

  let clustersList = []

  try {
    if (props.map) {
      if (props.markers.length < props.minCluster) {
        // We've seen some cases where Supercluster excludes some markers from the cluster, so that the sum of
        // what we get back is less than the number we passed in.  That isn't obvious except for low numbers of
        // markers, i.e. high zoom levels.  It may be a bug, but we don't much care - for our purposes the
        // actual numbers don't have to be spot on.  Using a minimum means that we can avoid that becoming
        // obvious.
        clustersList = points.value
      } else {
        const bounds = props.map.getBounds()
        const zoom = Math.round(props.map.getZoom())
        let bbox = null

        try {
          if (bounds) {
            bbox = [
              bounds.getNorthWest().lng,
              bounds.getSouthEast().lat,
              bounds.getSouthEast().lng,
              bounds.getNorthWest().lat,
            ]

            clustersList = index.value.getClusters(bbox, zoom)
          }
        } catch (e) {
          console.log('Exception 1', e, bounds, zoom, index.value)
        }
      }
    }
  } catch (e) {
    console.log('Exception 2', e)
  }

  return clustersList
})

function clusterClick(cluster) {
  let zoom = index.value.getClusterExpansionZoom(cluster.properties.cluster_id)

  // Don't allow us to zoom in too far.
  zoom = Math.min(zoom, props.maxZoom)

  props.map.flyTo(
    [cluster.geometry.coordinates[1], cluster.geometry.coordinates[0]],
    zoom
  )

  emit('click')
}

function pointClick(cluster) {
  // It's a point. Centre on it, and zoom right in.
  props.map.flyTo(
    [cluster.geometry.coordinates[1], cluster.geometry.coordinates[0]],
    props.maxZoom
  )

  emit('click')
}
</script>
