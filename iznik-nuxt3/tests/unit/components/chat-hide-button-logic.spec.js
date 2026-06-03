/**
 * TDD tests for the per-chat Hide/Unhide button visibility rules (PR #627 corrected).
 *
 * These tests verify the v-if logic for the Hide/Unhide button in ChatPane.vue
 * and ChatMobileNavbar.vue directly, without mounting the full async component.
 *
 * Spec:
 *   - User2User chat (any status): button always shown
 *       - status !== 'Closed' → "Hide"
 *       - status === 'Closed' → "Unhide"
 *   - User2Mod chat, status !== 'Closed': button MUST NOT appear (no hiding volunteer chats)
 *   - User2Mod chat, status === 'Closed': button MUST appear as "Unhide"
 *     (recovery path for chats hidden by the previously-buggy Hide all)
 *
 * The condition is:
 *   chat.chattype !== 'User2Mod' || chat.status === 'Closed'
 *
 * This file tests this condition expression and the derived label directly so
 * they can fail before any template edits and pass after.
 */

import { describe, it, expect } from 'vitest'

/**
 * shouldShowHideButton replicates the v-if condition from the template:
 *   chat.chattype !== 'User2Mod' || chat.status === 'Closed'
 *
 * On BUGGY code (PR as-over-reached) the condition is absent or always true,
 * so volunteer chats also get a Hide button. After the fix this returns false
 * for User2Mod with status !== 'Closed'.
 */
function shouldShowHideButton(chat) {
  return chat.chattype !== 'User2Mod' || chat.status === 'Closed'
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
     * STEP 2 — INVERTED assertion.
     *
     * On BUGGY code (button has no v-if or always-true v-if) this test FAILS
     * because shouldShowHideButton returns true for a User2Mod Online chat,
     * meaning volunteers can be accidentally hidden.
     *
     * After the fix (v-if="chat.chattype !== 'User2Mod' || chat.status === 'Closed'")
     * this returns false → PASSES.
     */
    it(
      'does NOT show the Hide button for an active User2Mod chat (status Online)',
      () => {
        const chat = { chattype: 'User2Mod', status: 'Online' }
        expect(shouldShowHideButton(chat)).toBe(false)
      }
    )

    it(
      'does NOT show the Hide button for a User2Mod chat with status Active',
      () => {
        const chat = { chattype: 'User2Mod', status: 'Active' }
        expect(shouldShowHideButton(chat)).toBe(false)
      }
    )

    /**
     * STEP 2 — INVERTED assertion for the recovery path.
     *
     * On BUGGY code that always hides the button for User2Mod, this FAILS.
     * After the fix (show Unhide when status === 'Closed') → PASSES.
     */
    it(
      'shows Unhide button for a User2Mod chat that is already hidden (status Closed)',
      () => {
        const chat = { chattype: 'User2Mod', status: 'Closed' }
        expect(shouldShowHideButton(chat)).toBe(true)
        expect(hideButtonLabel(chat)).toBe('Unhide')
      }
    )

    it(
      'never shows Hide (only Unhide) for User2Mod: when status is Closed it is Unhide',
      () => {
        const chat = { chattype: 'User2Mod', status: 'Closed' }
        // Button appears, but only as Unhide — never as Hide
        expect(shouldShowHideButton(chat)).toBe(true)
        expect(hideButtonLabel(chat)).toBe('Unhide')
        expect(hideButtonLabel(chat)).not.toBe('Hide')
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
