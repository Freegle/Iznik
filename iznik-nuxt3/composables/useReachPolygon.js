// Chaikin's corner-cutting algorithm: smooths a closed polygon ring by
// inserting two new points on each edge, biased 25/75 toward each end.
// Repeated `iterations` times.  The ring's last point is expected to equal
// its first (closed); the returned ring has the same property.
//
// Mirrors modtools/composables/rippling/geometry.js so that member-facing
// components can share the same smoothing pass without depending on modtools.
export function chaikinSmooth(ring, iterations = 3) {
  let pts = ring.slice(0, -1)
  for (let iter = 0; iter < iterations; iter++) {
    const smoothed = []
    const n = pts.length
    for (let j = 0; j < n; j++) {
      const a = pts[j]
      const b = pts[(j + 1) % n]
      smoothed.push([0.75 * a[0] + 0.25 * b[0], 0.75 * a[1] + 0.25 * b[1]])
      smoothed.push([0.25 * a[0] + 0.75 * b[0], 0.25 * a[1] + 0.75 * b[1]])
    }
    pts = smoothed
  }
  pts.push(pts[0])
  return pts
}

// Convex hull of a set of [lng, lat] points, via Andrew's monotone chain.
// Returns a closed ring ([lng, lat], first point repeated at the end) suitable
// for a GeoJSON Polygon, or null when there are fewer than three distinct,
// non-collinear points. Used to draw a coverage hull around the posts currently
// shown on the map; feed the result through smoothGeoJSON for the rounded
// rippling-explorer look.
export function convexHull(points) {
  const seen = new Set()
  const pts = []
  for (const p of points || []) {
    if (!Array.isArray(p) || p[0] == null || p[1] == null) continue
    const key = p[0] + ',' + p[1]
    if (seen.has(key)) continue
    seen.add(key)
    pts.push([p[0], p[1]])
  }
  if (pts.length < 3) return null

  pts.sort((a, b) => a[0] - b[0] || a[1] - b[1])

  const cross = (o, a, b) =>
    (a[0] - o[0]) * (b[1] - o[1]) - (a[1] - o[1]) * (b[0] - o[0])

  const build = (source) => {
    const chain = []
    for (const p of source) {
      while (
        chain.length >= 2 &&
        cross(chain[chain.length - 2], chain[chain.length - 1], p) <= 0
      ) {
        chain.pop()
      }
      chain.push(p)
    }
    chain.pop()
    return chain
  }

  const lower = build(pts)
  const upper = build(pts.slice().reverse())
  const hull = lower.concat(upper)
  if (hull.length < 3) return null
  hull.push(hull[0])
  return hull
}

// Round the corners of a CONVEX ring by offsetting it outward with a circular
// arc at each vertex - i.e. an outward buffer with rounded joins. The boundary
// is `r` (in the ring's own units, degrees) OUTSIDE the hull everywhere, so
// every point the hull was built from stays enclosed. This is the key
// difference from Chaikin corner-cutting, which pulls the boundary INWARD and
// so can leave outlying points outside. `ring` must be the closed convex hull
// ([lng,lat], first point repeated); `steps` is points per corner arc.
export function roundedConvexHull(ring, r, steps = 8) {
  const pts = ring.slice(0, -1)
  const n = pts.length
  if (n < 3 || !(r > 0)) return ring

  // Orientation from the signed area (>0 = counter-clockwise).
  let area = 0
  for (let i = 0; i < n; i++) {
    const [x1, y1] = pts[i]
    const [x2, y2] = pts[(i + 1) % n]
    area += x1 * y2 - x2 * y1
  }
  const ccw = area > 0

  const unit = ([x, y]) => {
    const l = Math.hypot(x, y) || 1
    return [x / l, y / l]
  }
  // Outward-pointing normal of an edge direction, given the orientation.
  const outward = ([dx, dy]) => (ccw ? [dy, -dx] : [-dy, dx])

  const out = []
  for (let i = 0; i < n; i++) {
    const prev = pts[(i - 1 + n) % n]
    const cur = pts[i]
    const next = pts[(i + 1) % n]

    const n1 = outward(unit([cur[0] - prev[0], cur[1] - prev[1]]))
    const n2 = outward(unit([next[0] - cur[0], next[1] - cur[1]]))

    const a1 = Math.atan2(n1[1], n1[0])
    let da = Math.atan2(n2[1], n2[0]) - a1
    // Sweep the short way (the convex exterior turn is between 0 and PI).
    while (da > Math.PI) da -= 2 * Math.PI
    while (da < -Math.PI) da += 2 * Math.PI

    for (let s = 0; s <= steps; s++) {
      const a = a1 + (da * s) / steps
      out.push([cur[0] + r * Math.cos(a), cur[1] + r * Math.sin(a)])
    }
  }
  out.push(out[0])
  return out
}

// Build a smoothed convex-hull GeoJSON Polygon enclosing a set of [lng, lat] points -
// the Browse map's "coverage" overlay showing the area the currently-shown posts span.
// Returns null when fewer than three distinct, non-collinear points are given (see
// convexHull). Deliberately uses roundedConvexHull (an outward buffer), NOT
// chaikinSmooth - chaikinSmooth cuts corners INWARD and so can leave an outlying point
// (e.g. a single distant post) outside the smoothed boundary, which would be
// misleading for a "coverage" indicator. The corner radius scales with the hull's mean
// radius (18%), with a ~1-mile-ish floor (0.015 degrees) so rounding stays visible even
// for a small, tight cluster of posts.
export function buildCoverageGeoJSON(points) {
  const hull = convexHull(points)
  if (!hull) return null

  const v = hull.slice(0, -1)
  const cx = v.reduce((s, p) => s + p[0], 0) / v.length
  const cy = v.reduce((s, p) => s + p[1], 0) / v.length
  const meanR =
    v.reduce((s, p) => s + Math.hypot(p[0] - cx, p[1] - cy), 0) / v.length
  const r = Math.max(meanR * 0.18, 0.015)

  return { type: 'Polygon', coordinates: [roundedConvexHull(hull, r)] }
}

// Apply Chaikin smoothing to every ring in a GeoJSON geometry.
// Returns a new geometry object (does not mutate the input).
// Handles Polygon (single exterior + optional holes) and MultiPolygon.
// Other geometry types are returned unchanged.
export function smoothGeoJSON(geometry) {
  if (!geometry) return geometry

  if (geometry.type === 'Polygon') {
    return {
      ...geometry,
      coordinates: geometry.coordinates.map((ring) => chaikinSmooth(ring)),
    }
  }

  if (geometry.type === 'MultiPolygon') {
    return {
      ...geometry,
      coordinates: geometry.coordinates.map((poly) =>
        poly.map((ring) => chaikinSmooth(ring))
      ),
    }
  }

  return geometry
}
