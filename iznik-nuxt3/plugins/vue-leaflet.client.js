// plugins/leaflet.ts
import { defineAsyncComponent } from 'vue'
import { defineNuxtPlugin } from '#app'

// @vue-leaflet 0.10+ ships dist-only (no src/ tree), so all components come
// from the package root as named exports. They still load lazily: the library
// chunk is only fetched the first time a map component actually renders.
//
// vue-leaflet runs with useGlobalLeaflet (window.L) by default, and when
// window.L is unset it imports the bare 'leaflet' package — a SECOND Leaflet
// instance, different from the 'leaflet/dist/leaflet-src.esm' one the rest of
// the app uses (0.6.x imported the same esm build, so this never mattered).
// Cross-instance LatLngBounds objects fail Leaflet's instanceof checks and
// fitBounds() then throws "Bounds are not valid.". Pin window.L to the app's
// canonical instance before any vue-leaflet component resolves.
const vueLeaflet = async () => {
  if (!window.L) {
    // Spread: the module namespace is frozen, and Leaflet plugins expect to
    // be able to mutate L (same pattern as composables/useMap.js).
    window.L = { ...(await import('leaflet/dist/leaflet-src.esm')) }
  }
  return import('@vue-leaflet/vue-leaflet')
}

export default defineNuxtPlugin((nuxtApp) => {
  nuxtApp.vueApp.component(
    'l-map',
    defineAsyncComponent(async () => {
      await import('leaflet/dist/leaflet.css')
      return (await vueLeaflet()).LMap
    })
  )

  const components = {
    'l-marker': 'LMarker',
    'l-tile-layer': 'LTileLayer',
    'l-icon': 'LIcon',
    'l-polygon': 'LPolygon',
    'l-geojson': 'LGeoJson',
    'l-circle-marker': 'LCircleMarker',
    'l-control': 'LControl',
    'l-feature-group': 'LFeatureGroup',
    'l-tooltip': 'LTooltip',
    'l-rectangle': 'LRectangle',
  }

  for (const [tag, name] of Object.entries(components)) {
    nuxtApp.vueApp.component(
      tag,
      defineAsyncComponent(async () => (await vueLeaflet())[name])
    )
  }
})
