import { describe, it, expect, vi, beforeEach } from 'vitest'
import { MT_EMAIL_REGEX } from '~/constants'

// Integration tests that exercise the REAL modtools chat composables
// (setupChatMT, fetchReferencedMessageMT, useChatMessageBaseMT) end to end,
// rather than a mirrored re-implementation of their logic — the mirrored
// helper in useChatMessageAlignment.spec.js doesn't catch divergence
// between the helper and the actual production computed. This file is the
// catching layer for modtools/composables/useChatMT.js, matching the
// pattern already used for the member-facing useChat.js in useChat.spec.js.

let mockChat
let mockMessages
let mockUsers
let mockAuthUser
let messageStoreFetchSpy
let messageStoreByIdSpy
let groupStoreFetchSpy
let twemSpy
let milesAwaySpy

const resetMocks = () => {
  mockChat = {
    id: 1,
    chattype: 'User2Mod',
    user1: 11,
    user2: 0,
    otheruid: 0,
    icon: '/group-icon.jpg',
    unseen: 2,
  }

  mockMessages = {}

  mockUsers = {
    11: {
      id: 11,
      displayname: 'Member Bob',
      profile: { turl: '', paththumb: '' },
      lat: 51.5,
      lng: -0.1,
    },
    22: {
      id: 22,
      displayname: 'Other User',
      profile: { turl: '', paththumb: '' },
    },
    99: {
      id: 99,
      displayname: 'Mod Alice',
      profile: { turl: '/mod-alice.jpg' },
    },
  }

  mockAuthUser = {
    id: 99,
    displayname: 'Mod Alice',
    lat: 51.5,
    lng: -0.2,
    profile: { turl: '', paththumb: '' },
  }

  messageStoreFetchSpy = vi.fn(async () => {})
  messageStoreByIdSpy = vi.fn(() => null)
  groupStoreFetchSpy = vi.fn(async () => {})
  twemSpy = vi.fn((s) => s)
  milesAwaySpy = vi.fn(() => 3)
}

resetMocks()

vi.mock('~/stores/chat', () => ({
  useChatStore: () => ({
    byChatId: (id) => (id === mockChat.id ? mockChat : null),
    messageById: (id) => mockMessages[id] || null,
    messagesById: (id) =>
      Object.values(mockMessages).filter((m) => m.chatid === id),
  }),
}))

vi.mock('~/stores/user', () => ({
  useUserStore: () => ({
    byId: (id) => mockUsers[id] || null,
  }),
}))

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({
    user: mockAuthUser,
  }),
}))

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    fetch: (...args) => messageStoreFetchSpy(...args),
    byId: (...args) => messageStoreByIdSpy(...args),
  }),
}))

vi.mock('~/stores/group', () => ({
  useGroupStore: () => ({
    fetch: (...args) => groupStoreFetchSpy(...args),
  }),
}))

vi.mock('~/composables/useTwem', () => ({
  twem: (...args) => twemSpy(...args),
}))

vi.mock('~/composables/useDistance', () => ({
  milesAway: (...args) => milesAwaySpy(...args),
}))

let setupChatMT
let fetchReferencedMessageMT
let useChatMessageBaseMT

beforeEach(async () => {
  resetMocks()
  vi.resetModules()
  ;({ setupChatMT, fetchReferencedMessageMT, useChatMessageBaseMT } =
    await import('~/modtools/composables/useChatMT'))
})

describe('setupChatMT', () => {
  it('exposes the chat, chatmessages and chatStore for the given chat id', () => {
    mockMessages[201] = { id: 201, chatid: 1, userid: 11, date: '2026-01-01' }
    const { chat, chatmessages, chatStore } = setupChatMT(1, null)

    expect(chat.value).toBe(mockChat)
    expect(chatmessages.value).toEqual([mockMessages[201]])
    expect(chatStore).toBeTruthy()
  })

  it('forwards unseen from the chat record', () => {
    mockChat.unseen = 5
    const { unseen } = setupChatMT(1, null)
    expect(unseen.value).toBe(5)
  })

  describe('mymessages / lastfromme / tooSoonToNudge', () => {
    it('lastfromme is 0 and tooSoonToNudge is false when I have never posted', () => {
      mockMessages[301] = { id: 301, chatid: 1, userid: 22, date: '2026-01-01' }
      const { mymessages, lastfromme, tooSoonToNudge } = setupChatMT(1, null)

      expect(mymessages.value).toEqual([])
      expect(lastfromme.value).toBe(0)
      expect(tooSoonToNudge.value).toBe(false)
    })

    it('tooSoonToNudge is true when my last message was moments ago', () => {
      mockMessages[302] = {
        id: 302,
        chatid: 1,
        userid: 99,
        date: new Date().toISOString(),
      }
      const { mymessages, lastfromme, tooSoonToNudge } = setupChatMT(1, null)

      expect(mymessages.value).toHaveLength(1)
      expect(lastfromme.value).toBeGreaterThan(0)
      expect(tooSoonToNudge.value).toBe(true)
    })

    it('tooSoonToNudge is false when my last message was days ago', () => {
      mockMessages[303] = {
        id: 303,
        chatid: 1,
        userid: 99,
        date: new Date(Date.now() - 3 * 24 * 60 * 60 * 1000).toISOString(),
      }
      const { tooSoonToNudge } = setupChatMT(1, null)

      expect(tooSoonToNudge.value).toBe(false)
    })

    it('lastfromme picks the MOST RECENT of several messages from me', () => {
      const older = new Date(Date.now() - 2 * 60 * 60 * 1000).toISOString()
      const newer = new Date(Date.now() - 60 * 1000).toISOString()
      mockMessages[304] = { id: 304, chatid: 1, userid: 99, date: older }
      mockMessages[305] = { id: 305, chatid: 1, userid: 99, date: newer }

      const { lastfromme } = setupChatMT(1, null)

      expect(lastfromme.value).toBe(new Date(newer).getTime())
    })
  })

  describe('milesaway / milesstring', () => {
    it('singularises "mile" when milesAway resolves to 1', () => {
      milesAwaySpy.mockReturnValue(1)
      const { milesaway, milesstring } = setupChatMT(1, null)

      expect(milesaway.value).toBe(1)
      expect(milesstring.value).toBe('1 mile away')
    })

    it('pluralises "miles" when milesAway resolves to more than 1', () => {
      milesAwaySpy.mockReturnValue(7)
      const { milesstring } = setupChatMT(1, null)

      expect(milesstring.value).toBe('7 miles away')
    })
  })

  describe('chatmessage', () => {
    it('is null (not a computed) when no chatMessageId is given', () => {
      const { chatmessage } = setupChatMT(1, null)
      expect(chatmessage).toBeNull()
    })

    it('resolves the referenced message by id when chatMessageId is given', () => {
      mockMessages[401] = { id: 401, chatid: 1, userid: 11 }
      const { chatmessage } = setupChatMT(1, 401)
      expect(chatmessage.value).toBe(mockMessages[401])
    })
  })

  describe('chat', () => {
    it('is null when no chat id is given at all', () => {
      const { chat } = setupChatMT(null, null)
      expect(chat.value).toBeNull()
    })
  })
})

describe('fetchReferencedMessageMT', () => {
  it('does nothing when the chat message does not exist', async () => {
    await fetchReferencedMessageMT(1, 999)
    expect(messageStoreFetchSpy).not.toHaveBeenCalled()
  })

  it('does nothing when the chat message has no refmsgid', async () => {
    mockMessages[501] = { id: 501, chatid: 1, userid: 11 }
    await fetchReferencedMessageMT(1, 501)
    expect(messageStoreFetchSpy).not.toHaveBeenCalled()
  })

  it('fetches the referenced message when refmsgid is set', async () => {
    mockMessages[502] = { id: 502, chatid: 1, userid: 11, refmsgid: 777 }
    await fetchReferencedMessageMT(1, 502)
    expect(messageStoreFetchSpy).toHaveBeenCalledWith(777)
  })

  it('swallows a failed fetch rather than throwing', async () => {
    const consoleSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
    messageStoreFetchSpy.mockRejectedValueOnce(new Error('network down'))
    mockMessages[503] = { id: 503, chatid: 1, userid: 11, refmsgid: 778 }

    await expect(fetchReferencedMessageMT(1, 503)).resolves.toBeUndefined()
    expect(consoleSpy).toHaveBeenCalled()

    consoleSpy.mockRestore()
  })
})

describe('useChatMessageBaseMT', () => {
  describe('emessage', () => {
    it('returns an empty string when there is no message', () => {
      const { emessage } = useChatMessageBaseMT(1, 999, null)
      expect(emessage.value).toBe('')
    })

    it('trims whitespace and collapses runs of 3+ newlines to a blank line', () => {
      mockMessages[601] = {
        id: 601,
        chatid: 1,
        userid: 11,
        message: '  Hello\n\n\nWorld  ',
      }
      const { emessage } = useChatMessageBaseMT(1, 601, null)
      expect(emessage.value).toBe('Hello\n\nWorld')
    })

    it('passes the trimmed text through twem', () => {
      mockMessages[602] = { id: 602, chatid: 1, userid: 11, message: 'hi' }
      twemSpy.mockImplementation((s) => `[twem]${s}`)

      const { emessage } = useChatMessageBaseMT(1, 602, null)

      expect(emessage.value).toBe('[twem]hi')
    })

    it('logs and recovers when twem throws on the first pass', () => {
      const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
      mockMessages[603] = { id: 603, chatid: 1, userid: 11, message: 'boom' }
      twemSpy
        .mockImplementationOnce(() => {
          throw new Error('twem exploded')
        })
        .mockImplementationOnce((s) => s)

      const { emessage } = useChatMessageBaseMT(1, 603, null)

      expect(emessage.value).toBe('boom')
      expect(consoleSpy).toHaveBeenCalled()

      consoleSpy.mockRestore()
    })
  })

  describe('messageIsFromCurrentUser', () => {
    it.each([
      // chattype, pov, msgUserId, expected, description
      ['User2User', 11, 11, true, 'pov=user1, message from user1 → true'],
      ['User2User', 11, 22, false, 'pov=user1, message from user2 → false'],
      ['User2User', 22, 11, false, 'pov=user2, message from user1 → false'],
      ['User2User', 22, 22, true, 'pov=user2, message from user2 → true'],
      ['User2Mod', null, 11, false, 'User2Mod, message from member → false'],
      ['User2Mod', null, 99, true, 'User2Mod, message from a mod → true'],
    ])(
      '%s pov=%s msgUserId=%s → %s (%s)',
      (chattype, pov, msgUserId, expected) => {
        mockChat.chattype = chattype
        mockChat.user1 = 11
        mockChat.user2 = 22
        mockMessages[700 + msgUserId] = {
          id: 700 + msgUserId,
          chatid: 1,
          userid: msgUserId,
        }

        const { messageIsFromCurrentUser } = useChatMessageBaseMT(
          1,
          700 + msgUserId,
          pov
        )

        expect(messageIsFromCurrentUser.value).toBe(expected)
      }
    )
  })

  describe('refmsgid / refmsg', () => {
    it('reads the id off an already-populated refmsg object', () => {
      mockMessages[801] = {
        id: 801,
        chatid: 1,
        userid: 11,
        refmsg: { id: 555, subject: 'Old sofa' },
      }
      const { refmsgid, refmsg } = useChatMessageBaseMT(1, 801, null)

      expect(refmsgid.value).toBe(555)
      expect(refmsg.value).toEqual({ id: 555, subject: 'Old sofa' })
      expect(messageStoreByIdSpy).not.toHaveBeenCalled()
    })

    it('falls back to refmsgid and looks the message up in the store', () => {
      messageStoreByIdSpy.mockReturnValue({ id: 556, subject: 'Old chair' })
      mockMessages[802] = { id: 802, chatid: 1, userid: 11, refmsgid: 556 }

      const { refmsgid, refmsg } = useChatMessageBaseMT(1, 802, null)

      expect(refmsgid.value).toBe(556)
      expect(refmsg.value).toEqual({ id: 556, subject: 'Old chair' })
      expect(messageStoreByIdSpy).toHaveBeenCalledWith(556)
    })

    it('is undefined/null when there is no referenced message at all', () => {
      mockMessages[803] = { id: 803, chatid: 1, userid: 11 }
      const { refmsgid, refmsg } = useChatMessageBaseMT(1, 803, null)

      expect(refmsgid.value).toBeFalsy()
      expect(refmsg.value).toBeNull()
    })
  })

  describe('me / myid', () => {
    it('is the logged-in user when no pov is given', () => {
      mockMessages[901] = { id: 901, chatid: 1, userid: 11 }
      const { me, myid } = useChatMessageBaseMT(1, 901, null)

      expect(me.value).toBe(mockAuthUser)
      expect(myid.value).toBe(99)
    })

    it('resolves via the user store when pov matches user1', () => {
      mockChat.user1 = 11
      mockChat.user2 = 22
      mockMessages[902] = { id: 902, chatid: 1, userid: 11 }

      const { me, myid } = useChatMessageBaseMT(1, 902, 11)

      expect(me.value).toBe(mockUsers[11])
      expect(myid.value).toBe(11)
    })

    it('resolves via the user store when pov matches user2', () => {
      mockChat.user1 = 11
      mockChat.user2 = 22
      mockMessages[903] = { id: 903, chatid: 1, userid: 11 }

      const { me } = useChatMessageBaseMT(1, 903, 22)

      expect(me.value).toBe(mockUsers[22])
    })

    it('falls back to realMe when pov does not match either participant', () => {
      mockChat.user1 = 11
      mockChat.user2 = 22
      mockMessages[904] = { id: 904, chatid: 1, userid: 11 }

      const { me } = useChatMessageBaseMT(1, 904, 4321)

      expect(me.value).toBe(mockAuthUser)
    })

    it('falls back to realMe when pov matches a participant but the store has no record', () => {
      mockChat.user1 = 11
      mockChat.user2 = 22
      mockUsers[11] = undefined
      mockMessages[905] = { id: 905, chatid: 1, userid: 22 }

      const { me } = useChatMessageBaseMT(1, 905, 11)

      expect(me.value).toBe(mockAuthUser)
    })

    it('falls back to the user1id/user2id fields when user1/user2 are unset', () => {
      mockChat.user1 = 0
      mockChat.user2 = 0
      mockChat.user1id = 11
      mockChat.user2id = 22
      mockMessages[906] = { id: 906, chatid: 1, userid: 11 }

      const { me } = useChatMessageBaseMT(1, 906, 11)

      expect(me.value).toBe(mockUsers[11])
    })
  })

  describe('chatMessageProfileImage', () => {
    it('uses my turl when the message is not from user1', () => {
      mockAuthUser.profile.turl = '/me.jpg'
      mockMessages[1001] = { id: 1001, chatid: 1, userid: 99 } // not user1 (11)

      const { chatMessageProfileImage } = useChatMessageBaseMT(1, 1001, null)

      expect(chatMessageProfileImage.value).toBe('/me.jpg')
    })

    it('falls back to my paththumb when turl is empty', () => {
      mockAuthUser.profile = { turl: '', paththumb: '/me-thumb.jpg' }
      mockMessages[1002] = { id: 1002, chatid: 1, userid: 99 }

      const { chatMessageProfileImage } = useChatMessageBaseMT(1, 1002, null)

      expect(chatMessageProfileImage.value).toBe('/me-thumb.jpg')
    })

    it("uses the other user's turl when the message IS from user1", () => {
      mockChat.otheruid = 22
      mockUsers[22].profile.turl = '/other.jpg'
      mockMessages[1003] = { id: 1003, chatid: 1, userid: 11 } // IS user1

      const { chatMessageProfileImage } = useChatMessageBaseMT(1, 1003, null)

      expect(chatMessageProfileImage.value).toBe('/other.jpg')
    })

    it('falls back to the chat icon when the other user has no profile image', () => {
      mockChat.otheruid = 22
      mockChat.icon = '/group-icon.jpg'
      mockUsers[22].profile = { turl: '', paththumb: '' }
      mockMessages[1004] = { id: 1004, chatid: 1, userid: 11 }

      const { chatMessageProfileImage } = useChatMessageBaseMT(1, 1004, null)

      expect(chatMessageProfileImage.value).toBe('/group-icon.jpg')
    })
  })

  describe('chatMessageProfileName', () => {
    it('uses my displayname when the message is not from user1', () => {
      mockMessages[1101] = { id: 1101, chatid: 1, userid: 99 }
      const { chatMessageProfileName } = useChatMessageBaseMT(1, 1101, null)
      expect(chatMessageProfileName.value).toBe('Mod Alice')
    })

    it("uses the other user's displayname when the message IS from user1", () => {
      mockChat.otheruid = 22
      mockMessages[1102] = { id: 1102, chatid: 1, userid: 11 }
      const { chatMessageProfileName } = useChatMessageBaseMT(1, 1102, null)
      expect(chatMessageProfileName.value).toBe('Other User')
    })
  })

  describe('regexEmail / regexEmailMT', () => {
    it('regexEmail matches a plain email address', () => {
      mockMessages[1201] = { id: 1201, chatid: 1, userid: 11 }
      const { regexEmail } = useChatMessageBaseMT(1, 1201, null)

      expect(regexEmail.value).toBeInstanceOf(RegExp)
      expect(
        'contact me at bob@example.com please'.match(regexEmail.value)[0]
      ).toBe('bob@example.com')
    })

    it('regexEmailMT mirrors the shared MT_EMAIL_REGEX constant', () => {
      mockMessages[1202] = { id: 1202, chatid: 1, userid: 11 }
      const { regexEmailMT } = useChatMessageBaseMT(1, 1202, null)

      expect(regexEmailMT.value).toBe(MT_EMAIL_REGEX.toString())
    })
  })

  describe('otheruser', () => {
    it('is null when the chat has no otheruid', () => {
      mockChat.otheruid = 0
      mockMessages[1301] = { id: 1301, chatid: 1, userid: 11 }
      const { otheruser } = useChatMessageBaseMT(1, 1301, null)
      expect(otheruser.value).toBeNull()
    })

    it('resolves the other user from the store when otheruid is set', () => {
      mockChat.otheruid = 22
      mockMessages[1302] = { id: 1302, chatid: 1, userid: 11 }
      const { otheruser } = useChatMessageBaseMT(1, 1302, null)
      expect(otheruser.value).toBe(mockUsers[22])
    })
  })

  describe('brokenImage', () => {
    it('replaces the broken image src with the default profile image', () => {
      mockMessages[1401] = { id: 1401, chatid: 1, userid: 11 }
      const { brokenImage } = useChatMessageBaseMT(1, 1401, null)

      const event = { target: { src: '/broken.jpg' } }
      brokenImage(event)

      expect(event.target.src).toBe('/defaultprofile.png')
    })
  })

  describe('refetch', () => {
    it('re-fetches the referenced message when refmsgid is present', () => {
      mockMessages[1501] = { id: 1501, chatid: 1, userid: 11, refmsgid: 888 }
      const { refetch } = useChatMessageBaseMT(1, 1501, null)

      refetch()

      expect(messageStoreFetchSpy).toHaveBeenCalledWith(888)
    })

    it('does nothing when there is no refmsgid', () => {
      mockMessages[1502] = { id: 1502, chatid: 1, userid: 11 }
      const { refetch } = useChatMessageBaseMT(1, 1502, null)

      refetch()

      expect(messageStoreFetchSpy).not.toHaveBeenCalled()
    })
  })

  describe('fetchMessage', () => {
    it('does nothing when there is no refmsgid', async () => {
      mockMessages[1601] = { id: 1601, chatid: 1, userid: 11 }
      const { fetchMessage } = useChatMessageBaseMT(1, 1601, null)

      await fetchMessage()

      expect(messageStoreFetchSpy).not.toHaveBeenCalled()
      expect(groupStoreFetchSpy).not.toHaveBeenCalled()
    })

    it('fetches the referenced message and its groups when found', async () => {
      mockMessages[1602] = { id: 1602, chatid: 1, userid: 11, refmsgid: 889 }
      messageStoreByIdSpy.mockReturnValue({
        id: 889,
        groups: [{ groupid: 10 }, { groupid: 20 }],
      })

      const { fetchMessage } = useChatMessageBaseMT(1, 1602, null)
      await fetchMessage()

      expect(messageStoreFetchSpy).toHaveBeenCalledWith(889)
      expect(groupStoreFetchSpy).toHaveBeenCalledWith(10)
      expect(groupStoreFetchSpy).toHaveBeenCalledWith(20)
    })

    it('skips the groups loop when the referenced message is not found', async () => {
      mockMessages[1603] = { id: 1603, chatid: 1, userid: 11, refmsgid: 890 }
      messageStoreByIdSpy.mockReturnValue(null)

      const { fetchMessage } = useChatMessageBaseMT(1, 1603, null)
      await fetchMessage()

      expect(messageStoreFetchSpy).toHaveBeenCalledWith(890)
      expect(groupStoreFetchSpy).not.toHaveBeenCalled()
    })

    it('swallows a failed fetch rather than throwing', async () => {
      const consoleSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      mockMessages[1604] = { id: 1604, chatid: 1, userid: 11, refmsgid: 891 }
      messageStoreFetchSpy.mockRejectedValueOnce(new Error('network down'))

      const { fetchMessage } = useChatMessageBaseMT(1, 1604, null)

      await expect(fetchMessage()).resolves.toBeUndefined()
      expect(consoleSpy).toHaveBeenCalled()

      consoleSpy.mockRestore()
    })
  })
})
