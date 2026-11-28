/**
 * The desktop Give flow's last step. Posting needs a postcode and an open community, so
 * a member who arrives without them (a deep link, cleared storage) must not get an enabled
 * button that ends in a generic error.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import WhoamiPage from '~/pages/give/whoami.vue'

globalThis.__testUseRouter = () => ({ push: vi.fn(), replace: vi.fn() })
globalThis.__testUseRoute = () => ({
  params: {},
  query: {},
  path: '/give/whoami',
})
globalThis.useHead = () => {}

const state = vi.hoisted(() => ({}))

vi.mock('~/stores/compose', async () => {
  const { reactive, ref } = await import('vue')
  const store = reactive({
    messages: [{ id: 7, type: 'Offer' }],
    postcode: null,
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

vi.mock('~/api', () => ({ default: () => ({}) }))

vi.mock('~/composables/useBuildHead', () => ({ buildHead: () => ({}) }))

vi.mock('~/composables/useMe', async () => {
  const { ref } = await import('vue')
  return {
    useMe: () => ({ me: ref({ id: 1, email: 'freegler@example.com' }) }),
  }
})

vi.mock('~/composables/useCompose', async () => {
  const { ref } = await import('vue')
  const actual = await vi.importActual('~/composables/useCompose')
  return {
    setup: async () => ({
      messageValid: ref(true),
      postcodeValid: ref(state.postcodeValid),
      noGroups: ref(state.noGroups),
      closed: ref(state.closed),
      loggedIn: ref(true),
      emailIsntOurs: ref(false),
      submitting: ref(false),
      notAllowed: ref(false),
      unvalidatedEmail: ref(false),
      wentWrong: ref(false),
    }),
    freegleIt: vi.fn(),
    makeCanSubmit: actual.makeCanSubmit,
    reportValidationFailure: vi.fn(),
  }
})

const stubs = {
  'client-only': { template: '<div><slot /></div>' },
  'b-button': {
    template: '<button class="b-btn" :disabled="disabled"><slot /></button>',
    props: ['variant', 'size', 'disabled'],
  },
  'v-icon': { template: '<i />' },
  WizardProgressCompact: { template: '<div />' },
  NoticeMessage: { template: '<div class="notice"><slot /></div>' },
  ExternalLink: { template: '<a><slot /></a>' },
  EmailValidator: { template: '<div />' },
  EmailBelongsToSomeoneElse: { template: '<div />' },
  PostLoggedInEmail: { template: '<div />' },
  'nuxt-link': { template: '<a><slot /></a>' },
}

async function mountPage() {
  const Host = {
    components: { WhoamiPage },
    template: '<Suspense><WhoamiPage /></Suspense>',
  }
  const wrapper = mount(Host, { global: { stubs } })
  await flushPromises()
  return wrapper
}

describe('give/whoami: the post button', () => {
  beforeEach(() => {
    state.postcodeValid = true
    state.noGroups = false
    state.closed = false
  })

  it('is enabled for a logged in member with a postcode and an open community', async () => {
    const wrapper = await mountPage()

    expect(wrapper.find('.next-btn').text()).toContain('Freegle it')
  })

  it('is disabled when there is no valid postcode', async () => {
    state.postcodeValid = false

    const wrapper = await mountPage()

    expect(wrapper.find('.next-btn').attributes('disabled')).toBeDefined()
  })

  it('is disabled when the community is closed', async () => {
    state.closed = true

    const wrapper = await mountPage()

    expect(wrapper.find('.next-btn').attributes('disabled')).toBeDefined()
  })

  it('is disabled when there is no community nearby', async () => {
    state.noGroups = true

    const wrapper = await mountPage()

    expect(wrapper.find('.next-btn').attributes('disabled')).toBeDefined()
  })
})
