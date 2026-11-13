import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import ChatButton from '~/components/ChatButton.vue'
import { useReplyToPost } from '~/composables/useReplyToPost'

// A reply is only reported as sent once the chat has taken the message. These tests use the real
// ChatButton, because the contract between replyToPost and openChat is what is under test.

const mockChatStore = {
  openChatToMods: vi.fn().mockResolvedValue(123),
  openChatToUser: vi.fn().mockResolvedValue(null),
  send: vi.fn().mockResolvedValue({}),
  fetchMessages: vi.fn().mockResolvedValue([]),
  showContactDetailsAskModal: false,
}
vi.mock('~/stores/chat', () => ({
  useChatStore: () => mockChatStore,
}))

const mockMessageStore = {
  fetch: vi.fn().mockResolvedValue({}),
  byId: vi.fn(),
}
vi.mock('~/stores/message', () => ({
  useMessageStore: () => mockMessageStore,
}))

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({ modtools: false }),
}))

let mockReplyStore
vi.mock('~/stores/reply', () => ({
  useReplyStore: () => mockReplyStore,
}))

const mockPush = vi.fn()
vi.hoisted(() => {
  vi.resetModules()
})
vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return {
    ...actual,
    useRouter: () => ({ push: mockPush }),
  }
})

const mockMe = ref({ id: 1, settings: { mylocation: true } })
const mockMyid = ref(1)
vi.mock('~/composables/useMe', () => ({
  useMe: () => ({ me: mockMe, myid: mockMyid }),
}))

vi.mock('~/composables/useClientLog', () => ({
  action: vi.fn(),
}))

const mockTrackConversion = vi.fn()
vi.mock('~/composables/useTrackConversion', () => ({
  trackConversion: (...args) => mockTrackConversion(...args),
}))

function mountChatButton(userid) {
  return mount(ChatButton, {
    props: { userid },
    global: {
      stubs: {
        'b-button': { template: '<button><slot /></button>' },
        'v-icon': { template: '<i />' },
      },
    },
  })
}

describe('replyToPost when no chat could be opened', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockChatStore.openChatToUser.mockResolvedValue(null)
    mockReplyStore = {
      replyMsgId: 42,
      replyMessage: 'Hello, is this still available?',
      replyingAt: Date.now() - 60_000,
      replySource: 'browse',
      replyImage: null,
      draftMsgId: null,
      clearDraft: vi.fn(),
    }
  })

  it('does not report the reply as sent when the chat has no id', async () => {
    const wrapper = mountChatButton(2)
    const { replyToPost } = useReplyToPost()

    const result = await replyToPost(wrapper.vm)

    expect(mockChatStore.send).not.toHaveBeenCalled()
    expect(result).toBeFalsy()
  })

  it('keeps the typed reply when the chat has no id', async () => {
    const wrapper = mountChatButton(2)
    const { replyToPost } = useReplyToPost()

    await replyToPost(wrapper.vm)

    expect(mockReplyStore.replyMsgId).toBe(42)
    expect(mockReplyStore.replyMessage).toBe('Hello, is this still available?')
  })

  it('does not track a Reply Sent conversion when nothing was sent', async () => {
    const wrapper = mountChatButton(2)
    const { replyToPost } = useReplyToPost()

    await replyToPost(wrapper.vm)

    expect(mockTrackConversion).not.toHaveBeenCalled()
  })

  it('does not report the reply as sent when there is no poster to chat to', async () => {
    // A message whose poster is not in the store gives the ChatButton no userid.
    const wrapper = mountChatButton(null)
    const { replyToPost } = useReplyToPost()

    const result = await replyToPost(wrapper.vm)

    expect(mockChatStore.openChatToUser).not.toHaveBeenCalled()
    expect(mockChatStore.send).not.toHaveBeenCalled()
    expect(result).toBeFalsy()
    expect(mockReplyStore.replyMessage).toBe('Hello, is this still available?')
  })
})
