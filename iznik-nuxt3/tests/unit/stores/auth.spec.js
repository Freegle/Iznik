import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

// Import with .js extension to bypass vitest.config alias that maps
// ~/stores/auth → tests/unit/mocks/auth-store.js (for component tests).
// This test needs the real store implementation.
import { useAuthStore } from '~/stores/auth.js'

const mockLogin = vi.fn()
const mockLogout = vi.fn()
const mockFetchv2 = vi.fn()
const mockRelated = vi.fn()
const mockLostPassword = vi.fn()
const mockUnsubscribe = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    session: {
      login: mockLogin,
      logout: mockLogout,
      fetchv2: mockFetchv2,
      related: mockRelated,
      lostPassword: mockLostPassword,
      unsubscribe: mockUnsubscribe,
    },
  }),
}))

vi.mock('~/api/BaseAPI', () => ({
  abortAllPendingRequests: vi.fn(),
  enterLogoutMode: vi.fn(),
  exitLogoutMode: vi.fn(),
}))

vi.mock('~/api/APIErrors', () => ({
  LoginError: class LoginError extends Error {
    constructor(status, message) {
      super(message)
      this.status = status
    }
  },
  SignUpError: class SignUpError extends Error {},
}))

vi.mock('@capgo/capacitor-social-login', () => ({
  SocialLogin: { initialize: vi.fn(), logout: vi.fn() },
}))

vi.mock('~/stores/compose', () => ({
  useComposeStore: () => ({}),
}))

vi.mock('~/stores/group', () => ({
  useGroupStore: () => ({ list: {}, fetchBatch: vi.fn() }),
}))

vi.mock('~/stores/mobile', () => ({
  useMobileStore: () => ({ isApp: false }),
}))

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({ modtools: false, source: null }),
}))

describe('auth store', () => {
  let store

  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    store = useAuthStore()
    store.init({ public: { BUILD_DATE: '2026-01-01' }, app: {} })
  })

  describe('initial state', () => {
    it('starts with no user and loginCount 0', () => {
      expect(store.user).toBeNull()
      expect(store.loginCount).toBe(0)
      expect(store.loginStateKnown).toBe(false)
      expect(store.forceLogin).toBe(false)
      expect(store.loggedInEver).toBe(false)
    })

    it('starts with empty auth credentials', () => {
      expect(store.auth.jwt).toBeNull()
      expect(store.auth.persistent).toBeNull()
    })
  })

  describe('setAuth', () => {
    it('stores jwt and persistent token', () => {
      store.setAuth('test-jwt', 'test-persistent')
      expect(store.auth.jwt).toBe('test-jwt')
      expect(store.auth.persistent).toBe('test-persistent')
    })
  })

  describe('setUser', () => {
    it('sets user and marks loggedInEver', () => {
      store.setUser({ id: 1, displayname: 'Test' })
      expect(store.user.id).toBe(1)
      expect(store.loggedInEver).toBe(true)
    })

    it('ensures default notification settings exist', () => {
      store.setUser({ id: 1 })
      expect(store.user.settings).toBeDefined()
      expect(store.user.settings.notifications).toBeDefined()
      expect(store.user.settings.notifications.email).toBe(true)
    })

    it('preserves existing settings', () => {
      store.setUser({
        id: 1,
        settings: { notifications: { email: false, push: false } },
      })
      expect(store.user.settings.notifications.email).toBe(false)
      expect(store.user.settings.notifications.push).toBe(false)
    })

    it('removes password from user object', () => {
      store.setUser({ id: 1, password: 'secret123' })
      expect(store.user.password).toBeUndefined()
    })

    it('clears forceLogin when user is set', () => {
      store.forceLogin = true
      store.setUser({ id: 1 })
      expect(store.forceLogin).toBe(false)
    })

    it('sets user to null when called with falsy value', () => {
      store.setUser({ id: 1 })
      store.setUser(null)
      expect(store.user).toBeNull()
    })
  })

  describe('addRelatedUser', () => {
    it('adds user id to userlist', async () => {
      await store.addRelatedUser(42)
      expect(store.userlist).toContain(42)
    })

    it('does not add duplicate ids', async () => {
      await store.addRelatedUser(42)
      await store.addRelatedUser(42)
      expect(store.userlist.filter((id) => id === 42)).toHaveLength(1)
    })

    it('adds new ids to the front', async () => {
      await store.addRelatedUser(1)
      await store.addRelatedUser(2)
      expect(store.userlist[0]).toBe(2)
      expect(store.userlist[1]).toBe(1)
    })

    it('caps userlist at 10 entries', async () => {
      for (let i = 1; i <= 12; i++) {
        await store.addRelatedUser(i)
      }
      expect(store.userlist.length).toBeLessThanOrEqual(10)
    })

    it('calls session.related when multiple users', async () => {
      await store.addRelatedUser(1)
      expect(mockRelated).not.toHaveBeenCalled()
      await store.addRelatedUser(2)
      expect(mockRelated).toHaveBeenCalledWith([2, 1])
    })

    it('ignores falsy id', async () => {
      await store.addRelatedUser(null)
      expect(store.userlist).toHaveLength(0)
    })
  })

  describe('clearRelated', () => {
    it('empties the userlist', async () => {
      await store.addRelatedUser(1)
      store.clearRelated()
      expect(store.userlist).toHaveLength(0)
    })
  })

  describe('login', () => {
    it('sets auth tokens and increments loginCount', async () => {
      mockLogin.mockResolvedValue({ jwt: 'new-jwt', persistent: 'new-p' })
      mockFetchv2.mockResolvedValue({ me: { id: 1 }, groups: [] })

      await store.login({ email: 'test@test.com', password: 'pass' })

      expect(store.auth.jwt).toBe('new-jwt')
      expect(store.auth.persistent).toBe('new-p')
      expect(store.loginCount).toBe(1)
    })

    it('increments loginCount on each login', async () => {
      mockLogin.mockResolvedValue({ jwt: 'jwt', persistent: 'p' })
      mockFetchv2.mockResolvedValue({ me: { id: 1 }, groups: [] })

      await store.login({ email: 'a@b.com', password: 'x' })
      await store.login({ email: 'a@b.com', password: 'x' })

      expect(store.loginCount).toBe(2)
    })

    it('throws LoginError on API failure', async () => {
      const { LoginError } = await import('~/api/APIErrors')
      mockLogin.mockRejectedValue(new LoginError(401, 'Bad creds'))

      await expect(
        store.login({ email: 'a@b.com', password: 'wrong' })
      ).rejects.toThrow('Bad creds')
      expect(store.loginCount).toBe(0)
    })
  })

  describe('logout', () => {
    it('resets user but preserves loginCount and loggedInEver', async () => {
      mockLogin.mockResolvedValue({ jwt: 'jwt', persistent: 'p' })
      mockFetchv2.mockResolvedValue({ me: { id: 1 }, groups: [] })
      await store.login({ email: 'a@b.com', password: 'x' })

      expect(store.loginCount).toBe(1)
      expect(store.loggedInEver).toBe(true)

      await store.logout()

      expect(store.user).toBeNull()
      expect(store.auth.jwt).toBeNull()
      expect(store.loginCount).toBe(1)
      expect(store.loggedInEver).toBe(true)
    })
  })

  describe('disableGoogleAutoselect', () => {
    it('returns cleanly when window is undefined (simulates post-teardown setTimeout)', () => {
      // Reproduces the Vitest unhandled-error seen when logout() scheduled a
      // 100ms retry via setTimeout and that retry fired AFTER the test env
      // had been torn down. A bare `window` reference in the guard threw
      // ReferenceError; the fix uses `typeof window === 'undefined'`.
      const originalWindow = globalThis.window
      // eslint-disable-next-line no-undef
      delete globalThis.window
      try {
        expect(() => store.disableGoogleAutoselect()).not.toThrow()
      } finally {
        globalThis.window = originalWindow
      }
    })
  })

  describe('lostPassword', () => {
    it('returns worked=true on success', async () => {
      mockLostPassword.mockResolvedValue({})
      const result = await store.lostPassword('test@test.com')
      expect(result.worked).toBe(true)
      expect(result.unknown).toBe(false)
    })

    it('returns unknown=true on 404', async () => {
      mockLostPassword.mockRejectedValue({ response: { status: 404 } })
      const result = await store.lostPassword('nobody@test.com')
      expect(result.worked).toBe(true)
      expect(result.unknown).toBe(true)
    })

    it('returns worked=false on other errors', async () => {
      mockLostPassword.mockRejectedValue(new Error('network'))
      const result = await store.lostPassword('test@test.com')
      expect(result.worked).toBe(false)
    })
  })

  describe('persistence config', () => {
    it('does not persist loginCount (verified via store source)', () => {
      // loginCount was removed from persistence to prevent SSR hydration
      // race conditions with the app.vue watcher (see commit f8af3c7f).
      // The persist.pick array in stores/auth.js should not include loginCount.
      // We verify by reading the store definition file directly.
      const fs = require('fs')
      const path = require('path')
      const storePath = path.resolve(__dirname, '../../../stores/auth.js')
      const source = fs.readFileSync(storePath, 'utf8')
      const pickMatch = source.match(/pick:\s*\[([^\]]+)\]/)
      expect(pickMatch).toBeTruthy()
      expect(pickMatch[1]).not.toContain('loginCount')
    })
  })
})
