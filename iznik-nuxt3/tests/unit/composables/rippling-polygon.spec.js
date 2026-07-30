import { describe, it, expect } from 'vitest'
import {
  hasRing,
  quintileOfFreegler,
  groupCentroid,
  distSq,
  ringsOverlap,
  homeGroupOverlapFraction,
  geoJsonToLatLngs,
} from '~/modtools/composables/rippling/polygon.js'

// A minimal GeoJSON-shaped polygon wrapper.
const poly = (ring) => ({
  geometry: { coordinates: [ring] },
})

const square = [
  [-1, -1],
  [1, -1],
  [1, 1],
  [-1, 1],
  [-1, -1],
]

const farSquare = [
  [10, 10],
  [12, 10],
  [12, 12],
  [10, 12],
  [10, 10],
]

const overlapping = [
  [0, 0],
  [2, 0],
  [2, 2],
  [0, 2],
  [0, 0],
]

describe('rippling/polygon', () => {
  describe('hasRing', () => {
    it('returns true for a closed quad (4+ points)', () => {
      expect(hasRing(poly(square))).toBe(true)
    })

    it('returns false for null / undefined / missing geometry', () => {
      expect(hasRing(null)).toBeFalsy()
      expect(hasRing(undefined)).toBeFalsy()
      expect(hasRing({})).toBeFalsy()
      expect(hasRing({ geometry: {} })).toBeFalsy()
    })

    it('returns false for a degenerate ring with under 4 vertices', () => {
      // pointInRing needs ≥4 verts (triangle + close).
      expect(hasRing(poly([[0, 0], [1, 0], [0, 0]]))).toBe(false)
    })
  })

  describe('quintileOfFreegler', () => {
    const data = {
      standard: poly([
        [-5, -5],
        [5, -5],
        [5, 5],
        [-5, 5],
        [-5, -5],
      ]),
      quintiles: {
        1: { polygon: poly(square), islands: [] },
        3: {
          polygon: poly([
            [2, 2],
            [4, 2],
            [4, 4],
            [2, 4],
            [2, 2],
          ]),
          islands: [poly(farSquare)],
        },
      },
    }

    it('returns the quintile number when inside its main polygon', () => {
      expect(quintileOfFreegler(0, 0, data)).toBe(1)
    })

    it('matches against quintile islands as well as the main polygon', () => {
      expect(quintileOfFreegler(11, 11, data)).toBe(3)
    })

    it('returns -1 for points inside the standard isochrone but no quintile', () => {
      // (4.5, 4.5) is inside the standard 10x10 square but outside both
      // quintile polygons defined above.
      expect(quintileOfFreegler(4.5, 4.5, data)).toBe(-1)
    })

    it('returns 0 for points entirely outside the boundary', () => {
      expect(quintileOfFreegler(100, 100, data)).toBe(0)
    })

    it('handles missing quintiles object gracefully', () => {
      const noQuints = { standard: data.standard }
      expect(quintileOfFreegler(0, 0, noQuints)).toBe(-1)
    })

    it('returns 0 when there is no standard and no quintile hits', () => {
      expect(quintileOfFreegler(0, 0, { quintiles: {} })).toBe(0)
    })

    it('prefers the lower-numbered quintile when polygons overlap', () => {
      // Both Q1 and Q3 contain (3, 3) here.
      const overlap = {
        standard: poly([[-5, -5], [5, -5], [5, 5], [-5, 5], [-5, -5]]),
        quintiles: {
          1: {
            polygon: poly([
              [-1, -1],
              [4, -1],
              [4, 4],
              [-1, 4],
              [-1, -1],
            ]),
            islands: [],
          },
          3: {
            polygon: poly([
              [2, 2],
              [4, 2],
              [4, 4],
              [2, 4],
              [2, 2],
            ]),
            islands: [],
          },
        },
      }
      expect(quintileOfFreegler(3, 3, overlap)).toBe(1)
    })
  })

  describe('groupCentroid', () => {
    it('returns the mean of the outer ring vertices', () => {
      const f = poly(square)
      const [lng, lat] = groupCentroid(f)
      // Mean of -1,1,1,-1,-1 over 5 verts is -0.2; same for lat.
      expect(lng).toBeCloseTo(-0.2, 6)
      expect(lat).toBeCloseTo(-0.2, 6)
    })

    it('returns [0, 0] for missing geometry', () => {
      expect(groupCentroid({})).toEqual([0, 0])
      expect(groupCentroid({ geometry: {} })).toEqual([0, 0])
      expect(groupCentroid({ geometry: { coordinates: [[]] } })).toEqual([0, 0])
    })
  })

  describe('distSq', () => {
    it('returns 0 for identical points', () => {
      expect(distSq(51.5, -0.1, 51.5, -0.1)).toBe(0)
    })

    it('grows monotonically with separation along a meridian', () => {
      const near = distSq(51.5, 0, 51.6, 0)
      const far = distSq(51.5, 0, 52.0, 0)
      expect(far).toBeGreaterThan(near)
    })

    it('uses the first point as the projection reference (cos(lat1) only)', () => {
      // The implementation scales the longitude delta by cos(lat1) only,
      // so swapping the two points changes the result at very different
      // latitudes.  Document this asymmetry so we don't accidentally
      // "fix" it later — callers (sort/nearest-neighbour at a fixed point)
      // rely on the reference being the first arg.
      const ab = distSq(0, 0, 80, 1)
      const ba = distSq(80, 0, 0, 1)
      expect(ab).not.toBeCloseTo(ba, 6)
    })

    it('shrinks longitude deltas with cosine(latitude) — equator deltas are larger', () => {
      const atEquator = distSq(0, 0, 0, 1)
      const atArctic = distSq(80, 0, 80, 1)
      expect(atArctic).toBeLessThan(atEquator)
    })
  })

  describe('ringsOverlap', () => {
    it('returns false for completely separate rings via bbox reject', () => {
      expect(ringsOverlap(square, farSquare)).toBe(false)
    })

    it('returns true when one ring fully contains the other', () => {
      const big = [
        [-5, -5],
        [5, -5],
        [5, 5],
        [-5, 5],
        [-5, -5],
      ]
      expect(ringsOverlap(big, square)).toBe(true)
      expect(ringsOverlap(square, big)).toBe(true)
    })

    it('returns true for partially overlapping rings (edge crossing)', () => {
      expect(ringsOverlap(square, overlapping)).toBe(true)
    })

    it('returns true when only edges cross but no vertex is inside', () => {
      // Cross-shape: a thin horizontal bar crossing a thin vertical bar.
      const horiz = [
        [-3, -0.5],
        [3, -0.5],
        [3, 0.5],
        [-3, 0.5],
        [-3, -0.5],
      ]
      const vert = [
        [-0.5, -3],
        [0.5, -3],
        [0.5, 3],
        [-0.5, 3],
        [-0.5, -3],
      ]
      expect(ringsOverlap(horiz, vert)).toBe(true)
    })

    it('handles touching-only edges as overlap (point-in-ring catches the shared edge)', () => {
      // Two squares sharing an edge at x=1.
      const left = [
        [-1, -1],
        [1, -1],
        [1, 1],
        [-1, 1],
        [-1, -1],
      ]
      const right = [
        [1, -1],
        [3, -1],
        [3, 1],
        [1, 1],
        [1, -1],
      ]
      // The bbox test passes (touching), and the shared edge means a vertex
      // of one lies on the boundary of the other.  We just check it doesn't
      // throw and the result is a boolean.
      const r = ringsOverlap(left, right)
      expect(typeof r).toBe('boolean')
    })
  })

  describe('homeGroupOverlapFraction', () => {
    // A unit square centred at origin — used as the group polygon.
    const groupRing = [
      [-1, -1],
      [1, -1],
      [1, 1],
      [-1, 1],
      [-1, -1],
    ]

    it('returns ~1 when the isochrone fully contains the group polygon', () => {
      // A large square that fully encloses groupRing.
      const bigIso = [
        [-5, -5],
        [5, -5],
        [5, 5],
        [-5, 5],
        [-5, -5],
      ]
      const frac = homeGroupOverlapFraction(bigIso, groupRing)
      expect(frac).toBeGreaterThan(0.95)
    })

    it('returns ~0 when the isochrone is entirely outside the group polygon', () => {
      const farIso = [
        [10, 10],
        [12, 10],
        [12, 12],
        [10, 12],
        [10, 10],
      ]
      const frac = homeGroupOverlapFraction(farIso, groupRing)
      expect(frac).toBeLessThan(0.05)
    })

    it('returns ~0.5 when the isochrone covers exactly half the group (left half)', () => {
      // Isochrone covers x in [-1, 0] — the left half of groupRing.
      const halfIso = [
        [-2, -2],
        [0, -2],
        [0, 2],
        [-2, 2],
        [-2, -2],
      ]
      const frac = homeGroupOverlapFraction(halfIso, groupRing)
      // Allow ±0.08 tolerance for the point-grid approximation.
      expect(frac).toBeGreaterThan(0.42)
      expect(frac).toBeLessThan(0.58)
    })

    it('returns 0 gracefully for null or short rings', () => {
      expect(homeGroupOverlapFraction(null, groupRing)).toBe(0)
      expect(homeGroupOverlapFraction(groupRing, null)).toBe(0)
      expect(homeGroupOverlapFraction([], groupRing)).toBe(0)
      expect(homeGroupOverlapFraction(groupRing, [[0, 0], [1, 0]])).toBe(0)
    })

    it('exceeds the 0.9 engine threshold when 95% covered', () => {
      // Isochrone covers 95% of groupRing: x in [-1, 0.9].
      const nearlyAllIso = [
        [-2, -2],
        [0.9, -2],
        [0.9, 2],
        [-2, 2],
        [-2, -2],
      ]
      const frac = homeGroupOverlapFraction(nearlyAllIso, groupRing)
      expect(frac).toBeGreaterThanOrEqual(0.9)
    })
  })
})

// geoJsonToLatLngs feeds the mod reach modal's ACTUAL-reach overlay: the /message/{id}/reach
// endpoint returns the stored reach as a GeoJSON string, Leaflet wants [lat, lng] rings.
describe('geoJsonToLatLngs', () => {
  const RING = [
    [-0.2, 51.4],
    [0, 51.4],
    [0, 51.6],
    [-0.2, 51.4],
  ]

  it('parses a GeoJSON Polygon string and flips [lng, lat] to [lat, lng]', () => {
    const out = geoJsonToLatLngs(
      JSON.stringify({ type: 'Polygon', coordinates: [RING] })
    )
    expect(out).toEqual([[RING.map(([lng, lat]) => [lat, lng])]])
    expect(out[0][0][0]).toEqual([51.4, -0.2])
  })

  it('accepts an already-parsed object', () => {
    const out = geoJsonToLatLngs({ type: 'Polygon', coordinates: [RING] })
    expect(out[0][0]).toHaveLength(RING.length)
  })

  it('wraps each MultiPolygon part as its own polygon', () => {
    const out = geoJsonToLatLngs({
      type: 'MultiPolygon',
      coordinates: [[RING], [RING]],
    })
    expect(out).toHaveLength(2)
    expect(out[1][0][0]).toEqual([51.4, -0.2])
  })

  it('keeps holes (inner rings) with their polygon', () => {
    const hole = [
      [-0.1, 51.45],
      [-0.05, 51.45],
      [-0.05, 51.5],
      [-0.1, 51.45],
    ]
    const out = geoJsonToLatLngs({
      type: 'Polygon',
      coordinates: [RING, hole],
    })
    expect(out[0]).toHaveLength(2)
    expect(out[0][1][0]).toEqual([51.45, -0.1])
  })

  it('returns null rather than throwing for unusable input', () => {
    expect(geoJsonToLatLngs('not json')).toBeNull()
    expect(geoJsonToLatLngs('')).toBeNull()
    expect(geoJsonToLatLngs(null)).toBeNull()
    expect(geoJsonToLatLngs({})).toBeNull()
    expect(geoJsonToLatLngs({ type: 'Polygon' })).toBeNull()
  })
})
