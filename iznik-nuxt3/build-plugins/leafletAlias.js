// Vite alias that sends every bare `leaflet` import to Leaflet's ES module build.
//
// The package entry for a bare `leaflet` is the UMD build, and the UMD build ends with
// `window.L = exports`. The app pins window.L to the ES module build
// ('leaflet/dist/leaflet-src.esm') and builds all its bounds, markers and layers from it, so a
// library that does a bare `import 'leaflet'` (leaflet-control-geocoder does) loads a second copy
// and replaces window.L with it. Maps created afterwards use one copy while our code uses the
// other, and Leaflet's instanceof checks then fail: fitBounds throws "Bounds are not valid" and
// flyToBounds throws on `_northEast.lat`.
//
// It is an alias, not a resolver plugin, so the dev dependency pre-bundling (esbuild, which
// honours resolve.alias but not user plugins), the client build and SSR all agree. The pattern
// matches the exact specifier only: leaflet/dist/leaflet.css and the ES build itself are untouched.
export const leafletAlias = {
  find: /^leaflet$/,
  replacement: 'leaflet/dist/leaflet-src.esm.js',
}
