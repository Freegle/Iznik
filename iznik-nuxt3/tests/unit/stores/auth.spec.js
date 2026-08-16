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
const mockSave = vi.fn()
const mockSetAppOutOfDate = vi.fn()
const mockSignUp = vi.fn()
const mockTrackConversion = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    session: {
      login: mockLogin,
      logout: mockLogout,
      fetchv2: mockFetchv2,
      related: mockRelated,
      lostPassword: mockLostPassword,
      unsubscribe: mockUnsubscribe,
      save: mockSave,
    },
    user: {
      signUp: mockSignUp,
    },
  }),
}))

vi.mock('~/composables/useTrackConversion', () => ({
  trackConversion: (...args) => mockTrackConversion(...args),
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

// A controllable compose-store mock so we can verify the resume-after-login wiring
// in setUser(). resumePendingSubmit returns a promise because setUser calls .catch()
// on it.
const mockCompose = vi.hoisted(() => ({
  pendingSubmit: null,
  clearPendingSubmit: vi.fn(),
  resumePendingSubmit: vi.fn(() => Promise.resolve()),
}))

vi.mock('~/stores/compose', () => ({
  useComposeStore: () => mockCompose,
}))

const mockFetchBatch = vi.fn()
vi.mock('~/stores/group', () => ({
  useGroupStore: () => ({ list: {}, fetchBatch: mockFetchBatch }),
}))

vi.mock('~/stores/mobile', () => ({
  useMobileStore: () => ({ isApp: false }),
}))

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({
    modtools: false,
    source: null,
    setAppOutOfDate: mockSetAppOutOfDate,
  }),
}))

describe('auth store', () => {
  let store

  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    mockCompose.pendingSubmit = null
    store = useAuthStore()
    store.init({ public: { BUILD_DATE: '2026-01-01' }, app: {} })
  })

  describe('resume deferred submit on login', () => {
    it('fires resumePendingSubmit when a deferred submit is pending', () => {
      mockCompose.pendingSubmit = {
        message: { type: 'Offer', item: 'Sofa' },
        email: 'a@b.com',
        options: {},
        at: Date.now(),
      }
      store.setUser({ id: 42, displayname: 'Test' })
      expect(mockCompose.resumePendingSubmit).toHaveBeenCalledTimes(1)
    })

    it('does not fire resumePendingSubmit when nothing is pending', () => {
      mockCompose.pendingSubmit = null
      store.setUser({ id: 42, displayname: 'Test' })
      expect(mockCompose.resumePendingSubmit).not.toHaveBeenCalled()
    })
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

    it('recovers when userlist rehydrated as a non-array', async () => {
      // State-shape drift: persisted userlist can come back as an object
      // instead of an array, which used to make .includes() throw
      // (Sentry: "this.userlist.includes is not a function").
      store.userlist = { 0: 99 }
      await store.addRelatedUser(42)
      expect(Array.isArray(store.userlist)).toBe(true)
      expect(store.userlist).toContain(42)
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

    it('fires Register with Website when a social login creates the account', async () => {
      mockLogin.mockResolvedValue({ jwt: 'jwt', persistent: 'p' })
      // Account created moments ago - this social login IS the registration.
      mockFetchv2.mockResolvedValue({
        me: { id: 1, added: new Date().toISOString() },
        groups: [],
      })

      await store.login({ googlejwt: 'tok', googlelogin: true })

      expect(mockTrackConversion).toHaveBeenCalledWith('Register with Website')
    })

    it('does not fire Register with Website for a returning social login', async () => {
      mockLogin.mockResolvedValue({ jwt: 'jwt', persistent: 'p' })
      mockFetchv2.mockResolvedValue({
        me: { id: 1, added: '2020-01-01T00:00:00Z' },
        groups: [],
      })

      await store.login({ fblogin: 1, fbaccesstoken: 'tok' })

      expect(mockTrackConversion).not.toHaveBeenCalled()
    })

    it('does not fire Register with Website for an email login even to a fresh account', async () => {
      // Native signups are tracked in signUp(); a fresh email login must not
      // double-count (e.g. the auto-login right after posting anonymously).
      mockLogin.mockResolvedValue({ jwt: 'jwt', persistent: 'p' })
      mockFetchv2.mockResolvedValue({
        me: { id: 1, added: new Date().toISOString() },
        groups: [],
      })

      await store.login({ email: 'a@b.com', password: 'x' })

      expect(mockTrackConversion).not.toHaveBeenCalled()
    })
  })

  describe('signUp', () => {
    it('fires Register with Website only after the server confirms signup', async () => {
      mockSignUp.mockResolvedValue({ jwt: 'jwt', persistent: 'p' })
      mockFetchv2.mockResolvedValue({ me: { id: 1 }, groups: [] })

      await store.signUp({
        fullname: 'Test User',
        email: 'new@test.com',
        password: 'pw',
      })

      expect(mockTrackConversion).toHaveBeenCalledWith('Register with Website')
    })

    it('does not fire Register with Website when signup fails', async () => {
      mockSignUp.mockRejectedValue({
        response: { status: 409, data: { message: 'Email in use' } },
      })

      await expect(
        store.signUp({
          fullname: 'Test User',
          email: 'dup@test.com',
          password: 'pw',
        })
      ).rejects.toThrow()

      expect(mockTrackConversion).not.toHaveBeenCalled()
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

      delete globalThis.window
      try {
        expect(() => store.disableGoogleAutoselect()).not.toThrow()
      } finally {
        globalThis.window = originalWindow
      }
    })

    it('calls disableAutoSelect when window.google.accounts.id is available', () => {
      const mockDisableAutoSelect = vi.fn()
      const originalGoogle = globalThis.window.google
      globalThis.window.google = {
        accounts: { id: { disableAutoSelect: mockDisableAutoSelect } },
      }
      try {
        expect(() => store.disableGoogleAutoselect()).not.toThrow()
        expect(mockDisableAutoSelect).toHaveBeenCalled()
      } finally {
        if (originalGoogle === undefined) {
          delete globalThis.window.google
        } else {
          globalThis.window.google = originalGoogle
        }
      }
    })

    it('handles disableAutoSelect throwing (catches error silently)', () => {
      const originalGoogle = globalThis.window.google
      globalThis.window.google = {
        accounts: {
          id: {
            disableAutoSelect: vi.fn(() => {
              throw new Error('Google error')
            }),
          },
        },
      }
      try {
        expect(() => store.disableGoogleAutoselect()).not.toThrow()
      } finally {
        if (originalGoogle === undefined) {
          delete globalThis.window.google
        } else {
          globalThis.window.google = originalGoogle
        }
      }
    })
  })

  describe('lostPassword', () => {
    it('returns worked=true on success', async () => {
      // Backend returns ret:0 when a reset email has been queued.
      mockLostPassword.mockResolvedValue({ ret: 0 })
      const result = await store.lostPassword('test@test.com')
      expect(result.worked).toBe(true)
      expect(result.unknown).toBe(false)
    })

    it('returns unknown=true and worked=false on 404 (unknown email)', async () => {
      mockLostPassword.mockRejectedValue({ response: { status: 404 } })
      const result = await store.lostPassword('nobody@test.com')
      expect(result.worked).toBe(false)
      expect(result.unknown).toBe(true)
    })

    it('returns worked=false for a social-login-only account (ret:1)', async () => {
      // Backend returns HTTP 200 with ret:1/socialSignin:true and queues no
      // email, so the store must not report success.
      mockLostPassword.mockResolvedValue({ ret: 1, socialSignin: true })
      const result = await store.lostPassword('social@test.com')
      expect(result.worked).toBe(false)
      expect(result.unknown).toBe(false)
    })

    it('returns worked=false on other errors', async () => {
      mockLostPassword.mockRejectedValue(new Error('network'))
      const result = await store.lostPassword('test@test.com')
      expect(result.worked).toBe(false)
    })
  })

  // Reproduces the production "PATCH /session 401" signature from the
  // forgot-password flow: the Go API's sessions.series bug (hex string
  // coerced to bigint by MySQL, collapsing ≥7,000 rows to series=0) meant
  // that a user's JWT could point at a sessions row that was effectively
  // unreachable or had been purged. When the forgot-password page then
  // submitted a new password via PATCH /session, the middleware's
  // sessions JOIN users check failed and returned 401 — even though the
  // user existed and their JWT signature was valid.
  //
  // At the store boundary this surfaces as saveAndGet rejecting, with
  // BaseAPI having already wiped auth (simulated here by clearing the
  // auth state as BaseAPI's 401 path does).
  describe('forgot-password PATCH /session 401 repro', () => {
    it('saveAndGet rejects and leaves auth wiped when PATCH /session returns 401', async () => {
      // User just landed via ?u=X&k=KEY link; u/k login set fresh auth.
      store.setAuth('jwt-from-u-k-login', 'persistent-from-u-k-login')

      // Simulate the production scenario: the server returns 401 for the
      // PATCH. BaseAPI's real implementation would wipe auth before the
      // error propagates up to the store — emulate that here.
      mockSave.mockImplementation(() => {
        store.setAuth(null, null)
        store.setUser(null)
        const err = new Error('Unauthorized')
        err.response = { status: 401 }
        return Promise.reject(err)
      })

      await expect(
        store.saveAndGet({ password: 'newpassword' })
      ).rejects.toThrow('Unauthorized')

      expect(mockSave).toHaveBeenCalledWith({ password: 'newpassword' })
      expect(store.auth.jwt).toBeNull()
      expect(store.auth.persistent).toBeNull()
      expect(store.user).toBeNull()
    })

    it('saveAndGet succeeds when PATCH /session returns 200', async () => {
      store.setAuth('valid-jwt', 'valid-persistent')
      mockSave.mockResolvedValue({})
      mockFetchv2.mockResolvedValue({ me: { id: 42 }, groups: [] })

      await store.saveAndGet({ password: 'newpassword' })

      expect(store.auth.jwt).toBe('valid-jwt')
      expect(store.user.id).toBe(42)
    })
  })

  describe('fetchUser app out of date (ret:123)', () => {
    it('flags the app as out of date and preserves auth when session returns ret:123', async () => {
      store.setAuth('valid-jwt', 'valid-persistent')

      // Server kill switch: GET /session returns HTTP 200 with ret:123 when the
      // client build is older than app_min_webversion.
      mockFetchv2.mockResolvedValue({
        ret: 123,
        status: 'App is out of date - please upgrade or use the website',
      })

      await store.fetchUser()

      // Auth must NOT be wiped (otherwise the user is silently bounced to the
      // login screen, which looks like a generic failure).
      expect(store.auth.jwt).toBe('valid-jwt')
      expect(store.auth.persistent).toBe('valid-persistent')
      expect(store.user).toBeNull()
      // ...and the message must be surfaced clearly.
      expect(mockSetAppOutOfDate).toHaveBeenCalledWith(
        'App is out of date - please upgrade or use the website'
      )
    })

    it('does not flag out of date on a normal successful session', async () => {
      store.setAuth('valid-jwt', 'valid-persistent')
      mockFetchv2.mockResolvedValue({ me: { id: 5 }, groups: [] })

      await store.fetchUser()

      expect(mockSetAppOutOfDate).not.toHaveBeenCalled()
      expect(store.user.id).toBe(5)
    })
  })

  describe('fetchUser group batch off the critical path', () => {
    it('resolves without waiting for the group detail batch', async () => {
      store.setAuth('valid-jwt', 'valid-persistent')
      mockFetchv2.mockResolvedValue({
        me: { id: 5 },
        groups: [{ groupid: 11 }, { groupid: 22 }],
      })
      // The batch hangs forever - fetchUser (and therefore first paint, which
      // awaits it in the layouts) must not wait for group details.
      mockFetchBatch.mockReturnValue(new Promise(() => {}))

      const result = await Promise.race([
        store.fetchUser().then(() => 'fetchUser'),
        new Promise((resolve) => setTimeout(() => resolve('timeout'), 1000)),
      ])

      expect(result).toBe('fetchUser')
      expect(store.user.id).toBe(5)
      expect(mockFetchBatch).toHaveBeenCalledWith([11, 22])
    })

    it('survives a rejected group batch', async () => {
      store.setAuth('valid-jwt', 'valid-persistent')
      mockFetchv2.mockResolvedValue({
        me: { id: 5 },
        groups: [{ groupid: 11 }],
      })
      mockFetchBatch.mockRejectedValue(new Error('batch down'))

      await store.fetchUser()
      // Let the rejected batch settle - it must not become an unhandled
      // rejection or clear the user.
      await new Promise((resolve) => setTimeout(resolve, 0))

      expect(store.user.id).toBe(5)
    })

    it('records when the session was fetched', async () => {
      store.setAuth('valid-jwt', 'valid-persistent')
      mockFetchv2.mockResolvedValue({ me: { id: 5 }, groups: [] })

      const before = Date.now()
      await store.fetchUser()

      expect(store.userFetchedAt).toBeGreaterThanOrEqual(before)
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

  describe('unsubscribe', () => {
    it('returns worked:true unknown:false when API confirms email sent', async () => {
      mockUnsubscribe.mockResolvedValue({
        ret: 0,
        status: 'Success',
        emailsent: true,
        unknown: false,
      })
      const result = await store.unsubscribe('known@example.com')
      expect(result.worked).toBe(true)
      expect(result.unknown).toBe(false)
    })

    it('returns worked:false unknown:true when API reports unknown email', async () => {
      mockUnsubscribe.mockResolvedValue({
        ret: 0,
        status: 'Success',
        emailsent: false,
        unknown: true,
      })
      const result = await store.unsubscribe('nobody@example.com')
      expect(result.worked).toBe(false)
      expect(result.unknown).toBe(true)
    })

    it('returns worked:false unknown:false when API throws', async () => {
      mockUnsubscribe.mockRejectedValue(new Error('network'))
      const result = await store.unsubscribe('any@example.com')
      expect(result.worked).toBe(false)
      expect(result.unknown).toBe(false)
    })
  })
})
