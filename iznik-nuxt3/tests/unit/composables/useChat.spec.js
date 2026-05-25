import { describe, it, expect, vi, beforeEach } from 'vitest'

// Integration test that exercises the REAL useChatMessageBase composable
// (not a mirrored helper). The mirrored-helper pattern in
// useChatProfileImage.spec.js doesn't catch divergence between the helper
// and the production computed — this file is the catching layer.

const mockChat = {
  id: 1,
  chattype: 'User2User',
  user1: 11, // BELAL (sender, no profile image)
  user2: 22, // David (recipient, has profile image)
  otheruid: 22,
  icon: '/david.jpg', // Go API enriches as user1 for mod viewers,
  // so chat.icon is user2's image — only correct for one of the two bubbles.
}

const mockMessages = {
  101: { id: 101, chatid: 1, userid: 11 }, // BELAL's message
  102: { id: 102, chatid: 1, userid: 22 }, // David's message
}

const mockUsers = {
  11: { id: 11, displayname: 'BELAL', profile: { paththumb: '' } },
  22: { id: 22, displayname: 'David', profile: { paththumb: '/david.jpg' } },
  99: { id: 99, displayname: 'Mod', profile: { paththumb: '/mod.jpg' } },
}

vi.mock('~/stores/chat', () => ({
  useChatStore: () => ({
    byChatId: (id) => (id === mockChat.id ? mockChat : null),
    messageById: (id) => mockMessages[id] || null,
    messagesById: (id) => Object.values(mockMessages).filter((m) => m.chatid === id),
  }),
}))

vi.mock('~/stores/user', () => ({
  useUserStore: () => ({
    byId: (id) => mockUsers[id] || null,
  }),
}))

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({
    user: { id: 99 }, // Viewing mod (not a participant)
  }),
}))

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    fetch: vi.fn(),
    byId: () => null,
  }),
}))

vi.mock('~/stores/group', () => ({
  useGroupStore: () => ({ get: () => null }),
}))

vi.mock('~/composables/useTwem', () => ({
  twem: (s) => s,
}))

vi.mock('~/composables/useDistance', () => ({
  milesAway: () => 0,
}))

// Imported after mocks are set up
let useChatMessageBase
beforeEach(async () => {
  vi.resetModules()
  ;({ useChatMessageBase } = await import('~/composables/useChat'))
})

describe('useChatMessageBase — chatMessageProfileImage (Discourse #9707)', () => {
  describe('Mod viewing User2User chat modal, sender has no profile image', () => {
    // Concrete case from Discourse #9707 / chat 20843147:
    //   user1 = BELAL (11) — no profile image
    //   user2 = David (22) — has /david.jpg
    //   pov = 22 (recipient, set by ModChatViewButton)
    //   chat.icon = /david.jpg (Go API enriches as user1)
    //
    // Bug: sender's bubble fell back to chat.icon (David's photo) when
    // BELAL's paththumb was empty. Fix: don't fall back to chat.icon for
    // the non-pov user — let ProfileImage render a GeneratedAvatar from
    // the user's name.

    it("David's own message uses David's profile (matches name)", () => {
      const { chatMessageProfileImage } = useChatMessageBase(1, 102, 22)
      expect(chatMessageProfileImage.value).toBe('/david.jpg')
    })

    it("BELAL's message does NOT fall back to chat.icon (David's photo)", () => {
      const { chatMessageProfileImage } = useChatMessageBase(1, 101, 22)
      // The bug: returned '/david.jpg'. Fix: empty so GeneratedAvatar shows.
      expect(chatMessageProfileImage.value).not.toBe('/david.jpg')
    })

    it("BELAL's message returns empty paththumb so a generated avatar renders", () => {
      const { chatMessageProfileImage } = useChatMessageBase(1, 101, 22)
      expect(chatMessageProfileImage.value).toBeFalsy()
    })

    it('profile name still reflects the actual sender (BELAL), not the pov user', () => {
      const { chatMessageProfileName } = useChatMessageBase(1, 101, 22)
      expect(chatMessageProfileName.value).toBe('BELAL')
    })
  })

  describe('Mirror: pov=user1, recipient (user2) has no profile image', () => {
    // Same bug class, mirrored. If chat.icon would happen to be user1's
    // image (e.g. on a future API change), the user2 bubble must still
    // not use it when pov is set.

    it("user2's empty paththumb does not fall back to chat.icon under pov=user1", () => {
      const originalUser2 = mockUsers[22].profile.paththumb
      const originalIcon = mockChat.icon
      mockUsers[22].profile.paththumb = ''
      mockChat.icon = '/something.jpg' // anything truthy

      try {
        const { chatMessageProfileImage } = useChatMessageBase(1, 102, 11)
        expect(chatMessageProfileImage.value).not.toBe('/something.jpg')
        expect(chatMessageProfileImage.value).toBeFalsy()
      } finally {
        mockUsers[22].profile.paththumb = originalUser2
        mockChat.icon = originalIcon
      }
    })
  })
})
