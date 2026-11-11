// Vite plugin that sends every bare `leaflet` import to Leaflet's ES module build.
//
// The package entry for a bare `leaflet` is the UMD build, and the UMD build ends with
// `window.L = exports`. The app pins window.L to the ES module build
// ('leaflet/dist/leaflet-src.esm') and builds all its bounds, markers and layers from it, so a
// library that does a bare `import 'leaflet'` (leaflet-control-geocoder does) loads a second copy
// and replaces window.L with it. Maps created afterwards use one copy while our code uses the
// other, and Leaflet's instanceof checks then fail: fitBounds throws "Bounds are not valid" and
// flyToBounds throws on `_northEast.lat`.
//
// Only the exact specifier `leaflet` is rewritten. Sub-paths such as leaflet/dist/leaflet.css
// are left alone.
export const LEAFLET_ESM = 'leaflet/dist/leaflet-src.esm.js'

export function leafletSingleInstance() {
  return {
    name: 'freegle-leaflet-single-instance',
    enforce: 'pre',
    async resolveId(source, importer, options) {
      if (source !== 'leaflet') {
        return null
      }

      return this.resolve(LEAFLET_ESM, importer, {
        ...options,
        skipSelf: true,
      })
    },
  }
}
