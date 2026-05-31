import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { ref } from 'vue'

// ── Store mocks ──────────────────────────────────────────────────────────────
// Must be declared before importing the module under test.

let mockReplyStore
vi.mock('~/stores/reply', () => ({
  useReplyStore: () => mockReplyStore,
}))

let mockMessageStore
vi.mock('~/stores/message', () => ({
  useMessageStore: () => mockMessageStore,
}))

// ── Composable mocks ─────────────────────────────────────────────────────────

const mockMe = ref(null)
vi.mock('~/composables/useMe', () => ({
  useMe: () => ({ me: mockMe }),
}))

const mockAction = vi.fn()
vi.mock('~/composables/useClientLog', () => ({
  action: (...args) => mockAction(...args),
}))

// ── Module under test ────────────────────────────────────────────────────────
import { useReplyToPost } from '~/composables/useReplyToPost'

// ── Helpers ───────────────────────────────────────────────────────────────────

const DAY_MS = 24 * 60 * 60 * 1000
const NOW = new Date('2026-05-31T12:00:00.000Z').getTime()
// A replyingAt that is definitely recent (1 minute ago)
const RECENT_AT = NOW - 60_000
// A replyingAt that is just over 24 h old (stale)
const STALE_AT = NOW - DAY_MS - 1

function freshReplyStore(overrides = {}) {
  return {
    replyMsgId: 42,
    replyMessage: 'Hello, is this still available?',
    replyingAt: RECENT_AT,
    ...overrides,
  }
}

function freshMessageStore(overrides = {}) {
  return {
    byId: vi.fn().mockReturnValue(null),
    ...overrides,
  }
}

// ── Test suite ────────────────────────────────────────────────────────────────

describe('useReplyToPost', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(NOW)
    vi.clearAllMocks()

    // Default: logged-in user
    mockMe.value = { id: 1, displayname: 'Alice' }

    // Default stores
    mockReplyStore = freshReplyStore()
    mockMessageStore = freshMessageStore()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  // ── replyToSend ────────────────────────────────────────────────────────────

  describe('replyToSend', () => {
    it('returns null when user is not logged in', () => {
      mockMe.value = null
      const { replyToSend } = useReplyToPost()
      expect(replyToSend.value).toBeNull()
    })

    it('returns null when replyMsgId is missing', () => {
      mockReplyStore = freshReplyStore({ replyMsgId: null })
      const { replyToSend } = useReplyToPost()
      expect(replyToSend.value).toBeNull()
    })

    it('returns null when replyMessage is missing', () => {
      mockReplyStore = freshReplyStore({ replyMessage: null })
      const { replyToSend } = useReplyToPost()
      expect(replyToSend.value).toBeNull()
    })

    it('returns null when replyingAt is missing', () => {
      mockReplyStore = freshReplyStore({ replyingAt: null })
      const { replyToSend } = useReplyToPost()
      expect(replyToSend.value).toBeNull()
    })

    it('returns null when reply is older than 24 hours (stale)', () => {
      mockReplyStore = freshReplyStore({ replyingAt: STALE_AT })
      const { replyToSend } = useReplyToPost()
      expect(replyToSend.value).toBeNull()
    })

    it('returns null when reply is exactly 24 hours old', () => {
      // exactly at the boundary — Date.now() - replyingAt === DAY_MS, not < DAY_MS
      mockReplyStore = freshReplyStore({ replyingAt: NOW - DAY_MS })
      const { replyToSend } = useReplyToPost()
      expect(replyToSend.value).toBeNull()
    })

    it('returns the reply object when user is logged in and reply is fresh', () => {
      const { replyToSend } = useReplyToPost()
      expect(replyToSend.value).toEqual({
        replyMsgId: 42,
        replyMessage: 'Hello, is this still available?',
        replyingAt: RECENT_AT,
      })
    })

    it('returns object when reply is 1 ms inside the 24-hour window', () => {
      mockReplyStore = freshReplyStore({ replyingAt: NOW - DAY_MS + 1 })
      const { replyToSend } = useReplyToPost()
      expect(replyToSend.value).not.toBeNull()
      expect(replyToSend.value.replyMsgId).toBe(42)
    })

    it('includes all three store fields in the returned object', () => {
      mockReplyStore = freshReplyStore({
        replyMsgId: 99,
        replyMessage: 'Test message',
        replyingAt: RECENT_AT,
      })
      const { replyToSend } = useReplyToPost()
      expect(replyToSend.value).toEqual({
        replyMsgId: 99,
        replyMessage: 'Test message',
        replyingAt: RECENT_AT,
      })
    })
  })

  // ── replyToUser ────────────────────────────────────────────────────────────

  describe('replyToUser', () => {
    it('returns null when replyToSend is null (user not logged in)', () => {
      mockMe.value = null
      const { replyToUser } = useReplyToPost()
      expect(replyToUser.value).toBeNull()
    })

    it('returns null when replyToSend is null (stale reply)', () => {
      mockReplyStore = freshReplyStore({ replyingAt: STALE_AT })
      const { replyToUser } = useReplyToPost()
      expect(replyToUser.value).toBeNull()
    })

    it('returns null when message is not found in the store', () => {
      mockMessageStore = freshMessageStore({ byId: vi.fn().mockReturnValue(null) })
      const { replyToUser } = useReplyToPost()
      expect(replyToUser.value).toBeNull()
      expect(mockMessageStore.byId).toHaveBeenCalledWith(42)
    })

    it('returns null when message exists but has no fromuser', () => {
      mockMessageStore = freshMessageStore({
        byId: vi.fn().mockReturnValue({ id: 42, subject: 'An item' }),
      })
      const { replyToUser } = useReplyToPost()
      expect(replyToUser.value).toBeNull()
    })

    it('returns fromuser when message has a fromuser field', () => {
      const fromuser = { id: 7, displayname: 'Bob' }
      mockMessageStore = freshMessageStore({
        byId: vi.fn().mockReturnValue({ id: 42, fromuser }),
      })
      const { replyToUser } = useReplyToPost()
      expect(replyToUser.value).toBe(fromuser)
    })

    it('looks up the message by the correct replyMsgId', () => {
      mockReplyStore = freshReplyStore({ replyMsgId: 123 })
      const { replyToUser } = useReplyToPost()
      // Access value to trigger the computed
      void replyToUser.value
      expect(mockMessageStore.byId).toHaveBeenCalledWith(123)
    })
  })

  // ── replyToPost ────────────────────────────────────────────────────────────

  describe('replyToPost', () => {
    it('returns null and logs a null action when replyToSend is null (not logged in)', async () => {
      mockMe.value = null
      mockReplyStore = freshReplyStore({ replyMsgId: 5, replyMessage: 'Hi', replyingAt: RECENT_AT })
      const { replyToPost } = useReplyToPost()
      const result = await replyToPost({ openChat: vi.fn() })
      expect(result).toBeNull()
      expect(mockAction).toHaveBeenCalledWith('reply_to_post_null', expect.objectContaining({
        is_logged_in: false,
      }))
    })

    it('returns null and logs a null action when reply is stale', async () => {
      mockReplyStore = freshReplyStore({ replyingAt: STALE_AT })
      const { replyToPost } = useReplyToPost()
      const result = await replyToPost({ openChat: vi.fn() })
      expect(result).toBeNull()
      expect(mockAction).toHaveBeenCalledWith('reply_to_post_null', expect.objectContaining({
        is_logged_in: true,
        reply_stale: true,
      }))
    })

    it('returns null when chatButtonRef is null even with a valid pending reply', async () => {
      const { replyToPost } = useReplyToPost()
      const result = await replyToPost(null)
      expect(result).toBeNull()
    })

    it('calls openChat with correct arguments', async () => {
      const openChat = vi.fn().mockResolvedValue(undefined)
      const chatButtonRef = { openChat }
      const { replyToPost } = useReplyToPost()
      await replyToPost(chatButtonRef)
      expect(openChat).toHaveBeenCalledWith(null, 'Hello, is this still available?', 42)
    })

    it('returns the replyMsgId on success', async () => {
      const chatButtonRef = { openChat: vi.fn().mockResolvedValue(undefined) }
      const { replyToPost } = useReplyToPost()
      const result = await replyToPost(chatButtonRef)
      expect(result).toBe(42)
    })

    it('clears replyMsgId and replyMessage in the store after sending', async () => {
      const chatButtonRef = { openChat: vi.fn().mockResolvedValue(undefined) }
      const { replyToPost } = useReplyToPost()
      await replyToPost(chatButtonRef)
      expect(mockReplyStore.replyMsgId).toBeNull()
      expect(mockReplyStore.replyMessage).toBeNull()
    })

    it('updates replyingAt to current time after sending', async () => {
      const chatButtonRef = { openChat: vi.fn().mockResolvedValue(undefined) }
      const { replyToPost } = useReplyToPost()
      await replyToPost(chatButtonRef)
      expect(mockReplyStore.replyingAt).toBe(NOW)
    })

    it('logs a success action with the message_id after sending', async () => {
      const chatButtonRef = { openChat: vi.fn().mockResolvedValue(undefined) }
      const { replyToPost } = useReplyToPost()
      await replyToPost(chatButtonRef)
      expect(mockAction).toHaveBeenCalledWith('reply_to_post_success', { message_id: 42 })
    })

    it('does not call action() when chatButtonRef is null (returns early without logging)', async () => {
      // The composable exits early with console.error when chatButtonRef is null,
      // skipping the action() call — this tests that behavior.
      const { replyToPost } = useReplyToPost()
      await replyToPost(null)
      expect(mockAction).not.toHaveBeenCalled()
    })

    it('includes replyingAt age in null action when replyingAt is set but stale', async () => {
      const oldReplyingAt = NOW - DAY_MS - 5000
      mockReplyStore = freshReplyStore({ replyingAt: oldReplyingAt })
      const { replyToPost } = useReplyToPost()
      await replyToPost({ openChat: vi.fn() })
      expect(mockAction).toHaveBeenCalledWith('reply_to_post_null', expect.objectContaining({
        has_replying_at: true,
        reply_age_ms: NOW - oldReplyingAt,
        reply_stale: true,
      }))
    })

    it('reports reply_age_ms as null when replyingAt is not set', async () => {
      mockReplyStore = freshReplyStore({ replyingAt: null })
      const { replyToPost } = useReplyToPost()
      await replyToPost({ openChat: vi.fn() })
      expect(mockAction).toHaveBeenCalledWith('reply_to_post_null', expect.objectContaining({
        has_replying_at: false,
        reply_age_ms: null,
        reply_stale: null,
      }))
    })

    it('awaits openChat before clearing the store', async () => {
      let storeClearedDuringOpenChat = false
      const openChat = vi.fn().mockImplementation(async () => {
        storeClearedDuringOpenChat =
          mockReplyStore.replyMsgId === null || mockReplyStore.replyMessage === null
      })
      const { replyToPost } = useReplyToPost()
      await replyToPost({ openChat })
      // Store should NOT have been cleared while openChat was still running
      expect(storeClearedDuringOpenChat).toBe(false)
      // But after replyToPost returns it should be cleared
      expect(mockReplyStore.replyMsgId).toBeNull()
    })

    it('uses the correct replyMsgId as replySent even if message is not in messageStore', async () => {
      mockReplyStore = freshReplyStore({ replyMsgId: 77, replyMessage: 'Still available?', replyingAt: RECENT_AT })
      mockMessageStore = freshMessageStore({ byId: vi.fn().mockReturnValue(null) })
      const chatButtonRef = { openChat: vi.fn().mockResolvedValue(undefined) }
      const { replyToPost } = useReplyToPost()
      const result = await replyToPost(chatButtonRef)
      expect(result).toBe(77)
      expect(mockAction).toHaveBeenCalledWith('reply_to_post_success', { message_id: 77 })
    })
  })

  // ── null-action payload shape ──────────────────────────────────────────────

  describe('reply_to_post_null action payload', () => {
    const cases = [
      {
        label: 'user not logged in, no store data',
        setup() {
          mockMe.value = null
          mockReplyStore = freshReplyStore({ replyMsgId: null, replyMessage: null, replyingAt: null })
        },
        expected: {
          is_logged_in: false,
          has_reply_msg_id: false,
          has_reply_message: false,
          has_replying_at: false,
          reply_age_ms: null,
          reply_stale: null,
        },
      },
      {
        label: 'user logged in, all store data present but stale',
        setup() {
          mockReplyStore = freshReplyStore({ replyingAt: STALE_AT })
        },
        expected: {
          is_logged_in: true,
          has_reply_msg_id: true,
          has_reply_message: true,
          has_replying_at: true,
          reply_age_ms: NOW - STALE_AT,
          reply_stale: true,
        },
      },
      {
        label: 'user logged in but replyMessage missing',
        setup() {
          mockReplyStore = freshReplyStore({ replyMessage: null })
        },
        expected: {
          is_logged_in: true,
          has_reply_msg_id: true,
          has_reply_message: false,
          has_replying_at: true,
          reply_age_ms: NOW - RECENT_AT,
          reply_stale: false,
        },
      },
      {
        label: 'user logged in but replyMsgId missing',
        setup() {
          mockReplyStore = freshReplyStore({ replyMsgId: null })
        },
        expected: {
          is_logged_in: true,
          has_reply_msg_id: false,
          has_reply_message: true,
          has_replying_at: true,
          reply_age_ms: NOW - RECENT_AT,
          reply_stale: false,
        },
      },
    ]

    for (const { label, setup, expected } of cases) {
      it(`logs correct payload when ${label}`, async () => {
        setup()
        const { replyToPost } = useReplyToPost()
        await replyToPost({ openChat: vi.fn() })
        expect(mockAction).toHaveBeenCalledWith('reply_to_post_null', expected)
      })
    }
  })
})
