/**
 * AssertFlip test for Discourse #9690 post #14 — bug: unhide() resets
 * showClosed to false, kicking the user back to the visible chats list when
 * they were selectively unhiding from the hidden list.
 *
 * Root cause: chatStore.unhide() (stores/chat.js) contained
 *   this.showClosed = false
 * after the API call. This fired the watch(showClosed, ...) in the chats page
 * which reset showChats and re-rendered the visible (not hidden) list.
 *
 * Fix: remove that line so showClosed is left as-is after unhide.
 *
 * AssertFlip protocol:
 *   STEP 1 — buggy behaviour: showClosed becomes false after unhide().
 *             Verified, then inverted.
 *   STEP 2 — inverted assertion committed here:
 *             showClosed must remain true → FAILS on buggy code,
 *             PASSES once the `this.showClosed = false` line is removed.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const mockUnHideChat = vi.fn().mockResolvedValue()
const mockFetchChat = vi.fn().mockResolvedValue(null)
const mockListChats = vi.fn().mockResolvedValue([])

vi.mock('~/api', () => ({
  default: () => ({
    chat: {
      listChats: mockListChats,
      listChatsMT: vi.fn().mockResolvedValue({ chatrooms: [] }),
      fetchChat: mockFetchChat,
      fetchMessages: vi.fn().mockResolvedValue([]),
      markRead: vi.fn().mockResolvedValue(),
      send: vi.fn().mockResolvedValue(),
      hideChat: vi.fn().mockResolvedValue(),
      unHideChat: mockUnHideChat,
      blockChat: vi.fn().mockResolvedValue(),
      deleteMessage: vi.fn().mockResolvedValue(),
      nudge: vi.fn().mockResolvedValue(),
      typing: vi.fn().mockResolvedValue(),
      openChat: vi.fn().mockResolvedValue({ id: 42 }),
      sendMT: vi.fn().mockResolvedValue(),
      unseenCountMT: vi.fn().mockResolvedValue(0),
      allSeen: vi.fn().mockResolvedValue(),
      fetchReviewChatsMT: vi.fn().mockResolvedValue({ chatmessages: [] }),
      rsvp: vi.fn().mockResolvedValue(),
    },
  }),
}))

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({ user: { id: 1 } }),
}))
vi.mock('~/stores/group', () => ({
  useGroupStore: () => ({ fetch: vi.fn(), get: vi.fn(), list: {} }),
}))
vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({ fetch: vi.fn() }),
}))
vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({ modtools: false }),
}))
vi.mock('~/stores/user', () => ({
  useUserStore: () => ({ fetch: vi.fn(), list: {} }),
}))

import { useChatStore } from '../../../../stores/chat'

describe('bug #9690/14 — unhide must not reset showClosed', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    mockFetchChat.mockResolvedValue({ id: 1, status: 'Online' })
    mockListChats.mockResolvedValue([])
  })

  /*
   * STEP 2 — INVERTED assertion.
   *
   * On BUGGY code this test FAILS because unhide() sets this.showClosed = false,
   * so showClosed changes from true to false — the user is kicked back to the
   * visible-chats view while they were still on the hidden-chats view.
   *
   * After the fix (removing `this.showClosed = false` from unhide()) showClosed
   * remains true → test PASSES.
   */
  it(
    'preserves showClosed=true after unhide so the user stays on the hidden list',
    async () => {
      const store = useChatStore()
      store.config = {}
      store.listByChatId[1] = { id: 1, status: 'Closed' }

      // User is viewing the hidden list
      store.showClosed = true

      await store.unhide(1)

      // showClosed must NOT be reset — user stays on the hidden-chats view
      // so they can selectively unhide more chats without navigating away.
      expect(store.showClosed).toBe(true)
    }
  )

  it('updates the chat status to Online when unhidden', async () => {
    const store = useChatStore()
    store.config = {}
    store.listByChatId[1] = { id: 1, status: 'Closed' }

    await store.unhide(1)

    expect(mockUnHideChat).toHaveBeenCalledWith(1)
    expect(store.listByChatId[1].status).toBe('Online')
  })
})
