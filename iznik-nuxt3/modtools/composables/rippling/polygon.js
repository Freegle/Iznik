// Pure polygon / GeoJSON helpers used by RipplingExplorer.  No DOM, no
// Leaflet — safe to import from anywhere and easy to unit-test.

import { pointInRing, segmentsIntersect } from './geometry.js'

// True if a GeoJSON polygon has a usable outer ring (≥4 points — a triangle
// plus the explicit close vertex).
export function hasRing(poly) {
  return (
    poly &&
    poly.geometry &&
    poly.geometry.coordinates &&
    poly.geometry.coordinates[0] &&
    poly.geometry.coordinates[0].length >= 4
  )
}

// Which deprivation quintile (1=most deprived, 5=least) covers a freegler at
// (fLng, fLat)?  Returns:
//   1..5  inside that quintile's polygon (or one of its islands)
//   -1    inside the standard isochrone but in a node with no LSOA data
//         (motorway, industrial area, untagged road) — count for notifications
//         but don't skew the deprivation percentage
//   0     outside the boundary
export function quintileOfFreegler(fLng, fLat, data) {
  for (let q = 1; q <= 5; q++) {
    const qr = (data.quintiles || {})[q]
    if (!qr) continue
    if (
      hasRing(qr.polygon) &&
      pointInRing(fLng, fLat, qr.polygon.geometry.coordinates[0])
    )
      return q
    for (const isl of qr.islands || []) {
      if (
        hasRing(isl) &&
        pointInRing(fLng, fLat, isl.geometry.coordinates[0])
      )
        return q
    }
  }
  const std = data.standard
  if (hasRing(std) && pointInRing(fLng, fLat, std.geometry.coordinates[0]))
    return -1
  return 0
}

// Naive centroid of a GeoJSON polygon (mean of outer-ring vertices).  Good
// enough for "where do we anchor this group's label" purposes.
export function groupCentroid(f) {
  const coords =
    f.geometry && f.geometry.coordinates && f.geometry.coordinates[0]
  if (!coords || !coords.length) return [0, 0]
  let sumLng = 0
  let sumLat = 0
  coords.forEach(([lng, lat]) => {
    sumLng += lng
    sumLat += lat
  })
  return [sumLng / coords.length, sumLat / coords.length]
}

// Equirectangular squared distance between two lat/lng points — fine for
// sorting and nearest-neighbour comparisons at the scale of a single town
// (avoids the cost of haversine when only ordering matters).
export function distSq(lat1, lng1, lat2, lng2) {
  const dlat = lat1 - lat2
  const dlng = (lng1 - lng2) * Math.cos((lat1 * Math.PI) / 180)
  return dlat * dlat + dlng * dlng
}

// True iff two closed [lng,lat] rings overlap (share area, contain each
// other, or have crossing edges).  Cheap bbox reject first.
export function ringsOverlap(ring1, ring2) {
  let r1minX = Infinity
  let r1maxX = -Infinity
  let r1minY = Infinity
  let r1maxY = -Infinity
  let r2minX = Infinity
  let r2maxX = -Infinity
  let r2minY = Infinity
  let r2maxY = -Infinity
  for (const [x, y] of ring1) {
    if (x < r1minX) r1minX = x
    if (x > r1maxX) r1maxX = x
    if (y < r1minY) r1minY = y
    if (y > r1maxY) r1maxY = y
  }
  for (const [x, y] of ring2) {
    if (x < r2minX) r2minX = x
    if (x > r2maxX) r2maxX = x
    if (y < r2minY) r2minY = y
    if (y > r2maxY) r2maxY = y
  }
  if (
    r1maxX < r2minX ||
    r2maxX < r1minX ||
    r1maxY < r2minY ||
    r2maxY < r1minY
  )
    return false
  for (const [lng, lat] of ring1) {
    if (pointInRing(lng, lat, ring2)) return true
  }
  for (const [lng, lat] of ring2) {
    if (pointInRing(lng, lat, ring1)) return true
  }
  for (let i = 0; i < ring1.length - 1; i++) {
    const [ax, ay] = ring1[i]
    const [bx, by] = ring1[i + 1]
    for (let j = 0; j < ring2.length - 1; j++) {
      const [cx, cy] = ring2[j]
      const [dx, dy] = ring2[j + 1]
      if (segmentsIntersect(ax, ay, bx, by, cx, cy, dx, dy)) return true
    }
  }
  return false
}
