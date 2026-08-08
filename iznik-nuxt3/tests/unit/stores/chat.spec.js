import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

// Import the REAL store directly — vitest.config.mts aliases ~/stores/chat
// to a global mock for other tests. We need the actual implementation here.
import { useChatStore, dedupeRetriedChatMessages } from '../../../stores/chat'

const mockListChats = vi.fn().mockResolvedValue([])
const mockListChatsMT = vi.fn().mockResolvedValue({ chatrooms: [] })
const mockFetchChat = vi.fn().mockResolvedValue(null)
const mockFetchMessages = vi.fn().mockResolvedValue([])
const mockMarkRead = vi.fn().mockResolvedValue()
const mockSend = vi.fn().mockResolvedValue()
const mockHideChat = vi.fn().mockResolvedValue()
const mockUnHideChat = vi.fn().mockResolvedValue()
const mockBlockChat = vi.fn().mockResolvedValue()
const mockDeleteMessage = vi.fn().mockResolvedValue()
const mockNudge = vi.fn().mockResolvedValue()
const mockTyping = vi.fn().mockResolvedValue()
const mockOpenChat = vi.fn().mockResolvedValue({ id: 42 })
const mockSendMT = vi.fn().mockResolvedValue()
const mockUnseenCountMT = vi.fn().mockResolvedValue(0)
const mockAllSeen = vi.fn().mockResolvedValue()
const mockRsvp = vi.fn().mockResolvedValue()
const mockFetchReviewChatsMT = vi.fn().mockResolvedValue({ chatmessages: [] })
const mockAnswerPrompt = vi.fn().mockResolvedValue({ ok: true })
const mockCommonGroups = vi.fn().mockResolvedValue([])
const mockReportNoGroup = vi.fn().mockResolvedValue()

vi.mock('~/api', () => ({
  default: () => ({
    chat: {
      listChats: mockListChats,
      listChatsMT: mockListChatsMT,
      fetchChat: mockFetchChat,
      fetchMessages: mockFetchMessages,
      markRead: mockMarkRead,
      send: mockSend,
      hideChat: mockHideChat,
      unHideChat: mockUnHideChat,
      blockChat: mockBlockChat,
      deleteMessage: mockDeleteMessage,
      nudge: mockNudge,
      typing: mockTyping,
      openChat: mockOpenChat,
      sendMT: mockSendMT,
      unseenCountMT: mockUnseenCountMT,
      allSeen: mockAllSeen,
      fetchReviewChatsMT: mockFetchReviewChatsMT,
      rsvp: mockRsvp,
      answerPrompt: mockAnswerPrompt,
      commonGroups: mockCommonGroups,
      reportNoGroup: mockReportNoGroup,
    },
  }),
}))

const mockUseAuthStore = vi.fn(() => ({
  user: { id: 999 },
  clearRelated: vi.fn(),
  logout: vi.fn(),
  login: vi.fn(),
}))
vi.mock('~/stores/auth', () => ({
  useAuthStore: (...args) => mockUseAuthStore(...args),
}))

vi.mock('~/stores/group', () => ({
  useGroupStore: () => ({
    fetch: vi.fn().mockResolvedValue(),
    get: vi.fn().mockReturnValue({ id: 1, nameshort: 'TestGroup' }),
    list: {},
  }),
}))

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    fetch: vi.fn().mockResolvedValue(),
  }),
}))

const mockUseMiscStore = vi.fn(() => ({
  modtools: false,
}))
vi.mock('~/stores/misc', () => ({
  useMiscStore: (...args) => mockUseMiscStore(...args),
}))

vi.mock('~/stores/user', () => ({
  useUserStore: () => ({
    fetch: vi.fn().mockResolvedValue(),
    list: {},
  }),
}))

vi.stubGlobal('useRoute', () => ({ path: '/', query: {} }))

describe('chat store', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
  })

  describe('clear', () => {
    it('resets all state', () => {
      const store = useChatStore()
      store.list = [{ id: 1 }]
      store.listByChatId = { 1: { id: 1 } }
      store.listByChatMessageId = { 10: { id: 10 } }
      store.messages = { 1: [{ id: 10 }] }
      store.searchSince = '2026-01-01'
      store.showContactDetailsAskModal = true
      store.currentChatMT = 5
      store.lastSearchMT = 'hello'

      store.clear()

      expect(store.list).toEqual([])
      expect(store.listByChatId).toEqual({})
      expect(store.listByChatMessageId).toEqual({})
      expect(store.messages).toEqual({})
      expect(store.searchSince).toBeNull()
      expect(store.showContactDetailsAskModal).toBe(false)
      expect(store.currentChatMT).toBeNull()
      expect(store.lastSearchMT).toBeNull()
    })
  })

  describe('markRead', () => {
    it('optimistically sets unseen to 0 and calls API with lastmsg', async () => {
      const store = useChatStore()
      store.config = {}
      store.listByChatId[5] = { id: 5, unseen: 3, lastmsg: 100 }

      await store.markRead(5)

      expect(store.listByChatId[5].unseen).toBe(0)
      expect(mockMarkRead).toHaveBeenCalledWith(5, 100, false)
    })

    it('does nothing when unseen is 0', async () => {
      const store = useChatStore()
      store.config = {}
      store.listByChatId[5] = { id: 5, unseen: 0, lastmsg: 100 }

      await store.markRead(5)

      expect(mockMarkRead).not.toHaveBeenCalled()
    })

    it('falls back to highest loaded message ID when lastmsg missing', async () => {
      const store = useChatStore()
      store.config = {}
      store.listByChatId[5] = { id: 5, unseen: 2 }
      store.messages[5] = [{ id: 50 }, { id: 75 }, { id: 80 }]

      await store.markRead(5)

      expect(mockMarkRead).toHaveBeenCalledWith(5, 80, false)
    })

    it('does not call API when no chat entry exists', async () => {
      const store = useChatStore()
      store.config = {}

      await store.markRead(5)

      expect(mockMarkRead).not.toHaveBeenCalled()
    })
  })

  describe('markAllReadMT', () => {
    it('calls allSeen API and resets currentCountMT to 0', async () => {
      const store = useChatStore()
      store.config = {}
      store.currentCountMT = 7

      await store.markAllReadMT()

      expect(mockAllSeen).toHaveBeenCalledOnce()
      expect(store.currentCountMT).toBe(0)
    })

    it('resets badge even when currentCountMT was already 0', async () => {
      const store = useChatStore()
      store.config = {}
      store.currentCountMT = 0

      await store.markAllReadMT()

      expect(mockAllSeen).toHaveBeenCalledOnce()
      expect(store.currentCountMT).toBe(0)
    })
  })

  describe('markAllRead', () => {
    it('calls allSeen API', async () => {
      const store = useChatStore()
      store.config = {}
      store.listByChatId[1] = { id: 1, unseen: 3, status: 'Online' }

      await store.markAllRead()

      expect(mockAllSeen).toHaveBeenCalledOnce()
    })

    it('resets unseen to 0 for all chats in listByChatId', async () => {
      const store = useChatStore()
      store.config = {}
      store.listByChatId[1] = { id: 1, unseen: 3, status: 'Online' }
      store.listByChatId[2] = { id: 2, unseen: 5, status: 'Online' }
      store.listByChatId[3] = { id: 3, unseen: 2, status: 'Closed' }

      await store.markAllRead()

      expect(store.listByChatId[1].unseen).toBe(0)
      expect(store.listByChatId[2].unseen).toBe(0)
      expect(store.listByChatId[3].unseen).toBe(0)
    })

    it('does not call per-chat markRead', async () => {
      const store = useChatStore()
      store.config = {}
      store.listByChatId[1] = { id: 1, unseen: 3, status: 'Online' }

      await store.markAllRead()

      expect(mockMarkRead).not.toHaveBeenCalled()
    })
  })

  describe('send', () => {
    it('updates snippet in listByChatId immediately after sending', async () => {
      const store = useChatStore()
      store.config = {}
      store.listByChatId[10] = { id: 10, snippet: 'old message' }
      mockFetchMessages.mockResolvedValue([])

      await store.send(10, 'new message')

      expect(mockSend).toHaveBeenCalledWith({
        roomid: 10,
        message: 'new message',
      })
      expect(store.listByChatId[10].snippet).toBe('new message')
    })

    it('includes optional params when provided', async () => {
      const store = useChatStore()
      store.config = {}
      store.listByChatId[10] = { id: 10 }
      mockFetchMessages.mockResolvedValue([])

      await store.send(10, 'hi', 1, 2, 3, true, 'browse')

      expect(mockSend).toHaveBeenCalledWith({
        roomid: 10,
        message: 'hi',
        addressid: 1,
        imageid: 2,
        refmsgid: 3,
        modnote: true,
        replysource: 'browse',
      })
    })

    it('omits falsy optional params', async () => {
      const store = useChatStore()
      store.config = {}
      mockFetchMessages.mockResolvedValue([])

      await store.send(10, 'hi', null, null, null, false)

      expect(mockSend).toHaveBeenCalledWith({
        roomid: 10,
        message: 'hi',
      })
    })

    it('only sends replysource alongside a refmsgid (reply provenance, not chat chatter)', async () => {
      const store = useChatStore()
      store.config = {}
      mockFetchMessages.mockResolvedValue([])

      await store.send(10, 'hi', null, null, null, false, 'browse')

      expect(mockSend).toHaveBeenCalledWith({
        roomid: 10,
        message: 'hi',
      })
    })
  })

  describe('hide', () => {
    it('sets chat status to Closed immediately', async () => {
      const store = useChatStore()
      store.config = {}
      store.listByChatId[7] = { id: 7, status: 'Online' }
      mockListChats.mockResolvedValue([])

      await store.hide(7)

      expect(mockHideChat).toHaveBeenCalledWith(7)
      expect(store.listByChatId[7].status).toBe('Closed')
    })
  })

  describe('unhide', () => {
    it('sets chat status to Online without resetting showClosed', async () => {
      const store = useChatStore()
      store.config = {}
      store.listByChatId[7] = { id: 7, status: 'Closed' }
      store.showClosed = true
      mockFetchChat.mockResolvedValue({ id: 7, status: 'Online' })
      mockListChats.mockResolvedValue([])

      await store.unhide(7)

      expect(mockUnHideChat).toHaveBeenCalledWith(7)
      expect(store.listByChatId[7].status).toBe('Online')
      // showClosed must be preserved so the user stays on the hidden-chats view
      // when selectively unhiding (#9690/14).
      expect(store.showClosed).toBe(true)
    })
  })

  describe('fetchMessages', () => {
    it('updates store when message count changes', async () => {
      const store = useChatStore()
      store.config = {}
      store.messages[1] = [{ id: 10 }]
      mockFetchMessages.mockResolvedValue([{ id: 10 }, { id: 11 }])

      const result = await store.fetchMessages(1)

      expect(result).toHaveLength(2)
      expect(store.messages[1]).toHaveLength(2)
      expect(store.listByChatMessageId[10]).toBeTruthy()
      expect(store.listByChatMessageId[11]).toBeTruthy()
    })

    it('skips update when message count is unchanged and force is false', async () => {
      const store = useChatStore()
      store.config = {}
      store.messages[1] = [{ id: 10 }]
      mockFetchMessages.mockResolvedValue([{ id: 10 }])

      await store.fetchMessages(1, false)

      // Messages unchanged — listByChatMessageId should not be rebuilt
      expect(store.messages[1]).toEqual([{ id: 10 }])
      expect(store.listByChatMessageId[10]).toBeUndefined()
    })

    it('forces update when force is true even with same count', async () => {
      const store = useChatStore()
      store.config = {}
      store.messages[1] = [{ id: 10 }]
      const newMessages = [{ id: 10, message: 'updated' }]
      mockFetchMessages.mockResolvedValue(newMessages)

      await store.fetchMessages(1, true)

      expect(store.messages[1]).toEqual(newMessages)
      // Force causes listByChatMessageId to be rebuilt
      expect(store.listByChatMessageId[10]).toBeTruthy()
    })
  })

  describe('fetchChat', () => {
    it('merges new data with existing chat entry', async () => {
      const store = useChatStore()
      store.config = {}
      store.listByChatId[5] = { id: 5, icon: 'old-icon.png' }
      mockFetchChat.mockResolvedValue({ id: 5, snippet: 'hello' })

      await store.fetchChat(5)

      expect(store.listByChatId[5].icon).toBe('old-icon.png')
      expect(store.listByChatId[5].snippet).toBe('hello')
    })

    it('adds chat to list if not already present', async () => {
      const store = useChatStore()
      store.config = {}
      store.list = []
      mockFetchChat.mockResolvedValue({ id: 5, snippet: 'hello' })

      await store.fetchChat(5)

      expect(store.list).toHaveLength(1)
      expect(store.list[0].id).toBe(5)
    })

    it('does not duplicate in list if already present', async () => {
      const store = useChatStore()
      store.config = {}
      store.list = [{ id: 5 }]
      mockFetchChat.mockResolvedValue({ id: 5, snippet: 'hello' })

      await store.fetchChat(5)

      expect(store.list).toHaveLength(1)
    })

    it('removes stale reference on 404', async () => {
      const store = useChatStore()
      store.config = {}
      store.listByChatId[5] = { id: 5 }
      const err = new Error('Not found')
      err.response = { status: 404 }
      mockFetchChat.mockRejectedValue(err)

      await store.fetchChat(5)

      expect(store.listByChatId[5]).toBeUndefined()
    })

    it('ignores id <= 0', async () => {
      const store = useChatStore()
      store.config = {}

      await store.fetchChat(0)
      await store.fetchChat(-1)

      expect(mockFetchChat).not.toHaveBeenCalled()
    })
  })

  describe('chat moderation actions', () => {
    it('approveChat sends Approve action', async () => {
      const store = useChatStore()
      store.config = {}

      await store.approveChat(42)

      expect(mockSendMT).toHaveBeenCalledWith({ id: 42, action: 'Approve' })
    })

    it('rejectChat sends Reject action', async () => {
      const store = useChatStore()
      store.config = {}

      await store.rejectChat(42)

      expect(mockSendMT).toHaveBeenCalledWith({ id: 42, action: 'Reject' })
    })

    it('_sendChatMT swallows 404 errors', async () => {
      const store = useChatStore()
      store.config = {}
      const err = new Error('Not found')
      err.response = { status: 404 }
      mockSendMT.mockRejectedValueOnce(err)

      // Should not throw
      await store.approveChat(42)
    })

    it('_sendChatMT rethrows non-404 errors', async () => {
      const store = useChatStore()
      store.config = {}
      const err = new Error('Server error')
      err.response = { status: 500 }
      mockSendMT.mockRejectedValueOnce(err)

      await expect(store.approveChat(42)).rejects.toThrow('Server error')
    })

    // Discourse #9879/1: holdChat used to only send the API action, leaving the
    // caller (ModChatReview.vue) to emit('reload') and have the parent clear +
    // refetch the whole review queue just to pick up the new held state — which
    // reset scroll to the top. holdChat now patches the held message in the store
    // directly, matching messageStore.hold()'s in-place update for pending posts.
    it('holdChat sends Hold action and patches the message in place with held info', async () => {
      const store = useChatStore()
      store.config = {}
      store.listByChatMessageId[42] = { id: 42, message: 'hi', held: null }

      await store.holdChat(42)

      expect(mockSendMT).toHaveBeenCalledWith({ id: 42, action: 'Hold' })
      const updated = store.listByChatMessageId[42]
      expect(updated.held).toBeTruthy()
      expect(updated.held.id).toBe(999) // mocked authStore.user.id
      expect(updated.held.timestamp).toBeTruthy()
      // Other fields on the message are preserved, not wiped by a refetch.
      expect(updated.message).toBe('hi')
    })

    it('holdChat does not clear or refetch the review list', async () => {
      const store = useChatStore()
      store.config = {}
      store.listByChatMessageId[42] = { id: 42, held: null }
      store.messages.null = [{ id: 42 }, { id: 43 }]

      await store.holdChat(42)

      expect(mockFetchReviewChatsMT).not.toHaveBeenCalled()
      // The rest of the list is untouched — no reload, no reordering.
      expect(store.messages.null).toEqual([{ id: 42 }, { id: 43 }])
    })
  })

  describe('openChat', () => {
    it('returns chat id and fetches the chat', async () => {
      const store = useChatStore()
      store.config = {}
      mockOpenChat.mockResolvedValue({ id: 42 })
      mockFetchChat.mockResolvedValue({ id: 42, snippet: 'hi' })

      const id = await store.openChat({ chattype: 'User2User', userid: 1 })

      expect(id).toBe(42)
      expect(mockFetchChat).toHaveBeenCalledWith(42, false)
    })

    it('openChatToMods uses User2Mod chattype', async () => {
      const store = useChatStore()
      store.config = {}
      mockOpenChat.mockResolvedValue({ id: 50 })
      mockFetchChat.mockResolvedValue({ id: 50 })

      const id = await store.openChatToMods(10, 20)

      expect(id).toBe(50)
      expect(mockOpenChat).toHaveBeenCalledWith(
        { chattype: 'User2Mod', groupid: 10, userid: 20 },
        expect.any(Function)
      )
    })
  })

  describe('getters', () => {
    describe('byChatId', () => {
      it('returns chat by id', () => {
        const store = useChatStore()
        store.listByChatId[5] = { id: 5, snippet: 'hello' }

        expect(store.byChatId(5)).toEqual({ id: 5, snippet: 'hello' })
      })

      it('returns undefined for missing id', () => {
        const store = useChatStore()

        expect(store.byChatId(999)).toBeUndefined()
      })
    })

    describe('messagesById', () => {
      it('returns messages for chat id', () => {
        const store = useChatStore()
        store.messages[3] = [{ id: 10 }, { id: 11 }]

        expect(store.messagesById(3)).toHaveLength(2)
      })

      it('returns empty array for missing chat id', () => {
        const store = useChatStore()

        expect(store.messagesById(999)).toEqual([])
      })
    })

    describe('unreadCount', () => {
      it('sums unseen for non-Closed non-Blocked chats', () => {
        const store = useChatStore()
        store.listByChatId[1] = { id: 1, unseen: 3, status: 'Online' }
        store.listByChatId[2] = { id: 2, unseen: 2, status: 'Online' }
        store.listByChatId[3] = { id: 3, unseen: 5, status: 'Closed' }
        store.listByChatId[4] = { id: 4, unseen: 1, status: 'Blocked' }

        expect(store.unreadCount).toBe(5)
      })
    })

    describe('toUser', () => {
      it('finds User2User chat by otheruid', () => {
        const store = useChatStore()
        store.listByChatId[1] = {
          id: 1,
          chattype: 'User2User',
          otheruid: 42,
        }
        store.listByChatId[2] = {
          id: 2,
          chattype: 'User2Mod',
          otheruid: 42,
        }

        const result = store.toUser(42)
        expect(result.id).toBe(1)
      })

      it('returns null when no matching chat', () => {
        const store = useChatStore()
        store.listByChatId[1] = {
          id: 1,
          chattype: 'User2User',
          otheruid: 99,
        }

        expect(store.toUser(42)).toBeNull()
      })
    })
  })

  describe('fetchReviewChatsMT + fetchMessages merge', () => {
    it('preserves review fields in listByChatMessageId after a per-room fetchMessages', async () => {
      // Seed the store with a rich review-queue message via fetchReviewChatsMT.
      // fetchMessages for the same room then returns only the slim per-room shape.
      // The review fields (touserid, fromuserid) must survive the overwrite.
      const store = useChatStore()
      store.config = {}

      const richMsg = {
        id: 500,
        chatid: 10,
        userid: 1,
        type: 'Chat',
        message: 'hello',
        date: '2026-01-01',
        fromuserid: 1,
        touserid: 2,
        chatroom: { id: 10, chattype: 'User2User' },
      }
      mockFetchReviewChatsMT.mockResolvedValueOnce({ chatmessages: [richMsg] })
      await store.fetchReviewChatsMT(10, {})

      // Confirm the rich shape is stored
      expect(store.messageById(500).touserid).toBe(2)

      // Now fetchMessages returns the slim per-room shape (no touserid)
      const slimMsg = {
        id: 500,
        chatid: 10,
        userid: 1,
        type: 'Chat',
        message: 'hello',
        date: '2026-01-01',
      }
      mockFetchMessages.mockResolvedValueOnce([slimMsg])
      // Use force=true so the update() path definitely runs
      await store.fetchMessages(10, true)

      // touserid must still be present — merge, not replace
      expect(store.messageById(500).touserid).toBe(2)
    })
  })

  describe('deleteMessage', () => {
    it('calls API and refetches messages', async () => {
      const store = useChatStore()
      store.config = {}
      mockFetchMessages.mockResolvedValue([])

      await store.deleteMessage(5, 100)

      expect(mockDeleteMessage).toHaveBeenCalledWith(100)
    })
  })

  describe('typing', () => {
    it('calls typing API', async () => {
      const store = useChatStore()
      store.config = {}

      await store.typing(5)

      expect(mockTyping).toHaveBeenCalledWith(5)
    })
  })

  describe('block', () => {
    it('calls block API and refetches chats', async () => {
      const store = useChatStore()
      store.config = {}
      mockListChats.mockResolvedValue([])

      await store.block(5)

      expect(mockBlockChat).toHaveBeenCalledWith(5)
    })
  })

  describe('markUnread', () => {
    it('calls markRead with unread flag and refetches chat', async () => {
      const store = useChatStore()
      store.config = {}
      mockFetchChat.mockResolvedValue({ id: 5, unseen: 1 })

      await store.markUnread(5, 99)

      expect(mockMarkRead).toHaveBeenCalledWith(5, 99, true)
    })
  })

  describe('init', () => {
    it('stores config and reads the current route', () => {
      const store = useChatStore()
      store.init({ apiUrl: 'x' })

      expect(store.config).toEqual({ apiUrl: 'x' })
      expect(store.route).toBeDefined()
    })
  })

  describe('listChatsMT', () => {
    it('builds default params, stores the list and remembers the search term', async () => {
      const store = useChatStore()
      store.config = {}
      mockListChatsMT.mockResolvedValue({
        chatrooms: [{ id: 1, lastdate: '2026-01-01' }],
      })

      await store.listChatsMT(null, null)

      expect(store.list).toEqual([{ id: 1, lastdate: '2026-01-01' }])
      expect(store.listByChatId[1].lastdate).toBe('2026-01-01')
      expect(mockListChatsMT).toHaveBeenCalledWith(
        expect.objectContaining({ summary: true })
      )
    })

    it('does not overwrite an unchanged chat entry (avoids reactivity churn)', async () => {
      const store = useChatStore()
      store.config = {}
      // Mark the existing entry so we can tell whether it survived unreplaced
      // (Pinia's reactivity proxies nested objects, so reference equality via
      // toBe() isn't reliable here — a marker field is).
      store.listByChatId[1] = { id: 1, lastdate: '2026-01-01', marker: 'kept' }
      mockListChatsMT.mockResolvedValue({
        chatrooms: [{ id: 1, lastdate: '2026-01-01' }],
      })

      await store.listChatsMT({ search: null })

      expect(store.listByChatId[1].marker).toBe('kept')
    })

    it('also fetches and merges a selectedChatId not in the list results', async () => {
      const store = useChatStore()
      store.config = {}
      mockListChatsMT.mockResolvedValue({ chatrooms: [] })
      mockFetchChat.mockResolvedValue({ id: 77, snippet: 'hi' })

      await store.listChatsMT({ search: null }, 77)

      expect(store.listByChatId[77].snippet).toBe('hi')
    })

    it('swallows a failed selectedChatId fetch', async () => {
      const store = useChatStore()
      store.config = {}
      mockListChatsMT.mockResolvedValue({ chatrooms: [] })
      mockFetchChat.mockRejectedValueOnce(new Error('gone'))

      await expect(
        store.listChatsMT({ search: null }, 77)
      ).resolves.toBeUndefined()
    })

    it('swallows the listChatsMT error when noerror is set', async () => {
      const store = useChatStore()
      store.config = {}
      mockListChatsMT.mockRejectedValueOnce(new Error('network'))

      await expect(
        store.listChatsMT({ search: null, noerror: true })
      ).resolves.toBeUndefined()
    })

    it('rethrows the listChatsMT error when noerror is not set', async () => {
      const store = useChatStore()
      store.config = {}
      mockListChatsMT.mockRejectedValueOnce(new Error('network'))

      await expect(store.listChatsMT({ search: null })).rejects.toThrow(
        'network'
      )
    })
  })

  describe('fetchLatestChatsMT', () => {
    it('does nothing when nobody is logged in', async () => {
      vi.useFakeTimers()
      mockUseAuthStore.mockReturnValueOnce({ user: null })
      const store = useChatStore()
      store.config = {}

      await store.fetchLatestChatsMT()

      expect(mockUnseenCountMT).not.toHaveBeenCalled()
      vi.clearAllTimers()
      vi.useRealTimers()
    })

    it('updates the badge count and refreshes the list when the count changes', async () => {
      vi.useFakeTimers()
      const store = useChatStore()
      store.config = {}
      store.currentCountMT = 0
      mockUnseenCountMT.mockResolvedValue(3)
      mockListChatsMT.mockResolvedValue({ chatrooms: [] })

      await store.fetchLatestChatsMT()

      expect(store.currentCountMT).toBe(3)
      expect(mockListChatsMT).toHaveBeenCalled()
      vi.clearAllTimers()
      vi.useRealTimers()
    })

    it('does not refresh the list mid-search even when the count changes', async () => {
      vi.useFakeTimers()
      const store = useChatStore()
      store.config = {}
      store.currentCountMT = 0
      store.lastSearchMT = 'searching'
      mockUnseenCountMT.mockResolvedValue(5)

      await store.fetchLatestChatsMT()

      expect(store.currentCountMT).toBe(5)
      expect(mockListChatsMT).not.toHaveBeenCalled()
      vi.clearAllTimers()
      vi.useRealTimers()
    })

    it('swallows errors and still schedules the next poll', async () => {
      vi.useFakeTimers()
      const store = useChatStore()
      store.config = {}
      mockUnseenCountMT.mockRejectedValueOnce(new Error('down'))

      await expect(store.fetchLatestChatsMT()).resolves.toBeUndefined()
      expect(vi.getTimerCount()).toBeGreaterThan(0)
      vi.clearAllTimers()
      vi.useRealTimers()
    })
  })

  describe('removeMessageMT', () => {
    it('removes a message from the chat by id', () => {
      const store = useChatStore()
      store.messages[5] = [{ id: 10 }, { id: 11 }]

      store.removeMessageMT(5, 10)

      expect(store.messages[5][0]).toBeUndefined()
      expect(store.messages[5][1]).toEqual({ id: 11 })
    })

    it('does nothing when the message id is not found', () => {
      const store = useChatStore()
      store.messages[5] = [{ id: 10 }]

      expect(() => store.removeMessageMT(5, 999)).not.toThrow()
      expect(store.messages[5]).toEqual([{ id: 10 }])
    })
  })

  describe('nudge', () => {
    it('nudges and refetches messages', async () => {
      const store = useChatStore()
      store.config = {}
      const fetchSpy = vi.spyOn(store, 'fetchMessages')

      await store.nudge(5)

      expect(mockNudge).toHaveBeenCalledWith(5)
      expect(fetchSpy).toHaveBeenCalledWith(5)
    })
  })

  describe('answerPrompt', () => {
    it('answers a prompt and refetches messages, returning the API result', async () => {
      const store = useChatStore()
      store.config = {}
      mockAnswerPrompt.mockResolvedValueOnce({ ok: true })
      const fetchSpy = vi.spyOn(store, 'fetchMessages')

      const ret = await store.answerPrompt(5, 10, 'yes')

      expect(mockAnswerPrompt).toHaveBeenCalledWith(5, 10, 'yes')
      expect(fetchSpy).toHaveBeenCalledWith(5, true)
      expect(ret).toEqual({ ok: true })
    })
  })

  describe('commonGroups', () => {
    it('returns the common groups from the API', async () => {
      const store = useChatStore()
      store.config = {}
      mockCommonGroups.mockResolvedValueOnce([{ id: 1 }])

      const groups = await store.commonGroups(5)

      expect(mockCommonGroups).toHaveBeenCalledWith(5)
      expect(groups).toEqual([{ id: 1 }])
    })
  })

  describe('report / reportNoGroup', () => {
    it('report sends a report reason and refetches messages', async () => {
      const store = useChatStore()
      store.config = {}
      const fetchSpy = vi.spyOn(store, 'fetchMessages')

      await store.report(5, 'Spam', 'This is spam', 6)

      expect(mockSend).toHaveBeenCalledWith({
        roomid: 5,
        reportreason: 'Spam',
        message: 'This is spam',
        refchatid: 6,
      })
      expect(fetchSpy).toHaveBeenCalledWith(5)
    })

    it('reportNoGroup reports without refetching messages', async () => {
      const store = useChatStore()
      store.config = {}

      await store.reportNoGroup(5, 'Spam', 'comment')

      expect(mockReportNoGroup).toHaveBeenCalledWith(5, 'Spam', 'comment')
    })
  })

  describe('approveAllFutureChat / releaseChat / redactChat', () => {
    it('approveAllFutureChat sends ApproveAllFuture action', async () => {
      const store = useChatStore()
      store.config = {}

      await store.approveAllFutureChat(42)

      expect(mockSendMT).toHaveBeenCalledWith({
        id: 42,
        action: 'ApproveAllFuture',
      })
    })

    it('releaseChat sends Release action', async () => {
      const store = useChatStore()
      store.config = {}

      await store.releaseChat(42)

      expect(mockSendMT).toHaveBeenCalledWith({ id: 42, action: 'Release' })
    })

    it('redactChat sends Redact action', async () => {
      const store = useChatStore()
      store.config = {}

      await store.redactChat(42)

      expect(mockSendMT).toHaveBeenCalledWith({ id: 42, action: 'Redact' })
    })
  })

  describe('openChatToUser', () => {
    it('defaults chattype to User2User', async () => {
      const store = useChatStore()
      store.config = {}
      mockOpenChat.mockResolvedValue({ id: 30 })
      mockFetchChat.mockResolvedValue({ id: 30 })

      const id = await store.openChatToUser({ groupid: 1, userid: 2 })

      expect(id).toBe(30)
      expect(mockOpenChat).toHaveBeenCalledWith(
        { chattype: 'User2User', groupid: 1, userid: 2 },
        expect.any(Function)
      )
    })

    it('honours an explicit chattype override and updateRoster flag', async () => {
      const store = useChatStore()
      store.config = {}
      mockOpenChat.mockResolvedValue({ id: 31 })
      mockFetchChat.mockResolvedValue({ id: 31 })

      const id = await store.openChatToUser({
        chattype: 'Mod2Mod',
        groupid: 1,
        userid: 2,
        updateRoster: true,
      })

      expect(id).toBe(31)
      expect(mockOpenChat).toHaveBeenCalledWith(
        {
          chattype: 'Mod2Mod',
          groupid: 1,
          userid: 2,
          updateRoster: true,
        },
        expect.any(Function)
      )
    })
  })

  describe('pollForChatUpdates', () => {
    it('does nothing (but still reschedules) when nobody is logged in', async () => {
      vi.useFakeTimers()
      mockUseAuthStore.mockReturnValueOnce({ user: null })
      const store = useChatStore()
      store.config = {}

      await store.pollForChatUpdates()

      expect(mockListChats).not.toHaveBeenCalled()
      expect(vi.getTimerCount()).toBeGreaterThan(0)
      vi.clearAllTimers()
      vi.useRealTimers()
    })

    it('extracts the chat id from a /chats/:id route and passes the search term', async () => {
      vi.useFakeTimers()
      const store = useChatStore()
      store.config = {}
      store.route = { path: '/chats/123', query: { search: 'freegle' } }
      mockListChats.mockResolvedValue([])

      await store.pollForChatUpdates()

      expect(mockListChats).toHaveBeenCalledWith(null, 'freegle', 123, false)
      vi.clearAllTimers()
      vi.useRealTimers()
    })

    it('swallows a fetchChats error during poll', async () => {
      vi.useFakeTimers()
      const store = useChatStore()
      store.config = {}
      store.route = { path: '/', query: {} }
      mockListChats.mockRejectedValueOnce(new Error('down'))

      await expect(store.pollForChatUpdates()).resolves.toBeUndefined()
      vi.clearAllTimers()
      vi.useRealTimers()
    })
  })

  describe('rsvp', () => {
    it('rsvps and refetches the chat', async () => {
      const store = useChatStore()
      store.config = {}
      mockFetchChat.mockResolvedValue({ id: 7 })

      await store.rsvp(1, 7, 'yes')

      expect(mockRsvp).toHaveBeenCalledWith(1, 7, 'yes')
      expect(mockFetchChat).toHaveBeenCalledWith(7, false)
    })
  })

  describe('fetchChats branches', () => {
    it('uses the search-since timestamp when previously set', async () => {
      const store = useChatStore()
      store.config = {}
      store.searchSince = '2026-01-01T00:00:00.000Z'
      mockListChats.mockResolvedValue([])

      await store.fetchChats('term', false, null)

      expect(mockListChats).toHaveBeenCalledWith(
        expect.stringContaining('2026-01-01'),
        'term',
        null,
        false
      )
    })

    it('uses listChatsMT when in modtools mode', async () => {
      mockUseMiscStore.mockReturnValueOnce({ modtools: true })
      const store = useChatStore()
      store.config = {}
      mockListChatsMT.mockResolvedValue({ chatrooms: [{ id: 9 }] })

      await store.fetchChats()

      expect(store.list).toEqual([{ id: 9 }])
    })
  })

  describe('dedupeRetriedChatMessages (Discourse 9913)', () => {
    it('drops a retry-duplicate row (same author + identical content, within the window)', () => {
      const msgs = [
        {
          id: 1,
          userid: 5,
          type: 'Default',
          message: 'hi',
          date: '2026-07-15T10:00:00Z',
        },
        {
          id: 2,
          userid: 5,
          type: 'Default',
          message: 'hi',
          date: '2026-07-15T10:00:02Z',
        },
      ]
      expect(dedupeRetriedChatMessages(msgs).map((m) => m.id)).toEqual([1])
    })

    it('drops a retry duplicate even when the other party replied between the two rows', () => {
      const msgs = [
        {
          id: 1,
          userid: 5,
          type: 'Default',
          message: 'hi',
          date: '2026-07-15T10:00:00Z',
        },
        {
          id: 2,
          userid: 9,
          type: 'Default',
          message: 'ok',
          date: '2026-07-15T10:00:01Z',
        },
        {
          id: 3,
          userid: 5,
          type: 'Default',
          message: 'hi',
          date: '2026-07-15T10:00:03Z',
        },
      ]
      expect(dedupeRetriedChatMessages(msgs).map((m) => m.id)).toEqual([1, 2])
    })

    it('keeps two messages that share text but differ in refmsgid (not a duplicate)', () => {
      const msgs = [
        {
          id: 1,
          userid: 5,
          type: 'Interested',
          message: 'yes please',
          refmsgid: 100,
          date: '2026-07-15T10:00:00Z',
        },
        {
          id: 2,
          userid: 5,
          type: 'Interested',
          message: 'yes please',
          refmsgid: 200,
          date: '2026-07-15T10:00:01Z',
        },
      ]
      expect(dedupeRetriedChatMessages(msgs).map((m) => m.id)).toEqual([1, 2])
    })

    it('keeps images/addresses that differ even when the text is empty', () => {
      const msgs = [
        {
          id: 1,
          userid: 5,
          message: '',
          imageid: 11,
          date: '2026-07-15T10:00:00Z',
        },
        {
          id: 2,
          userid: 5,
          message: '',
          imageid: 12,
          date: '2026-07-15T10:00:01Z',
        },
      ]
      expect(dedupeRetriedChatMessages(msgs)).toHaveLength(2)
    })

    it('keeps an identical message sent well after the retry window (a deliberate repeat)', () => {
      const msgs = [
        {
          id: 1,
          userid: 5,
          type: 'Default',
          message: 'thanks',
          date: '2026-07-15T10:00:00Z',
        },
        {
          id: 2,
          userid: 5,
          type: 'Default',
          message: 'thanks',
          date: '2026-07-15T10:00:30Z',
        },
      ]
      expect(dedupeRetriedChatMessages(msgs).map((m) => m.id)).toEqual([1, 2])
    })

    it('is a no-op for empty or single-message arrays', () => {
      expect(dedupeRetriedChatMessages([])).toEqual([])
      const one = [
        { id: 1, userid: 5, message: 'hi', date: '2026-07-15T10:00:00Z' },
      ]
      expect(dedupeRetriedChatMessages(one)).toBe(one)
    })

    it('does not dedupe when timestamps are missing/invalid (needs the retry window)', () => {
      // Without a valid date we cannot establish the retry window, so both rows
      // are kept even if their content keys match.
      const msgs = [
        { id: 10, userid: 5, message: 'hi' },
        { id: 11, userid: 5, message: 'hi' },
      ]
      expect(dedupeRetriedChatMessages(msgs).map((m) => m.id)).toEqual([10, 11])
    })

    it('fetchMessages drops a retry-duplicate before storing it', async () => {
      const store = useChatStore()
      store.config = {}
      mockFetchMessages.mockResolvedValue([
        {
          id: 1,
          userid: 5,
          type: 'Default',
          message: 'hi',
          date: '2026-07-15T10:00:00Z',
        },
        {
          id: 2,
          userid: 5,
          type: 'Default',
          message: 'hi',
          date: '2026-07-15T10:00:02Z',
        },
      ])

      await store.fetchMessages(7)

      expect(store.messages[7].map((m) => m.id)).toEqual([1])
    })
  })
})
