import { describe, it, expect, vi, beforeEach } from 'vitest'
import { useComposeChoice } from '~/composables/useComposeChoice'

// The voice-post rollout % is read from server config at runtime (key
// `voicepost_rollout_pct`) so it can be changed without a new frontend build.
const mockFetch = vi.fn()
const mockRoute = { query: {} }
let mockBreakpoint = 'sm' // mobile
let mockIsApp = false
let mockUserId = 42

// Shared bandit spy so tests can assert exposure/conversion calls.
const { mockBandit } = vi.hoisted(() => ({
  mockBandit: { shown: vi.fn(), chosen: vi.fn() },
}))

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({ breakpoint: mockBreakpoint }),
}))
vi.mock('~/stores/auth', () => ({
  // mockUserId null => logged out, so authStore.user?.id is undefined.
  useAuthStore: () => ({ user: mockUserId ? { id: mockUserId } : null }),
}))
vi.mock('~/stores/mobile', () => ({
  useMobileStore: () => ({ isApp: mockIsApp }),
}))
vi.mock('~/stores/config', () => ({
  useConfigStore: () => ({ fetch: mockFetch }),
}))
vi.mock('#imports', () => ({
  useNuxtApp: () => ({
    $api: { bandit: mockBandit },
  }),
  useRoute: () => mockRoute,
}))

describe('useComposeChoice rollout from config', () => {
  beforeEach(() => {
    mockFetch.mockReset()
    mockRoute.query = {}
    mockBreakpoint = 'sm'
    mockIsApp = false
    mockUserId = 42
  })

  it('reads the rollout % from the voicepost_rollout_pct config key', async () => {
    mockFetch.mockResolvedValue([{ value: '0' }])
    const { loadRollout, experimentActive } = useComposeChoice()

    await loadRollout()

    expect(mockFetch).toHaveBeenCalledWith('voicepost_rollout_pct')
    // 0% => experiment off, no new build needed to disable it.
    expect(experimentActive()).toBe(false)
  })

  it('turns the experiment on when config sets a positive %', async () => {
    mockFetch.mockResolvedValue([{ value: '100' }])
    const { loadRollout, experimentActive, assign } = useComposeChoice()

    await loadRollout()

    expect(experimentActive()).toBe(true)
    // 100% => every eligible mobile user gets the voice variant.
    expect(assign()).toBe('voice')
  })

  it('honours ?voice=1 regardless of the configured rollout', async () => {
    mockFetch.mockResolvedValue([{ value: '0' }])
    mockRoute.query = { voice: '1' }
    const { loadRollout, experimentActive, assign } = useComposeChoice()

    await loadRollout()

    expect(experimentActive()).toBe(true)
    expect(assign()).toBe('voice')
  })

  it('never throws when the config fetch fails (keeps last/default rollout)', async () => {
    mockFetch.mockRejectedValue(new Error('config down'))
    const { loadRollout } = useComposeChoice()

    await expect(loadRollout()).resolves.toBeUndefined()
  })
})

// Regression for the flaw that invalidated the first run: the bucket is keyed on the
// user id, so logged-out users all hashed 'voice:0' -> bucket 45 -> control, every
// time. 23% of mobile compose entries were logged out and every one of them landed in
// control, so control held a population voice could never contain and the arms weren't
// comparable. Logged-out users must now sit the experiment out entirely.
describe('useComposeChoice logged-out users are not enrolled', () => {
  beforeEach(() => {
    mockFetch.mockReset()
    mockBandit.shown.mockReset()
    mockRoute.query = {}
    mockBreakpoint = 'sm'
    mockIsApp = false
    mockUserId = null // logged out
  })

  it('reports it cannot bucket a logged-out user', () => {
    const { canBucket } = useComposeChoice()
    expect(canBucket()).toBe(false)
  })

  it('leaves the experiment inactive when logged out, even at 100% rollout', async () => {
    mockFetch.mockResolvedValue([{ value: '100' }])
    const { loadRollout, experimentActive } = useComposeChoice()

    await loadRollout()

    // Inactive => the entry point never records exposure and keeps the typed flow.
    expect(experimentActive()).toBe(false)
  })

  it('assigns control rather than hashing a missing id', async () => {
    mockFetch.mockResolvedValue([{ value: '100' }])
    const { loadRollout, assign } = useComposeChoice()

    await loadRollout()

    // At 100% a bucketable user would get voice; a logged-out one must not.
    expect(assign()).toBe('control')
  })

  it('enrols the same user once they are logged in', async () => {
    mockFetch.mockResolvedValue([{ value: '100' }])
    const loggedOut = useComposeChoice()

    await loggedOut.loadRollout()
    expect(loggedOut.experimentActive()).toBe(false)

    // The composable reads the auth store once, on creation, so logging in means a
    // fresh instance - which is what the compose entry gets on its next visit.
    mockUserId = 42
    const loggedIn = useComposeChoice()
    expect(loggedIn.canBucket()).toBe(true)
    expect(loggedIn.experimentActive()).toBe(true)
    expect(loggedIn.assign()).toBe('voice')
  })

  it('still honours the ?voice=1 demo override when logged out', async () => {
    mockFetch.mockResolvedValue([{ value: '0' }])
    mockRoute.query = { voice: '1' }
    const { loadRollout, experimentActive, assign } = useComposeChoice()

    await loadRollout()

    // The override forces an arm, so it needs no bucket - demos keep working.
    expect(experimentActive()).toBe(true)
    expect(assign()).toBe('voice')
  })
})

// Conversion is recorded at the shared final "Freegle it!" step for BOTH arms, keyed
// by a variant stashed at compose entry — so voice and control are measured at the
// same funnel point (actual post creation). Regression for the gap where control
// conversions were never recorded (its typed flow didn't know about the experiment).
describe('useComposeChoice conversion tracking', () => {
  const sessionStore = new Map()

  beforeEach(() => {
    mockBandit.shown.mockReset()
    mockBandit.chosen.mockReset()
    sessionStore.clear()
    globalThis.sessionStorage = {
      getItem: (k) => (sessionStore.has(k) ? sessionStore.get(k) : null),
      setItem: (k, v) => sessionStore.set(k, String(v)),
      removeItem: (k) => sessionStore.delete(k),
    }
  })

  it('records the pending variant as a conversion (control)', () => {
    const { markConversionPending, recordConversionIfPending } =
      useComposeChoice()
    markConversionPending('control')
    recordConversionIfPending()
    expect(mockBandit.chosen).toHaveBeenCalledWith(
      expect.objectContaining({
        uid: 'mobile-compose-variant',
        variant: 'control',
      })
    )
  })

  it('records voice the same way, at the same point', () => {
    const { markConversionPending, recordConversionIfPending } =
      useComposeChoice()
    markConversionPending('voice')
    recordConversionIfPending()
    expect(mockBandit.chosen).toHaveBeenCalledWith(
      expect.objectContaining({
        uid: 'mobile-compose-variant',
        variant: 'voice',
      })
    )
  })

  it('is a no-op when the user did not enter through the experiment', () => {
    const { recordConversionIfPending } = useComposeChoice()
    recordConversionIfPending()
    expect(mockBandit.chosen).not.toHaveBeenCalled()
  })

  it('records a conversion at most once (marker cleared after recording)', () => {
    const { markConversionPending, recordConversionIfPending } =
      useComposeChoice()
    markConversionPending('control')
    recordConversionIfPending()
    recordConversionIfPending()
    expect(mockBandit.chosen).toHaveBeenCalledTimes(1)
  })
})
