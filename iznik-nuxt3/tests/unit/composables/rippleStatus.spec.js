import { describe, it, expect } from 'vitest'
import {
  isRippledInToContextGroup,
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
})
