/**
 * AssertFlip failing test — ModTools notification bootstrap race (9737-5).
 *
 * Bug: After login, authStore.work (notification badge) is populated by
 * fetchUser() but modGroupStore.list stays empty because fetchUser() never
 * calls modGroupStore.getModGroups().  On sign-out then sign-in, this means
 * zero groups appear in the menu until the user navigates (triggers the
 * route-change watcher) or waits 30 s for the next checkWork() timer tick.
 *
 * Root cause: authStore.fetchUser() → sets authStore.groups and authStore.work
 * but does NOT call modGroupStore.getModGroups(), so the group menu and pending
 * work badges are out of sync right after login.
 *
 * AssertFlip Step 1 (BUGGY_ASSERTION suite): asserts the WRONG behaviour IS
 * happening — modGroupStore.list IS empty after login.  Passes on the current
 * broken code.
 *
 * AssertFlip Step 2 (INVERTED suite): flipped assertions — modGroupStore.list
 * SHOULD be populated after login.  Fails on the current code, will pass once
 * the fix (e.g. calling modGroupStore.getModGroups() inside fetchUser after
 * groups are populated, or via a loginStateKnown watcher in default.vue) lands.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

// ---------------------------------------------------------------------------
// Top-level mock functions (cleared in beforeEach, re-implemented there).
// ---------------------------------------------------------------------------
const mockLogin = vi.fn()
const mockFetchv2 = vi.fn()
const mockFetchWork = vi.fn()
const mockFetchGroupsMT = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    session: {
      login: mockLogin,
      logout: vi.fn(),
      fetchv2: mockFetchv2,
      related: vi.fn(),
      save: vi.fn(),
    },
    group: {
      fetchBatch: vi.fn().mockResolvedValue([]),
      fetchWork: mockFetchWork,
      fetchGroupsMT: mockFetchGroupsMT,
      list: vi.fn().mockResolvedValue([]),
      fetch: vi.fn(),
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
    constructor(status, msg) {
      super(msg)
      this.status = status
    }
  },
  SignUpError: class SignUpError extends Error {},
}))

vi.mock('@capgo/capacitor-social-login', () => ({
  SocialLogin: { initialize: vi.fn(), logout: vi.fn() },
}))

vi.mock('~/stores/compose', () => ({
  useComposeStore: () => ({ email: null }),
}))

vi.mock('~/stores/mobile', () => ({
  useMobileStore: () => ({ isApp: false, mobilePushId: null }),
}))

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({
    modtools: true,
    source: null,
    marketingConsent: undefined,
  }),
}))

// The group store is mocked to avoid the full Nuxt/API dependency chain.
// The summaryList stays empty so getModGroups() exercises its fetch() path,
// which is a no-op here — the key side effect under test is modGroupStore.list.
const mockGroupStore = {
  list: {},
  summaryList: {},
  fetchBatch: vi.fn(),
  clear: vi.fn(),
  fetch: vi.fn(),
}
vi.mock('~/stores/group', () => ({
  useGroupStore: () => mockGroupStore,
}))

// ---------------------------------------------------------------------------
// Import the REAL auth store using the .js file extension to bypass the
// vitest.config alias that maps  ~/stores/auth  →  tests/unit/mocks/auth-store.js.
// (Component tests need the lightweight mock; this test needs the real impl.)
// ---------------------------------------------------------------------------
import { useAuthStore } from '~/stores/auth.js'

const RUNTIME_CONFIG = { public: { BUILD_DATE: '2026-01-01' }, app: {} }

// Session data returned by the mocked GET /session (fetchv2) endpoint.
// Mirrors a real moderator session with one group and three pending work items.
const SESSION_DATA = {
  me: { id: 42, email: 'mod@example.com', settings: {} },
  groups: [{ groupid: 100, role: 'Moderator' }],
  emails: [],
  work: { total: 3 },
  discourse: { notifications: 0, newtopics: 0, unreadtopics: 0 },
}

describe('ModTools notification bootstrap race (bug 9737-5)', () => {
  let useModGroupStore

  beforeEach(async () => {
    vi.clearAllMocks()
    setActivePinia(createPinia())

    // Re-implement mocks cleared by clearAllMocks()
    mockLogin.mockResolvedValue({ jwt: 'test-jwt', persistent: 'test-persistent' })
    mockFetchv2.mockResolvedValue(SESSION_DATA)
    mockFetchWork.mockResolvedValue([])
    mockFetchGroupsMT.mockResolvedValue([{ id: 100, nameshort: 'TestGroup' }])

    // Dynamic import so the store is registered under the fresh pinia created above
    const mod = await import('~/modtools/stores/modgroup')
    useModGroupStore = mod.useModGroupStore
  })

  afterEach(() => {
    // Clean up the bridge so other tests cannot accidentally inherit it
    delete globalThis.__mockAuthStore
  })

  // -------------------------------------------------------------------------
  // Helper: creates and initialises both stores, bridges the real pinia-backed
  // authStore into the modgroup mock's useAuthStore() call via __mockAuthStore.
  //
  // Why the bridge?  vitest.config aliases  ~/stores/auth  to the lightweight
  // mock (auth-store.js) for component tests.  That mock reads
  // globalThis.__mockAuthStore.  By pointing it at the real pinia instance we
  // get end-to-end store interaction without component-level mounting.
  // -------------------------------------------------------------------------
  function setupStores() {
    const authStore = useAuthStore()
    authStore.init(RUNTIME_CONFIG)
    globalThis.__mockAuthStore = authStore // modgroup's useAuthStore() returns this

    const modGroupStore = useModGroupStore()
    modGroupStore.init(RUNTIME_CONFIG)

    return { authStore, modGroupStore }
  }

  // =========================================================================
  // STEP 1 — BUGGY_ASSERTION
  // These assertions describe the WRONG behaviour that exists today.
  // They PASS on the current (broken) code.
  // =========================================================================
  describe('BUGGY_ASSERTION — passes on current broken code', () => {
    it(
      'after login, modGroupStore.list is empty even though authStore.groups is populated',
      async () => {
        const { authStore, modGroupStore } = setupStores()

        await authStore.login({ email: 'mod@example.com', password: 'secret' })

        // fetchUser ran and correctly populated auth state
        expect(authStore.groups).toHaveLength(1)
        expect(authStore.work?.total).toBe(3)

        // BUG: modGroupStore.list is still empty because fetchUser never calls
        // getModGroups() — the menu shows no groups right after sign-in.
        expect(Object.keys(modGroupStore.list)).toHaveLength(0)
      }
    )
  })

  // =========================================================================
  // STEP 2 — INVERTED
  // These are the flipped assertions documenting correct behaviour.
  // They FAIL on the current (broken) code.
  // They will PASS once the fix is applied.
  // =========================================================================
  describe('INVERTED — fails on current broken code, passes after fix', () => {
    it(
      'after login, modGroupStore.list should be populated with the user\'s groups',
      async () => {
        const { authStore, modGroupStore } = setupStores()

        await authStore.login({ email: 'mod@example.com', password: 'secret' })

        // Confirm auth state is populated (pre-condition)
        expect(authStore.groups).toHaveLength(1)
        expect(authStore.work?.total).toBe(3)

        // INVERTED ASSERTION: group 100 must appear in the modtools group list.
        // Fails on the current code because fetchUser does not call getModGroups().
        // Will pass once fetchUser (or a loginStateKnown watcher) triggers
        // modGroupStore.getModGroups() after the user's groups are loaded.
        expect(Object.keys(modGroupStore.list)).toHaveLength(1)
      }
    )
  })
})
