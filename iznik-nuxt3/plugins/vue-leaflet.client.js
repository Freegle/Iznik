// plugins/leaflet.ts
import { defineAsyncComponent } from 'vue'
import { defineNuxtPlugin } from '#app'

// @vue-leaflet 0.10+ ships dist-only (no src/ tree), so all components come
// from the package root as named exports. They still load lazily: the library
// chunk is only fetched the first time a map component actually renders.
const vueLeaflet = () => import('@vue-leaflet/vue-leaflet')

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
