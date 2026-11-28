/**
 * The phone Give flow's postcode step. When the member cannot continue, the page has to say
 * why: a closed community, no community nearby, an invalid post or a deleted account. It must not
 * fall through to "Enter email to continue" for a member who is logged in and has no email field.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import WhereamiPage from '~/pages/give/mobile/whereami.vue'

globalThis.__testUseRouter = () => ({ push: vi.fn(), replace: vi.fn() })

const state = vi.hoisted(() => ({}))

vi.mock('~/stores/compose', async () => {
  const { reactive, ref } = await import('vue')
  const store = reactive({
    get all() {
      return [{ id: 7, type: 'Offer' }]
    },
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

vi.mock('~/composables/useFlowTelemetry', () => ({
  FlowName: { GIVE: 'give', ASK: 'ask' },
  flowArm: () => null,
}))

vi.mock('~/composables/useMe', async () => {
  const { computed } = await import('vue')
  return {
    useMe: () => ({ me: computed(() => state.me) }),
  }
})

// The page's setup() supplies the state the template reads; makeCanSubmit is the real one.
vi.mock('~/composables/useCompose', async () => {
  const { ref } = await import('vue')
  const actual = await vi.importActual('~/composables/useCompose')
  return {
    setup: async () => ({
      initialPostcode: ref(''),
      messageValid: ref(state.messageValid),
      postcodeValid: ref(state.postcodeValid),
      noGroups: ref(state.noGroups),
      closed: ref(state.closed),
      submitting: ref(false),
      loggedIn: ref(state.loggedIn),
      emailIsntOurs: ref(false),
      notAllowed: ref(false),
      unvalidatedEmail: ref(false),
      wentWrong: ref(false),
      group: ref(null),
    }),
    postcodeSelect: vi.fn(),
    postcodeClear: vi.fn(),
    freegleIt: vi.fn(),
    makeCanSubmit: actual.makeCanSubmit,
    reportValidationFailure: vi.fn(),
  }
})

const stubs = {
  'b-button': {
    template: '<button class="b-btn" :disabled="disabled"><slot /></button>',
    props: ['variant', 'size', 'disabled'],
  },
  'v-icon': { template: '<i />' },
  PostCode: { template: '<div />' },
  ComposeGroup: { template: '<div />' },
  NoticeMessage: { template: '<div class="notice"><slot /></div>' },
  ExternalLink: { template: '<a><slot /></a>' },
  EmailValidator: { template: '<div />' },
  EmailBelongsToSomeoneElse: { template: '<div />' },
  PostPersonalInfoWarning: { template: '<div />' },
  'nuxt-link': { template: '<a><slot /></a>' },
}

async function mountPage() {
  const Host = {
    components: { WhereamiPage },
    template: '<Suspense><WhereamiPage /></Suspense>',
  }
  const wrapper = mount(Host, { global: { stubs } })
  await flushPromises()
  return wrapper
}

describe('give/mobile/whereami: why the member cannot continue', () => {
  beforeEach(() => {
    state.me = { id: 1, deleted: null }
    state.messageValid = true
    state.postcodeValid = true
    state.noGroups = false
    state.closed = false
    state.loggedIn = true
  })

  it('says the community is closed rather than asking a logged in member for an email', async () => {
    state.closed = true

    const wrapper = await mountPage()

    const button = wrapper.find('.app-footer .b-btn')
    expect(button.attributes('disabled')).toBeDefined()
    expect(button.text()).not.toContain('email')
    expect(wrapper.text().toLowerCase()).toContain('closed')
  })

  it('does not ask a logged in member for an email when the post is not valid', async () => {
    state.messageValid = false

    const wrapper = await mountPage()

    expect(wrapper.find('.app-footer .b-btn').text()).not.toContain('email')
  })

  it('does not ask a logged in member for an email when there is no community nearby', async () => {
    state.noGroups = true

    const wrapper = await mountPage()

    expect(wrapper.find('.app-footer .b-btn').text()).not.toContain('email')
  })

  it('still asks a member who is not logged in for their email', async () => {
    state.loggedIn = false

    const wrapper = await mountPage()

    expect(wrapper.find('.app-footer .b-btn').text()).toContain('email')
  })

  it('tells a member whose account is deleted that they cannot post, and blocks posting', async () => {
    state.me = { id: 1, deleted: '2026-01-01T00:00:00Z' }

    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('restore your account')
    expect(
      wrapper.find('.app-footer .b-btn').attributes('disabled')
    ).toBeDefined()
    expect(wrapper.find('.app-footer .b-btn').text()).not.toContain(
      'Freegle it'
    )
  })
})
