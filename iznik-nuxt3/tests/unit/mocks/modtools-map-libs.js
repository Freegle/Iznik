/**
 * Stub for the map libraries that only the ModTools layer installs:
 * turf-polygon, turf-intersect and @geoman-io/leaflet-geoman-free (see
 * modtools/package.json). They are not in the root package.json, so the root
 * Vitest project cannot resolve them. Without this alias the coverage
 * transform of modtools/components/ModGroupMap.vue fails at import analysis,
 * the provider swallows the error and falls back to parsing the raw .vue file,
 * which fails with "Unexpected JSX expression", and the component is silently
 * dropped from coverage.
 *
 * No spec executes these libraries (ModGroupMap.spec.js mounts a stand-in), so
 * an inert default export is enough to make the module resolvable.
 */
export default function () {
  return null
}
