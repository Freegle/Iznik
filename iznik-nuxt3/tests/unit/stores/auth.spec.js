import {
  describe,
  it,
  expect,
  vi,
  beforeEach,
  beforeAll,
  afterAll,
} from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

// Import with .js extension to bypass vitest.config alias that maps
// ~/stores/auth → tests/unit/mocks/auth-store.js (for component tests).
// This test needs the real store implementation.
import { useAuthStore } from '~/stores/auth.js'
import { abortAllPendingRequests } from '~/api/BaseAPI'

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
const mockForgetSession = vi.fn()
const mockRestoreSession = vi.fn()
const mockUnbounce = vi.fn()
const mockUserSave = vi.fn()
const mockAddEmail = vi.fn()
const mockRemoveEmail = vi.fn()
const mockMerge = vi.fn()
const mockUpdateMembership = vi.fn()
const mockLeaveGroup = vi.fn()
const mockJoinGroup = vi.fn()

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
      forget: mockForgetSession,
      restore: mockRestoreSession,
    },
    user: {
      signUp: mockSignUp,
      unbounce: mockUnbounce,
      save: mockUserSave,
      addEmail: mockAddEmail,
      removeEmail: mockRemoveEmail,
      merge: mockMerge,
    },
    memberships: {
      update: mockUpdateMembership,
      leaveGroup: mockLeaveGroup,
      joinGroup: mockJoinGroup,
    },
  }),
}))

vi.mock('~/composables/useTrackConversion', () => ({
  trackConversion: (...args) => mockTrackConversion(...args),
}))

const mockSaveSessionForRestore = vi.fn()
const mockRestoreSessionFromDevice = vi.fn()
const mockClearRestoredSession = vi.fn()
vi.mock('~/composables/useSessionRestore', () => ({
  saveSessionForRestore: (...args) => mockSaveSessionForRestore(...args),
  restoreSessionFromDevice: (...args) => mockRestoreSessionFromDevice(...args),
  clearRestoredSession: (...args) => mockClearRestoredSession(...args),
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

const mockFetchBatch = vi.fn()
vi.mock('~/stores/group', () => ({
  useGroupStore: () => ({ list: {}, fetchBatch: mockFetchBatch }),
}))

const mockMobileStore = {
  isApp: false,
  mobilePushId: null,
  acceptedMobilePushId: false,
  isiOS: false,
  deviceuserinfo: 'test-device',
}
vi.mock('~/stores/mobile', () => ({
  useMobileStore: () => mockMobileStore,
}))

const mockMiscStore = {
  modtools: false,
  source: null,
  setAppOutOfDate: mockSetAppOutOfDate,
  marketingConsent: undefined,
}
vi.mock('~/stores/misc', () => ({
  useMiscStore: () => mockMiscStore,
}))

describe('auth store', () => {
  let store
  let logSpy

  // The real auth store logs from fire-and-forget async paths - the Google and
  // Facebook logout catch blocks, and the marketing-consent sync - which can
  // emit AFTER the test that triggered them has finished. Vitest forwards every
  // console call to the main process over the worker RPC, so a log still in
  // flight when the worker closes surfaces as
  //   EnvironmentTeardownError: Closing rpc while "onUserConsoleLog" was pending
  // and vitest exits non-zero even though every test passed (observed on
  // CircleCI 33421: 16,148 passed, 1 unhandled error, build failed).
  //
  // beforeAll/afterAll rather than the per-test spy used elsewhere in these
  // specs: the racing log arrives between tests, so the stub has to outlive
  // any single one of them.
  beforeAll(() => {
    logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
  })

  afterAll(() => {
    logSpy.mockRestore()
  })

  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    Object.assign(mockMobileStore, {
      isApp: false,
      mobilePushId: null,
      acceptedMobilePushId: false,
      isiOS: false,
      deviceuserinfo: 'test-device',
    })
    Object.assign(mockMiscStore, {
      modtools: false,
      source: null,
      marketingConsent: undefined,
    })
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

    it('hands the persistent token to Block Store for the next device', () => {
      store.setAuth('test-jwt', 'test-persistent')
      expect(mockSaveSessionForRestore).toHaveBeenCalledWith('test-persistent')
    })
  })

  describe('wipeAuth', () => {
    it('clears credentials, the user, and the Block Store copy', () => {
      store.setAuth('dead-jwt', 'dead-persistent')
      store.setUser({ id: 123 })
      mockClearRestoredSession.mockClear()

      store.wipeAuth()

      expect(store.auth.jwt).toBeNull()
      expect(store.auth.persistent).toBeNull()
      expect(store.user).toBeNull()
      // Without this, an Android device whose localStorage was evicted keeps
      // re-adopting the same dead token from Block Store and loops back to
      // the login screen.
      expect(mockClearRestoredSession).toHaveBeenCalled()
    })
  })

  describe('adoptRestoredSession', () => {
    it('adopts the session a previous device left in Block Store', async () => {
      mockRestoreSessionFromDevice.mockResolvedValue('transferred-persistent')

      expect(await store.adoptRestoredSession()).toBe(true)
      expect(store.auth.persistent).toBe('transferred-persistent')
      // No JWT: the persistent token alone authenticates, and GET /session mints one.
      expect(store.auth.jwt).toBeNull()
    })

    it('returns false when Block Store holds nothing', async () => {
      mockRestoreSessionFromDevice.mockResolvedValue(null)

      expect(await store.adoptRestoredSession()).toBe(false)
      expect(store.auth.persistent).toBeNull()
    })

    it('leaves an existing jwt alone', async () => {
      store.setAuth('live-jwt', null)

      expect(await store.adoptRestoredSession()).toBe(false)
      expect(mockRestoreSessionFromDevice).not.toHaveBeenCalled()
      expect(store.auth.jwt).toBe('live-jwt')
    })

    it('leaves an existing persistent token alone', async () => {
      store.setAuth(null, 'live-persistent')

      expect(await store.adoptRestoredSession()).toBe(false)
      expect(mockRestoreSessionFromDevice).not.toHaveBeenCalled()
      expect(store.auth.persistent).toBe('live-persistent')
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

    it('clears the transferable session, so a device restore does not sign us back in', async () => {
      mockLogin.mockResolvedValue({ jwt: 'jwt', persistent: 'p' })
      mockFetchv2.mockResolvedValue({ me: { id: 1 }, groups: [] })
      await store.login({ email: 'a@b.com', password: 'x' })

      await store.logout()

      expect(mockClearRestoredSession).toHaveBeenCalled()
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

    it('stops retrying once Google has clearly not loaded', () => {
      // Privacy extensions block the Google script outright, and the retry used
      // to reschedule itself for ever: a timer plus a console line every 100ms
      // for the life of the page. In the unit tests those logs outlive the test
      // file and race the worker shutdown, which fails the whole run with
      // "Closing rpc while onUserConsoleLog was pending" while every test
      // passes. Drive the retries with fake timers and check they stop.
      const originalGoogle = globalThis.window.google
      delete globalThis.window.google

      vi.useFakeTimers()
      try {
        store.disableGoogleAutoselect()

        // Well past the five-second budget.
        vi.advanceTimersByTime(30000)

        expect(vi.getTimerCount()).toBe(0)
      } finally {
        vi.useRealTimers()
        globalThis.window.google = originalGoogle
      }
    })

    it('logs once, not on every retry', () => {
      // The retries run for five seconds, long outliving the test that starts
      // them and the console stub this spec restores in afterAll. A log still
      // in flight when the vitest worker closes fails the whole run with
      // "Closing rpc while onUserConsoleLog was pending" while every test
      // passes, so the retry path has to stay silent.
      const originalGoogle = globalThis.window.google
      delete globalThis.window.google

      vi.useFakeTimers()
      try {
        logSpy.mockClear()
        store.disableGoogleAutoselect()
        vi.advanceTimersByTime(30000)

        expect(logSpy).toHaveBeenCalledTimes(1)
      } finally {
        vi.useRealTimers()
        globalThis.window.google = originalGoogle
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

  describe('abortPendingRequests', () => {
    it('delegates to abortAllPendingRequests', () => {
      store.abortPendingRequests()
      expect(abortAllPendingRequests).toHaveBeenCalled()
    })
  })

  describe('forget', () => {
    it('calls session.forget then logs out', async () => {
      mockLogin.mockResolvedValue({ jwt: 'jwt', persistent: 'p' })
      mockFetchv2.mockResolvedValue({ me: { id: 1 }, groups: [] })
      await store.login({ email: 'a@b.com', password: 'x' })

      await store.forget()

      expect(mockForgetSession).toHaveBeenCalled()
      expect(store.user).toBeNull()
    })
  })

  describe('restore', () => {
    it('calls session.restore then fetches the user', async () => {
      store.setAuth('valid-jwt', 'valid-persistent')
      mockFetchv2.mockResolvedValue({ me: { id: 7 }, groups: [] })

      await store.restore()

      expect(mockRestoreSession).toHaveBeenCalled()
      expect(store.user.id).toBe(7)
    })
  })

  describe('logout with mobile app social-login cleanup', () => {
    it.each([
      ['Facebook and Google logout both succeed', true, true],
      ['Facebook logout throws, Google still attempted', false, true],
      ['Google logout throws, Facebook already done', true, false],
    ])('%s', async (label, fbOk, googleOk) => {
      const { SocialLogin } = await import('@capgo/capacitor-social-login')
      mockMobileStore.isApp = true
      let call = 0
      SocialLogin.logout.mockImplementation(({ provider }) => {
        call++
        if (provider === 'facebook' && !fbOk) {
          return Promise.reject(new Error('fb logout failed'))
        }
        if (provider === 'google' && !googleOk) {
          return Promise.reject(new Error('google logout failed'))
        }
        return Promise.resolve()
      })

      await expect(store.logout()).resolves.toBeUndefined()
      expect(call).toBe(2)
      // logoutPushId() must still run (it zaps mobileStore.acceptedMobilePushId).
      expect(mockMobileStore.acceptedMobilePushId).toBe(false)
    })
  })

  describe('saveAboutMe / saveEmail / saveMicrovolunteering', () => {
    it('saveAboutMe saves aboutme and refetches the user', async () => {
      store.setAuth('valid-jwt', 'valid-persistent')
      mockSave.mockResolvedValue({ ret: 0 })
      mockFetchv2.mockResolvedValue({ me: { id: 1 }, groups: [] })

      await store.saveAboutMe('Hello world')

      expect(mockSave).toHaveBeenCalledWith({ aboutme: 'Hello world' })
      expect(store.user.id).toBe(1)
    })

    it('saveEmail saves email and refetches the user', async () => {
      store.setAuth('valid-jwt', 'valid-persistent')
      mockSave.mockResolvedValue({ ret: 0 })
      mockFetchv2.mockResolvedValue({ me: { id: 2 }, groups: [] })

      await store.saveEmail('new@example.com')

      expect(mockSave).toHaveBeenCalledWith({ email: 'new@example.com' })
      expect(store.user.id).toBe(2)
    })

    it('saveMicrovolunteering saves trustlevel for the current user', async () => {
      store.setAuth('valid-jwt', 'valid-persistent')
      store.user = { id: 55 }
      mockUserSave.mockResolvedValue({ ret: 0 })
      mockFetchv2.mockResolvedValue({ me: { id: 55 }, groups: [] })

      await store.saveMicrovolunteering('Advanced')

      expect(mockUserSave).toHaveBeenCalledWith({
        id: 55,
        trustlevel: 'Advanced',
      })
      expect(store.user.id).toBe(55)
    })
  })

  describe('unbounce / unbounceMT', () => {
    it('unbounce clears bouncing on the current user', async () => {
      store.user = { id: 3, bouncing: 1 }

      await store.unbounce(3)

      expect(mockUnbounce).toHaveBeenCalledWith(3)
      expect(store.user.bouncing).toBe(0)
    })

    it('unbounceMT unbounces another user without touching current user', async () => {
      store.user = { id: 3, bouncing: 1 }

      await store.unbounceMT(999)

      expect(mockUnbounce).toHaveBeenCalledWith(999)
      // Only the target user is unbounced server-side; our own state is untouched.
      expect(store.user.bouncing).toBe(1)
    })
  })

  describe('setGroup / leaveGroup / joinGroup', () => {
    it('setGroup updates membership and refetches by default', async () => {
      store.setAuth('valid-jwt', 'valid-persistent')
      mockFetchv2.mockResolvedValue({ me: { id: 1 }, groups: [] })

      await store.setGroup({ groupid: 5, role: 'Member' })

      expect(mockUpdateMembership).toHaveBeenCalledWith({
        groupid: 5,
        role: 'Member',
      })
      expect(mockFetchv2).toHaveBeenCalled()
    })

    it('setGroup skips the refetch when nofetch is set', async () => {
      await store.setGroup({ groupid: 5, role: 'Member' }, true)

      expect(mockUpdateMembership).toHaveBeenCalled()
      expect(mockFetchv2).not.toHaveBeenCalled()
    })

    it('leaveGroup leaves and returns the refetched user', async () => {
      store.setAuth('valid-jwt', 'valid-persistent')
      mockFetchv2.mockResolvedValue({ me: { id: 9 }, groups: [] })

      const user = await store.leaveGroup(9, 20)

      expect(mockLeaveGroup).toHaveBeenCalledWith({ userid: 9, groupid: 20 })
      expect(user.id).toBe(9)
    })

    it('joinGroup joins and returns the refetched user', async () => {
      store.setAuth('valid-jwt', 'valid-persistent')
      mockJoinGroup.mockResolvedValue({})
      mockFetchv2.mockResolvedValue({ me: { id: 9 }, groups: [] })

      const user = await store.joinGroup(9, 20, true)

      expect(mockJoinGroup).toHaveBeenCalledWith({
        userid: 9,
        groupid: 20,
        manual: true,
      })
      expect(user.id).toBe(9)
    })

    it('joinGroup swallows a banned-member 403 and returns the current user silently', async () => {
      store.user = { id: 9 }
      const err = new Error('Failed - banned')
      err.response = { status: 403, data: 'Failed - banned' }
      mockJoinGroup.mockRejectedValue(err)

      const user = await store.joinGroup(9, 20, false)

      expect(user.id).toBe(9)
      expect(mockFetchv2).not.toHaveBeenCalled()
    })

    it('joinGroup rethrows a non-banned failure', async () => {
      const err = new Error('Server error')
      err.response = { status: 500, data: 'boom' }
      mockJoinGroup.mockRejectedValue(err)

      await expect(store.joinGroup(9, 20, false)).rejects.toThrow(
        'Server error'
      )
    })
  })

  describe('logoutPushId', () => {
    it('zaps acceptedMobilePushId', () => {
      mockMobileStore.acceptedMobilePushId = 'some-token'

      store.logoutPushId()

      expect(mockMobileStore.acceptedMobilePushId).toBe(false)
    })
  })

  describe('savePushId', () => {
    it('does nothing when not logged in', async () => {
      store.user = null
      mockMobileStore.mobilePushId = 'token-123'

      await store.savePushId()

      expect(mockSave).not.toHaveBeenCalled()
    })

    it('does nothing when there is no mobile push token', async () => {
      store.user = { id: 1 }
      mockMobileStore.mobilePushId = null

      await store.savePushId()

      expect(mockSave).not.toHaveBeenCalled()
    })

    it('sends FCMAndroid type and marks accepted on success', async () => {
      store.user = { id: 1 }
      mockMobileStore.mobilePushId = 'android-token'
      mockMobileStore.isiOS = false
      mockSave.mockResolvedValue({ ret: 0 })

      await store.savePushId()

      expect(mockSave).toHaveBeenCalledWith({
        notifications: {
          push: {
            type: 'FCMAndroid',
            subscription: 'android-token',
            deviceuserinfo: 'test-device',
          },
        },
      })
      expect(mockMobileStore.acceptedMobilePushId).toBe('android-token')
    })

    it('sends FCMIOS type on iOS', async () => {
      store.user = { id: 1 }
      mockMobileStore.mobilePushId = 'ios-token'
      mockMobileStore.isiOS = true
      mockSave.mockResolvedValue({ ret: 0 })

      await store.savePushId()

      expect(mockSave).toHaveBeenCalledWith(
        expect.objectContaining({
          notifications: expect.objectContaining({
            push: expect.objectContaining({ type: 'FCMIOS' }),
          }),
        })
      )
    })

    it('does not throw and leaves acceptedMobilePushId unset when the save fails', async () => {
      store.user = { id: 1 }
      mockMobileStore.mobilePushId = 'android-token'
      mockMobileStore.acceptedMobilePushId = false
      mockSave.mockRejectedValue(new Error('network down'))

      await expect(store.savePushId()).resolves.toBeUndefined()

      expect(mockMobileStore.acceptedMobilePushId).toBe(false)
    })
  })

  describe('makeEmailPrimary / removeEmail / merge', () => {
    it('makeEmailPrimary adds the email as primary and refetches the user', async () => {
      store.setAuth('valid-jwt', 'valid-persistent')
      store.user = { id: 4 }
      mockFetchv2.mockResolvedValue({ me: { id: 4 }, groups: [] })

      const user = await store.makeEmailPrimary('primary@example.com')

      expect(mockAddEmail).toHaveBeenCalledWith(4, 'primary@example.com', true)
      expect(user.id).toBe(4)
    })

    it('removeEmail removes the email and refetches when a user is logged in', async () => {
      store.user = { id: 4 }
      mockFetchv2.mockResolvedValue({ me: { id: 4 }, groups: [] })

      await store.removeEmail('old@example.com')

      expect(mockRemoveEmail).toHaveBeenCalledWith(4, 'old@example.com')
    })

    it('removeEmail is a no-op when nobody is logged in', async () => {
      store.user = null

      await store.removeEmail('old@example.com')

      expect(mockRemoveEmail).not.toHaveBeenCalled()
    })

    it('merge merges two accounts by email/id/reason', async () => {
      await store.merge({
        email1: 'a@example.com',
        email2: 'b@example.com',
        id1: 1,
        id2: 2,
        reason: 'Duplicate account',
      })

      expect(mockMerge).toHaveBeenCalledWith(
        'a@example.com',
        'b@example.com',
        1,
        2,
        'Duplicate account'
      )
    })
  })

  describe('fetchUser marketing consent sync', () => {
    it('syncs local marketing consent to the profile when it differs', async () => {
      store.setAuth('valid-jwt', 'valid-persistent')
      mockMiscStore.marketingConsent = true
      mockFetchv2.mockResolvedValue({
        me: { id: 1, marketingconsent: false },
        groups: [],
      })
      mockSave.mockResolvedValue({})

      await store.fetchUser()

      expect(mockSave).toHaveBeenCalledWith({ marketingconsent: true })
      expect(store.user.marketingconsent).toBe(true)
    })

    it('does not resync when local consent already matches the profile', async () => {
      store.setAuth('valid-jwt', 'valid-persistent')
      mockMiscStore.marketingConsent = true
      mockFetchv2.mockResolvedValue({
        me: { id: 1, marketingconsent: true },
        groups: [],
      })

      await store.fetchUser()

      expect(mockSave).not.toHaveBeenCalled()
    })

    it('survives the consent-sync save failing', async () => {
      store.setAuth('valid-jwt', 'valid-persistent')
      mockMiscStore.marketingConsent = true
      mockFetchv2.mockResolvedValue({
        me: { id: 1, marketingconsent: false },
        groups: [],
      })
      mockSave.mockRejectedValue(new Error('save failed'))

      await expect(store.fetchUser()).resolves.toBeDefined()
      expect(store.user.id).toBe(1)
    })
  })

  describe('fetchUser session refresh and work/discourse tracking', () => {
    it('updates auth tokens when the session response includes a refreshed jwt', async () => {
      store.setAuth('old-jwt', 'old-p')
      mockFetchv2.mockResolvedValue({
        me: { id: 1 },
        groups: [],
        jwt: 'refreshed-jwt',
      })

      await store.fetchUser()

      expect(store.auth.jwt).toBe('refreshed-jwt')
      expect(store.auth.persistent).toBe('old-p')
    })

    it('records ModTools work counts and Discourse stats when present', async () => {
      store.setAuth('valid-jwt', 'valid-persistent')
      mockFetchv2.mockResolvedValue({
        me: { id: 1 },
        groups: [],
        work: { pending: 3 },
        discourse: { unread: 2 },
      })

      await store.fetchUser()

      expect(store.work).toEqual({ pending: 3 })
      expect(store.discourse).toEqual({ unread: 2 })
    })
  })

  describe('fetchUser error handling', () => {
    it('preserves auth on a genuine server error (5xx)', async () => {
      store.setAuth('valid-jwt', 'valid-persistent')
      const err = new Error('Internal error')
      err.response = { status: 500 }
      mockFetchv2.mockRejectedValue(err)

      await store.fetchUser()

      expect(store.auth.jwt).toBe('valid-jwt')
      expect(store.user).toBeNull()
    })

    it('wipes auth on 401', async () => {
      store.setAuth('valid-jwt', 'valid-persistent')
      const err = new Error('Unauthorized')
      err.response = { status: 401 }
      mockFetchv2.mockRejectedValue(err)

      await store.fetchUser()

      expect(store.auth.jwt).toBeNull()
      expect(store.user).toBeNull()
    })

    it('wipes auth on a network error with no response', async () => {
      store.setAuth('valid-jwt', 'valid-persistent')
      mockFetchv2.mockRejectedValue(new Error('network down'))

      await store.fetchUser()

      expect(store.auth.jwt).toBeNull()
    })
  })

  describe('member getter', () => {
    it('returns the role for a group the user belongs to', () => {
      store.user = { id: 1 }
      store.groups = [{ groupid: 10, role: 'Owner' }]

      expect(store.member(10)).toBe('Owner')
      expect(store.member('10')).toBe('Owner')
    })

    it('returns false when the user does not belong to the group', () => {
      store.user = { id: 1 }
      store.groups = [{ groupid: 10, role: 'Owner' }]

      expect(store.member(20)).toBe(false)
    })

    it('returns false when nobody is logged in', () => {
      store.user = null

      expect(store.member(10)).toBe(false)
    })
  })
})
