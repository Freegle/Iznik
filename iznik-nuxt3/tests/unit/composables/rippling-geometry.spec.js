import { describe, it, expect } from 'vitest'
import {
  chaikinSmooth,
  geoToLeaflet,
  crowFliesKm,
  pointInRing,
  segmentsIntersect,
} from '~/modtools/composables/rippling/geometry.js'

// Closed [lng,lat] ring for a 2-unit square centred on origin.
const square = [
  [-1, -1],
  [1, -1],
  [1, 1],
  [-1, 1],
  [-1, -1],
]

describe('rippling/geometry', () => {
  describe('chaikinSmooth', () => {
    // Smoothing is deliberately a no-op: the reach is a grid-derived raster and must be
    // displayed exactly as computed. Corner-cutting rounded the boundary, and where it
    // hugged a river bank the curve bulged across the water - drawing the reach touching
    // a far bank it cannot actually reach.
    it('returns the ring unchanged (display must be exactly what was computed)', () => {
      const out = chaikinSmooth(square)
      expect(out).toEqual(square)
    })

    it('keeps the ring closed (first point equals last)', () => {
      const out = chaikinSmooth(square)
      expect(out[0]).toEqual(out[out.length - 1])
    })
  })

  describe('geoToLeaflet', () => {
    it('swaps [lng,lat] to [lat,lng] without altering the shape', () => {
      const out = geoToLeaflet(square)
      // No smoothing: exact vertex-for-vertex swap of the input ring.
      expect(out).toEqual(square.map(([lng, lat]) => [lat, lng]))
    })
  })

  describe('crowFliesKm', () => {
    it('returns 0 km for two identical points', () => {
      expect(crowFliesKm(51.5074, -0.1278, 51.5074, -0.1278)).toBeCloseTo(0, 6)
    })

    it('matches the known London→Paris great-circle distance (~344 km)', () => {
      // London (51.5074, -0.1278) → Paris (48.8566, 2.3522)
      const d = crowFliesKm(51.5074, -0.1278, 48.8566, 2.3522)
      expect(d).toBeGreaterThan(340)
      expect(d).toBeLessThan(350)
    })

    it('is symmetric (a→b == b→a)', () => {
      const a = crowFliesKm(51.5, -0.1, 52.5, 1.1)
      const b = crowFliesKm(52.5, 1.1, 51.5, -0.1)
      expect(a).toBeCloseTo(b, 6)
    })

    it('returns the equator quarter (one earth-quadrant) for antipodal-ish points', () => {
      // North pole to equator-on-prime-meridian = quarter of circumference
      // R=6371 → quarter is π R / 2 ≈ 10007 km
      const d = crowFliesKm(90, 0, 0, 0)
      expect(d).toBeGreaterThan(10000)
      expect(d).toBeLessThan(10015)
    })
  })

  describe('pointInRing', () => {
    it('returns true for a point clearly inside the square', () => {
      expect(pointInRing(0, 0, square)).toBe(true)
    })

    it('returns false for a point clearly outside the square', () => {
      expect(pointInRing(2, 2, square)).toBe(false)
      expect(pointInRing(-2, 0.5, square)).toBe(false)
    })

    it('handles non-convex rings via ray casting', () => {
      // C-shape: outer rect minus a notch on the right.
      const cShape = [
        [-2, -2],
        [2, -2],
        [2, -1],
        [0, -1],
        [0, 1],
        [2, 1],
        [2, 2],
        [-2, 2],
        [-2, -2],
      ]
      expect(pointInRing(-1, 0, cShape)).toBe(true) // inside the C
      expect(pointInRing(1, 0, cShape)).toBe(false) // inside the notch
    })
  })

  describe('segmentsIntersect', () => {
    it('detects crossing segments', () => {
      // (0,0)→(2,2) and (0,2)→(2,0) cross at (1,1)
      expect(segmentsIntersect(0, 0, 2, 2, 0, 2, 2, 0)).toBe(true)
    })

    it('returns false for parallel non-overlapping segments', () => {
      // Two horizontal segments at y=0 and y=1
      expect(segmentsIntersect(0, 0, 2, 0, 0, 1, 2, 1)).toBe(false)
    })

    it('returns false when segments share only an endpoint (open interval)', () => {
      // (0,0)→(1,0) and (1,0)→(1,1) meet at (1,0) — the function uses
      // strict 0<t<1, so endpoint-touching counts as no intersection.
      expect(segmentsIntersect(0, 0, 1, 0, 1, 0, 1, 1)).toBe(false)
    })

    it('returns false for collinear segments', () => {
      // (0,0)→(2,0) and (1,0)→(3,0) are collinear — cross product is 0.
      expect(segmentsIntersect(0, 0, 2, 0, 1, 0, 3, 0)).toBe(false)
    })
  })
})
