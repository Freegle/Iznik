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

// --- Audio mock ---

let mockAudioPlay

beforeEach(() => {
  vi.useFakeTimers()
  mockAudioPlay = vi.fn().mockResolvedValue(undefined)
  mockSetBadgeCount.mockReset()
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

  it('calls setBadgeCount with work total after checkWork', async () => {
    mockFetchMe.mockImplementation(async () => {
      globalThis.__mockAuthStore.work = { total: 4 }
    })

    const { useModMe } = await import('~/modtools/composables/useModMe')
    const { checkWork } = useModMe()
    await checkWork(true)

    expect(mockSetBadgeCount).toHaveBeenCalledWith(4)
  })

  it('calls setBadgeCount with 0 when no pending work', async () => {
    mockFetchMe.mockImplementation(async () => {
      globalThis.__mockAuthStore.work = { total: 0 }
    })

    const { useModMe } = await import('~/modtools/composables/useModMe')
    const { checkWork } = useModMe()
    await checkWork(true)

    expect(mockSetBadgeCount).toHaveBeenCalledWith(0)
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

describe('useModMe re-login behavior', () => {
  it('spurious beep on re-login when isFirstCheckWork not reset (bug confirmation)', async () => {
    // First checkWork establishes baseline; isFirstCheckWork → false after this
    mockFetchMe.mockImplementationOnce(async () => {
      globalThis.__mockAuthStore.work = { total: 3 }
    })
    const { useModMe } = await import('~/modtools/composables/useModMe')
    const { checkWork } = useModMe()
    await checkWork(true)
    expect(mockAudioPlay).not.toHaveBeenCalled() // baseline, no beep

    // Simulate re-login where all prior work was handled (login returns 0 items).
    // This mirrors the scenario where a user logs out after clearing their queue,
    // then new items arrive before the first post-login checkWork fires fetchMe.
    globalThis.__mockAuthStore.work = { total: 0 }

    // fetchMe returns 3 new arrivals — these appeared since the login moment
    mockFetchMe.mockImplementationOnce(async () => {
      globalThis.__mockAuthStore.work = { total: 3 }
    })
    await checkWork(true)

    // BUG: spurious beep fires because isFirstCheckWork was stale-false.
    // currentTotal=0 (work at login time), totalCount=3 (new arrivals), 3 > 0 → beep.
    expect(mockAudioPlay).toHaveBeenCalledOnce()
  })

  it('no spurious beep on re-login when resetCheckWork is called first', async () => {
    // First checkWork establishes baseline
    mockFetchMe.mockImplementationOnce(async () => {
      globalThis.__mockAuthStore.work = { total: 3 }
    })
    const { useModMe } = await import('~/modtools/composables/useModMe')
    const { checkWork, resetCheckWork } = useModMe()
    await checkWork(true)
    expect(mockAudioPlay).not.toHaveBeenCalled()

    // Re-login with 0 items at login time (all prior work handled)
    globalThis.__mockAuthStore.work = { total: 0 }

    // FIX: resetCheckWork() so the next checkWork treats this as the first call
    // (establishes a new baseline, like a fresh page load) — prevents spurious beep
    resetCheckWork()
    mockFetchMe.mockImplementationOnce(async () => {
      globalThis.__mockAuthStore.work = { total: 3 }
    })
    await checkWork(true)

    // FIXED: no spurious beep — isFirstCheckWork reset means skipBeep=true for this call
    expect(mockAudioPlay).not.toHaveBeenCalled()
  })
})

describe('useModMe document.title refresh after mod action', () => {
  it('clears title count when checkWork is called after mod action while modal is open', async () => {
    const { useModMe } = await import('~/modtools/composables/useModMe')
    const { checkWork } = useModMe()

    // Establish initial state: 2 pending items → title becomes "(2) ModTools"
    mockFetchMe.mockImplementationOnce(async () => {
      globalThis.__mockAuthStore.work = { total: 2 }
    })
    await checkWork(true)
    expect(global.document.title).toBe('(2) ModTools')

    // Mod action completes: queue clears, but modal is still open (body overflow:hidden).
    // checkWorkDeferGetMessages() calls checkWork() WITHOUT force.
    // Guard in checkWork: bodyoverflow==='hidden' && !force → skips the entire update block,
    // so document.title is never refreshed with the new zero count.
    global.document.body.style.overflow = 'hidden'
    mockFetchMe.mockImplementation(async () => {
      globalThis.__mockAuthStore.work = { total: 0 }
    })
    await checkWork() // no force — reproduces checkWorkDeferGetMessages() call path

    // Title must update to 'ModTools' when work queue empties after a mod action.
    // FAILS on buggy code: guard blocks title refresh when body overflow is 'hidden'
    // and force is not passed, leaving stale '(2) ModTools' as the tab title.
    expect(global.document.title).toBe('ModTools')
  })
})
