// Pure geometry helpers used by RipplingExplorer.  No DOM, no Leaflet —
// safe to import from anywhere and easy to unit-test.

// Smoothing is deliberately disabled: the reach is a grid-derived raster and must be
// displayed EXACTLY as computed. Corner-cutting (Chaikin) rounds the boundary, and where the
// boundary hugs a bank the rounded curve bulges across the river - drawing the reach reaching
// a far bank it cannot actually reach. The displayed polygon must be what is reached, so we
// return the ring unchanged (it is already closed). Kept as a pass-through so callers and the
// [lng,lat]->[lat,lng] swap in geoToLeaflet are unaffected.
export function chaikinSmooth(ring) {
  return ring
}

// Smooth a GeoJSON-style [lng, lat] ring AND swap to Leaflet's [lat, lng]
// convention in a single pass.
export function geoToLeaflet(coords) {
  return chaikinSmooth(coords).map(([lng, lat]) => [lat, lng])
}

// Haversine great-circle distance in km between two lat/lng points.
export function crowFliesKm(lat1, lng1, lat2, lng2) {
  const R = 6371
  const toRad = (d) => (d * Math.PI) / 180
  const dLat = toRad(lat2 - lat1)
  const dLng = toRad(lng2 - lng1)
  const a =
    Math.sin(dLat / 2) ** 2 +
    Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) ** 2
  return 2 * R * Math.asin(Math.min(1, Math.sqrt(a)))
}

// Ray-casting point-in-polygon test against a [lng, lat] ring.
export function pointInRing(fLng, fLat, ring) {
  let inside = false
  for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
    const [xi, yi] = ring[i]
    const [xj, yj] = ring[j]
    if (
      yi > fLat !== yj > fLat &&
      fLng < ((xj - xi) * (fLat - yi)) / (yj - yi) + xi
    )
      inside = !inside
  }
  return inside
}

// True if segment AB and segment CD cross strictly between their endpoints.
export function segmentsIntersect(ax, ay, bx, by, cx, cy, dx, dy) {
  const d1x = bx - ax
  const d1y = by - ay
  const d2x = dx - cx
  const d2y = dy - cy
  const cross = d1x * d2y - d1y * d2x
  if (Math.abs(cross) < 1e-12) return false
  const t = ((cx - ax) * d2y - (cy - ay) * d2x) / cross
  const u = ((cx - ax) * d1y - (cy - ay) * d1x) / cross
  return t > 0 && t < 1 && u > 0 && u < 1
}

// The group-tint decision: which groups count as REACHED right now. Prefers a
// ripple frame's per-tick reachable ids (the targeting decision at that tick);
// otherwise the max-extent gate ids for the pin; otherwise nothing - never a
// geometric fallback, because polygon overlap can wrongly tint far-bank groups.
export function reachedIdSet(frameIds, gateIds) {
  if (Array.isArray(frameIds)) return new Set(frameIds)
  return new Set(gateIds || [])
}
