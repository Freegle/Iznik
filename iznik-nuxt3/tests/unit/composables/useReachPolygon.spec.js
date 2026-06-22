import { describe, it, expect } from 'vitest'
import { chaikinSmooth, smoothGeoJSON } from '~/composables/useReachPolygon'

// A simple square ring in [lng, lat] order (GeoJSON convention), closed.
const SQUARE = [
  [0, 0],
  [1, 0],
  [1, 1],
  [0, 1],
  [0, 0],
]

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
