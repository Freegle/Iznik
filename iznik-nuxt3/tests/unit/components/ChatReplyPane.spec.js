import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h, Suspense, ref } from 'vue'
import ChatReplyPane from '~/components/ChatReplyPane.vue'

const mockMessage = {
  id: 1,
  subject: 'OFFER: Sofa (Edinburgh EH17)',
  type: 'Offer',
  fromuser: 200,
  groups: [{ groupid: 100 }],
  lat: 51.5,
  lng: -0.1,
  deliverypossible: false,
  textbody: 'A lovely sofa.',
  attachments: [],
  promised: false,
  promisedtome: false,
}

const mockMessageStore = {
  fetch: vi.fn().mockResolvedValue(mockMessage),
  byId: vi.fn().mockReturnValue(mockMessage),
}

const mockUserStore = {
  fetch: vi.fn().mockResolvedValue({}),
  byId: vi.fn().mockReturnValue({
    id: 200,
    displayname: 'Jane Doe',
    profile: { paththumb: '/profile.jpg' },
  }),
}

const mockReplyStateMachine = {
  email: ref(''),
  emailValid: ref(false),
  replyText: ref(''),
  collectText: ref(''),
  error: ref(null),
  canSend: ref(true),
  isProcessing: ref(false),
  isComplete: ref(false),
  showWelcomeModal: ref(false),
  newUserPassword: ref(''),
  state: ref('IDLE'),
  startTyping: vi.fn(),
  submit: vi.fn().mockResolvedValue(undefined),
  retry: vi.fn(),
  setRefs: vi.fn(),
  setReplySource: vi.fn(),
  onLoginSuccess: vi.fn(),
  closeWelcomeModal: vi.fn(),
}

const mockSetReplyOverlayOpen = vi.fn()

vi.mock('~/stores/message', () => ({
  useMessageStore: () => mockMessageStore,
}))

vi.mock('~/stores/user', () => ({
  useUserStore: () => mockUserStore,
}))

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({
    setReplyOverlayOpen: mockSetReplyOverlayOpen,
  }),
}))

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({ forceLogin: false }),
}))

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    me: ref({ id: 1 }),
    myGroups: ref({ 100: { id: 100, namedisplay: 'Test Group' } }),
  }),
}))

vi.mock('~/composables/useReplyStateMachine', () => ({
  useReplyStateMachine: () => mockReplyStateMachine,
  ReplyState: {
    IDLE: 'IDLE',
    AUTHENTICATING: 'AUTHENTICATING',
  },
}))

vi.mock('~/composables/useDistance', () => ({
  milesAway: vi.fn().mockReturnValue(5),
}))

vi.mock('~/composables/useClientLog', () => ({
  action: vi.fn(),
}))

vi.mock('~/constants', () => ({
  FAR_AWAY: 20,
}))

vi.hoisted(() => {
  vi.resetModules()
})

describe('ChatReplyPane', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockMessageStore.byId.mockReturnValue(mockMessage)
    mockReplyStateMachine.canSend.value = true
    mockReplyStateMachine.isProcessing.value = false
    mockReplyStateMachine.error.value = null
    mockReplyStateMachine.showWelcomeModal.value = false
  })

  async function createWrapper(props = {}) {
    const TestWrapper = defineComponent({
      setup() {
        return () =>
          h(Suspense, null, {
            default: () =>
              h(ChatReplyPane, {
                messageId: 1,
                ...props,
              }),
            fallback: () => h('div', 'Loading...'),
          })
      },
    })

    const wrapper = mount(TestWrapper, {
      global: {
        stubs: {
          'v-icon': {
            template: '<span class="v-icon" :data-icon="icon" />',
            props: ['icon'],
          },
          'b-button': {
            template:
              '<button class="b-button" :class="variant" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
            props: ['variant', 'size', 'disabled'],
            emits: ['click'],
          },
          'b-modal': {
            template:
              '<div class="b-modal"><slot /><slot name="title" /></div>',
            props: ['id', 'scrollable', 'okOnly', 'okTitle'],
            emits: ['ok'],
            methods: {
              show() {},
              hide() {},
            },
          },
          EmailValidator: {
            template: '<div class="email-validator" />',
            props: ['email', 'valid', 'size', 'label'],
            emits: ['update:email', 'update:valid'],
          },
          ChatMessageCard: {
            template: '<div class="chat-message-card-stub" :data-id="id" />',
            props: ['id', 'showLocation'],
          },
          NoticeMessage: {
            template:
              '<div class="notice-message" :class="variant"><slot /></div>',
            props: ['variant'],
          },
          NewUserInfo: {
            template: '<div class="new-user-info" />',
            props: ['password'],
          },
          NewFreegler: {
            template: '<div class="new-freegler" />',
          },
          MessagePhotosModal: {
            template: '<div class="photos-modal-stub" :data-id="id" />',
            props: ['id', 'initialIndex'],
            emits: ['hidden'],
          },
          SpinButton: {
            template:
              '<button class="spin-button" :disabled="disabled" @click="$emit(\'handle\', () => {})"><slot /></button>',
            props: [
              'variant',
              'size',
              'doneIcon',
              'iconName',
              'disabled',
              'iconlast',
            ],
            emits: ['handle'],
          },
          ChatButton: {
            template: '<div class="chat-button" />',
            props: ['userid'],
          },
          ProfileImage: {
            template: '<div class="profile-image" :data-name="name" />',
            props: [
              'image',
              'externaluid',
              'ouruid',
              'externalmods',
              'name',
              'isThumbnail',
              'size',
            ],
          },
          Field: {
            template:
              '<textarea class="field" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)"></textarea>',
            props: [
              'id',
              'modelValue',
              'name',
              'rules',
              'validateOnMount',
              'validateOnModelUpdate',
              'as',
              'rows',
              'maxRows',
              'placeholder',
            ],
            emits: ['update:modelValue', 'input'],
          },
          ErrorMessage: {
            template: '<span class="error-message" />',
            props: ['name'],
          },
          VeeForm: {
            template: '<form class="vee-form"><slot /></form>',
          },
          'client-only': {
            template: '<div class="client-only"><slot /></div>',
          },
          NuxtLink: {
            template: '<a class="nuxt-link" :href="to"><slot /></a>',
            props: ['to'],
          },
        },
      },
    })

    await flushPromises()
    return wrapper
  }

  describe('post photo zoom', () => {
    it('opens the photo carousel modal when a multi-photo post is tapped', async () => {
      // Multiple photos → the modal is the swipeable carousel (it shows all
      // attachments with prev/next + dots); a single photo just shows the one.
      mockMessageStore.byId.mockReturnValue({
        ...mockMessage,
        attachments: [
          { id: 10, path: 'https://example.com/p1.jpg' },
          { id: 11, path: 'https://example.com/p2.jpg' },
          { id: 12, path: 'https://example.com/p3.jpg' },
        ],
      })
      const wrapper = await createWrapper()

      expect(wrapper.find('.photos-modal-stub').exists()).toBe(false)

      await wrapper.find('.reply-card__incoming').trigger('click')
      await flushPromises()

      // The modal is opened for the whole message (id), so it carousels through
      // every attachment rather than showing a single fixed image.
      const modal = wrapper.find('.photos-modal-stub')
      expect(modal.exists()).toBe(true)
      expect(modal.attributes('data-id')).toBe('1')
    })

    it('opens the photo modal for a single-photo post too', async () => {
      mockMessageStore.byId.mockReturnValue({
        ...mockMessage,
        attachments: [{ id: 10, path: 'https://example.com/p.jpg' }],
      })
      const wrapper = await createWrapper()

      await wrapper.find('.reply-card__incoming').trigger('click')
      await flushPromises()

      expect(wrapper.find('.photos-modal-stub').exists()).toBe(true)
    })

    it('does not open the photo modal when the post has no photo', async () => {
      mockMessageStore.byId.mockReturnValue({ ...mockMessage, attachments: [] })
      const wrapper = await createWrapper()

      await wrapper.find('.reply-card__incoming').trigger('click')
      await flushPromises()

      expect(wrapper.find('.photos-modal-stub').exists()).toBe(false)
    })
  })

  describe('rendering', () => {
    it('renders the reply overlay container', async () => {
      const wrapper = await createWrapper()
      expect(wrapper.find('.reply-overlay').exists()).toBe(true)
      expect(wrapper.find('.reply-card').exists()).toBe(true)
    })

    it('renders the reply header', async () => {
      const wrapper = await createWrapper()
      expect(wrapper.find('.reply-card__header').exists()).toBe(true)
    })

    it('renders the back button', async () => {
      const wrapper = await createWrapper()
      expect(wrapper.find('.reply-card__back').exists()).toBe(true)
    })

    it('renders the reply body', async () => {
      const wrapper = await createWrapper()
      expect(wrapper.find('.reply-card__body').exists()).toBe(true)
    })

    it('renders the composer when user not deleted', async () => {
      const wrapper = await createWrapper()
      expect(wrapper.find('.reply-card__composer').exists()).toBe(true)
    })

    it('shows send button', async () => {
      const wrapper = await createWrapper()
      expect(wrapper.find('.spin-button').exists()).toBe(true)
      expect(wrapper.text()).toContain('Send')
    })

    it('shows the poster name in the header', async () => {
      const wrapper = await createWrapper()
      expect(wrapper.find('.reply-card__name').text()).toContain('Jane Doe')
    })
  })

  describe('collect time field', () => {
    it('shows collect field for Offer type', async () => {
      const wrapper = await createWrapper()
      expect(wrapper.text()).toContain('When could you collect?')
    })

    it('hides collect field for Wanted type', async () => {
      mockMessageStore.byId.mockReturnValue({
        ...mockMessage,
        type: 'Wanted',
      })
      const wrapper = await createWrapper()
      expect(wrapper.text()).not.toContain('When could you collect?')
    })
  })

  describe('delivery notice', () => {
    it('shows delivery notice when delivery possible', async () => {
      mockMessageStore.byId.mockReturnValue({
        ...mockMessage,
        deliverypossible: true,
      })
      const wrapper = await createWrapper()
      expect(wrapper.text()).toContain('Delivery may be possible')
    })

    it('hides delivery notice when no delivery', async () => {
      const wrapper = await createWrapper()
      expect(wrapper.text()).not.toContain('Delivery may be possible')
    })
  })

  describe('distance warning', () => {
    it('shows far away warning for Offer when over threshold', async () => {
      const { milesAway } = await import('~/composables/useDistance')
      milesAway.mockReturnValue(30)
      const wrapper = await createWrapper()
      expect(wrapper.find('.notice-message.warning').exists()).toBe(true)
      expect(wrapper.text()).toContain('miles away')
    })

    it('no distance warning when within threshold', async () => {
      const { milesAway } = await import('~/composables/useDistance')
      milesAway.mockReturnValue(5)
      const wrapper = await createWrapper()
      const warnings = wrapper
        .findAll('.notice-message.warning')
        .filter((n) => n.text().includes('miles away'))
      expect(warnings.length).toBe(0)
    })
  })

  describe('promised warning', () => {
    it('shows promised warning when promised and not promisedtome', async () => {
      mockMessageStore.byId.mockReturnValue({
        ...mockMessage,
        promised: true,
        promisedtome: false,
      })
      const wrapper = await createWrapper()
      expect(wrapper.text()).toContain('Already promised')
    })

    it('hides promised warning when promisedtome', async () => {
      mockMessageStore.byId.mockReturnValue({
        ...mockMessage,
        promised: true,
        promisedtome: true,
      })
      const wrapper = await createWrapper()
      const notices = wrapper
        .findAll('.notice-message.warning')
        .filter((n) => n.text().includes('Already promised'))
      expect(notices.length).toBe(0)
    })
  })

  describe('error state', () => {
    it('shows error message when error present', async () => {
      mockReplyStateMachine.error.value = 'Something went wrong'
      const wrapper = await createWrapper()
      expect(wrapper.text()).toContain('Something went wrong')
    })

    it('shows try again button on error', async () => {
      mockReplyStateMachine.error.value = 'Something went wrong'
      const wrapper = await createWrapper()
      expect(wrapper.text()).toContain('Try again')
    })
  })

  describe('send button state', () => {
    it('disables send when canSend is false', async () => {
      mockReplyStateMachine.canSend.value = false
      const wrapper = await createWrapper()
      const sendBtn = wrapper.find('.spin-button')
      expect(sendBtn.attributes('disabled')).toBeDefined()
    })

    it('disables send when processing', async () => {
      mockReplyStateMachine.isProcessing.value = true
      const wrapper = await createWrapper()
      const sendBtn = wrapper.find('.spin-button')
      expect(sendBtn.attributes('disabled')).toBeDefined()
    })
  })

  describe('state machine integration', () => {
    it('calls setRefs on mount', async () => {
      await createWrapper()
      expect(mockReplyStateMachine.setRefs).toHaveBeenCalled()
    })

    it('hides the sticky ad banner while open', async () => {
      await createWrapper()
      expect(mockSetReplyOverlayOpen).toHaveBeenCalledWith(true)
    })
  })

  describe('closing', () => {
    it('emits close when the back button is clicked', async () => {
      const wrapper = await createWrapper()
      await wrapper.find('.reply-card__back').trigger('click')
      const inner = wrapper.findComponent(ChatReplyPane)
      expect(inner.emitted('close')).toBeTruthy()
    })

    it('emits close when the backdrop is clicked', async () => {
      const wrapper = await createWrapper()
      await wrapper.find('.reply-overlay').trigger('click')
      const inner = wrapper.findComponent(ChatReplyPane)
      expect(inner.emitted('close')).toBeTruthy()
    })
  })

  describe('post card', () => {
    it('shows the post as a chat message card', async () => {
      const wrapper = await createWrapper()
      expect(wrapper.find('.chat-message-card-stub').exists()).toBe(true)
      expect(
        wrapper.find('.chat-message-card-stub').attributes('data-id')
      ).toBe('1')
    })

    it('shows an intro line about replying to the poster', async () => {
      const wrapper = await createWrapper()
      expect(wrapper.find('.reply-card__intro').text()).toContain('Jane Doe')
    })
  })

  describe('validateReply', () => {
    it('returns error when reply is empty', async () => {
      const wrapper = await createWrapper()
      const comp = wrapper.findComponent(ChatReplyPane)
      const result = comp.vm.validateReply('')
      expect(result).toBe('Please fill out your reply.')
    })

    it('returns true for valid reply', async () => {
      const wrapper = await createWrapper()
      const comp = wrapper.findComponent(ChatReplyPane)
      const result = comp.vm.validateReply('Hello, I would like this item.')
      expect(result).toBe(true)
    })

    it('returns error for "still available" short message on Offer', async () => {
      const wrapper = await createWrapper()
      const comp = wrapper.findComponent(ChatReplyPane)
      const result = comp.vm.validateReply('is this still available?')
      expect(typeof result).toBe('string')
      expect(result).not.toBe(true)
    })

    it('does not reject "still available" for Wanted type', async () => {
      mockMessageStore.byId.mockReturnValue({ ...mockMessage, type: 'Wanted' })
      const wrapper = await createWrapper()
      const comp = wrapper.findComponent(ChatReplyPane)
      const result = comp.vm.validateReply('is this still available?')
      expect(result).toBe(true)
    })
  })

  describe('validateCollect', () => {
    it('returns error when collect is empty', async () => {
      const wrapper = await createWrapper()
      const comp = wrapper.findComponent(ChatReplyPane)
      const result = comp.vm.validateCollect('')
      expect(result).toBe(
        'Please suggest some days and times when you could collect.'
      )
    })

    it('returns true for valid collect text', async () => {
      const wrapper = await createWrapper()
      const comp = wrapper.findComponent(ChatReplyPane)
      const result = comp.vm.validateCollect('Monday afternoon or weekend')
      expect(result).toBe(true)
    })
  })

  describe('subject processing', () => {
    it('strips OFFER prefix from subject', async () => {
      const wrapper = await createWrapper()
      const comp = wrapper.findComponent(ChatReplyPane)
      expect(comp.vm.subjectItemName).toBe('Sofa')
    })

    it('strips location suffix from subject', async () => {
      mockMessageStore.byId.mockReturnValue({
        ...mockMessage,
        subject: 'WANTED: Chair (London SW1)',
      })
      const wrapper = await createWrapper()
      const comp = wrapper.findComponent(ChatReplyPane)
      expect(comp.vm.subjectItemName).toBe('Chair')
    })

    it('returns empty string when no subject', async () => {
      mockMessageStore.byId.mockReturnValue({ ...mockMessage, subject: '' })
      const wrapper = await createWrapper()
      const comp = wrapper.findComponent(ChatReplyPane)
      expect(comp.vm.subjectItemName).toBe('')
    })
  })
})
