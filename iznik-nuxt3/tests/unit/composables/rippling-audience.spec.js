import { describe, it, expect } from 'vitest'
import {
  driveMinForAudience,
  clampAudienceMinutes,
} from '~/modtools/composables/rippling/audience.js'

describe('rippling/audience', () => {
  describe('driveMinForAudience', () => {
    it('returns the tick drive_min exactly on an exact boundary crossing', () => {
      const ticks = [
        { drive_min: 3, cumulative_users: 50 },
        { drive_min: 5, cumulative_users: 200 },
        { drive_min: 8, cumulative_users: 500 },
      ]
      expect(driveMinForAudience(ticks, 200)).toBe(5)
    })

    it('interpolates linearly between two bracketing ticks', () => {
      const ticks = [
        { drive_min: 5, cumulative_users: 100 },
        { drive_min: 6, cumulative_users: 300 },
      ]
      expect(driveMinForAudience(ticks, 200)).toBeCloseTo(5.5)
    })

    it('interpolates from the implicit (0,0) origin inside the first tick', () => {
      const ticks = [
        { drive_min: 10, cumulative_users: 100 },
        { drive_min: 20, cumulative_users: 300 },
      ]
      expect(driveMinForAudience(ticks, 50)).toBeCloseTo(5)
    })

    it('returns the last tick drive_min when nstar is never reached', () => {
      const ticks = [
        { drive_min: 5, cumulative_users: 100 },
        { drive_min: 10, cumulative_users: 150 },
      ]
      expect(driveMinForAudience(ticks, 1000)).toBe(10)
    })

    it('returns null for an empty or missing ticks array', () => {
      expect(driveMinForAudience([], 200)).toBe(null)
      expect(driveMinForAudience(null, 200)).toBe(null)
      expect(driveMinForAudience(undefined, 200)).toBe(null)
    })

    it('returns 0 when nstar is zero or negative', () => {
      const ticks = [{ drive_min: 5, cumulative_users: 100 }]
      expect(driveMinForAudience(ticks, 0)).toBe(0)
      expect(driveMinForAudience(ticks, -10)).toBe(0)
    })
  })

  describe('clampAudienceMinutes', () => {
    it('clamps values below the floor up to the floor', () => {
      expect(clampAudienceMinutes(3)).toBe(10)
    })

    it('clamps values above the ceiling down to the ceiling', () => {
      expect(clampAudienceMinutes(45)).toBe(30)
    })

    it('passes through values already inside the range unchanged', () => {
      expect(clampAudienceMinutes(18)).toBe(18)
    })

    it('respects custom min/max bounds', () => {
      expect(clampAudienceMinutes(2, 5, 25)).toBe(5)
      expect(clampAudienceMinutes(40, 5, 25)).toBe(25)
    })

    it('passes null/NaN through unchanged as null', () => {
      expect(clampAudienceMinutes(null)).toBe(null)
      expect(clampAudienceMinutes(undefined)).toBe(null)
      expect(clampAudienceMinutes(NaN)).toBe(null)
    })
  })
})
