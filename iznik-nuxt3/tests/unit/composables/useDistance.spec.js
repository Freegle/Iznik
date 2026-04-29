import { describe, it, expect } from 'vitest'
import { milesAway } from '~/composables/useDistance'

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
