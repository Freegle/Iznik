import { describe, it, expect } from 'vitest'
import { badgeTitle, applyBadgeToTitle } from '~/composables/useTitleBadge'

describe('badgeTitle', () => {
  it('prefixes the count when there are unread items', () => {
    expect(badgeTitle('Freegle - Home', 3)).toBe('(3) Freegle - Home')
  })

  it('does not prefix when the count is zero', () => {
    expect(badgeTitle('Freegle - Home', 0)).toBe('Freegle - Home')
  })

  it('does not prefix for a negative/falsey count', () => {
    expect(badgeTitle('Freegle - Home', -1)).toBe('Freegle - Home')
  })

  it('does not double-prefix when a count is already present', () => {
    expect(badgeTitle('(2) Freegle - Home', 5)).toBe('(2) Freegle - Home')
  })

  it('returns null when there is no title', () => {
    expect(badgeTitle('', 3)).toBe(null)
    expect(badgeTitle(null, 3)).toBe(null)
    expect(badgeTitle(undefined, 3)).toBe(null)
  })

  it('combines chats + notifications (caller sums them)', () => {
    // The app passes notificationCount + chatCount; verify a typical total.
    expect(badgeTitle('Freegle', 1 + 99)).toBe('(100) Freegle')
  })
})

describe('applyBadgeToTitle (live re-apply on document.title)', () => {
  it('adds the badge to an unbadged title', () => {
    expect(applyBadgeToTitle('Freegle - Home', 3)).toBe('(3) Freegle - Home')
  })

  it('replaces an existing badge with the new count (the live-update case)', () => {
    expect(applyBadgeToTitle('(2) Freegle - Home', 5)).toBe(
      '(5) Freegle - Home'
    )
  })

  it('removes the badge when the count drops to zero', () => {
    expect(applyBadgeToTitle('(2) Freegle - Home', 0)).toBe('Freegle - Home')
  })

  it('leaves an unbadged title unchanged at zero', () => {
    expect(applyBadgeToTitle('Freegle - Home', 0)).toBe('Freegle - Home')
  })

  it('is idempotent — re-applying the same count does not stack prefixes', () => {
    const once = applyBadgeToTitle('Freegle', 4)
    expect(applyBadgeToTitle(once, 4)).toBe('(4) Freegle')
  })
})
