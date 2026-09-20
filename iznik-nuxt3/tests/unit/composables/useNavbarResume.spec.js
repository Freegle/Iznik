import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

// The navbar's counts are fetched on mount and then every 60s. A page that
// was hidden (a backgrounded tab, the app asleep in a pocket) has had that
// timer throttled or suspended, so when it comes back the unread badge is as
// stale as the gap was: a member's badge read 0 through half an hour of
// ChitChat while two new posts sat in reach (2026-09-06). These tests pin
// the two ways the loop is told to go early - the document becoming visible,
// and refreshNavbarCounts() from the app's resume handler - and that neither
// starts a second loop alongside the first.

let mountedCallbacks = []
let mockUser = null
const mockMessageFetchCount = vi.fn()
const mockNewsfeedFetchCount = vi.fn()

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
vi.mock('~/composables/useMe', () => ({ fetchMe: vi.fn() }))

let hidden = false
let mod

async function mountNavbar() {
  // One module for the file: the loop's "initialised once" flag and its
  // visibility listener are module state, reset between tests so each mount
  // is the first - and the previous test's listener is gone from the shared
  // document rather than firing alongside the new one.
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

function becomeVisible() {
  hidden = false
  document.dispatchEvent(new Event('visibilitychange'))
}

describe('navbar counts refresh when the page comes back to life', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    mountedCallbacks = []
    mockMessageFetchCount.mockReset().mockResolvedValue(0)
    mockNewsfeedFetchCount.mockReset().mockResolvedValue(0)
    mockUser = {
      id: 35909200,
      settings: { browseView: 'nearby', browseMaxDistance: 20.6 },
    }
    hidden = false
    Object.defineProperty(document, 'hidden', {
      configurable: true,
      get: () => hidden,
    })
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('fetches the counts on mount, with the member\'s browse settings', async () => {
    await mountNavbar()
    expect(mockMessageFetchCount).toHaveBeenCalledTimes(1)
    expect(mockMessageFetchCount).toHaveBeenCalledWith('nearby', 20.6, false)
  })

  it('fetches again the moment the document becomes visible', async () => {
    await mountNavbar()
    hidden = true
    becomeVisible()
    await flush()
    expect(mockMessageFetchCount).toHaveBeenCalledTimes(2)
  })

  it('does nothing when the document is hidden', async () => {
    await mountNavbar()
    hidden = true
    document.dispatchEvent(new Event('visibilitychange'))
    await flush()
    expect(mockMessageFetchCount).toHaveBeenCalledTimes(1)
  })

  it('refreshNavbarCounts() fetches now, for the app resume handler', async () => {
    const mod = await mountNavbar()
    mod.refreshNavbarCounts()
    await flush()
    expect(mockMessageFetchCount).toHaveBeenCalledTimes(2)
  })

  it('a refresh restarts the 60s cycle rather than adding a second loop', async () => {
    const mod = await mountNavbar()
    // 30s into the cycle a refresh lands: it fetches now (2) and the next
    // scheduled fetch is 60s after THAT, not 30s later on the old timer.
    await vi.advanceTimersByTimeAsync(30000)
    mod.refreshNavbarCounts()
    await flush()
    expect(mockMessageFetchCount).toHaveBeenCalledTimes(2)
    await vi.advanceTimersByTimeAsync(30000)
    expect(mockMessageFetchCount).toHaveBeenCalledTimes(2)
    await vi.advanceTimersByTimeAsync(30000)
    expect(mockMessageFetchCount).toHaveBeenCalledTimes(3)
    // And exactly one loop is running: another 60s brings exactly one more.
    await vi.advanceTimersByTimeAsync(60000)
    expect(mockMessageFetchCount).toHaveBeenCalledTimes(4)
  })

  it('a refresh that lands mid-pass runs once the pass ends, never in parallel', async () => {
    let release
    mockMessageFetchCount.mockImplementationOnce(
      () => new Promise((resolve) => (release = resolve))
    )
    const mod = await mountNavbar()
    // The first pass is parked on the message count; two refreshes arrive.
    mod.refreshNavbarCounts()
    mod.refreshNavbarCounts()
    await flush()
    expect(mockMessageFetchCount).toHaveBeenCalledTimes(1)
    release(0)
    await flush()
    await vi.advanceTimersByTimeAsync(0)
    await flush()
    expect(mockMessageFetchCount).toHaveBeenCalledTimes(2)
  })

  it('refreshNavbarCounts() is harmless before any navbar has mounted', async () => {
    mod = mod || (await import('~/composables/useNavbar'))
    mod.resetNavbarCountsForTest()
    expect(() => mod.refreshNavbarCounts()).not.toThrow()
    expect(mockMessageFetchCount).not.toHaveBeenCalled()
  })
})
