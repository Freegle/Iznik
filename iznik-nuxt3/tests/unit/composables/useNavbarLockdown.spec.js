import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

// The lockdown notice (plans/active/2026-09-27-lockdown-switch.md section
// 11.5) rides the navbar's existing sixty-second counts cycle rather than a
// timer of its own. Unlike every other count on that cycle it is not gated
// by login - a security notice ("don't click that link") has to reach an
// anonymous visitor too, not just a signed-in member.

let mountedCallbacks = []
let mockUser = null
const mockMessageFetchCount = vi.fn()
const mockNewsfeedFetchCount = vi.fn()
const mockLockdownFetch = vi.fn()

vi.stubGlobal('onMounted', (fn) => mountedCallbacks.push(fn))
vi.stubGlobal('useHead', () => {})
vi.stubGlobal('useRuntimeConfig', () => ({ public: {} }))
vi.stubGlobal('useRoute', () => ({ path: '/chitchat', params: {}, query: {} }))
vi.stubGlobal('useRouter', () => ({
  push: vi.fn(),
  back: vi.fn(),
  currentRoute: { value: { path: '/chitchat' } },
}))

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({
    get user() {
      return mockUser
    },
    forceLogin: false,
  }),
}))
vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({ online: true, get: () => null }),
}))
vi.mock('~/stores/newsfeed', () => ({
  useNewsfeedStore: () => ({ count: 0, fetchCount: mockNewsfeedFetchCount }),
}))
vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    count: 0,
    activePostsCounter: 0,
    fetchCount: mockMessageFetchCount,
    fetchActivePostCount: vi.fn(),
  }),
}))
vi.mock('~/stores/notification', () => ({
  useNotificationStore: () => ({
    count: 0,
    fetchCount: vi.fn().mockResolvedValue(0),
    fetchList: vi.fn(),
  }),
}))
vi.mock('~/stores/chat', () => ({
  useChatStore: () => ({ unreadCount: 0, byChatId: () => null }),
}))
vi.mock('~/stores/communityevent', () => ({
  useCommunityEventStore: () => ({ count: 0, fetchList: vi.fn() }),
}))
vi.mock('~/stores/volunteering', () => ({
  useVolunteeringStore: () => ({ count: 0, fetchList: vi.fn() }),
}))
vi.mock('~/stores/mobile', () => ({
  useMobileStore: () => ({ isApp: false, setBadgeCount: vi.fn() }),
}))
vi.mock('~/stores/lockdown', () => ({
  useLockdownStore: () => ({ fetch: mockLockdownFetch }),
}))
vi.mock('~/composables/useMe', () => ({ fetchMe: vi.fn() }))

let mod

async function mountNavbar() {
  // One module for the file: the loop's "initialised once" flag is module
  // state, reset between tests so each mount is the first.
  mod = mod || (await import('~/composables/useNavbar'))
  mod.resetNavbarCountsForTest()
  mod.useNavbar()
  for (const cb of mountedCallbacks) cb()
  await flush()
  return mod
}

async function flush() {
  for (let i = 0; i < 10; i++) {
    await Promise.resolve()
  }
}

describe('the navbar fetches the lockdown notice on its counts cycle', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    mountedCallbacks = []
    mockMessageFetchCount.mockReset().mockResolvedValue(0)
    mockNewsfeedFetchCount.mockReset().mockResolvedValue(0)
    mockLockdownFetch.mockReset().mockResolvedValue({ notice: null })
    mockUser = {
      id: 35909200,
      settings: { browseView: 'nearby', browseMaxDistance: 20.6 },
    }
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('fetches the lockdown notice on mount', async () => {
    await mountNavbar()
    expect(mockLockdownFetch).toHaveBeenCalledTimes(1)
  })

  it('fetches the lockdown notice again on the next sixty-second pass', async () => {
    await mountNavbar()
    await vi.advanceTimersByTimeAsync(60000)
    expect(mockLockdownFetch).toHaveBeenCalledTimes(2)
  })

  it('fetches the lockdown notice even when nobody is logged in', async () => {
    mockUser = null
    await mountNavbar()
    expect(mockLockdownFetch).toHaveBeenCalledTimes(1)
    // The gated, logged-in-only counts did not fire.
    expect(mockMessageFetchCount).not.toHaveBeenCalled()
  })

  it('does not stop the counts cycle if the lockdown fetch fails', async () => {
    mockLockdownFetch.mockRejectedValueOnce(new Error('network error'))
    await mountNavbar()
    expect(mockMessageFetchCount).toHaveBeenCalledTimes(1)
  })
})
