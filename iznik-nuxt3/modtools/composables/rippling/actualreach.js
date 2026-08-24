// The ACTUAL stored reach overlay for the per-post reach modal: what the engine actually
// holds, as opposed to what the schedule says it should - the two diverge when a reach is
// held, clipped where members left a group, or capped by the poster's distance preference.
// Takes L and the map as arguments (rather than closing over them) so the whole update is
// unit-testable with stubs.
import { geoJsonToLatLngs } from './polygon.js'

// Replace `existing` (if any) with a layer drawn from `raw` (GeoJSON string or object, from
// the mod-only /message/{id}/reach endpoint). Returns the new layer, or null when there is
// nothing to draw. With the projection suppressed (`filled`) this is the only reach on the
// map, so fill it and drop the dashes - it's the subject, not an annotation over something
// else. Alongside a projection it stays a dashed outline so the filled red area still reads
// through.
export function updateActualReachLayer(L, map, existing, raw, filled) {
  if (existing && map && map.hasLayer(existing)) {
    map.removeLayer(existing)
  }
  if (!raw || !map) return null
  const latlngs = geoJsonToLatLngs(raw)
  if (!latlngs) return null
  return L.polygon(
    latlngs,
    filled
      ? {
          color: '#0055cc',
          weight: 2,
          fill: true,
          fillColor: '#0055cc',
          fillOpacity: 0.18,
        }
      : { color: '#0055cc', weight: 2, dashArray: '6 4', fill: false }
  )
    .bindTooltip('Actual reach right now (from the engine)', { sticky: true })
    .addTo(map)
}
