import { describe, it, expect } from 'vitest'
import {
  milesAway,
  isWithinDistance,
  filterMessagesByDistance,
} from '~/composables/useDistance'
import { BROWSE_DISTANCE_UNLIMITED } from '~/constants'

describe('milesAway', () => {
  it('returns null when both from coords are zero/null', () => {
    expect(milesAway(0, 0, 51.5, -0.1)).toBeNull()
    expect(milesAway(null, null, 51.5, -0.1)).toBeNull()
  })

  it('returns null when both to coords are zero/null', () => {
    expect(milesAway(51.5, -0.1, 0, 0)).toBeNull()
    expect(milesAway(51.5, -0.1, null, null)).toBeNull()
  })

  it('returns a positive distance for two distinct points', () => {
    // London ~ Brighton ~ 47 miles
    const d = milesAway(51.5074, -0.1278, 50.8225, -0.1372)
    expect(d).toBeGreaterThan(40)
    expect(d).toBeLessThan(60)
  })

  it('computes an east-west distance correctly', () => {
    // NR29 (Norfolk) -> CB1 (Cambridge), ~72 miles almost due west.
    const d = milesAway(52.679929, 1.688231, 52.196389, 0.182197)
    expect(d).toBeGreaterThan(65)
    expect(d).toBeLessThan(78)
  })

  it('returns 0 for the same point', () => {
    expect(milesAway(51.5, -0.1, 51.5, -0.1)).toBe(0)
  })

  it('rounds to integer miles for distances over 2 miles', () => {
    const d = milesAway(51.5074, -0.1278, 52.0, -0.1278)
    expect(Number.isInteger(d)).toBe(true)
  })

  it('rounds to one decimal for distances under 2 miles', () => {
    // Two very close points — a few hundred metres apart
    const d = milesAway(51.5074, -0.1278, 51.5124, -0.1278)
    expect(d).toBeGreaterThan(0)
    expect(d).toBeLessThan(2)
    // Rounded to 1dp: multiplying by 10 yields an integer
    expect(Number.isInteger(Math.round(d * 10))).toBe(true)
    expect(Math.abs(d * 10 - Math.round(d * 10))).toBeLessThan(1e-9)
  })

  it('works when one coord in a pair is non-zero even if the other is zero', () => {
    // Passes the (flat || flng) guard
    const d = milesAway(0, -0.1, 51.5, -0.1)
    expect(d).not.toBeNull()
    expect(typeof d).toBe('number')
  })

  it('is symmetric in its arguments', () => {
    const a = milesAway(51.5074, -0.1278, 50.8225, -0.1372)
    const b = milesAway(50.8225, -0.1372, 51.5074, -0.1278)
    expect(a).toBe(b)
  })
})

// Browse distance-slider predicate (bug class: distance filter semantics). Shared by
// PostMap (map markers + coverage hull) and PostMapAndList (the post list), so these
// tests pin down the exact semantics both views rely on.
describe('isWithinDistance', () => {
  it('passes everything when maxDistance is the unlimited sentinel, regardless of distance', () => {
    expect(isWithinDistance(1, BROWSE_DISTANCE_UNLIMITED)).toBe(true)
    expect(isWithinDistance(10000, BROWSE_DISTANCE_UNLIMITED)).toBe(true)
    expect(isWithinDistance(null, BROWSE_DISTANCE_UNLIMITED)).toBe(true)
  })

  it('passes when distance is null/undefined even with a real limit (defensive - never hide on missing data)', () => {
    expect(isWithinDistance(null, 5)).toBe(true)
    expect(isWithinDistance(undefined, 5)).toBe(true)
  })

  it('passes when distance is exactly at the limit (inclusive <=)', () => {
    expect(isWithinDistance(5, 5)).toBe(true)
  })

  it('passes when distance is below the limit', () => {
    expect(isWithinDistance(4.9, 5)).toBe(true)
  })

  it('fails when distance exceeds the limit', () => {
    expect(isWithinDistance(5.1, 5)).toBe(false)
  })

  it('treats distance 0 as within any real limit (not confused with null)', () => {
    expect(isWithinDistance(0, 5)).toBe(true)
    expect(isWithinDistance(0, 0)).toBe(true)
  })
})

describe('filterMessagesByDistance', () => {
  const posts = [
    { id: 1, distance: 1 },
    { id: 2, distance: 5 },
    { id: 3, distance: 10 },
    { id: 4, distance: null },
    { id: 5 }, // no distance field at all
  ]

  it('returns the SAME array reference (no-op) when maxDistance is unlimited', () => {
    const result = filterMessagesByDistance(posts, BROWSE_DISTANCE_UNLIMITED)
    expect(result).toBe(posts)
  })

  it('keeps only posts at or under a real limit, plus any with no distance', () => {
    const result = filterMessagesByDistance(posts, 5)
    expect(result.map((m) => m.id).sort()).toEqual([1, 2, 4, 5])
  })

  it('excludes posts strictly beyond the limit', () => {
    const result = filterMessagesByDistance(posts, 5)
    expect(result.find((m) => m.id === 3)).toBeUndefined()
  })

  it('a limit of 0 keeps only distance-0 or missing-distance posts', () => {
    const withZero = [
      { id: 1, distance: 0 },
      { id: 2, distance: 0.1 },
      { id: 3, distance: null },
    ]
    const result = filterMessagesByDistance(withZero, 0)
    expect(result.map((m) => m.id).sort()).toEqual([1, 3])
  })

  it('road minuteCheck wins over the crow radius in both directions', () => {
    // Post 2 is crow-inside (5<=5) but a long drive: dropped. Post 3 is
    // crow-outside (10>5) but a quick drive: kept. Posts the engine has not
    // answered for (null) keep the crow behaviour.
    const check = (m) => (m.id === 2 ? false : m.id === 3 ? true : null)
    const result = filterMessagesByDistance(posts, 5, check)
    expect(result.map((m) => m.id).sort()).toEqual([1, 3, 4, 5])
  })

  it('minuteCheck is not consulted when unlimited (referential no-op stays)', () => {
    const check = () => false
    const result = filterMessagesByDistance(
      posts,
      BROWSE_DISTANCE_UNLIMITED,
      check
    )
    expect(result).toBe(posts)
  })

  it('handles an empty list', () => {
    expect(filterMessagesByDistance([], 5)).toEqual([])
  })

  it('handles a null/undefined list defensively (treats as empty)', () => {
    expect(filterMessagesByDistance(null, 5)).toEqual([])
    expect(filterMessagesByDistance(undefined, 5)).toEqual([])
  })

  it('does not mutate the input array', () => {
    const original = [...posts]
    filterMessagesByDistance(posts, 5)
    expect(posts).toEqual(original)
  })
})
