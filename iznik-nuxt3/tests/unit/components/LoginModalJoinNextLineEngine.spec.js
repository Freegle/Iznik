/**
 * The 'join-what-happens-next' experiment through the real engine: useExperiment, flowArm and the
 * login flow instance are not mocked, only the config the engine reads, the session and the log
 * sink. This is what proves the arm really decides what a member sees and that the exposure is
 * counted once, and only for a member who used the email form. Delete this file with the variant
 * when the experiment is decided.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { shallowMount, flushPromises } from '@vue/test-utils'
import { ref, defineComponent } from 'vue'
import LoginModal from '~/components/LoginModal.vue'
import {
  setLiveExperiments,
  resetLiveExperiments,
} from '~/composables/useExperiment'
import { _resetFlowTelemetryForTests } from '~/composables/useFlowTelemetry'

vi.mock('vue', async (importOriginal) => {
  const actual = await importOriginal()
  return {
    ...actual,
    defineAsyncComponent: () =>
      defineComponent({
        name: 'MockedAsyncComponent',
        template: '<div class="mocked-async-component"><slot /></div>',
      }),
  }
})

const state = vi.hoisted(() => ({
  path: '/',
  loggedInEver: false,
  modtools: false,
}))

const log = vi.hoisted(() => ({ action: vi.fn() }))

vi.mock('~/composables/useClientLog', () => ({
  action: log.action,
  flush: vi.fn(),
  isSignedIn: () => false,
}))

vi.mock('~/composables/useTrace', () => ({
  getSessionId: () => 'sess-join-next',
}))

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({
    login: vi.fn().mockResolvedValue(),
    signUp: vi.fn().mockResolvedValue(),
    fetchUser: vi.fn().mockResolvedValue(),
    get loggedInEver() {
      return state.loggedInEver
    },
    loginType: null,
    forceLogin: true,
  }),
}))

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({
    marketingConsent: true,
    setMarketingConsent: vi.fn(),
    get modtools() {
      return state.modtools
    },
  }),
}))

vi.mock('~/stores/mobile', () => ({
  useMobileStore: () => ({ isApp: false, isiOS: false, osVersion: '16.0' }),
}))

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    get me() {
      return ref(null)
    },
    get loggedIn() {
      return ref(false)
    },
  }),
}))

vi.mock('pinia', async (importOriginal) => {
  const actual = await importOriginal()
  const { ref } = require('vue')
  return {
    ...actual,
    storeToRefs: (store) => ({
      loggedInEver: ref(store.loggedInEver),
      loginType: ref(store.loginType),
      forceLogin: ref(store.forceLogin),
    }),
  }
})

vi.hoisted(() => {
  vi.resetModules()
})

vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return {
    ...actual,
    useRoute: () => ({ params: {}, query: {}, path: state.path }),
    useRouter: () => ({ push: vi.fn() }),
    useRuntimeConfig: () => ({ public: { GOOGLE_CLIENT_ID: 'x' } }),
  }
})

vi.mock('#app', async () => {
  const actual = await vi.importActual('#imports')
  return {
    ...actual,
    useRuntimeConfig: () => ({ public: { GOOGLE_CLIENT_ID: 'x' } }),
  }
})

globalThis.__testUseRoute = () => ({ params: {}, query: {}, path: state.path })
globalThis.__testUseRouter = () => ({ push: vi.fn() })

vi.mock('~/api', () => ({
  default: () => ({ bandit: { chosen: vi.fn(), shown: vi.fn() } }),
}))

vi.mock('@capgo/capacitor-social-login', () => ({
  SocialLogin: { initialize: vi.fn(), login: vi.fn() },
}))
vi.mock('@sentry/browser', () => ({
  captureException: vi.fn(),
  captureMessage: vi.fn(),
}))
vi.mock('@capacitor-community/apple-sign-in', () => ({
  SignInWithApple: { authorize: vi.fn() },
}))

describe('LoginModal join-what-happens-next experiment, real engine', () => {
  let wrapper = null

  beforeEach(() => {
    state.path = '/'
    state.loggedInEver = false
    state.modtools = false
    localStorage.clear()
    log.action.mockClear()
    resetLiveExperiments()
    _resetFlowTelemetryForTests()
    global.window.FB = { login: vi.fn(), init: vi.fn() }
    global.window.google = {
      accounts: {
        id: {
          initialize: vi.fn(),
          renderButton: vi.fn(),
          disableAutoSelect: vi.fn(),
        },
      },
    }
    global.window.PasswordCredential = null
  })

  afterEach(() => {
    if (wrapper) {
      wrapper.unmount()
      wrapper = null
    }
    _resetFlowTelemetryForTests()
  })

  // The config the batch publishes for a running experiment that puts everyone in one arm.
  function serve(arm) {
    setLiveExperiments({
      'join-what-happens-next': {
        arms: { [arm]: [0, 99] },
        until: Math.floor(Date.now() / 1000) + 12 * 3600,
      },
    })
  }

  const exposures = () =>
    log.action.mock.calls
      .filter(([name]) => name === 'experiment_exposure')
      .map(([, data]) => data)

  const flowStarts = () =>
    log.action.mock.calls
      .filter(([name]) => name === 'flow_start')
      .map(([, data]) => data)

  const line = () => wrapper.find('.join-next-line')

  const touchForm = async () => {
    await wrapper.find('form').trigger('focusin')
    await flushPromises()
  }

  function mountModal() {
    wrapper = shallowMount(LoginModal, {
      attachTo: document.body,
      global: {
        stubs: {
          'b-modal': {
            template: `<div v-if="modelValue" class="b-modal"><slot name="title" /><slot /></div>`,
            props: ['modelValue'],
          },
          'b-button': {
            template:
              '<button :class="$attrs.class" @click="$emit(\'click\', $event)"><slot /></button>',
            emits: ['click'],
          },
          'b-img': { template: '<img />' },
          'b-form': { template: '<form><slot /></form>' },
          'b-form-group': { template: '<div><slot /></div>' },
          'b-form-input': { template: '<input />' },
          'b-form-checkbox': { template: '<label><slot /></label>' },
          'b-alert': { template: '<div class="alert"><slot /></div>' },
          'nuxt-link': { template: '<a><slot /></a>' },
          EmailValidator: { template: '<input class="email-validator" />' },
          NoticeMessage: { template: '<div><slot /></div>' },
          PasswordEntry: { template: '<input type="password" />' },
        },
        config: {
          globalProperties: {
            gtm: { enabled: () => false, trackEvent: vi.fn() },
          },
        },
      },
    })
    return wrapper
  }

  it('arm b: the line appears once the email form is used', async () => {
    serve('b')
    mountModal()
    await flushPromises()
    expect(flowStarts().length).toBe(1)
    expect(flowStarts()[0].variant).toBe('join-what-happens-next:b')

    await touchForm()
    expect(line().exists()).toBe(true)
    expect(line().text()).toBe(
      "Joining is free. You'll be signed in straight away."
    )
  })

  it('control: no line, and the arm is still counted as seen once', async () => {
    serve('control')
    mountModal()
    await flushPromises()
    await touchForm()
    expect(line().exists()).toBe(false)
    expect(wrapper.text()).toContain('Join Freegle!')
    expect(exposures()).toEqual([
      { experiment: 'join-what-happens-next', arm: 'control' },
    ])
  })

  it('not configured: control, no line, no exposure', async () => {
    mountModal()
    await flushPromises()
    await touchForm()
    expect(line().exists()).toBe(false)
    expect(exposures()).toEqual([])
  })

  it('arm b: the exposure is counted once, however often the form is used', async () => {
    serve('b')
    mountModal()
    await flushPromises()
    await touchForm()
    await touchForm()
    await touchForm()
    expect(exposures()).toEqual([
      { experiment: 'join-what-happens-next', arm: 'b' },
    ])
  })

  it('arm b: a member who never uses the email form is not counted as exposed', async () => {
    serve('b')
    mountModal()
    await flushPromises()
    expect(line().exists()).toBe(false)
    expect(exposures()).toEqual([])
  })

  it('arm b: the sign in pane never shows the line and counts no exposure', async () => {
    serve('b')
    state.loggedInEver = true
    mountModal()
    await flushPromises()
    await touchForm()
    expect(wrapper.text()).toContain('Welcome back')
    expect(line().exists()).toBe(false)
    expect(exposures()).toEqual([])
  })

  it('arm b: ModTools shows no line and counts no exposure', async () => {
    serve('b')
    state.modtools = true
    mountModal()
    await flushPromises()
    await touchForm()
    expect(line().exists()).toBe(false)
    expect(exposures()).toEqual([])
  })

  it('the arm is the one frozen into the login flow when the pane opened', async () => {
    serve('b')
    mountModal()
    await flushPromises()
    // The published arms change while the pane is open; the member stays in the arm the flow
    // events are stamped with.
    serve('control')
    await touchForm()
    expect(line().exists()).toBe(true)
  })
})
