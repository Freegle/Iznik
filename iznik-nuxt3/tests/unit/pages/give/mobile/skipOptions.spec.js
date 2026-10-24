/**
 * Experiment give-skip-options-screen: on the phone Offer flow, arm 'b' goes from the details step
 * straight to the postcode step, and the postcode step offers a "Delivery or a deadline?" link to
 * the options screen. Any other arm is control: details goes to the options screen and the link is
 * not drawn.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import DetailsPage from '~/pages/give/mobile/details.vue'
import WhereamiPage from '~/pages/give/mobile/whereami.vue'

const mockPush = vi.fn()
const mockReplace = vi.fn()
globalThis.__testUseRouter = () => ({
  push: mockPush,
  replace: mockReplace,
  currentRoute: { value: { path: '/give/mobile/details' } },
})

vi.mock('~/stores/compose', async () => {
  const { reactive, ref } = await import('vue')
  const store = reactive({
    get all() {
      return [{ id: 7, type: 'Offer' }]
    },
    get messages() {
      return { 7: { id: 7, type: 'Offer' } }
    },
    setType: vi.fn(),
    attachments: () => [],
    message: () => ({ id: 7, item: 'Red sofa', description: 'Good sofa' }),
    setItem: vi.fn(),
    setDescription: vi.fn(),
    setAvailableNow: vi.fn(),
    setAttachmentsForMessage: vi.fn(),
    setAiDeclined: vi.fn(),
    // storeToRefs only exposes refs, so email must be one.
    email: ref(''),
  })
  return { useComposeStore: () => store }
})

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({ user: { id: 1 } }),
}))

vi.mock('~/stores/user', () => ({
  useUserStore: () => ({ emailIsInUse: vi.fn() }),
}))

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({ stickyAdRendered: false }),
}))

vi.mock('~/api', () => ({ default: () => ({}) }))

vi.mock('~/composables/useAiIllustration', () => ({
  fetchAiIllustration: vi.fn(),
}))

// The experiment arm. Both pages ask with flowArm(FlowName.GIVE, 'give-skip-options-screen').
let mockArm = null
const mockFlowArm = vi.fn((flow, name) => (name ? mockArm : null))
vi.mock('~/composables/useFlowTelemetry', () => ({
  FlowName: { GIVE: 'give', ASK: 'ask' },
  flowArm: (...args) => mockFlowArm(...args),
}))

// The postcode page's setup() does the real work of posting; here it only supplies the state the
// template reads.
vi.mock('~/composables/useCompose', async () => {
  const { ref } = await import('vue')
  return {
    setup: async () => ({
      initialPostcode: ref(''),
      messageValid: ref(true),
      postcodeValid: ref(false),
      noGroups: ref(false),
      closed: ref(false),
      submitting: ref(false),
      loggedIn: ref(true),
      emailIsntOurs: ref(false),
      notAllowed: ref(false),
      unvalidatedEmail: ref(false),
      wentWrong: ref(false),
      group: ref(null),
    }),
    postcodeSelect: vi.fn(),
    postcodeClear: vi.fn(),
    freegleIt: vi.fn(),
    makeCanSubmit: () => ref(false),
    reportValidationFailure: vi.fn(),
  }
})

const stubs = {
  'b-button': {
    template:
      '<button class="b-btn" @click="$emit(\'click\')"><slot /></button>',
    props: ['variant', 'size', 'disabled'],
    emits: ['click'],
  },
  'b-form-input': { template: '<input />', props: ['modelValue'] },
  'b-form-textarea': { template: '<textarea />', props: ['modelValue'] },
  'v-icon': { template: '<i />' },
  NumberIncrementDecrement: { template: '<div />' },
  OurUploadedImage: { template: '<div />' },
  PostCode: { template: '<div class="postcode-stub" />' },
  ComposeGroup: { template: '<div />' },
  NoticeMessage: { template: '<div><slot /></div>' },
  ExternalLink: { template: '<a><slot /></a>' },
  EmailValidator: { template: '<div />' },
  EmailBelongsToSomeoneElse: { template: '<div />' },
  PostPersonalInfoWarning: { template: '<div />' },
  'nuxt-link': { template: '<a><slot /></a>' },
}

function mountDetails() {
  return mount(DetailsPage, { global: { stubs } })
}

async function mountWhereami() {
  // The page has an async setup, so it needs a Suspense boundary above it.
  const Host = {
    components: { WhereamiPage },
    template: '<Suspense><WhereamiPage /></Suspense>',
  }
  const wrapper = mount(Host, { global: { stubs } })
  await flushPromises()
  return wrapper
}

async function tapNext(wrapper) {
  const next = wrapper.findAll('.b-btn').find((b) => b.text().includes('Next'))
  await next.trigger('click')
}

describe('give-skip-options-screen: details step', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockArm = null
  })

  it('control: Next goes to the options screen', async () => {
    mockArm = null
    const wrapper = mountDetails()
    await tapNext(wrapper)
    expect(mockPush).toHaveBeenCalledWith('/give/mobile/options')
  })

  it('an unknown arm is control', async () => {
    mockArm = 'a'
    const wrapper = mountDetails()
    await tapNext(wrapper)
    expect(mockPush).toHaveBeenCalledWith('/give/mobile/options')
  })

  it('arm b: Next goes straight to the postcode step', async () => {
    mockArm = 'b'
    const wrapper = mountDetails()
    await tapNext(wrapper)
    expect(mockPush).toHaveBeenCalledWith('/give/mobile/whereami')
    expect(mockPush).not.toHaveBeenCalledWith('/give/mobile/options')
    expect(mockFlowArm).toHaveBeenCalledWith('give', 'give-skip-options-screen')
  })

  it('reads the arm only when Next is tapped with a valid item', async () => {
    mockArm = 'b'
    mountDetails()
    expect(mockFlowArm).not.toHaveBeenCalled()
  })
})

describe('give-skip-options-screen: postcode step', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockArm = null
  })

  it('control: no link to the options screen', async () => {
    mockArm = null
    const wrapper = await mountWhereami()
    expect(wrapper.find('[data-testid="options-link"]').exists()).toBe(false)
  })

  it('arm b: shows "Delivery or a deadline?" and it opens the options screen', async () => {
    mockArm = 'b'
    const wrapper = await mountWhereami()
    const link = wrapper.find('[data-testid="options-link"]')
    expect(link.exists()).toBe(true)
    expect(link.text()).toBe('Delivery or a deadline?')
    await link.trigger('click')
    expect(mockPush).toHaveBeenCalledWith('/give/mobile/options')
  })
})
