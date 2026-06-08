import { describe, it, expect, vi, beforeEach, afterEach, afterAll } from 'vitest'

// Mock all store dependencies before importing the composable.
// useMiscStore is the only store used by the module-level functions.
const mockMiscStore = { lastTyping: null }

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => mockMiscStore,
}))

vi.mock('~/stores/newsfeed', () => ({ useNewsfeedStore: () => ({}) }))
vi.mock('~/stores/message', () => ({ useMessageStore: () => ({}) }))
vi.mock('~/stores/notification', () => ({ useNotificationStore: () => ({}) }))
vi.mock('~/stores/logo', () => ({ useLogoStore: () => ({}) }))
vi.mock('~/stores/communityevent', () => ({ useCommunityEventStore: () => ({}) }))
vi.mock('~/stores/volunteering', () => ({ useVolunteeringStore: () => ({}) }))
vi.mock('~/stores/mobile', () => ({ useMobileStore: () => ({ isApp: false }) }))
vi.mock('~/composables/useMe', () => ({ fetchMe: vi.fn() }))

import {
  navBarHidden,
  clearNavBarTimeout,
  setNavBarHidden,
  updateScrollTime,
} from '~/composables/useNavbar'

// TYPING_TIME_INVERVAL is 10000ms (from constants.js)
const TYPING_TIME_INVERVAL = 10000

// lastScrollTime is a private module-level variable that persists across tests within this
// file (module state is shared in vitest's fork pool). Using an ever-increasing base time
// ensures that after any test that calls updateScrollTime(), the NEXT test's Date.now() is
// always at least 1_000_000ms (1000s) newer, making the stale-scroll check pass safely.
let testNow = 1_000_000_000 // start at 1 billion ms (11.5 days)

describe('useNavbar module-level functions', () => {
  beforeEach(() => {
    // Advance base time by 1_000_000ms per test so lastScrollTime set in previous tests
    // is always at least ~990s in the past (>> 300ms threshold).
    testNow += 1_000_000
    vi.useFakeTimers({ now: testNow })
    vi.clearAllTimers() // discard any lingering fake-timer callbacks

    // Reset exported module state to known defaults
    navBarHidden.value = false
    clearNavBarTimeout() // sets internal navBarTimeout = null

    // Reset mock misc store
    mockMiscStore.lastTyping = null
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.clearAllMocks()
  })

  describe('navBarHidden', () => {
    it('is a reactive ref that starts as false', () => {
      navBarHidden.value = false
      expect(navBarHidden.value).toBe(false)
    })

    it('can be set to true', () => {
      navBarHidden.value = true
      expect(navBarHidden.value).toBe(true)
    })
  })

  describe('updateScrollTime', () => {
    it('marks a recent scroll: setNavBarHidden(false) keeps navbar hidden', () => {
      navBarHidden.value = true
      updateScrollTime() // lastScrollTime = testNow
      setNavBarHidden(false) // Date.now() - lastScrollTime = 0 < 300 → keep hidden
      expect(navBarHidden.value).toBe(true)
    })

    it('scroll becomes stale after 300ms elapses', () => {
      navBarHidden.value = true
      updateScrollTime() // lastScrollTime = testNow
      vi.advanceTimersByTime(300) // Date.now() = testNow + 300
      // 300 < 300 is false → scroll is not recent → show
      setNavBarHidden(false)
      expect(navBarHidden.value).toBe(false)
    })

    it('scroll at 299ms is still considered recent', () => {
      navBarHidden.value = true
      updateScrollTime() // lastScrollTime = testNow
      vi.advanceTimersByTime(299) // Date.now() = testNow + 299
      setNavBarHidden(false) // 299 < 300 → still scrolling
      expect(navBarHidden.value).toBe(true)
    })
  })

  describe('clearNavBarTimeout', () => {
    it('does not throw when no timeout is pending', () => {
      expect(() => clearNavBarTimeout()).not.toThrow()
    })

    it('can be called multiple times without error', () => {
      clearNavBarTimeout()
      expect(() => clearNavBarTimeout()).not.toThrow()
    })

    it('cancels the timer set by setNavBarHidden(true)', () => {
      navBarHidden.value = false
      setNavBarHidden(true) // hides + schedules 5s timer
      expect(vi.getTimerCount()).toBe(1)

      clearNavBarTimeout()
      expect(vi.getTimerCount()).toBe(0)
    })

    it('prevents the 5s auto-show timer from firing', () => {
      navBarHidden.value = false
      setNavBarHidden(true) // hide + schedule 5s show timer
      clearNavBarTimeout() // cancel

      vi.advanceTimersByTime(10000) // advance past where timer would have fired
      expect(navBarHidden.value).toBe(true) // still hidden: timer was cancelled
    })
  })

  describe('setNavBarHidden', () => {
    describe('no-op: state already matches request', () => {
      it('does nothing when requesting hide and navbar is already hidden', () => {
        navBarHidden.value = true
        setNavBarHidden(true)
        expect(navBarHidden.value).toBe(true)
        expect(vi.getTimerCount()).toBe(0)
      })

      it('does nothing when requesting show and navbar is already shown', () => {
        navBarHidden.value = false
        setNavBarHidden(false)
        expect(navBarHidden.value).toBe(false)
        expect(vi.getTimerCount()).toBe(0)
      })
    })

    describe('hiding the navbar (hideRequest=true)', () => {
      it('sets navBarHidden to true immediately', () => {
        navBarHidden.value = false
        setNavBarHidden(true)
        expect(navBarHidden.value).toBe(true)
      })

      it('schedules exactly one 5s timer to attempt show', () => {
        navBarHidden.value = false
        setNavBarHidden(true)
        expect(vi.getTimerCount()).toBe(1)
      })

      it('shows navbar after 5s when no typing or scrolling', () => {
        navBarHidden.value = false
        setNavBarHidden(true)
        expect(navBarHidden.value).toBe(true)

        vi.advanceTimersByTime(5000)
        expect(navBarHidden.value).toBe(false)
        expect(vi.getTimerCount()).toBe(0) // no more timers after showing
      })

      it('keeps navbar hidden after first 5s when still typing, shows after typing expires', () => {
        navBarHidden.value = false
        // Typing 1s ago: will expire after ~9s more
        mockMiscStore.lastTyping = testNow - 1000

        setNavBarHidden(true) // hide + 5s timer
        expect(navBarHidden.value).toBe(true)

        // After 5s: typing was 6s ago, 6000 < 10000 → still typing → kept hidden, new timer
        vi.advanceTimersByTime(5000)
        expect(navBarHidden.value).toBe(true)

        // After another 5s: typing was 11s ago, 11000 > 10000 → not typing → shown
        vi.advanceTimersByTime(5000)
        expect(navBarHidden.value).toBe(false)
      })

      it('shows after 5s even when recent scroll occurred during hide (scroll stale by then)', () => {
        navBarHidden.value = false
        updateScrollTime() // lastScrollTime = testNow
        setNavBarHidden(true) // hide + 5s timer

        // After 5s: Date.now = testNow + 5000, scroll was 5000ms ago > 300 → stale → show
        vi.advanceTimersByTime(5000)
        expect(navBarHidden.value).toBe(false)
      })
    })

    describe('showing the navbar (hideRequest=false)', () => {
      it('shows immediately when no recent typing or scrolling', () => {
        navBarHidden.value = true
        // lastTyping=null, lastScrollTime is from previous test (~990s ago >> 300ms)
        setNavBarHidden(false)
        expect(navBarHidden.value).toBe(false)
      })

      it('sets no timer when showing successfully', () => {
        navBarHidden.value = true
        setNavBarHidden(false)
        expect(vi.getTimerCount()).toBe(0)
      })

      it('keeps navbar hidden when typing recently (within TYPING_TIME_INVERVAL)', () => {
        navBarHidden.value = true
        mockMiscStore.lastTyping = testNow - 1000 // 1s ago, within 10s window
        setNavBarHidden(false)
        expect(navBarHidden.value).toBe(true)
      })

      it('keeps navbar hidden when scrolled recently (within 300ms)', () => {
        navBarHidden.value = true
        updateScrollTime() // scrolled at testNow
        setNavBarHidden(false) // 0ms since scroll < 300 → keep hidden
        expect(navBarHidden.value).toBe(true)
      })

      it('schedules a 5s retry timer when keeping hidden due to recent typing', () => {
        navBarHidden.value = true
        mockMiscStore.lastTyping = testNow - 1000
        setNavBarHidden(false)
        expect(vi.getTimerCount()).toBe(1)
      })

      it('schedules a 5s retry timer when keeping hidden due to recent scrolling', () => {
        navBarHidden.value = true
        updateScrollTime()
        setNavBarHidden(false)
        expect(vi.getTimerCount()).toBe(1)
      })

      it('shows after 5s when scroll becomes stale', () => {
        navBarHidden.value = true
        updateScrollTime() // lastScrollTime = testNow
        setNavBarHidden(false) // kept hidden: 0ms since scroll
        expect(navBarHidden.value).toBe(true)

        // After 5s: 5000ms since scroll >> 300ms → stale → show
        vi.advanceTimersByTime(5000)
        expect(navBarHidden.value).toBe(false)
      })

      it('shows after typing interval expires (needs two 5s retries)', () => {
        navBarHidden.value = true
        mockMiscStore.lastTyping = testNow - 1000 // typed 1s ago
        setNavBarHidden(false) // kept hidden: 1s < 10s
        expect(navBarHidden.value).toBe(true)

        // After 5s: typed 6s ago, 6000 < 10000 → still typing → kept hidden
        vi.advanceTimersByTime(5000)
        expect(navBarHidden.value).toBe(true)

        // After another 5s: typed 11s ago, 11000 > 10000 → not typing → show
        vi.advanceTimersByTime(5000)
        expect(navBarHidden.value).toBe(false)
      })

      describe('lastTyping falsy values: treated as no typing', () => {
        it.each([
          ['null', null],
          ['undefined', undefined],
          ['zero', 0],
          ['false', false],
        ])('shows immediately when lastTyping is %s', (_label, lastTyping) => {
          navBarHidden.value = true
          mockMiscStore.lastTyping = lastTyping
          setNavBarHidden(false)
          expect(navBarHidden.value).toBe(false)
        })
      })

      describe('TYPING_TIME_INVERVAL boundary (10000ms)', () => {
        it('keeps hidden when typing is 1ms inside the interval', () => {
          navBarHidden.value = true
          mockMiscStore.lastTyping = testNow - (TYPING_TIME_INVERVAL - 1)
          setNavBarHidden(false)
          expect(navBarHidden.value).toBe(true)
        })

        it('shows when typing is exactly at TYPING_TIME_INVERVAL (not < boundary)', () => {
          navBarHidden.value = true
          // now - lastTyping = TYPING_TIME_INVERVAL, which is NOT < TYPING_TIME_INVERVAL
          mockMiscStore.lastTyping = testNow - TYPING_TIME_INVERVAL
          setNavBarHidden(false)
          expect(navBarHidden.value).toBe(false)
        })

        it('shows when typing is 1ms past the interval', () => {
          navBarHidden.value = true
          mockMiscStore.lastTyping = testNow - (TYPING_TIME_INVERVAL + 1)
          setNavBarHidden(false)
          expect(navBarHidden.value).toBe(false)
        })
      })
    })

    describe('timer cancellation', () => {
      it('cancels the pending show timer when navbar is shown before timer fires', () => {
        navBarHidden.value = false
        setNavBarHidden(true) // hide + 5s show timer
        expect(vi.getTimerCount()).toBe(1)
        expect(navBarHidden.value).toBe(true)

        setNavBarHidden(false) // clears the 5s timer + shows immediately (no scroll/typing)
        expect(vi.getTimerCount()).toBe(0)
        expect(navBarHidden.value).toBe(false)
      })

      it('only has one active timer at a time when hide is re-triggered', () => {
        navBarHidden.value = false
        setNavBarHidden(true) // hide + timer #1
        expect(vi.getTimerCount()).toBe(1)

        // Manually reset to shown so a second hide is allowed
        navBarHidden.value = false
        setNavBarHidden(true) // clears timer #1, hides, schedules timer #2
        expect(vi.getTimerCount()).toBe(1) // exactly 1 timer, not 2
      })

      it('no timer remains after retry timer fires and shows navbar', () => {
        navBarHidden.value = true
        updateScrollTime() // recent scroll → retry
        setNavBarHidden(false) // kept hidden, 5s retry scheduled
        expect(vi.getTimerCount()).toBe(1)

        vi.advanceTimersByTime(5000) // retry fires → scroll stale → shows
        expect(navBarHidden.value).toBe(false)
        expect(vi.getTimerCount()).toBe(0) // no more timers
      })
    })
  })
})

// AssertFlip: badge must include notificationStore.count, not just chatStore.unreadCount.
// Scenario: user deletes 2 chat-review items → chatStore.unreadCount drops to 0;
// notificationStore.count stays at 1 (the "spurious stuck" notification).
// BUGGY: chatCount computed fires setBadgeCount(0), clearing the badge.
// FIXED: chatCount computed fires setBadgeCount(1) = 0 chats + 1 notification.
describe('chatCount badge sync (fix/ios-badge-sync-9654-13)', () => {
  let mockSetBadgeCount
  // Use a closure variable so the doMock factory picks up per-test values.
  let mockNotificationCount = 1

  beforeEach(async () => {
    vi.resetModules()
    mockSetBadgeCount = vi.fn()
    mockNotificationCount = 1 // default: 1 stuck notification

    // Stub Nuxt auto-import globals that useNavbar() calls directly (not via import).
    vi.stubGlobal('useRoute', () => ({ path: '/', params: {}, query: {} }))
    vi.stubGlobal('useRouter', () => ({
      push: vi.fn(),
      currentRoute: { value: { path: '/' } },
    }))
    vi.stubGlobal('useHead', () => {})
    vi.stubGlobal('useRuntimeConfig', () => ({ public: {} }))
    // useNavbar() calls onMounted() at module top level (outside a component).
    // Stub it as a no-op to prevent Vue's "no active instance" warning (which
    // the test setup converts into a thrown error). The callback only calls
    // getCounts() which is irrelevant to the badge-count assertion.
    vi.stubGlobal('onMounted', vi.fn())

    vi.doMock('~/stores/mobile', () => ({
      useMobileStore: () => ({ isApp: true, setBadgeCount: mockSetBadgeCount }),
    }))
    // Factory reads mockNotificationCount at import time (lazy closure).
    vi.doMock('~/stores/notification', () => ({
      useNotificationStore: () => ({ count: mockNotificationCount }),
    }))
    vi.doMock('~/stores/misc', () => ({
      useMiscStore: () => ({ lastTyping: null }),
    }))
    vi.doMock('~/stores/newsfeed', () => ({
      useNewsfeedStore: () => ({ count: 0, fetchCount: vi.fn() }),
    }))
    vi.doMock('~/stores/message', () => ({
      useMessageStore: () => ({
        count: 0,
        activePostsCounter: 0,
        fetchCount: vi.fn(),
        fetchActivePostCount: vi.fn(),
      }),
    }))
    vi.doMock('~/stores/logo', () => ({
      useLogoStore: () => ({ fetch: vi.fn() }),
    }))
    vi.doMock('~/stores/communityevent', () => ({
      useCommunityEventStore: () => ({ count: 0, fetchList: vi.fn() }),
    }))
    vi.doMock('~/stores/volunteering', () => ({
      useVolunteeringStore: () => ({ count: 0, fetchList: vi.fn() }),
    }))
    vi.doMock('~/composables/useMe', () => ({ fetchMe: vi.fn() }))

    // Chat store: 0 unread chats (all review items deleted)
    globalThis.__mockChatStore = {
      unreadCount: 0,
      byChatId: () => null,
      fetchChats: vi.fn(),
      fetchMessages: vi.fn(),
    }
  })

  afterEach(() => {
    vi.clearAllMocks()
    vi.unstubAllGlobals()
    delete globalThis.__mockChatStore
  })

  afterAll(() => {
    vi.resetModules()
  })

  it('sets badge to chatCount + notificationCount when app is active', async () => {
    // mockNotificationCount = 1 (set in beforeEach), chatStore.unreadCount = 0
    const { useNavbar } = await import('~/composables/useNavbar')
    const { chatCount } = useNavbar()

    // Accessing the computed triggers setBadgeCount.
    // BUGGY: setBadgeCount(0) — only chatStore.unreadCount (0).
    // FIXED: setBadgeCount(1) — 0 chats + 1 notification.
    void chatCount.value

    expect(mockSetBadgeCount).toHaveBeenCalledWith(1)
  })

  it('sets badge to 0 when both chat and notification counts are 0', async () => {
    // Override notification count before the module is imported.
    // The doMock factory reads mockNotificationCount lazily, so setting it here
    // (before the import below) makes the factory return count: 0.
    mockNotificationCount = 0

    const { useNavbar } = await import('~/composables/useNavbar')
    const { chatCount } = useNavbar()
    void chatCount.value

    expect(mockSetBadgeCount).toHaveBeenCalledWith(0)
  })
})
