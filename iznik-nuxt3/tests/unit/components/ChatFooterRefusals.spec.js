import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ChatFooter from '~/components/ChatFooter.vue'
import { APIError } from '~/api/APIErrors'

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

// Every refusable store call in ChatFooter: a refusal from the server stays
// quiet or shows an inline notice, and the SpinButton always gets its
// callback (frontend-traps rule: callback on every exit path). Anything that
// is not a refusal still goes on to the global error handler.
describe('ChatFooter when the server refuses', () => {
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

  const stubs = {
    'b-button': { template: '<button><slot /></button>' },
    'b-form-textarea': {
      template:
        '<textarea :id="id" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)"></textarea>',
      props: ['id', 'modelValue'],
    },
    JumpingDots: { template: '<span />' },
    ChatNotice: { template: '<div class="chat-notice"><slot /></div>' },
    NoticeMessage: { template: '<div />' },
    'v-icon': { template: '<i />' },
    NudgeWarningModal: {
      name: 'NudgeWarningModal',
      template: '<div class="nudge-warning-modal" />',
      emits: ['confirm'],
    },
    AddressModal: {
      name: 'AddressModal',
      template: '<div class="address-modal" />',
      emits: ['chosen', 'hidden'],
    },
    OurUploader: {
      name: 'OurUploader',
      template: '<div class="our-uploader" />',
      props: ['modelValue'],
      emits: ['update:modelValue'],
    },
  }

  // What BaseAPI throws when the server refuses a call.
  const refusal = (status = 401) =>
    new APIError({ request: {}, response: { status } }, `refused ${status}`)

  let errorSpy
  let errorHandler

  async function mountFooter(finishSpy = vi.fn()) {
    const wrapper = mount(ChatFooter, {
      props: { id: 123 },
      global: {
        stubs: { ...stubs, SpinButton: spinStub(finishSpy) },
        directives: { 'b-tooltip': {} },
        config: { errorHandler },
      },
    })
    await flushPromises()
    return wrapper
  }

  function actionChip(wrapper, label) {
    return wrapper.findAll('button.action-chip').find((b) => b.text() === label)
  }

  async function sendText(wrapper, text) {
    await wrapper.find('#chatmessage').setValue(text)
    await wrapper.find('button.send-button').trigger('click')
    await flushPromises()
  }

  async function confirmNudge(wrapper) {
    await actionChip(wrapper, 'Nudge').trigger('click')
    await flushPromises()
    wrapper.findComponent({ name: 'NudgeWarningModal' }).vm.$emit('confirm')
    await flushPromises()
  }

  beforeEach(() => {
    vi.clearAllMocks()
    errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
    errorHandler = vi.fn()
    for (const fn of Object.values(mockChatStore)) {
      fn.mockResolvedValue()
    }
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  describe('refresh after a successful send', () => {
    it('still scrolls and refreshes the chat list on the happy path', async () => {
      const finishSpy = vi.fn()
      const wrapper = await mountFooter(finishSpy)
      await sendText(wrapper, 'hello')
      expect(mockChatStore.send).toHaveBeenCalledWith(123, 'hello')
      expect(mockChatStore.fetchMessages).toHaveBeenCalled()
      expect(mockChatStore.fetchChat).toHaveBeenCalledWith(123)
      expect(wrapper.emitted('scrollbottom')).toBeTruthy()
      expect(finishSpy).toHaveBeenCalledTimes(1)
    })

    it('stops the SpinButton and still refreshes the chat list when the message refresh fails', async () => {
      // The send itself succeeded; only the follow-up message refresh is
      // broken. send() must still stop the button, and the chat-list refresh
      // must not be held back by the failed message refresh.
      const finishSpy = vi.fn()
      const wrapper = await mountFooter(finishSpy)
      mockChatStore.fetchMessages.mockRejectedValueOnce(refusal(502))
      await sendText(wrapper, 'hello')
      expect(finishSpy).toHaveBeenCalledTimes(1)
      expect(mockChatStore.fetchChat).toHaveBeenCalledWith(123)
      expect(errorSpy).toHaveBeenCalled()
      // The typed text is gone: the message really was sent and stays sent.
      expect(wrapper.find('#chatmessage').element.value).toBe('')
    })

    it('stops the SpinButton when the chat-list refresh fails', async () => {
      const finishSpy = vi.fn()
      const wrapper = await mountFooter(finishSpy)
      mockChatStore.fetchChat.mockRejectedValueOnce(refusal(502))
      await sendText(wrapper, 'hello')
      expect(finishSpy).toHaveBeenCalledTimes(1)
      expect(wrapper.emitted('scrollbottom')).toBeTruthy()
    })
  })

  describe('mark read and typing', () => {
    it('keeps the chat alive when marking it read is refused', async () => {
      // Focusing the textarea marks the chat read; a refusal there (401 in a
      // long-open tab, network blip) used to escape the focus handler.
      const wrapper = await mountFooter()
      mockChatStore.markRead.mockRejectedValueOnce(refusal())
      await wrapper.find('#chatmessage').trigger('focus')
      await flushPromises()
      expect(mockChatStore.markRead).toHaveBeenCalledWith(123)
      expect(errorSpy).toHaveBeenCalled()
      expect(wrapper.find('#chatmessage').exists()).toBe(true)
    })

    it('keeps the chat alive when the typing ping is refused', async () => {
      const wrapper = await mountFooter()
      mockChatStore.typing.mockRejectedValueOnce(refusal())
      await wrapper.find('#chatmessage').trigger('keydown', { key: 'a' })
      await flushPromises()
      expect(mockChatStore.typing).toHaveBeenCalledWith(123)
      expect(errorSpy).toHaveBeenCalled()
      expect(wrapper.find('.chat-notice').exists()).toBe(false)
    })
  })

  describe('nudge', () => {
    it('still nudges and refreshes on the happy path', async () => {
      const wrapper = await mountFooter()
      await confirmNudge(wrapper)
      expect(mockChatStore.nudge).toHaveBeenCalledWith(123)
      expect(mockChatStore.fetchMessages).toHaveBeenCalled()
      expect(wrapper.find('.chat-notice').exists()).toBe(false)
    })

    it('shows an inline notice when a nudge is refused', async () => {
      const wrapper = await mountFooter()
      mockChatStore.nudge.mockRejectedValueOnce(refusal())
      await confirmNudge(wrapper)
      expect(mockChatStore.nudge).toHaveBeenCalledWith(123)
      expect(wrapper.find('.chat-notice').text()).toContain(
        "your nudge couldn't be sent"
      )
      expect(wrapper.find('#chatmessage').exists()).toBe(true)
    })

    it('lets an error that is not a refusal through to the error handler', async () => {
      // Only a refusal from the server is caught. Anything else is a bug and
      // has to reach the global handler, and so Sentry.
      const wrapper = await mountFooter()
      const bug = new TypeError('bug')
      mockChatStore.nudge.mockRejectedValueOnce(bug)
      await confirmNudge(wrapper)
      expect(errorHandler).toHaveBeenCalledTimes(1)
      expect(errorHandler.mock.calls[0][0]).toBe(bug)
      expect(wrapper.find('.chat-notice').exists()).toBe(false)
    })

    it('clears the notice when a later nudge goes through', async () => {
      const wrapper = await mountFooter()
      mockChatStore.nudge.mockRejectedValueOnce(refusal())
      await confirmNudge(wrapper)
      expect(wrapper.find('.chat-notice').exists()).toBe(true)
      await confirmNudge(wrapper)
      expect(mockChatStore.nudge).toHaveBeenCalledTimes(2)
      expect(wrapper.find('.chat-notice').exists()).toBe(false)
    })
  })

  describe('address and photo', () => {
    it('shows an inline notice when sending an address is refused', async () => {
      const wrapper = await mountFooter()
      await actionChip(wrapper, 'Address').trigger('click')
      await flushPromises()
      mockChatStore.send.mockRejectedValueOnce(refusal())
      wrapper.findComponent({ name: 'AddressModal' }).vm.$emit('chosen', 55)
      await flushPromises()
      expect(mockChatStore.send).toHaveBeenCalledWith(123, null, 55)
      expect(wrapper.find('.chat-notice').text()).toContain(
        "your address couldn't be sent"
      )
      expect(wrapper.find('#chatmessage').exists()).toBe(true)
    })

    it('shows an inline notice when sending a photo is refused', async () => {
      const wrapper = await mountFooter()
      await actionChip(wrapper, 'Photo').trigger('click')
      await flushPromises()
      mockChatStore.send.mockRejectedValueOnce(refusal())
      wrapper
        .findComponent({ name: 'OurUploader' })
        .vm.$emit('update:modelValue', [{ id: 7 }])
      await flushPromises()
      expect(mockChatStore.send).toHaveBeenCalledWith(123, null, null, 7)
      expect(wrapper.find('.chat-notice').text()).toContain(
        "a photo couldn't be sent"
      )
      expect(wrapper.find('.our-uploader').exists()).toBe(false)
      expect(wrapper.find('#chatmessage').exists()).toBe(true)
    })

    it('sends a photo and refreshes on the happy path', async () => {
      const wrapper = await mountFooter()
      await actionChip(wrapper, 'Photo').trigger('click')
      await flushPromises()
      wrapper
        .findComponent({ name: 'OurUploader' })
        .vm.$emit('update:modelValue', [{ id: 7 }])
      await flushPromises()
      expect(mockChatStore.send).toHaveBeenCalledWith(123, null, null, 7)
      expect(mockChatStore.fetchChat).toHaveBeenCalledWith(123)
      expect(wrapper.find('.chat-notice').exists()).toBe(false)
      expect(wrapper.find('.our-uploader').exists()).toBe(false)
    })
  })
})
