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

describe('ChatFooter Ctrl+Enter / Cmd+Enter', () => {
  const stubs = {
    'b-button': { template: '<button><slot /></button>' },
    'b-form-textarea': {
      template:
        '<textarea :id="id" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)"></textarea>',
      props: ['id', 'modelValue'],
    },
    SpinButton: { template: '<button class="spin-button"><slot /></button>' },
    JumpingDots: { template: '<span />' },
    ChatNotice: { template: '<div />' },
    NoticeMessage: { template: '<div />' },
    'v-icon': { template: '<i />' },
  }

  async function mountFooter() {
    const wrapper = mount(ChatFooter, {
      props: { id: 123 },
      global: { stubs, directives: { 'b-tooltip': {} } },
    })
    await flushPromises()
    return wrapper
  }

  async function press(wrapper, keys) {
    const area = wrapper.find('#chatmessage')
    await area.setValue('hello')
    await area.trigger('keydown', { key: 'Enter', ...keys })
    await flushPromises()
  }

  beforeEach(() => {
    vi.clearAllMocks()
  })

  describe('when Enter inserts a new line', () => {
    beforeEach(() => {
      mockMe.value.settings.enterNewLine = true
    })

    it('sends on Ctrl+Enter', async () => {
      await press(await mountFooter(), { ctrlKey: true })
      expect(mockChatStore.send).toHaveBeenCalledWith(123, 'hello')
    })

    it('sends on Cmd+Enter', async () => {
      await press(await mountFooter(), { metaKey: true })
      expect(mockChatStore.send).toHaveBeenCalledWith(123, 'hello')
    })

    it('does not send on plain Enter', async () => {
      await press(await mountFooter(), {})
      expect(mockChatStore.send).not.toHaveBeenCalled()
    })
  })

  describe('when Enter sends', () => {
    beforeEach(() => {
      mockMe.value.settings.enterNewLine = false
    })

    it('sends on Ctrl+Enter', async () => {
      await press(await mountFooter(), { ctrlKey: true })
      expect(mockChatStore.send).toHaveBeenCalledWith(123, 'hello')
    })
  })

  describe('failed send', () => {
    it('stops the SpinButton and shows why the send failed', async () => {
      // The catch must call the SpinButton callback too, or the send button
      // spins for the full 20 seconds and reports a forgotten callback
      // (frontend-traps rule: callback on every early-return path).
      const finishSpy = vi.fn()
      const wrapper = mount(ChatFooter, {
        props: { id: 123 },
        global: {
          stubs: {
            ...stubs,
            ChatNotice: { template: '<div class="chat-notice"><slot /></div>' },
            SpinButton: {
              template:
                '<button class="spin-button" @click="fire"><slot /></button>',
              emits: ['handle'],
              methods: {
                fire() {
                  this.$emit('handle', finishSpy)
                },
              },
            },
          },
          directives: { 'b-tooltip': {} },
        },
      })
      await flushPromises()
      mockChatStore.send.mockRejectedValueOnce({
        response: { status: 404 },
      })
      const area = wrapper.find('#chatmessage')
      await area.setValue('hello')
      await wrapper.find('button.send-button').trigger('click')
      await flushPromises()
      expect(finishSpy).toHaveBeenCalledTimes(1)
      expect(wrapper.find('.chat-notice').text()).toContain(
        'no longer available'
      )
    })
  })
})
