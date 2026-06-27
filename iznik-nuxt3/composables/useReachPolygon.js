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
