import { describe, it, expect } from 'vitest'
import { badgeTitle } from '~/composables/useTitleBadge'

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
