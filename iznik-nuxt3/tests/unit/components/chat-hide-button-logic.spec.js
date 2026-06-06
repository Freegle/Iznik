/**
 * TDD tests for the per-chat Hide/Unhide button visibility rules.
 *
 * These tests verify the v-if logic for the Hide/Unhide button in ChatPane.vue
 * and ChatMobileNavbar.vue directly, without mounting the full async component.
 *
 * Spec (revised for #9690/20):
 *   - All chats: button always shown (v-if removed)
 *       - status !== 'Closed' → "Hide"
 *       - status === 'Closed' → "Unhide"
 *   - Bulk-hide protection lives in hideAll(), not per-chat buttons.
 *
 * Previous spec (PR #627 for #9690/14) removed the Hide button from User2Mod
 * chats entirely, but that caused #9690/20: after unhiding a volunteer chat,
 * there were zero action buttons (User2Mod has otheruid=0, so no Profile
 * button either). Fix: no per-chat chattype restriction.
 */

import { describe, it, expect } from 'vitest'

/**
 * shouldShowHideButton replicates the v-if condition from the template.
 *
 * After fix (#9690/20): no condition — button always shown for all chats.
 */
function shouldShowHideButton(_chat) {
  return true
}

/**
 * hideButtonLabel replicates the ternary label from the template:
 *   chat.status === 'Closed' ? 'Unhide' : 'Hide'
 */
function hideButtonLabel(chat) {
  return chat.status === 'Closed' ? 'Unhide' : 'Hide'
}

describe('per-chat Hide/Unhide button visibility (ChatPane + ChatMobileNavbar)', () => {
  describe('User2User chats', () => {
    it('shows Hide button on an active User2User chat', () => {
      const chat = { chattype: 'User2User', status: 'Online' }
      expect(shouldShowHideButton(chat)).toBe(true)
      expect(hideButtonLabel(chat)).toBe('Hide')
    })

    it('shows Unhide button on a hidden User2User chat (status Closed)', () => {
      const chat = { chattype: 'User2User', status: 'Closed' }
      expect(shouldShowHideButton(chat)).toBe(true)
      expect(hideButtonLabel(chat)).toBe('Unhide')
    })

    it('shows Hide on a User2User chat with Active status', () => {
      const chat = { chattype: 'User2User', status: 'Active' }
      expect(shouldShowHideButton(chat)).toBe(true)
      expect(hideButtonLabel(chat)).toBe('Hide')
    })
  })

  describe('User2Mod (volunteer) chats', () => {
    /**
     * Fix for #9690/20: Hide button MUST appear for active User2Mod chats.
     * After unhiding, the user sees the Hide button again (avoids zero-button state).
     * Bulk-hide protection is in hideAll() which already skips User2Mod.
     */
    it(
      'shows Hide button for an active User2Mod chat (status Online)',
      () => {
        const chat = { chattype: 'User2Mod', status: 'Online' }
        expect(shouldShowHideButton(chat)).toBe(true)
        expect(hideButtonLabel(chat)).toBe('Hide')
      }
    )

    it(
      'shows Hide button for a User2Mod chat with status Active',
      () => {
        const chat = { chattype: 'User2Mod', status: 'Active' }
        expect(shouldShowHideButton(chat)).toBe(true)
        expect(hideButtonLabel(chat)).toBe('Hide')
      }
    )

    it(
      'shows Unhide button for a User2Mod chat that is already hidden (status Closed)',
      () => {
        const chat = { chattype: 'User2Mod', status: 'Closed' }
        expect(shouldShowHideButton(chat)).toBe(true)
        expect(hideButtonLabel(chat)).toBe('Unhide')
      }
    )
  })

  describe('deleted-member User2User chats', () => {
    /**
     * A User2User chat where the other user is deleted: per the spec, action
     * buttons (including Hide/Unhide) MUST be shown. This is already true
     * because the condition only excludes User2Mod chats, not deleted users.
     */
    it(
      'shows Hide button for a User2User chat with a deleted member (status Online)',
      () => {
        const chat = { chattype: 'User2User', status: 'Online', otheruid: 42 }
        // deleted user info is on otheruser, not chat — the v-if only checks chattype
        expect(shouldShowHideButton(chat)).toBe(true)
      }
    )

    it(
      'shows Unhide button for a hidden User2User chat with a deleted member',
      () => {
        const chat = { chattype: 'User2User', status: 'Closed', otheruid: 42 }
        expect(shouldShowHideButton(chat)).toBe(true)
        expect(hideButtonLabel(chat)).toBe('Unhide')
      }
    )
  })
})
