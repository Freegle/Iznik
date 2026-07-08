import { describe, it, expect } from 'vitest'
import {
  chaikinSmooth,
  smoothGeoJSON,
  convexHull,
  roundedConvexHull,
  buildCoverageGeoJSON,
} from '~/composables/useReachPolygon'

// A simple square ring in [lng, lat] order (GeoJSON convention), closed.
const SQUARE = [
  [0, 0],
  [1, 0],
  [1, 1],
  [0, 1],
  [0, 0],
]

// Robust "point inside or on the boundary of a closed ring" test (ray-casting with a
// boundary tolerance), used to assert the key coverage-hull invariant: every point the
// hull was built from - including the hull's own vertices, which sit exactly ON the
// boundary - must be enclosed. `ring` is a closed [lng, lat] ring (first === last).
function pointOnSegment([px, py], [ax, ay], [bx, by], eps = 1e-9) {
  const cross = (bx - ax) * (py - ay) - (by - ay) * (px - ax)
  if (Math.abs(cross) > eps) return false
  const dot = (px - ax) * (bx - ax) + (py - ay) * (by - ay)
  if (dot < -eps) return false
  const lenSq = (bx - ax) * (bx - ax) + (by - ay) * (by - ay)
  return dot <= lenSq + eps
}

function pointInPolygon([x, y], ring) {
  let inside = false
  const pts = ring[0] === ring[ring.length - 1] ? ring.slice(0, -1) : ring
  const n = pts.length
  for (let i = 0, j = n - 1; i < n; j = i++) {
    const [xi, yi] = pts[i]
    const [xj, yj] = pts[j]
    if (pointOnSegment([x, y], [xi, yi], [xj, yj])) return true
    const intersect =
      yi > y !== yj > y && x < ((xj - xi) * (y - yi)) / (yj - yi) + xi
    if (intersect) inside = !inside
  }
  return inside
}

// Deterministic pseudo-random generator so failures are reproducible without relying
// on Math.random() seeding.
function mulberry32(seed) {
  let a = seed
  return function () {
    a |= 0
    a = (a + 0x6d2b79f5) | 0
    let t = Math.imul(a ^ (a >>> 15), 1 | a)
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
}

describe('chaikinSmooth', () => {
  it('returns more points than the input ring', () => {
    const result = chaikinSmooth(SQUARE)
    // One iteration doubles (minus 1 close) then re-closes; 3 iterations from 4
    // open points → 32 open + close = 33 points.
    expect(result.length).toBeGreaterThan(SQUARE.length)
  })

  it('closes the returned ring (first === last)', () => {
    const result = chaikinSmooth(SQUARE)
    expect(result[0]).toEqual(result[result.length - 1])
  })

  it('stays within the bounding box of the input ring', () => {
    const result = chaikinSmooth(SQUARE)
    result.forEach(([lng, lat]) => {
      expect(lng).toBeGreaterThanOrEqual(0)
      expect(lng).toBeLessThanOrEqual(1)
      expect(lat).toBeGreaterThanOrEqual(0)
      expect(lat).toBeLessThanOrEqual(1)
    })
  })

  it('applies exactly three iterations by default', () => {
    // After 1 iteration: 4 open pts → 8; after 2: → 16; after 3: → 32; +close = 33.
    const result = chaikinSmooth(SQUARE, 3)
    expect(result.length).toBe(33)
  })

  it('applies a single iteration when requested', () => {
    // 4 open pts → 8; +close = 9.
    const result = chaikinSmooth(SQUARE, 1)
    expect(result.length).toBe(9)
  })

  it('handles a triangle ring', () => {
    const tri = [
      [0, 0],
      [2, 0],
      [1, 2],
      [0, 0],
    ]
    const result = chaikinSmooth(tri, 1)
    // 3 open pts → 6; +close = 7.
    expect(result.length).toBe(7)
    expect(result[0]).toEqual(result[result.length - 1])
  })
})

describe('smoothGeoJSON', () => {
  it('returns null/undefined unchanged', () => {
    expect(smoothGeoJSON(null)).toBeNull()
    expect(smoothGeoJSON(undefined)).toBeUndefined()
  })

  it('returns an unrecognised geometry type unchanged', () => {
    const pt = { type: 'Point', coordinates: [0, 0] }
    expect(smoothGeoJSON(pt)).toEqual(pt)
  })

  it('smooths a Polygon geometry', () => {
    const poly = {
      type: 'Polygon',
      coordinates: [SQUARE],
    }
    const result = smoothGeoJSON(poly)
    expect(result.type).toBe('Polygon')
    // Each ring should now have more points than the original.
    expect(result.coordinates[0].length).toBeGreaterThan(SQUARE.length)
  })

  it('does not mutate the input Polygon', () => {
    const poly = {
      type: 'Polygon',
      coordinates: [SQUARE.map((pt) => [...pt])],
    }
    const original = JSON.parse(JSON.stringify(poly))
    smoothGeoJSON(poly)
    expect(poly).toEqual(original)
  })

  it('smooths a MultiPolygon geometry', () => {
    const mpoly = {
      type: 'MultiPolygon',
      coordinates: [[SQUARE], [SQUARE]],
    }
    const result = smoothGeoJSON(mpoly)
    expect(result.type).toBe('MultiPolygon')
    result.coordinates.forEach((poly) => {
      poly.forEach((ring) => {
        expect(ring.length).toBeGreaterThan(SQUARE.length)
      })
    })
  })

  it('smooths a Polygon with a hole (multiple rings)', () => {
    const hole = [
      [0.2, 0.2],
      [0.8, 0.2],
      [0.8, 0.8],
      [0.2, 0.8],
      [0.2, 0.2],
    ]
    const poly = {
      type: 'Polygon',
      coordinates: [SQUARE, hole],
    }
    const result = smoothGeoJSON(poly)
    // Both the exterior ring and the hole should be smoothed.
    expect(result.coordinates[0].length).toBeGreaterThan(SQUARE.length)
    expect(result.coordinates[1].length).toBeGreaterThan(hole.length)
  })

  it('preserves extra geometry properties', () => {
    const poly = {
      type: 'Polygon',
      coordinates: [SQUARE],
      crs: { type: 'name', properties: { name: 'EPSG:4326' } },
    }
    const result = smoothGeoJSON(poly)
    expect(result.crs).toEqual(poly.crs)
  })
})

// Bug class: the Browse "coverage" hull must enclose every post it was built from.
describe('convexHull', () => {
  it('returns null for fewer than three points', () => {
    expect(convexHull([])).toBeNull()
    expect(convexHull([[0, 0]])).toBeNull()
    expect(
      convexHull([
        [0, 0],
        [1, 1],
      ])
    ).toBeNull()
  })

  it('returns null when the points are collinear (degenerate hull)', () => {
    expect(
      convexHull([
        [0, 0],
        [1, 1],
        [2, 2],
        [3, 3],
      ])
    ).toBeNull()
  })

  it('returns null when all points are duplicates of one location', () => {
    expect(
      convexHull([
        [1, 1],
        [1, 1],
        [1, 1],
      ])
    ).toBeNull()
  })

  it('builds a closed ring (first point repeated at the end)', () => {
    const hull = convexHull([
      [0, 0],
      [4, 0],
      [4, 4],
      [0, 4],
      [2, 2], // interior point, not a hull vertex
    ])
    expect(hull).not.toBeNull()
    expect(hull[0]).toEqual(hull[hull.length - 1])
  })

  it('encloses every input point, including interior (non-hull-vertex) points', () => {
    const points = [
      [0, 0],
      [4, 0],
      [4, 4],
      [0, 4],
      [2, 2],
      [1, 1],
      [3, 3.9],
    ]
    const hull = convexHull(points)
    points.forEach((p) => {
      expect(pointInPolygon(p, hull)).toBe(true)
    })
  })

  it('encloses every point for many random point sets', () => {
    const rand = mulberry32(42)
    for (let trial = 0; trial < 30; trial++) {
      const n = 5 + Math.floor(rand() * 30)
      const points = []
      for (let i = 0; i < n; i++) {
        points.push([rand() * 10 - 5, rand() * 10 - 5])
      }
      const hull = convexHull(points)
      if (!hull) continue // degenerate (all collinear) - vanishingly rare here
      points.forEach((p) => {
        expect(pointInPolygon(p, hull)).toBe(true)
      })
    }
  })

  it('produces a strictly convex hull (every vertex turns the same way)', () => {
    const rand = mulberry32(7)
    const points = []
    for (let i = 0; i < 25; i++) {
      points.push([rand() * 20, rand() * 20])
    }
    const hull = convexHull(points)
    const v = hull.slice(0, -1)
    const cross = (o, a, b) =>
      (a[0] - o[0]) * (b[1] - o[1]) - (a[1] - o[1]) * (b[0] - o[0])
    for (let i = 0; i < v.length; i++) {
      const o = v[i]
      const a = v[(i + 1) % v.length]
      const b = v[(i + 2) % v.length]
      // Hull is built counter-clockwise by the monotone-chain construction; every
      // turn should be a strict left turn (positive cross product).
      expect(cross(o, a, b)).toBeGreaterThan(0)
    }
  })

  it('ignores points with a null/undefined coordinate', () => {
    const hull = convexHull([
      [0, 0],
      [1, 0],
      [1, 1],
      [null, 5],
      [5, null],
      [0, 1],
    ])
    expect(hull).not.toBeNull()
    // Should just be the valid square.
    expect(pointInPolygon([0, 0], hull)).toBe(true)
    expect(pointInPolygon([1, 1], hull)).toBe(true)
  })

  it('dedupes identical points before building the hull', () => {
    const hull = convexHull([
      [0, 0],
      [0, 0],
      [4, 0],
      [4, 0],
      [4, 4],
      [0, 4],
    ])
    expect(hull).not.toBeNull()
    // A square hull is 4 distinct vertices + closing point.
    expect(hull.length).toBe(5)
  })
})

describe('roundedConvexHull', () => {
  it('returns the input ring unchanged when there are fewer than 3 vertices', () => {
    const degenerate = [
      [0, 0],
      [1, 1],
      [0, 0],
    ]
    expect(roundedConvexHull(degenerate, 0.1)).toBe(degenerate)
  })

  it('returns the input ring unchanged when r is zero or negative', () => {
    const hull = convexHull([
      [0, 0],
      [4, 0],
      [4, 4],
      [0, 4],
    ])
    expect(roundedConvexHull(hull, 0)).toBe(hull)
    expect(roundedConvexHull(hull, -1)).toBe(hull)
  })

  it('is smoother than the input (more vertices)', () => {
    const hull = convexHull([
      [0, 0],
      [4, 0],
      [4, 4],
      [0, 4],
    ])
    const rounded = roundedConvexHull(hull, 0.2)
    expect(rounded.length).toBeGreaterThan(hull.length)
  })

  it('is closed (first === last) and free of NaN', () => {
    const hull = convexHull([
      [0, 0],
      [4, 0],
      [4, 4],
      [0, 4],
    ])
    const rounded = roundedConvexHull(hull, 0.2)
    expect(rounded[0]).toEqual(rounded[rounded.length - 1])
    rounded.forEach(([x, y]) => {
      expect(Number.isFinite(x)).toBe(true)
      expect(Number.isFinite(y)).toBe(true)
    })
  })

  it('encloses every vertex of the input hull (the key coverage invariant)', () => {
    const hull = convexHull([
      [0, 0],
      [4, 0],
      [4, 4],
      [0, 4],
      [2, 5], // a bit of a peak
    ])
    const rounded = roundedConvexHull(hull, 0.3)
    hull.slice(0, -1).forEach((p) => {
      expect(pointInPolygon(p, rounded)).toBe(true)
    })
  })

  it('encloses a sharp "spike" outlier that previously escaped Chaikin smoothing', () => {
    // A tight cluster near the origin plus one far, narrow outlier - this creates a
    // hull vertex with a very acute interior angle (a "needle"). Naive Chaikin
    // corner-cutting removes every hull vertex from the smoothed boundary (it cuts
    // corners INWARD), so the spike tip - and any real post near it - would end up
    // outside. roundedConvexHull instead buffers OUTWARD, so it must not have this
    // problem.
    const spikePoints = [
      [-1, -1],
      [1, -1],
      [1, 1],
      [-1, 1],
      [50, 0], // the spike
    ]
    const hull = convexHull(spikePoints)
    expect(pointInPolygon([50, 0], hull)).toBe(true) // sanity: hull itself encloses it

    // Demonstrates the bug this function was written to avoid: plain Chaikin
    // smoothing of the raw hull cuts the spike vertex away.
    const chaikin = chaikinSmooth(hull, 3)
    expect(pointInPolygon([50, 0], chaikin)).toBe(false)

    // The fix: roundedConvexHull encloses the spike (and the rest of the cluster) at
    // a range of radii.
    ;[0.01, 0.1, 1, 5].forEach((r) => {
      const rounded = roundedConvexHull(hull, r)
      spikePoints.forEach((p) => {
        expect(pointInPolygon(p, rounded)).toBe(true)
      })
    })
  })

  it('encloses every point across many random point sets, radii and counts', () => {
    const rand = mulberry32(123)
    const radii = [0.001, 0.01, 0.1, 1]
    const counts = [3, 5, 10, 30]

    counts.forEach((n) => {
      const points = []
      for (let i = 0; i < n; i++) {
        points.push([rand() * 20 - 10, rand() * 20 - 10])
      }
      const hull = convexHull(points)
      if (!hull) return

      radii.forEach((r) => {
        const rounded = roundedConvexHull(hull, r)

        // No NaNs at this radius/count combination.
        rounded.forEach(([x, y]) => {
          expect(Number.isFinite(x)).toBe(true)
          expect(Number.isFinite(y)).toBe(true)
        })

        // Every original point (hull vertex or interior) stays enclosed.
        points.forEach((p) => {
          expect(pointInPolygon(p, rounded)).toBe(true)
        })
      })
    })
  })

  it('also encloses a clockwise-wound hull (orientation handling)', () => {
    // Build the hull then reverse it to simulate a clockwise ring, and confirm the
    // outward-offset logic (which branches on orientation) still encloses every point.
    const hull = convexHull([
      [0, 0],
      [4, 0],
      [4, 4],
      [0, 4],
    ])
    const cw = hull.slice().reverse()
    const rounded = roundedConvexHull(cw, 0.2)
    hull.slice(0, -1).forEach((p) => {
      expect(pointInPolygon(p, rounded)).toBe(true)
    })
  })
})

describe('buildCoverageGeoJSON', () => {
  it('returns null for fewer than three distinct points', () => {
    expect(buildCoverageGeoJSON([])).toBeNull()
    expect(
      buildCoverageGeoJSON([
        [0, 0],
        [1, 1],
      ])
    ).toBeNull()
  })

  it('returns a Polygon GeoJSON geometry enclosing every point', () => {
    const points = [
      [-0.1, 51.5],
      [-0.2, 51.6],
      [-0.05, 51.45],
      [-0.15, 51.55], // interior
    ]
    const geo = buildCoverageGeoJSON(points)
    expect(geo).not.toBeNull()
    expect(geo.type).toBe('Polygon')
    const ring = geo.coordinates[0]
    points.forEach((p) => {
      expect(pointInPolygon(p, ring)).toBe(true)
    })
  })

  it('produces a smoother ring than the plain convex hull', () => {
    const points = [
      [-0.1, 51.5],
      [-0.2, 51.6],
      [-0.05, 51.45],
    ]
    const hull = convexHull(points)
    const geo = buildCoverageGeoJSON(points)
    expect(geo.coordinates[0].length).toBeGreaterThan(hull.length)
  })

  it('encloses every point for many random small point clusters (integration invariant)', () => {
    const rand = mulberry32(99)
    for (let trial = 0; trial < 15; trial++) {
      const n = 3 + Math.floor(rand() * 15)
      const points = []
      for (let i = 0; i < n; i++) {
        points.push([rand() * 0.5 - 0.25, rand() * 0.5 + 51])
      }
      const geo = buildCoverageGeoJSON(points)
      if (!geo) continue
      points.forEach((p) => {
        expect(pointInPolygon(p, geo.coordinates[0])).toBe(true)
      })
    }
  })
})
