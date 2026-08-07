import { describe, it, expect, vi, beforeEach } from 'vitest'

import {
  chatCollate,
  setupChat,
  fetchReferencedMessage,
  useChatMessageBase,
} from '~/composables/useChat'

// Covers chatCollate, setupChat, fetchReferencedMessage and the
// useChatMessageBase branches not already exercised by useChat.spec.js
// (which focuses narrowly on the Discourse #9707 profile-image bug).

const mockChatById = vi.fn()
const mockMessagesById = vi.fn()
const mockMessageById = vi.fn()

vi.mock('~/stores/chat', () => ({
  useChatStore: () => ({
    byChatId: mockChatById,
    messagesById: mockMessagesById,
    messageById: mockMessageById,
  }),
}))

const mockUserById = vi.fn()
vi.mock('~/stores/user', () => ({
  useUserStore: () => ({
    byId: mockUserById,
  }),
}))

let mockAuthUser
vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({
    get user() {
      return mockAuthUser
    },
  }),
}))

const mockMessageFetch = vi.fn()
const mockMessageStoreById = vi.fn()
vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    fetch: mockMessageFetch,
    byId: mockMessageStoreById,
  }),
}))

const mockGroupFetch = vi.fn()
vi.mock('~/stores/group', () => ({
  useGroupStore: () => ({
    fetch: mockGroupFetch,
  }),
}))

vi.mock('~/composables/useTwem', () => ({
  twem: (s) => `twem:${s}`,
}))

const mockMilesAway = vi.fn()
vi.mock('~/composables/useDistance', () => ({
  milesAway: (...args) => mockMilesAway(...args),
}))

describe('chatCollate', () => {
  const base = (over) => ({
    userid: 1,
    sameasnext: false,
    message: 'hi',
    type: 'Default',
    refmsg: null,
    replyexpected: false,
    replyreceived: false,
    date: '2024-01-01T00:00:00Z',
    ...over,
  })

  it('returns an empty array for no messages', () => {
    expect(chatCollate([])).toEqual([])
  })

  it('passes through a single message untouched', () => {
    const msgs = [base({ message: 'solo' })]
    expect(chatCollate(msgs)).toEqual(msgs)
  })

  it('collates two consecutive same-user messages within 10 minutes', () => {
    const msgs = [
      base({
        sameasnext: true,
        message: 'first',
        date: '2024-01-01T00:00:00Z',
      }),
      base({ message: 'second', date: '2024-01-01T00:05:00Z' }),
    ]
    const result = chatCollate(msgs)
    expect(result).toHaveLength(1)
    expect(result[0].message).toBe('\nfirst\nsecond')
  })

  it('collates three consecutive messages into one', () => {
    const msgs = [
      base({ sameasnext: true, message: 'a', date: '2024-01-01T00:00:00Z' }),
      base({ sameasnext: true, message: 'b', date: '2024-01-01T00:01:00Z' }),
      base({ message: 'c', date: '2024-01-01T00:02:00Z' }),
    ]
    const result = chatCollate(msgs)
    expect(result).toHaveLength(1)
    expect(result[0].message).toBe('\na\nb\nc')
  })

  it.each([
    ['not flagged sameasnext', { sameasnext: false }],
    ['has no message text', { message: '' }],
    ['is not a Default type', { type: 'Interested' }],
    ['has a refmsg on the first message', { refmsg: { id: 1 } }],
    [
      'is more than 10 minutes before the next',
      { date: '2023-12-31T23:00:00Z' },
    ],
    [
      'is expecting a reply that has not arrived',
      { replyexpected: true, replyreceived: false },
    ],
  ])('does not collate when the first message %s', (label, override) => {
    const msgs = [
      base({
        sameasnext: true,
        message: 'first',
        date: '2024-01-01T00:00:00Z',
        ...override,
      }),
      base({ message: 'second', date: '2024-01-01T00:05:00Z' }),
    ]
    expect(chatCollate(msgs)).toHaveLength(2)
  })

  it('does not collate when the next message has no text', () => {
    const msgs = [
      base({
        sameasnext: true,
        message: 'first',
        date: '2024-01-01T00:00:00Z',
      }),
      base({ message: '', date: '2024-01-01T00:05:00Z' }),
    ]
    expect(chatCollate(msgs)).toHaveLength(2)
  })

  it('does not collate when the next message is a reference message', () => {
    const msgs = [
      base({
        sameasnext: true,
        message: 'first',
        date: '2024-01-01T00:00:00Z',
      }),
      base({
        message: 'second',
        date: '2024-01-01T00:05:00Z',
        refmsg: { id: 2 },
      }),
    ]
    expect(chatCollate(msgs)).toHaveLength(2)
  })

  it('collates when a reply was expected but has since been received', () => {
    const msgs = [
      base({
        sameasnext: true,
        message: 'first',
        date: '2024-01-01T00:00:00Z',
        replyexpected: true,
        replyreceived: true,
      }),
      base({ message: 'second', date: '2024-01-01T00:05:00Z' }),
    ]
    expect(chatCollate(msgs)).toHaveLength(1)
  })
})

describe('setupChat', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockAuthUser = { id: 5, lat: 51.5, lng: -0.1 }
    mockChatById.mockReturnValue({ id: 1, otheruid: 6, unseen: 2 })
    mockUserById.mockReturnValue({ id: 6, lat: 51.6, lng: -0.2 })
    mockMilesAway.mockReturnValue(3)
    mockMessagesById.mockReturnValue([])
  })

  it('exposes the chat, unseen count and computed distance', () => {
    const c = setupChat(1)
    expect(c.chat.value).toEqual({ id: 1, otheruid: 6, unseen: 2 })
    expect(c.unseen.value).toBe(2)
    expect(c.milesaway.value).toBe(3)
    expect(c.milesstring.value).toBe('3 miles away')
    expect(c.chatmessage).toBeNull()
  })

  it('exposes a specific chatmessage when a chatMessageId is given', () => {
    mockMessageById.mockReturnValue({ id: 55, message: 'hi' })
    const c = setupChat(1, 55)
    expect(c.chatmessage.value).toEqual({ id: 55, message: 'hi' })
  })

  it('computes lastfromme from the most recent message I sent', () => {
    mockMessagesById.mockReturnValue([
      { userid: 5, date: '2024-01-01T00:00:00Z' },
      { userid: 6, date: '2024-06-01T00:00:00Z' },
      { userid: 5, date: '2024-03-01T00:00:00Z' },
    ])
    const c = setupChat(1)
    expect(c.mymessages.value).toHaveLength(2)
    expect(c.lastfromme.value).toBe(new Date('2024-03-01T00:00:00Z').getTime())
  })

  it('is too soon to nudge just after I last messaged', () => {
    mockMessagesById.mockReturnValue([
      { userid: 5, date: new Date().toISOString() },
    ])
    const c = setupChat(1)
    expect(c.tooSoonToNudge.value).toBe(true)
  })

  it('is not too soon to nudge if I have never messaged', () => {
    const c = setupChat(1)
    expect(c.tooSoonToNudge.value).toBe(false)
  })

  it('returns null for chat and otheruser when there is no selected chat id', () => {
    const c = setupChat(null)
    expect(c.chat.value).toBeNull()
    expect(c.otheruser.value).toBeNull()
  })
})

describe('fetchReferencedMessage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('does nothing when the chat message has no reference', async () => {
    mockMessageById.mockReturnValue({ id: 1, refmsgid: null })
    await fetchReferencedMessage(1, 1)
    expect(mockMessageFetch).not.toHaveBeenCalled()
  })

  it('does nothing when the chat message itself is not found', async () => {
    mockMessageById.mockReturnValue(null)
    await fetchReferencedMessage(1, 999)
    expect(mockMessageFetch).not.toHaveBeenCalled()
  })

  it('fetches the referenced message when present', async () => {
    mockMessageById.mockReturnValue({ id: 1, refmsgid: 42 })
    mockMessageFetch.mockResolvedValue(undefined)
    await fetchReferencedMessage(1, 1)
    expect(mockMessageFetch).toHaveBeenCalledWith(42)
  })

  it('swallows a fetch failure', async () => {
    mockMessageById.mockReturnValue({ id: 1, refmsgid: 42 })
    mockMessageFetch.mockRejectedValue(new Error('boom'))
    await expect(fetchReferencedMessage(1, 1)).resolves.toBeUndefined()
  })
})

describe('useChatMessageBase — remaining branches', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockAuthUser = {
      id: 5,
      profile: { paththumb: '/me.jpg' },
      displayname: 'Me',
    }
    mockGroupFetch.mockResolvedValue(undefined)
    mockMessageFetch.mockResolvedValue(undefined)
  })

  describe('isEmptyMessage / emessage', () => {
    it('flags a blank message as empty', () => {
      mockChatById.mockReturnValue({ id: 1, chattype: 'User2User' })
      mockMessageById.mockReturnValue({ id: 1, userid: 5, message: '   ' })
      const { isEmptyMessage, emessage } = useChatMessageBase(1, 1)
      expect(isEmptyMessage.value).toBe(true)
      expect(emessage.value).toBe('(empty message)')
    })

    it('collapses repeated blank lines and passes the rest through twem', () => {
      mockChatById.mockReturnValue({ id: 1, chattype: 'User2User' })
      mockMessageById.mockReturnValue({
        id: 1,
        userid: 5,
        message: 'hello\n\n\nworld',
      })
      const { isEmptyMessage, emessage } = useChatMessageBase(1, 1)
      expect(isEmptyMessage.value).toBe(false)
      expect(emessage.value).toBe('twem:hello\n\nworld')
    })
  })

  describe('messageIsFromCurrentUser', () => {
    it('with no pov, true when the message is mine', () => {
      mockChatById.mockReturnValue({ id: 1, chattype: 'User2User' })
      mockMessageById.mockReturnValue({ id: 1, userid: 5 })
      const { messageIsFromCurrentUser } = useChatMessageBase(1, 1)
      expect(messageIsFromCurrentUser.value).toBe(true)
    })

    it('with no pov, false when the message is not mine', () => {
      mockChatById.mockReturnValue({ id: 1, chattype: 'User2User' })
      mockMessageById.mockReturnValue({ id: 1, userid: 6 })
      const { messageIsFromCurrentUser } = useChatMessageBase(1, 1)
      expect(messageIsFromCurrentUser.value).toBe(false)
    })

    it('with pov on a User2User chat, left/right flips by whether pov is user1', () => {
      mockChatById.mockReturnValue({
        id: 1,
        chattype: 'User2User',
        user1: 11,
        user2: 22,
      })
      mockMessageById.mockReturnValue({ id: 1, userid: 11 })
      const asUser1 = useChatMessageBase(1, 1, 11)
      expect(asUser1.messageIsFromCurrentUser.value).toBe(true)

      const asUser2 = useChatMessageBase(1, 1, 22)
      expect(asUser2.messageIsFromCurrentUser.value).toBe(false)
    })

    it('with pov on a User2Mod chat, only the member (user1) is "from current user"', () => {
      mockChatById.mockReturnValue({
        id: 1,
        chattype: 'User2Mod',
        user1: 11,
      })
      mockMessageById.mockReturnValue({ id: 1, userid: 11 })
      const { messageIsFromCurrentUser } = useChatMessageBase(1, 1, 99)
      expect(messageIsFromCurrentUser.value).toBe(false)

      mockMessageById.mockReturnValue({ id: 1, userid: 99 })
      const { messageIsFromCurrentUser: fromMod } = useChatMessageBase(1, 1, 99)
      expect(fromMod.value).toBe(true)
    })
  })

  describe('refmsgid / refmsg', () => {
    it('prefers an embedded refmsg object over refmsgid', () => {
      mockChatById.mockReturnValue({ id: 1, chattype: 'User2User' })
      mockMessageById.mockReturnValue({
        id: 1,
        userid: 5,
        refmsgid: 10,
        refmsg: { id: 99, subject: 'Sofa' },
      })
      const { refmsgid, refmsg } = useChatMessageBase(1, 1)
      expect(refmsgid.value).toBe(99)
      expect(refmsg.value).toEqual({ id: 99, subject: 'Sofa' })
    })

    it('falls back to refmsgid and looks it up in the message store', () => {
      mockChatById.mockReturnValue({ id: 1, chattype: 'User2User' })
      mockMessageById.mockReturnValue({ id: 1, userid: 5, refmsgid: 10 })
      mockMessageStoreById.mockReturnValue({ id: 10, subject: 'Chair' })
      const { refmsgid, refmsg } = useChatMessageBase(1, 1)
      expect(refmsgid.value).toBe(10)
      expect(refmsg.value).toEqual({ id: 10, subject: 'Chair' })
    })

    it('is null when there is no reference at all', () => {
      mockChatById.mockReturnValue({ id: 1, chattype: 'User2User' })
      mockMessageById.mockReturnValue({ id: 1, userid: 5 })
      const { refmsgid, refmsg } = useChatMessageBase(1, 1)
      expect(refmsgid.value).toBeUndefined()
      expect(refmsg.value).toBeNull()
    })
  })

  describe('me / realMe / otheruser under pov', () => {
    it('me is realMe (authStore.user) when there is no pov', () => {
      mockChatById.mockReturnValue({ id: 1, chattype: 'User2User' })
      mockMessageById.mockReturnValue({ id: 1, userid: 5 })
      const { me, realMe, myid } = useChatMessageBase(1, 1)
      expect(me.value).toBe(mockAuthUser)
      expect(realMe.value).toBe(mockAuthUser)
      expect(myid.value).toBe(5)
    })

    it('me is looked up via the store when pov matches user1 or user2', () => {
      mockChatById.mockReturnValue({
        id: 1,
        chattype: 'User2User',
        user1: 11,
        user2: 22,
      })
      mockMessageById.mockReturnValue({ id: 1, userid: 11 })
      mockUserById.mockImplementation((id) => ({ id, displayname: `U${id}` }))

      const asUser1 = useChatMessageBase(1, 1, 11)
      expect(asUser1.me.value).toEqual({ id: 11, displayname: 'U11' })

      const asUser2 = useChatMessageBase(1, 1, 22)
      expect(asUser2.me.value).toEqual({ id: 22, displayname: 'U22' })
    })

    it('me falls back to realMe when pov matches neither participant', () => {
      mockChatById.mockReturnValue({
        id: 1,
        chattype: 'User2User',
        user1: 11,
        user2: 22,
      })
      mockMessageById.mockReturnValue({ id: 1, userid: 11 })
      const { me } = useChatMessageBase(1, 1, 99)
      expect(me.value).toBe(mockAuthUser)
    })

    it('otheruser under pov resolves to the participant that is not pov', () => {
      mockChatById.mockReturnValue({
        id: 1,
        chattype: 'User2User',
        user1: 11,
        user2: 22,
      })
      mockMessageById.mockReturnValue({ id: 1, userid: 11 })
      mockUserById.mockImplementation((id) => ({ id, displayname: `U${id}` }))

      const asUser1 = useChatMessageBase(1, 1, 11)
      expect(asUser1.otheruser.value).toEqual({ id: 22, displayname: 'U22' })

      const asUser2 = useChatMessageBase(1, 1, 22)
      expect(asUser2.otheruser.value).toEqual({ id: 11, displayname: 'U11' })
    })

    it('otheruser under pov is null when the counterpart id is missing', () => {
      mockChatById.mockReturnValue({
        id: 1,
        chattype: 'User2User',
        user1: 11,
        user2: null,
      })
      mockMessageById.mockReturnValue({ id: 1, userid: 11 })
      const { otheruser } = useChatMessageBase(1, 1, 11)
      expect(otheruser.value).toBeNull()
    })
  })

  describe('chatMessageProfileImage / chatMessageProfileName', () => {
    it('uses my own profile image and name for my own message', () => {
      mockChatById.mockReturnValue({ id: 1, chattype: 'User2User' })
      mockMessageById.mockReturnValue({ id: 1, userid: 5 })
      const { chatMessageProfileImage, chatMessageProfileName } =
        useChatMessageBase(1, 1)
      expect(chatMessageProfileImage.value).toBe('/me.jpg')
      expect(chatMessageProfileName.value).toBe('Me')
    })

    it('falls back to the group/member profile for a User2Mod chat', () => {
      mockChatById.mockReturnValue({
        id: 1,
        chattype: 'User2Mod',
        user1: 11,
        otheruid: 11,
        icon: '/group.jpg',
      })
      mockMessageById.mockReturnValue({ id: 1, userid: 11 })
      mockUserById.mockReturnValue({
        id: 11,
        displayname: 'Member',
        profile: { paththumb: '/member.jpg' },
      })
      const { chatMessageProfileImage, chatMessageProfileName } =
        useChatMessageBase(1, 1)
      expect(chatMessageProfileImage.value).toBe('/group.jpg')
      expect(chatMessageProfileName.value).toBe('Member')
    })
  })

  it('regexEmail matches an email address', () => {
    mockChatById.mockReturnValue({ id: 1, chattype: 'User2User' })
    mockMessageById.mockReturnValue({ id: 1, userid: 5 })
    const { regexEmail } = useChatMessageBase(1, 1)
    expect('contact me at test@example.com please').toMatch(regexEmail.value)
  })

  it('brokenImage swaps in the default profile picture', () => {
    mockChatById.mockReturnValue({ id: 1, chattype: 'User2User' })
    mockMessageById.mockReturnValue({ id: 1, userid: 5 })
    const { brokenImage } = useChatMessageBase(1, 1)
    const event = { target: { src: '/broken.jpg' } }
    brokenImage(event)
    expect(event.target.src).toBe('/defaultprofile.png')
  })

  describe('refetch', () => {
    it('refetches the referenced message when there is one', () => {
      mockChatById.mockReturnValue({ id: 1, chattype: 'User2User' })
      mockMessageById.mockReturnValue({ id: 1, userid: 5, refmsgid: 42 })
      const { refetch } = useChatMessageBase(1, 1)
      refetch()
      expect(mockMessageFetch).toHaveBeenCalledWith(42)
    })

    it('does nothing when there is no reference to refetch', () => {
      mockChatById.mockReturnValue({ id: 1, chattype: 'User2User' })
      mockMessageById.mockReturnValue({ id: 1, userid: 5 })
      const { refetch } = useChatMessageBase(1, 1)
      refetch()
      expect(mockMessageFetch).not.toHaveBeenCalled()
    })
  })

  describe('fetchMessage', () => {
    it('does nothing when there is no reference', async () => {
      mockChatById.mockReturnValue({ id: 1, chattype: 'User2User' })
      mockMessageById.mockReturnValue({ id: 1, userid: 5 })
      const { fetchMessage } = useChatMessageBase(1, 1)
      await fetchMessage()
      expect(mockMessageFetch).not.toHaveBeenCalled()
    })

    it('fetches the referenced message and each of its groups', async () => {
      mockChatById.mockReturnValue({ id: 1, chattype: 'User2User' })
      mockMessageById.mockReturnValue({ id: 1, userid: 5, refmsgid: 42 })
      mockMessageStoreById.mockReturnValue({
        id: 42,
        groups: [{ groupid: 100 }, { groupid: 200 }],
      })
      const { fetchMessage } = useChatMessageBase(1, 1)
      await fetchMessage()
      expect(mockMessageFetch).toHaveBeenCalledWith(42)
      await vi.waitFor(() => expect(mockGroupFetch).toHaveBeenCalledTimes(2))
      expect(mockGroupFetch).toHaveBeenCalledWith(100)
      expect(mockGroupFetch).toHaveBeenCalledWith(200)
    })

    it('fetches the referenced message but skips groups when not found', async () => {
      mockChatById.mockReturnValue({ id: 1, chattype: 'User2User' })
      mockMessageById.mockReturnValue({ id: 1, userid: 5, refmsgid: 42 })
      mockMessageStoreById.mockReturnValue(null)
      const { fetchMessage } = useChatMessageBase(1, 1)
      await fetchMessage()
      expect(mockGroupFetch).not.toHaveBeenCalled()
    })

    it('swallows a fetch failure', async () => {
      mockChatById.mockReturnValue({ id: 1, chattype: 'User2User' })
      mockMessageById.mockReturnValue({ id: 1, userid: 5, refmsgid: 42 })
      mockMessageFetch.mockRejectedValue(new Error('down'))
      const { fetchMessage } = useChatMessageBase(1, 1)
      await expect(fetchMessage()).resolves.toBeUndefined()
    })
  })
})
