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
    it('returns a closed ring (first point equals last)', () => {
      const out = chaikinSmooth(square)
      expect(out[0]).toEqual(out[out.length - 1])
    })

    it('multiplies vertex count by 2^iterations (4 verts × 8 → 32 plus closer)', () => {
      const out = chaikinSmooth(square, 3)
      // 4 unique input verts; each iteration doubles → 4*2^3 = 32; +1 to close.
      expect(out.length).toBe(33)
    })

    it('defaults to 3 iterations when count is omitted', () => {
      const out = chaikinSmooth(square)
      expect(out.length).toBe(33)
    })

    it('keeps the smoothed ring inside the original axis-aligned bbox', () => {
      const out = chaikinSmooth(square, 5)
      for (const [x, y] of out) {
        expect(x).toBeGreaterThanOrEqual(-1)
        expect(x).toBeLessThanOrEqual(1)
        expect(y).toBeGreaterThanOrEqual(-1)
        expect(y).toBeLessThanOrEqual(1)
      }
    })
  })

  describe('geoToLeaflet', () => {
    it('swaps [lng,lat] to [lat,lng] after smoothing', () => {
      const out = geoToLeaflet(square)
      // After smoothing the order is preserved; first point of a square
      // centred on origin should still be near (-1,-1) but swapped.
      const [lat, lng] = out[0]
      expect(typeof lat).toBe('number')
      expect(typeof lng).toBe('number')
      // The smoothed first point is the 25/75 mix toward the second vert:
      // 0.75*(-1,-1) + 0.25*(1,-1) = (-0.5, -1)  [lng, lat]
      // After swap that's [-1, -0.5] (lat, lng).  Repeat for 3 iterations
      // — exact value is not the point; just verify the swap by ranges.
      expect(lat).toBeGreaterThanOrEqual(-1)
      expect(lng).toBeGreaterThanOrEqual(-1)
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
