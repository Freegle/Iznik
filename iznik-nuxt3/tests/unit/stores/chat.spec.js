import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

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
const mockFetchReviewChatsMT = vi
  .fn()
  .mockResolvedValue({ chatmessages: [] })

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
    },
  }),
}))

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({
    user: { id: 999 },
  }),
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

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({
    modtools: false,
  }),
}))

vi.mock('~/stores/user', () => ({
  useUserStore: () => ({
    fetch: vi.fn().mockResolvedValue(),
    list: {},
  }),
}))

// Import the REAL store directly — vitest.config.mts aliases ~/stores/chat
// to a global mock for other tests. We need the actual implementation here.
import { useChatStore } from '../../../stores/chat'

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

  describe('send', () => {
    it('updates snippet in listByChatId immediately after sending', async () => {
      const store = useChatStore()
      store.config = {}
      store.listByChatId[10] = { id: 10, snippet: 'old message' }
      mockFetchMessages.mockResolvedValue([])

      await store.send(10, 'new message')

      expect(mockSend).toHaveBeenCalledWith({ roomid: 10, message: 'new message' })
      expect(store.listByChatId[10].snippet).toBe('new message')
    })

    it('includes optional params when provided', async () => {
      const store = useChatStore()
      store.config = {}
      store.listByChatId[10] = { id: 10 }
      mockFetchMessages.mockResolvedValue([])

      await store.send(10, 'hi', 1, 2, 3, true)

      expect(mockSend).toHaveBeenCalledWith({
        roomid: 10,
        message: 'hi',
        addressid: 1,
        imageid: 2,
        refmsgid: 3,
        modnote: true,
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
    it('sets chat status to Online and resets showClosed', async () => {
      const store = useChatStore()
      store.config = {}
      store.listByChatId[7] = { id: 7, status: 'Closed' }
      store.showClosed = true
      mockFetchChat.mockResolvedValue({ id: 7, status: 'Online' })
      mockListChats.mockResolvedValue([])

      await store.unhide(7)

      expect(mockUnHideChat).toHaveBeenCalledWith(7)
      expect(store.listByChatId[7].status).toBe('Online')
      expect(store.showClosed).toBe(false)
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
})
