import { describe, it, expect } from 'vitest'
import { shouldShowChatMarkReadIcon } from '~/composables/useChat'

// Discourse 10001: on the iOS app / mobile web, ChatMobileNavbar's per-chat
// "Mark read" icon flashed briefly then disappeared when opening a chat,
// unlike desktop where the equivalent button stays visible. Root cause: the
// icon's visibility was gated on `!profileCardExpanded`, and a 500ms
// onMounted timer auto-expands the profile card to show a first-visit hint -
// which hid the icon even though the user never asked to see the card and
// never marked the chat read. shouldShowChatMarkReadIcon is the extracted
// visibility rule the template's v-if now calls, so this exercises the real
// production logic rather than the template markup.
describe('shouldShowChatMarkReadIcon (Discourse 10001)', () => {
  it('stays visible when the profile card is only expanded by the auto-shown first-visit hint', () => {
    // unseen messages exist; the 500ms hint timer has set profileCardExpanded
    // and showProfileHint both true; the user never touched the avatar.
    expect(shouldShowChatMarkReadIcon(3, true, true)).toBe(true)
  })

  it('hides once the user deliberately opens the profile card themselves', () => {
    // toggleProfileCard() clears showProfileHint as soon as the user taps the
    // avatar, so a real, deliberate expansion still hides the icon in favour
    // of the "Mark read" action inside the expanded card.
    expect(shouldShowChatMarkReadIcon(3, true, false)).toBe(false)
  })

  it('shows when there are unseen messages and the card is collapsed', () => {
    expect(shouldShowChatMarkReadIcon(1, false, false)).toBe(true)
  })

  it('hides when there are no unseen messages, regardless of card state', () => {
    expect(shouldShowChatMarkReadIcon(0, false, false)).toBe(false)
    expect(shouldShowChatMarkReadIcon(0, false, true)).toBe(false)
  })
})
