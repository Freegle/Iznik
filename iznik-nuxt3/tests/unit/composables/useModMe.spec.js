import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

// The vitest config pre-aliases ~/stores/auth → tests/unit/mocks/auth-store.js
// and ~/stores/chat → tests/unit/mocks/chat-store.js. Those mocks read from
// globalThis.__mock*Store, so set those instead of re-mocking the modules.

const mockFetchMe = vi.fn()
const mockGetModGroups = vi.fn()
const mockMiscStore = {
  workTimer: null,
  deferGetMessages: false,
  modtoolsediting: false,
}

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

// --- Audio mock ---

let mockAudioPlay

beforeEach(() => {
  vi.useFakeTimers()
  mockAudioPlay = vi.fn().mockResolvedValue(undefined)
  global.Audio = class MockAudio {
    constructor(_src) {}
    play() {
      return mockAudioPlay()
    }
  }
  global.document = {
    body: { style: { overflow: '' } },
    title: '',
  }
  // Reset store state via globalThis pattern used by pre-aliased mocks
  globalThis.__mockAuthStore = {
    work: null,
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
})

afterEach(() => {
  vi.useRealTimers()
  vi.clearAllMocks()
  vi.resetModules()
  delete globalThis.__mockAuthStore
  delete globalThis.__mockChatStore
})

describe('useModMe checkWork beep behavior', () => {
  it('does not play beep on first checkWork even when work arrives (iOS audio safety)', async () => {
    // Simulates opening the app with pending work:
    // - authStore.work is null before fetchMe (nothing loaded yet)
    // - fetchMe loads 3 pending items → totalCount becomes 3 > currentTotal 0
    // Without the fix this would immediately beep, interrupting background audio on iOS.
    mockFetchMe.mockImplementation(async () => {
      globalThis.__mockAuthStore.work = { total: 3 }
    })

    const { useModMe } = await import('~/modtools/composables/useModMe')
    const { checkWork } = useModMe()
    await checkWork(true)

    expect(mockAudioPlay).not.toHaveBeenCalled()
  })

  it('plays beep when work count increases after first check', async () => {
    // First check: establishes baseline of 2 items (no beep)
    mockFetchMe.mockImplementationOnce(async () => {
      globalThis.__mockAuthStore.work = { total: 2 }
    })

    const { useModMe } = await import('~/modtools/composables/useModMe')
    const { checkWork } = useModMe()
    await checkWork(true)
    expect(mockAudioPlay).not.toHaveBeenCalled()

    // Second check: work increases to 5 → beep should fire
    mockFetchMe.mockImplementationOnce(async () => {
      globalThis.__mockAuthStore.work = { total: 5 }
    })
    await checkWork(true)

    expect(mockAudioPlay).toHaveBeenCalledOnce()
  })

  it('does not play beep when work count stays the same on subsequent checks', async () => {
    mockFetchMe.mockImplementation(async () => {
      globalThis.__mockAuthStore.work = { total: 2 }
    })

    const { useModMe } = await import('~/modtools/composables/useModMe')
    const { checkWork } = useModMe()

    await checkWork(true) // baseline
    await checkWork(true) // same count — no beep

    expect(mockAudioPlay).not.toHaveBeenCalled()
  })

  it('respects playbeep=false user setting on subsequent checks', async () => {
    globalThis.__mockAuthStore.user = { settings: { playbeep: false } }
    // First call: baseline
    mockFetchMe.mockImplementationOnce(async () => {
      globalThis.__mockAuthStore.work = { total: 2 }
    })

    const { useModMe } = await import('~/modtools/composables/useModMe')
    const { checkWork } = useModMe()
    await checkWork(true)

    // Work increases but beep is disabled by user setting
    mockFetchMe.mockImplementationOnce(async () => {
      globalThis.__mockAuthStore.work = { total: 5 }
    })
    await checkWork(true)

    expect(mockAudioPlay).not.toHaveBeenCalled()
  })
})
