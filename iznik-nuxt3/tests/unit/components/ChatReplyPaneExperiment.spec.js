/**
 * The reply-what-happens-next experiment in ChatReplyPane, through the real arm path: flowArm,
 * useExperiment, the live config and the sticky arm in localStorage. ChatReplyPane.spec.js mocks
 * flowArm to test the template switch; this spec mocks only the stores, the log and the session id.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h, Suspense, ref } from 'vue'
import ChatReplyPane from '~/components/ChatReplyPane.vue'
import {
  setLiveExperiments,
  resetLiveExperiments,
} from '~/composables/useExperiment'
import {
  useFlowTelemetry,
  FlowName,
  _resetFlowTelemetryForTests,
} from '~/composables/useFlowTelemetry'

const NAME = 'reply-what-happens-next'
const SENTENCE = 'Your message goes to Jane Doe as a chat on Freegle.'

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
  promisedtoyou: false,
}

const state = vi.hoisted(() => ({
  me: { id: 1 },
  log: vi.fn(),
}))

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

vi.mock('~/stores/message', () => ({
  useMessageStore: () => mockMessageStore,
}))

vi.mock('~/stores/user', () => ({
  useUserStore: () => mockUserStore,
}))

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({
    setReplyOverlayOpen: vi.fn(),
  }),
}))

// No user id, so the assignment id is the session id. The arms below cover every bucket, so which
// bucket it is does not matter.
vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({ forceLogin: false, user: null }),
}))

vi.mock('~/composables/useTrace', () => ({
  getSessionId: () => 'sess-reply',
}))

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    me: ref(state.me),
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
  action: state.log,
  flush: vi.fn(),
  isSignedIn: () => true,
}))

vi.mock('~/constants', () => ({
  FAR_AWAY: 20,
  LAST_SEEN_TOOLTIP: 'When they were last on Freegle',
  REPLY_TIME_TOOLTIP: 'How long they usually take to reply to a message',
  DISTANCE_TOOLTIP:
    'Roughly how far away they are, as the crow flies rather than by road',
  DISTANCE_TOOLTIP_ROAD:
    'Roughly how far away they are by road. Approximate: locations are blurred for privacy',
}))

vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return {
    ...actual,
    useRoute: () => ({ path: '/browse', query: {}, params: {} }),
  }
})

// A running experiment that ends in an hour, with every bucket in the one arm.
function liveArm(arm) {
  setLiveExperiments({
    [NAME]: {
      arms: { [arm]: [0, 99] },
      until: Math.floor(Date.now() / 1000) + 3600,
    },
  })
}

function exposures() {
  return state.log.mock.calls.filter((c) => c[0] === 'experiment_exposure')
}

async function createWrapper() {
  const TestWrapper = defineComponent({
    setup() {
      return () =>
        h(Suspense, null, {
          default: () => h(ChatReplyPane, { messageId: 1 }),
          fallback: () => h('div', 'Loading...'),
        })
    },
  })

  const wrapper = mount(TestWrapper, {
    global: {
      stubs: {
        'v-icon': { template: '<span class="v-icon" />', props: ['icon'] },
        'b-button': {
          template: '<button class="b-button"><slot /></button>',
          props: ['variant', 'size', 'disabled'],
        },
        'b-modal': {
          template: '<div class="b-modal"><slot /><slot name="title" /></div>',
          props: ['id', 'scrollable', 'okOnly', 'okTitle'],
          methods: { show() {}, hide() {} },
        },
        EmailValidator: { template: '<div class="email-validator" />' },
        ChatMessageCard: { template: '<div class="chat-message-card-stub" />' },
        NoticeMessage: {
          template: '<div class="notice-message"><slot /></div>',
          props: ['variant'],
        },
        NewUserInfo: { template: '<div class="new-user-info" />' },
        NewFreegler: { template: '<div class="new-freegler" />' },
        MessagePhotosModal: { template: '<div class="photos-modal-stub" />' },
        SpinButton: {
          template: '<button class="spin-button"><slot /></button>',
          props: ['variant', 'size', 'doneIcon', 'iconName', 'disabled'],
        },
        ChatButton: { template: '<div class="chat-button" />' },
        UserRatings: { template: '<div class="user-ratings" />' },
        SupporterInfo: { template: '<div class="supporter-info" />' },
        ProfileImage: { template: '<div class="profile-image" />' },
        Field: {
          template: '<textarea class="field"></textarea>',
          props: ['id', 'modelValue', 'name', 'rules', 'as', 'rows'],
        },
        ErrorMessage: { template: '<span class="error-message" />' },
        VeeForm: { template: '<form class="vee-form"><slot /></form>' },
        'client-only': { template: '<div><slot /></div>' },
        NuxtLink: { template: '<a><slot /></a>', props: ['to'] },
      },
      directives: { 'b-tooltip': {} },
    },
  })

  await flushPromises()
  return wrapper
}

describe('ChatReplyPane reply-what-happens-next, through the real arm path', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    localStorage.clear()
    resetLiveExperiments()
    _resetFlowTelemetryForTests()
    state.me = { id: 1 }
    mockMessageStore.byId.mockReturnValue(mockMessage)
  })

  afterEach(() => {
    _resetFlowTelemetryForTests()
    resetLiveExperiments()
  })

  it('shows no line to a member in the control arm, and notes that arm once', async () => {
    liveArm('control')
    const wrapper = await createWrapper()

    expect(wrapper.find('.reply-card__what-happens-next').exists()).toBe(false)
    expect(exposures()).toEqual([
      ['experiment_exposure', { experiment: NAME, arm: 'control' }],
    ])
  })

  it('shows the line to a member in arm b, and notes that arm once', async () => {
    liveArm('b')
    const wrapper = await createWrapper()

    const line = wrapper.find('.reply-card__what-happens-next')
    expect(line.exists()).toBe(true)
    expect(line.text()).toBe(SENTENCE)
    expect(exposures()).toEqual([
      ['experiment_exposure', { experiment: NAME, arm: 'b' }],
    ])
  })

  it('shows no line and notes nothing when the experiment is not in the config', async () => {
    setLiveExperiments({})
    const wrapper = await createWrapper()

    expect(wrapper.find('.reply-card__what-happens-next').exists()).toBe(false)
    expect(exposures()).toEqual([])
  })

  it('gives a deleted member no arm: no line and no exposure', async () => {
    liveArm('b')
    state.me = { id: 1, deleted: true }
    const wrapper = await createWrapper()

    expect(wrapper.find('.reply-card__what-happens-next').exists()).toBe(false)
    expect(exposures()).toEqual([])
  })

  it('notes no exposure while the message is not loaded', async () => {
    liveArm('b')
    mockMessageStore.byId.mockReturnValue(undefined)
    const wrapper = await createWrapper()

    expect(wrapper.find('.reply-card__what-happens-next').exists()).toBe(false)
    expect(exposures()).toEqual([])
  })

  it('notes no exposure while the reply is held', async () => {
    liveArm('b')
    mockMessageStore.byId.mockReturnValue({
      ...mockMessage,
      replyeligible: false,
    })
    const wrapper = await createWrapper()

    expect(wrapper.find('.reply-card__what-happens-next').exists()).toBe(false)
    expect(exposures()).toEqual([])
  })

  it('notes the exposure once however many reply panes are opened', async () => {
    liveArm('b')
    await createWrapper()
    await createWrapper()
    await createWrapper()

    expect(exposures()).toHaveLength(1)
  })

  it('keeps the arm frozen into a live reply flow when the config changes', async () => {
    liveArm('b')
    useFlowTelemetry(FlowName.REPLY).start('test')

    // The batch moves everyone to control while the member is part way through replying.
    liveArm('control')
    const wrapper = await createWrapper()

    expect(wrapper.find('.reply-card__what-happens-next').exists()).toBe(true)
    expect(exposures()).toEqual([
      ['experiment_exposure', { experiment: NAME, arm: 'b' }],
    ])
  })
})
