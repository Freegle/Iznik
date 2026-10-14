import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
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
        nudge: vi.fn().mockResolvedValue(),
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

describe('ChatFooter refused store actions', () => {
  const stubs = {
    'b-button': { template: '<button><slot /></button>' },
    'b-form-textarea': {
      template:
        '<textarea :id="id" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)"></textarea>',
      props: ['id', 'modelValue'],
    },
    SpinButton: { template: '<button class="spin-button"><slot /></button>' },
    JumpingDots: { template: '<span />' },
    ChatNotice: { template: '<div class="chat-notice"><slot /></div>' },
    NoticeMessage: { template: '<div />' },
    'v-icon': { template: '<i />' },
    NudgeWarningModal: {
      name: 'NudgeWarningModal',
      template: '<div class="nudge-warning-modal" />',
      emits: ['confirm'],
    },
  }

  let errorSpy

  async function mountFooter() {
    const wrapper = mount(ChatFooter, {
      props: { id: 123 },
      global: { stubs, directives: { 'b-tooltip': {} } },
    })
    await flushPromises()
    return wrapper
  }

  function nudgeButton(wrapper) {
    return wrapper
      .findAll('button.action-chip')
      .find((b) => b.text() === 'Nudge')
  }

  beforeEach(() => {
    vi.clearAllMocks()
    errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
    mockChatStore.markRead.mockResolvedValue()
    mockChatStore.nudge.mockResolvedValue()
    mockChatStore.fetchMessages.mockResolvedValue()
    mockChatStore.fetchChat.mockResolvedValue()
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('keeps the chat alive when marking it read is refused', async () => {
    // Focusing the textarea marks the chat read; on master a refusal there
    // (401 in a long-open tab, network blip) escaped the focus handler and
    // took down the whole app instead of staying a console log.
    const wrapper = await mountFooter()
    mockChatStore.markRead.mockRejectedValueOnce({
      response: { status: 401 },
    })
    await wrapper.find('#chatmessage').trigger('focus')
    await flushPromises()
    expect(mockChatStore.markRead).toHaveBeenCalledWith(123)
    expect(errorSpy).toHaveBeenCalled()
    expect(wrapper.find('#chatmessage').exists()).toBe(true)
  })

  it('shows an inline notice when a nudge is refused', async () => {
    const wrapper = await mountFooter()
    await nudgeButton(wrapper).trigger('click')
    mockChatStore.nudge.mockRejectedValueOnce({ response: { status: 401 } })
    wrapper
      .findComponent({ name: 'NudgeWarningModal' })
      .vm.$emit('confirm')
    await flushPromises()
    expect(mockChatStore.nudge).toHaveBeenCalledWith(123)
    expect(wrapper.find('.chat-notice').text()).toContain(
      "your nudge couldn't be sent"
    )
    expect(wrapper.find('#chatmessage').exists()).toBe(true)
  })

  it('still nudges and refreshes on the happy path', async () => {
    const wrapper = await mountFooter()
    await nudgeButton(wrapper).trigger('click')
    await flushPromises()
    wrapper
      .findComponent({ name: 'NudgeWarningModal' })
      .vm.$emit('confirm')
    await flushPromises()
    expect(mockChatStore.nudge).toHaveBeenCalledWith(123)
    expect(mockChatStore.fetchMessages).toHaveBeenCalled()
    expect(wrapper.find('.chat-notice').exists()).toBe(false)
  })
})
