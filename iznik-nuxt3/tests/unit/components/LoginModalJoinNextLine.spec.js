/**
 * The 'join-what-happens-next' experiment: on the join pane of the login modal the variant adds
 * one line saying what happens after joining. The control is the pane as it is. Delete this file
 * with the variant when the experiment is decided.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { shallowMount, flushPromises } from '@vue/test-utils'
import { ref, defineComponent } from 'vue'
import LoginModal from '~/components/LoginModal.vue'

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
  arm: null,
  path: '/',
  loggedInEver: false,
  modtools: false,
  armReads: [],
}))

vi.mock('~/composables/useFlowTelemetry', () => ({
  FlowName: { LOGIN: 'login' },
  flowArm: (flow, name) => {
    state.armReads.push([flow, name])
    return state.arm
  },
}))

vi.mock('~/composables/useLoginTelemetry', () => ({
  useLoginTelemetry: () => ({
    submit: vi.fn(),
    forgot: vi.fn(),
  }),
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

describe('LoginModal join-what-happens-next experiment', () => {
  let wrapper = null

  beforeEach(() => {
    state.arm = null
    state.path = '/'
    state.loggedInEver = false
    state.modtools = false
    state.armReads.length = 0
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
  })

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

  const line = () => wrapper.find('.join-next-line')

  // The member puts the cursor in a field of the email form.
  const touchForm = async () => {
    await wrapper.find('form').trigger('focusin')
    await flushPromises()
  }

  it('control: the join pane has no extra line and is exactly as it was', async () => {
    state.arm = 'control'
    mountModal()
    await flushPromises()
    await touchForm()
    expect(wrapper.text()).toContain('Join Freegle!')
    expect(line().exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Joining is free')
  })

  it('no arm at all (not configured) is control', async () => {
    state.arm = null
    mountModal()
    await flushPromises()
    await touchForm()
    expect(line().exists()).toBe(false)
  })

  it('variant: one line above the Join button says what happens after joining', async () => {
    state.arm = 'b'
    state.path = '/myposts'
    mountModal()
    await flushPromises()
    await touchForm()
    expect(line().exists()).toBe(true)
    expect(line().text()).toBe(
      "Joining is free. You'll be signed in straight away."
    )
    // It sits between the password box and the button.
    const html = wrapper.html()
    expect(html.indexOf('type="password"')).toBeLessThan(
      html.indexOf('join-next-line')
    )
    expect(html.indexOf('join-next-line')).toBeLessThan(
      html.indexOf('Join Freegle!')
    )
  })

  it('variant: the line is the same on every page and promises nothing about the page', async () => {
    state.arm = 'b'
    for (const path of ['/', '/myposts', '/give']) {
      state.path = path
      mountModal()
      await flushPromises()
      await touchForm()
      expect(line().text()).toBe(
        "Joining is free. You'll be signed in straight away."
      )
      expect(line().text()).not.toContain('page')
      wrapper.unmount()
      wrapper = null
    }
  })

  it('variant: the line is readable, not muted small print', async () => {
    state.arm = 'b'
    mountModal()
    await flushPromises()
    await touchForm()
    expect(line().classes()).not.toContain('text-muted')
    expect(line().classes()).toContain('text-body')
  })

  it('variant: no line, and no arm read, until the email form is used', async () => {
    state.arm = 'b'
    mountModal()
    await flushPromises()
    expect(line().exists()).toBe(false)
    expect(state.armReads).toEqual([])

    await touchForm()
    expect(line().exists()).toBe(true)
    expect(state.armReads[0]).toEqual(['login', 'join-what-happens-next'])
  })

  it('never shows in ModTools, which shares the modal but is not a member flow', async () => {
    state.arm = 'b'
    state.path = '/myposts'
    state.modtools = true
    mountModal()
    await flushPromises()
    await touchForm()
    expect(line().exists()).toBe(false)
    expect(state.armReads).toEqual([])
  })

  it('variant: the rest of the pane is the same as control', async () => {
    state.arm = 'control'
    mountModal()
    await flushPromises()
    await touchForm()
    const controlHtml = wrapper.html()
    wrapper.unmount()
    wrapper = null

    state.arm = 'b'
    mountModal()
    await flushPromises()
    await touchForm()
    const variantHtml = wrapper
      .html()
      .replace(/<p[^>]*join-next-line[^>]*>[\s\S]*?<\/p>/, '')
    expect(variantHtml.replace(/<!--[\s\S]*?-->/g, '')).toBe(
      controlHtml.replace(/<!--[\s\S]*?-->/g, '')
    )
  })

  it('variant: the sign in pane has no line and reads no arm', async () => {
    state.arm = 'b'
    state.loggedInEver = true
    mountModal()
    await flushPromises()
    await touchForm()
    expect(wrapper.text()).toContain('Welcome back')
    expect(line().exists()).toBe(false)
    expect(state.armReads).toEqual([])
  })
})
