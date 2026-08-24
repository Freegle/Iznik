import { describe, it, expect } from 'vitest'
import { combinedBadgeCount } from '~/composables/useBadgeCount'

describe('combinedBadgeCount', () => {
  it('sums unread chats and notifications', () => {
    expect(combinedBadgeCount(2, 1)).toBe(3)
  })

  it('clamps the combined total to 99', () => {
    expect(combinedBadgeCount(99, 5)).toBe(99)
  })

  // Discourse 9953/6 review: useNavbar()'s previous formula clamped
  // chatStore.unreadCount to 99 BEFORE adding notifications
  // (Math.min(99, Math.min(99, chats) + notifications)), while
  // mobileStore's startBadgeSync() clamped the raw sum
  // (Math.min(99, chats + notifications)). Both always agree because
  // clamping is idempotent once either input already exceeds the ceiling,
  // but this pins that equivalence for the case where it actually matters:
  // an unread count that's already over 99 on its own.
  it('agrees with a pre-clamped-chats calculation when chats alone exceed 99', () => {
    const chats = 150
    const notifications = 10
    const preClamped = Math.min(99, Math.min(99, chats) + notifications)
    expect(combinedBadgeCount(chats, notifications)).toBe(preClamped)
  })

  it('treats a missing/undefined chat count as zero', () => {
    expect(combinedBadgeCount(undefined, 4)).toBe(4)
  })

  it('treats a missing/undefined notification count as zero', () => {
    expect(combinedBadgeCount(4, undefined)).toBe(4)
  })

  it('returns 0 when both counts are zero', () => {
    expect(combinedBadgeCount(0, 0)).toBe(0)
  })
})
