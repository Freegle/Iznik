import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ChatFooter from '~/components/ChatFooter.vue'

const { mockChatStore, mockMiscStore, mockChat, mockOtheruser, mockMe } =
  vi.hoisted(() => {
    const { ref } = require('vue')

    return {
      mockChatStore: {
        send: vi.fn().mockResolvedValue(),
        fetchChat: vi.fn().mockResolvedValue(),
        typing: vi.fn().mockResolvedValue(),
        fetchMessages: vi.fn().mockResolvedValue(),
        markRead: vi.fn().mockResolvedValue(),
      },
      mockMiscStore: {
        get: vi.fn().mockReturnValue(false),
        set: vi.fn(),
        lastTyping: null,
      },
      mockChat: ref({
        id: 123,
        chattype: 'User2User',
        otheruid: 456,
        user1: 456,
        group: { id: 789 },
      }),
      mockMe: ref({ id: 999, settings: { enterNewLine: false } }),
      mockOtheruser: ref({
        id: 456,
        displayname: 'Test User',
        spammer: false,
        deleted: false,
        info: { ratings: { Up: 5, Down: 0, Mine: null } },
        comments: [],
      }),
    }
  })

vi.mock('~/composables/useChat', () => {
  const { computed, ref } = require('vue')
  return {
    setupChat: vi.fn(() => ({
      chat: mockChat,
      otheruser: mockOtheruser,
      tooSoonToNudge: computed(() => false),
      chatStore: mockChatStore,
      chatmessages: computed(() => []),
      crowmilesaway: ref(0),
      milesstring: ref(''),
    })),
  }
})

vi.mock('~/stores/misc', () => ({ useMiscStore: () => mockMiscStore }))
vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({ user: { id: 999 } }),
}))
vi.mock('~/stores/address', () => ({
  useAddressStore: () => ({ fetch: vi.fn(), properties: {} }),
}))
vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({ byId: vi.fn(), fetch: vi.fn() }),
}))
vi.mock('~/stores/chatdraft', () => ({
  useChatDraftStore: () => ({
    saveDraft: vi.fn(),
    getDraft: vi.fn(() => ''),
    clearDraft: vi.fn(),
  }),
}))
vi.mock('pinia', () => {
  const { ref } = require('vue')
  return { storeToRefs: () => ({ lastTyping: ref(null) }) }
})
vi.mock('~/composables/useMe', () => ({
  useMe: () => ({ me: mockMe, myid: 999 }),
}))
vi.mock('~/composables/useTwem', () => ({ untwem: vi.fn((m) => m) }))
vi.mock('~/composables/useThrottle', () => ({
  fetchOurOffers: vi.fn().mockResolvedValue([]),
}))
vi.mock('~/composables/useClientLog', () => ({ action: vi.fn() }))
vi.mock('floating-vue', () => ({
  Dropdown: {
    template: '<div class="dropdown"><slot /><slot name="popper" /></div>',
  },
}))
vi.mock('floating-vue/dist/style.css', () => ({}))
vi.mock('textarea-caret', () => ({
  default: vi.fn(() => ({ top: 0, left: 0 })),
}))

describe('ChatFooter refresh after a successful send', () => {
  const stubs = {
    'b-button': { template: '<button><slot /></button>' },
    'b-form-textarea': {
      template:
        '<textarea :id="id" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)"></textarea>',
      props: ['id', 'modelValue'],
    },
    JumpingDots: { template: '<span />' },
    ChatNotice: { template: '<div />' },
    NoticeMessage: { template: '<div />' },
    'v-icon': { template: '<i />' },
  }

  // The real SpinButton passes its finish callback to the @handle handler;
  // this stub reproduces that so the callback contract can be asserted.
  function spinStub(finishSpy) {
    return {
      template: '<button class="spin-button" @click="fire"><slot /></button>',
      emits: ['handle'],
      methods: {
        fire() {
          this.$emit('handle', finishSpy)
        },
      },
    }
  }

  beforeEach(() => {
    vi.clearAllMocks()
    mockChatStore.send.mockResolvedValue()
    mockChatStore.fetchMessages.mockResolvedValue()
    mockChatStore.fetchChat.mockResolvedValue()
  })

  it('stops the SpinButton even when the after-send refresh fails', async () => {
    // The send itself succeeded; only the follow-up refresh is broken. The
    // failure must not escape send(), or the SpinButton never gets its
    // callback, spins for the full 20 seconds and reports a forgotten
    // callback (frontend-traps rule: callback on every exit path).
    const finishSpy = vi.fn()
    const wrapper = mount(ChatFooter, {
      props: { id: 123 },
      global: {
        stubs: { ...stubs, SpinButton: spinStub(finishSpy) },
        directives: { 'b-tooltip': {} },
      },
    })
    await flushPromises()
    mockChatStore.fetchMessages.mockRejectedValueOnce(new Error('network down'))
    const area = wrapper.find('#chatmessage')
    await area.setValue('hello')
    await wrapper.find('button.send-button').trigger('click')
    await flushPromises()
    expect(mockChatStore.send).toHaveBeenCalledWith(123, 'hello')
    expect(finishSpy).toHaveBeenCalledTimes(1)
    // The typed text is gone: the message really was sent and stays sent.
    expect(wrapper.find('#chatmessage').element.value).toBe('')
  })

  it('still scrolls and refreshes the chat list on the happy path', async () => {
    const finishSpy = vi.fn()
    const wrapper = mount(ChatFooter, {
      props: { id: 123 },
      global: {
        stubs: { ...stubs, SpinButton: spinStub(finishSpy) },
        directives: { 'b-tooltip': {} },
      },
    })
    await flushPromises()
    const area = wrapper.find('#chatmessage')
    await area.setValue('hello')
    await wrapper.find('button.send-button').trigger('click')
    await flushPromises()
    expect(mockChatStore.fetchMessages).toHaveBeenCalled()
    expect(mockChatStore.fetchChat).toHaveBeenCalledWith(123)
    expect(wrapper.emitted('scrollbottom')).toBeTruthy()
    expect(finishSpy).toHaveBeenCalledTimes(1)
  })
})
