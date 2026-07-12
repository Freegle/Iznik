import { describe, it, expect, vi, beforeEach } from 'vitest'
import { useComposeChoice } from '~/composables/useComposeChoice'

// The voice-post rollout % is read from server config at runtime (key
// `voicepost_rollout_pct`) so it can be changed without a new frontend build.
const mockFetch = vi.fn()
const mockRoute = { query: {} }
let mockBreakpoint = 'sm' // mobile
let mockIsApp = false
let mockUserId = 42

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({ breakpoint: mockBreakpoint }),
}))
vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({ user: { id: mockUserId } }),
}))
vi.mock('~/stores/mobile', () => ({
  useMobileStore: () => ({ isApp: mockIsApp }),
}))
vi.mock('~/stores/config', () => ({
  useConfigStore: () => ({ fetch: mockFetch }),
}))
vi.mock('#imports', () => ({
  useNuxtApp: () => ({
    $api: { bandit: { shown: vi.fn(), chosen: vi.fn() } },
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
