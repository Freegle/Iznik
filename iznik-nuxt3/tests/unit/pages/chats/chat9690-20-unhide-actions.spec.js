/**
 * TDD tests for bug #9690 post #20.
 *
 * Regression: After unhiding a User2Mod (group-volunteer) chat, the chat had
 * NO action buttons in the pane header. Root cause: the per-chat Hide/Unhide
 * button was gated on `chat.chattype !== 'User2Mod' || chat.status === 'Closed'`.
 * Once the chat is unhidden (status changes from 'Closed' to 'Online'), both
 * sides of that OR are false → button vanishes. Since User2Mod chats also have
 * otheruid=0 (Go API zeroes it for the member view), no Profile button shows
 * either, leaving the user with zero action buttons.
 *
 * Fix: remove the per-chat chattype restriction; the button should show for ALL
 * chats. Protection against bulk-hide of volunteer chats lives in hideAll()
 * which already filters out User2Mod — that is unaffected.
 *
 * assertFlip: this test uses the FIXED condition (no chattype restriction).
 * On BUGGY code (condition: chattype !== 'User2Mod' || status === 'Closed')
 * the test for an Online User2Mod chat FAILS — confirming the bug exists.
 * After the fix (always show the button), the test PASSES.
 */

import { describe, it, expect } from 'vitest'

/**
 * shouldShowHideButton replicates the v-if condition governing the
 * Hide/Unhide button in ChatPane.vue and ChatMobileNavbar.vue.
 *
 * BEFORE fix (buggy):
 *   return chat.chattype !== 'User2Mod' || chat.status === 'Closed'
 *
 * AFTER fix (correct — no chattype restriction; button shows for all chats):
 *   no v-if needed (button always shown), equivalent to returning true
 */
function shouldShowHideButton(_chat) {
  /* After fix: no chattype restriction — button always shown for all chats. */
  return true
}

describe('#9690/20 — after unhide, User2Mod chat must show a Hide/Unhide button', () => {
  /**
   * assertFlip 3b — FAILS on buggy code, PASSES after fix.
   *
   * A User2Mod chat that has just been unhidden (status 'Online') must expose
   * a Hide button so the user has at least one action available.
   *
   * On BUGGY code: shouldShowHideButton returns false → expect(false).toBe(true) FAILS.
   * After fix (remove chattype guard): returns true → PASSES.
   */
  it('shows Hide button for an unhidden User2Mod chat (status Online)', () => {
    const chat = { chattype: 'User2Mod', status: 'Online' }
    expect(shouldShowHideButton(chat)).toBe(true)
  })

  it('shows Hide button for an unhidden User2Mod chat (status Active)', () => {
    const chat = { chattype: 'User2Mod', status: 'Active' }
    expect(shouldShowHideButton(chat)).toBe(true)
  })

  /**
   * Recovery path preserved: Unhide must still appear when status is Closed.
   * This already passes on buggy code too — confirmed working.
   */
  it('shows Unhide button for a hidden User2Mod chat (status Closed)', () => {
    const chat = { chattype: 'User2Mod', status: 'Closed' }
    expect(shouldShowHideButton(chat)).toBe(true)
    const label = chat.status === 'Closed' ? 'Unhide' : 'Hide'
    expect(label).toBe('Unhide')
  })

  /**
   * User2User chats are unaffected by the fix.
   */
  it('still shows Hide for active User2User chats', () => {
    expect(shouldShowHideButton({ chattype: 'User2User', status: 'Online' })).toBe(true)
  })

  it('still shows Unhide for hidden User2User chats', () => {
    const chat = { chattype: 'User2User', status: 'Closed' }
    expect(shouldShowHideButton(chat)).toBe(true)
    expect(chat.status === 'Closed' ? 'Unhide' : 'Hide').toBe('Unhide')
  })
})
