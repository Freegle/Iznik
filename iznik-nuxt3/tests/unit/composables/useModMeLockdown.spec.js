import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

// checkWork() is ModTools' equivalent of the member site's 60s counts cycle
// (see useNavbarLockdown.spec.js) - here it is a 30s work poll. The full
// moderator lockdown state (surfaces held, not just the public notice) rides
// that same cadence rather than a timer of its own, so the red banner and the
// button-hiding (plans/active/2026-09-27-lockdown-switch.md section 11.3)
// go stale by at most one poll. Unlike the work/badge refresh it is not
// skipped while modtoolsediting is true: a lockdown pressed mid-edit must
// still reach the screen promptly, because it is what hides the dangerous
// buttons.

const mockFetchMe = vi.fn()
const mockGetModGroups = vi.fn()
const mockFetchMod = vi.fn()
const mockMiscStore = {
  workTimer: null,
  deferGetMessages: false,
  modtoolsediting: false,
}

const mockSetBadgeCount = vi.fn()

vi.mock('@/stores/misc', () => ({
  useMiscStore: () => mockMiscStore,
}))

vi.mock('@/stores/modgroup', () => ({
  useModGroupStore: () => ({
    list: {},
    get: vi.fn(),
    getModGroups: mockGetModGroups,
  }),
}))

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    me: { value: null },
    fetchMe: mockFetchMe,
  }),
}))

vi.mock('~/stores/mobile', () => ({
  useMobileStore: () => ({
    isApp: true,
    setBadgeCount: mockSetBadgeCount,
  }),
}))

vi.mock('~/stores/lockdown', () => ({
  useLockdownStore: () => ({ fetchMod: mockFetchMod }),
}))

beforeEach(() => {
  vi.useFakeTimers()
  mockSetBadgeCount.mockReset()
  mockFetchMod.mockReset().mockResolvedValue({ active: false, surfaces: {} })
  global.Audio = class MockAudio {
    play() {
      return Promise.resolve()
    }
  }
  global.document = {
    body: { style: { overflow: '' } },
    title: '',
  }
  globalThis.__mockAuthStore = {
    work: { total: 0 },
    user: { settings: {} },
    member: vi.fn(() => null),
    groups: [],
  }
  globalThis.__mockChatStore = {
    unreadCount: 0,
  }
  mockMiscStore.workTimer = null
  mockMiscStore.deferGetMessages = false
  mockMiscStore.modtoolsediting = false
  mockGetModGroups.mockResolvedValue(undefined)
  mockFetchMe.mockImplementation(() => {
    globalThis.__mockAuthStore.work = { total: 0 }
  })
})

afterEach(() => {
  vi.useRealTimers()
  vi.clearAllMocks()
  vi.resetModules()
  delete globalThis.__mockAuthStore
  delete globalThis.__mockChatStore
})

describe('useModMe checkWork refreshes the lockdown state', () => {
  it('fetches the mod lockdown state on checkWork', async () => {
    const { useModMe } = await import('~/modtools/composables/useModMe')
    const { checkWork } = useModMe()
    await checkWork(true)

    expect(mockFetchMod).toHaveBeenCalledTimes(1)
  })

  it('still fetches the lockdown state while modtools editing is in progress', async () => {
    // The work/badge refresh is skipped in this state (oktocheck is false and
    // force is not passed) - the lockdown check is not, because a press must
    // still reach the screen while someone happens to be mid-edit.
    mockMiscStore.modtoolsediting = true
    const { useModMe } = await import('~/modtools/composables/useModMe')
    const { checkWork } = useModMe()
    await checkWork()

    expect(mockFetchMe).not.toHaveBeenCalled()
    expect(mockFetchMod).toHaveBeenCalledTimes(1)
  })

  it('does not stop the work poll if the lockdown fetch fails', async () => {
    mockFetchMod.mockRejectedValueOnce(new Error('network error'))
    const { useModMe } = await import('~/modtools/composables/useModMe')
    const { checkWork } = useModMe()
    await checkWork(true)

    expect(mockFetchMe).toHaveBeenCalledTimes(1)
    expect(mockSetBadgeCount).toHaveBeenCalledWith(0)
  })
})
