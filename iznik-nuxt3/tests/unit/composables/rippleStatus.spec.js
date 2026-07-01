import { describe, it, expect } from 'vitest'
import {
  isRippledInToContextGroup,
  earliestArrivalGroupId,
  homeGroupFirst,
  RIPPLE_ORIGIN_WINDOW_MS,
} from '~/composables/rippleStatus'

// Helper: an ISO arrival N minutes after a fixed base.
const base = new Date('2026-06-16T09:00:00Z').getTime()
const at = (mins) => new Date(base + mins * 60 * 1000).toISOString()

describe('isRippledInToContextGroup', () => {
  it('returns false for a single-group post', () => {
    const groups = [{ groupid: 1, arrival: at(0) }]
    expect(isRippledInToContextGroup(groups, 1)).toBe(false)
  })

  it('returns false for empty/missing groups', () => {
    expect(isRippledInToContextGroup([], 1)).toBe(false)
    expect(isRippledInToContextGroup(null, 1)).toBe(false)
    expect(isRippledInToContextGroup(undefined, 1)).toBe(false)
  })

  it('returns true when the context group is a later-arriving secondary group', () => {
    const groups = [
      { groupid: 1, arrival: at(0) }, // origin
      { groupid: 2, arrival: at(120) }, // rippled in 2h later
    ]
    expect(isRippledInToContextGroup(groups, 2)).toBe(true)
  })

  it('returns false when viewed under the origin (earliest) group', () => {
    const groups = [
      { groupid: 1, arrival: at(0) },
      { groupid: 2, arrival: at(120) },
    ]
    expect(isRippledInToContextGroup(groups, 1)).toBe(false)
  })

  it('treats groups added within the origin window as the same origin (no ripple)', () => {
    const groups = [
      { groupid: 1, arrival: at(0) },
      { groupid: 2, arrival: at(5) }, // 5 min — inside the 10-min window
    ]
    expect(isRippledInToContextGroup(groups, 2)).toBe(false)
  })

  it('treats groups added just past the origin window as rippled in', () => {
    const groups = [
      { groupid: 1, arrival: at(0) },
      { groupid: 2, arrival: at(11) }, // 11 min — past the 10-min window
    ]
    expect(isRippledInToContextGroup(groups, 2)).toBe(true)
  })

  it('handles string groupids and string arrivals', () => {
    const groups = [
      { groupid: '1', arrival: at(0) },
      { groupid: '2', arrival: at(120) },
    ]
    expect(isRippledInToContextGroup(groups, '2')).toBe(true)
  })

  it('returns false when contextGroupid does not match any group (no groups[0] fallback)', () => {
    const groups = [
      { groupid: 1, arrival: at(0) },
      { groupid: 2, arrival: at(120) },
    ]
    // No matching context group → no banner (we must not guess via array position,
    // since message.groups has no guaranteed order).
    expect(isRippledInToContextGroup(groups, 999)).toBe(false)
  })

  it('returns false when contextGroupid is null/undefined (all-groups view)', () => {
    const groups = [
      { groupid: 1, arrival: at(0) },
      { groupid: 2, arrival: at(120) },
    ]
    expect(isRippledInToContextGroup(groups, null)).toBe(false)
    expect(isRippledInToContextGroup(groups, undefined)).toBe(false)
  })

  it('returns false when arrivals are unparseable', () => {
    const groups = [
      { groupid: 1, arrival: 'not-a-date' },
      { groupid: 2, arrival: 'also-bad' },
    ]
    expect(isRippledInToContextGroup(groups, 2)).toBe(false)
  })

  it('respects a custom threshold', () => {
    const groups = [
      { groupid: 1, arrival: at(0) },
      { groupid: 2, arrival: at(30) },
    ]
    // 30 min ripple, threshold 60 min → not yet "rippled in".
    expect(isRippledInToContextGroup(groups, 2, 60 * 60 * 1000)).toBe(false)
    // Same gap, default 10-min threshold → rippled in.
    expect(isRippledInToContextGroup(groups, 2)).toBe(true)
  })

  it('exports the origin window as 10 minutes', () => {
    expect(RIPPLE_ORIGIN_WINDOW_MS).toBe(10 * 60 * 1000)
  })

  describe('authoritative rippled_in field', () => {
    it('returns true when the context group row has rippled_in=1', () => {
      const groups = [
        { groupid: 1, arrival: at(0), rippled_in: 0 },
        { groupid: 2, arrival: at(120), rippled_in: 1 },
      ]
      expect(isRippledInToContextGroup(groups, 2)).toBe(true)
    })

    it('returns false when the context group row has rippled_in=0', () => {
      const groups = [
        { groupid: 1, arrival: at(0), rippled_in: 0 },
        { groupid: 2, arrival: at(120), rippled_in: 0 },
      ]
      // Even though arrival ordering would say "rippled in", the authoritative
      // column says it wasn't, so the field wins.
      expect(isRippledInToContextGroup(groups, 2)).toBe(false)
    })

    it('uses rippled_in even when the approve path scrambled arrivals', () => {
      // Origin row re-stamped with arrival=NOW() at approval, so it looks NEWER than the
      // rippled-in copy - the arrival heuristic would wrongly hide the banner, but
      // rippled_in=1 on the context row is unambiguous.
      const groups = [
        { groupid: 1, arrival: at(200), rippled_in: 0 }, // origin, re-stamped late
        { groupid: 2, arrival: at(60), rippled_in: 1 }, // rippled in earlier
      ]
      expect(isRippledInToContextGroup(groups, 2)).toBe(true)
    })

    it('honours rippled_in=1 even for a single returned group row', () => {
      const groups = [{ groupid: 2, arrival: at(0), rippled_in: 1 }]
      expect(isRippledInToContextGroup(groups, 2)).toBe(true)
    })

    it('accepts a boolean rippled_in', () => {
      expect(
        isRippledInToContextGroup([{ groupid: 2, rippled_in: true }], 2)
      ).toBe(true)
      expect(
        isRippledInToContextGroup([{ groupid: 2, rippled_in: false }], 2)
      ).toBe(false)
    })

    it('still requires a matching context group when rippled_in is present', () => {
      const groups = [
        { groupid: 1, arrival: at(0), rippled_in: 0 },
        { groupid: 2, arrival: at(120), rippled_in: 1 },
      ]
      expect(isRippledInToContextGroup(groups, 999)).toBe(false)
    })

    it('falls back to the arrival heuristic when rippled_in is absent', () => {
      const groups = [
        { groupid: 1, arrival: at(0) },
        { groupid: 2, arrival: at(120) },
      ]
      expect(isRippledInToContextGroup(groups, 2)).toBe(true)
    })
  })
})

describe('earliestArrivalGroupId', () => {
  it('returns the earliest-arriving group (the origin), not a later rippled-in copy', () => {
    // Derek's case: post originates on Oxford and ripples into Vale/Didcot later.
    // An edit belongs to the origin, so we must anchor to Oxford (the earliest),
    // not the most-recent rippled-in copy.
    const groups = [
      { groupid: 21671, arrival: at(120) }, // Vale (rippled in)
      { groupid: 21555, arrival: at(0) }, // Oxford (origin)
      { groupid: 522858, arrival: at(120) }, // Didcot (rippled in)
    ]
    expect(earliestArrivalGroupId(groups)).toBe(21555)
  })

  it('returns null for empty/missing input', () => {
    expect(earliestArrivalGroupId([])).toBeNull()
    expect(earliestArrivalGroupId(null)).toBeNull()
    expect(earliestArrivalGroupId(undefined)).toBeNull()
  })

  it('returns the single group id for a one-group post', () => {
    expect(earliestArrivalGroupId([{ groupid: 42, arrival: at(0) }])).toBe(42)
  })

  it('returns a number even for string groupids', () => {
    const groups = [
      { groupid: '2', arrival: at(90) },
      { groupid: '1', arrival: at(0) },
    ]
    expect(earliestArrivalGroupId(groups)).toBe(1)
  })

  it('falls back to the first group id when no arrival is parseable', () => {
    const groups = [
      { groupid: 7, arrival: 'not-a-date' },
      { groupid: 8, arrival: null },
    ]
    expect(earliestArrivalGroupId(groups)).toBe(7)
  })

  it('ignores entries with missing arrivals when others are valid', () => {
    const groups = [
      { groupid: 5, arrival: null },
      { groupid: 6, arrival: at(30) },
      { groupid: 7, arrival: at(10) },
    ]
    expect(earliestArrivalGroupId(groups)).toBe(7)
  })
})

// The group list shown for a post is truncated (ShowMore limit), so the home/origin
// group must come first or it can be hidden behind "more". homeGroupFirst returns the
// groups with the home group moved to the front, preserving the order of the rest.
describe('homeGroupFirst', () => {
  const ids = (groups) => groups.map((g) => parseInt(g.groupid))

  it('moves the home group (earliest arrival) to the front', () => {
    const groups = [
      { groupid: 2, arrival: at(120) }, // rippled in
      { groupid: 3, arrival: at(120) }, // rippled in
      { groupid: 1, arrival: at(0) }, // origin/home, buried last
    ]
    expect(ids(homeGroupFirst(groups))).toEqual([1, 2, 3])
  })

  it('preserves the order of the non-home groups', () => {
    const groups = [
      { groupid: 5, arrival: at(60) },
      { groupid: 9, arrival: at(0) }, // home
      { groupid: 7, arrival: at(120) },
      { groupid: 4, arrival: at(90) },
    ]
    expect(ids(homeGroupFirst(groups))).toEqual([9, 5, 7, 4])
  })

  it('uses rippled_in to pick the home group even when its arrival is later', () => {
    // The approve path stamps arrival=NOW() on the origin row, so the origin can look
    // NEWER than the rippled-in copies. The authoritative rippled_in flag must win.
    const groups = [
      { groupid: 2, arrival: at(30), rippled_in: 1 }, // rippled in earlier
      { groupid: 1, arrival: at(200), rippled_in: 0 }, // home, re-stamped late
    ]
    expect(ids(homeGroupFirst(groups))).toEqual([1, 2])
  })

  it('leaves a single-group list unchanged', () => {
    const groups = [{ groupid: 42, arrival: at(0) }]
    expect(ids(homeGroupFirst(groups))).toEqual([42])
  })

  it('returns a new array and does not mutate the input', () => {
    const groups = [
      { groupid: 2, arrival: at(120) },
      { groupid: 1, arrival: at(0) },
    ]
    const out = homeGroupFirst(groups)
    expect(out).not.toBe(groups)
    expect(ids(groups)).toEqual([2, 1]) // original untouched
  })

  it('returns [] for empty/missing input', () => {
    expect(homeGroupFirst([])).toEqual([])
    expect(homeGroupFirst(null)).toEqual([])
    expect(homeGroupFirst(undefined)).toEqual([])
  })

  it('handles string groupids', () => {
    const groups = [
      { groupid: '2', arrival: at(90) },
      { groupid: '1', arrival: at(0) },
    ]
    expect(ids(homeGroupFirst(groups))).toEqual([1, 2])
  })
})
