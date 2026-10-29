import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import GoogleOneTap from '~/components/GoogleOneTap.vue'

const mockAuthStore = {
  loggedIn: false,
  login: vi.fn().mockResolvedValue(undefined),
}

const { mockRuntimeConfig } = vi.hoisted(() => {
  return {
    mockRuntimeConfig: {
      public: {
        GOOGLE_CLIENT_ID: 'test-client-id',
        CIRCLECI: false,
      },
    },
  }
})

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => mockAuthStore,
}))

vi.mock('#app', () => ({
  useRuntimeConfig: () => mockRuntimeConfig,
}))

vi.mock('jwt-decode', () => ({
  // jwt-decode v4 dropped the default export in favour of jwtDecode.
  jwtDecode: vi.fn().mockReturnValue({
    email: 'test@example.com',
    name: 'Test User',
  }),
}))

describe('GoogleOneTap', () => {
  let mockGoogle

  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers()
    mockAuthStore.loggedIn = false
    mockRuntimeConfig.public.CIRCLECI = false

    mockGoogle = {
      accounts: {
        id: {
          initialize: vi.fn(),
          prompt: vi.fn((callback) => callback()),
        },
      },
    }

    vi.stubGlobal('google', mockGoogle)
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  // The callback Google is given: it hands Google's credential to the component.
  function credentialCallback() {
    return mockGoogle.accounts.id.initialize.mock.calls[0][0].callback
  }

  function createWrapper() {
    return mount(GoogleOneTap, {
      global: {
        stubs: {
          ClientOnly: {
            template: '<div><slot /></div>',
          },
        },
      },
    })
  }

  describe('rendering', () => {
    // A g_id_onload element is Google's HTML way to set the library up. One
    // rendered after initialize() is a second, conflicting set of settings.
    it('renders no g_id_onload element', async () => {
      const wrapper = createWrapper()
      await flushPromises()

      expect(wrapper.find('#g_id_onload').exists()).toBe(false)
    })
  })

  describe('Google SDK initialization', () => {
    it('initializes Google SDK on mount, with FedCM for the prompt', async () => {
      createWrapper()
      await flushPromises()

      expect(mockGoogle.accounts.id.initialize).toHaveBeenCalledWith({
        client_id: 'test-client-id',
        callback: expect.any(Function),
        use_fedcm_for_prompt: true,
      })
    })

    it('calls Google prompt after initialization', async () => {
      createWrapper()
      await flushPromises()

      expect(mockGoogle.accounts.id.prompt).toHaveBeenCalled()
    })
  })

  describe('logged in state', () => {
    it('emits complete when already logged in', async () => {
      mockAuthStore.loggedIn = true
      const wrapper = createWrapper()
      await flushPromises()

      expect(wrapper.emitted('complete')).toBeTruthy()
    })

    it('does not initialize Google SDK when already logged in', async () => {
      mockAuthStore.loggedIn = true
      createWrapper()
      await flushPromises()

      expect(mockGoogle.accounts.id.initialize).not.toHaveBeenCalled()
    })
  })

  describe('fallback timeout', () => {
    it('emits complete after timeout', async () => {
      const wrapper = createWrapper()
      await flushPromises()

      vi.advanceTimersByTime(15000)

      expect(wrapper.emitted('complete')).toBeTruthy()
    })
  })

  describe('credential response handling', () => {
    it('calls authStore.login with credential', async () => {
      createWrapper()
      await flushPromises()

      await credentialCallback()({
        credential: 'test-jwt-token',
      })

      expect(mockAuthStore.login).toHaveBeenCalledWith({
        googlejwt: 'test-jwt-token',
        googlelogin: true,
      })
    })

    it('emits loggedin on successful login', async () => {
      const wrapper = createWrapper()
      await flushPromises()

      await credentialCallback()({
        credential: 'test-jwt-token',
      })

      expect(wrapper.emitted('loggedin')).toBeTruthy()
    })

    it('emits complete when already logged in on callback', async () => {
      const wrapper = createWrapper()
      await flushPromises()

      mockAuthStore.loggedIn = true

      await credentialCallback()({
        credential: 'test-jwt-token',
      })

      expect(wrapper.emitted('complete')).toBeTruthy()
    })
  })

  describe('CircleCI environment', () => {
    it('emits complete immediately in CircleCI', async () => {
      mockRuntimeConfig.public.CIRCLECI = true

      const wrapper = createWrapper()
      await flushPromises()

      expect(wrapper.emitted('complete')).toBeTruthy()
      expect(mockGoogle.accounts.id.initialize).not.toHaveBeenCalled()
    })
  })

  describe('error handling', () => {
    it('handles Google SDK initialization error gracefully', async () => {
      mockGoogle.accounts.id.initialize = vi.fn(() => {
        throw new Error('SDK error')
      })

      const wrapper = createWrapper()
      await flushPromises()

      expect(wrapper.emitted('complete')).toBeTruthy()
    })

    it('handles prompt error gracefully', async () => {
      mockGoogle.accounts.id.prompt = vi.fn(() => {
        throw new Error('Prompt error')
      })

      const wrapper = createWrapper()
      await flushPromises()

      expect(wrapper.emitted('complete')).toBeTruthy()
    })
  })
})
