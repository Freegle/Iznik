import { describe, it, expect, vi, beforeEach, afterEach, afterAll } from 'vitest'

// useNavbar() calls onMounted() at module top level (outside a real component),
// same workaround already used elsewhere in useNavbar.spec.js: stub it as a
// no-op so Vue doesn't warn about "no active instance", and so getCounts()
// (a large separately-scoped concern) never fires as a side effect here.
vi.stubGlobal('onMounted', vi.fn())
vi.stubGlobal('useHead', () => {})
vi.stubGlobal('useRuntimeConfig', () => ({ public: {} }))

let mockRoute = { path: '/', params: {}, query: {} }
let mockRouterPush
let mockRouterBack
let mockCurrentRoutePath = '/'

function stubRouteAndRouter() {
  vi.stubGlobal('useRoute', () => mockRoute)
  mockRouterPush = vi.fn()
  mockRouterBack = vi.fn()
  vi.stubGlobal('useRouter', () => ({
    push: mockRouterPush,
    back: mockRouterBack,
    get currentRoute() {
      return { value: { path: mockCurrentRoutePath } }
    },
  }))
}

let mockAuthUser = null
let mockAuthForceLogin = false
const mockAuthLogout = vi.fn().mockResolvedValue()
let mockMiscLastHomePage
let mockOnline = true
let mockNewsfeedCount = 0
let mockMessageCount = 0
let mockActivePostsCounter = 0
let mockCommunityEventCount = 0
let mockVolunteeringCount = 0
let mockChatUnreadCount = 0
let mockChatByChatId = () => null
let mockFetchMe

function stubStores() {
  vi.doMock('~/stores/auth', () => ({
    useAuthStore: () => ({
      get user() {
        return mockAuthUser
      },
      get forceLogin() {
        return mockAuthForceLogin
      },
      set forceLogin(v) {
        mockAuthForceLogin = v
      },
      logout: mockAuthLogout,
    }),
  }))
  vi.doMock('~/stores/misc', () => ({
    useMiscStore: () => ({
      lastTyping: null,
      get online() {
        return mockOnline
      },
      get: (key) => (key === 'lasthomepage' ? mockMiscLastHomePage : null),
    }),
  }))
  vi.doMock('~/stores/newsfeed', () => ({
    useNewsfeedStore: () => ({
      get count() {
        return mockNewsfeedCount
      },
      fetchCount: vi.fn(),
    }),
  }))
  vi.doMock('~/stores/message', () => ({
    useMessageStore: () => ({
      get count() {
        return mockMessageCount
      },
      get activePostsCounter() {
        return mockActivePostsCounter
      },
      fetchCount: vi.fn(),
      fetchActivePostCount: vi.fn(),
    }),
  }))
  vi.doMock('~/stores/notification', () => ({
    useNotificationStore: () => ({ count: 0, fetchCount: vi.fn(), fetchList: vi.fn() }),
  }))
  vi.doMock('~/stores/chat', () => ({
    useChatStore: () => ({
      get unreadCount() {
        return mockChatUnreadCount
      },
      byChatId: (id) => mockChatByChatId(id),
    }),
  }))
  vi.doMock('~/stores/communityevent', () => ({
    useCommunityEventStore: () => ({
      get count() {
        return mockCommunityEventCount
      },
      fetchList: vi.fn(),
    }),
  }))
  vi.doMock('~/stores/volunteering', () => ({
    useVolunteeringStore: () => ({
      get count() {
        return mockVolunteeringCount
      },
      fetchList: vi.fn(),
    }),
  }))
  vi.doMock('~/stores/mobile', () => ({
    useMobileStore: () => ({ isApp: false, setBadgeCount: vi.fn() }),
  }))
  vi.doMock('~/composables/useMe', () => ({
    fetchMe: (...args) => mockFetchMe(...args),
  }))
}

describe('useNavbar() — computed properties and actions', () => {
  beforeEach(() => {
    vi.resetModules()
    mockRoute = { path: '/', params: {}, query: {} }
    mockCurrentRoutePath = '/'
    mockAuthUser = null
    mockAuthForceLogin = false
    mockMiscLastHomePage = null
    mockOnline = true
    mockNewsfeedCount = 3
    mockMessageCount = 7
    mockActivePostsCounter = 2
    mockCommunityEventCount = 1
    mockVolunteeringCount = 4
    mockChatUnreadCount = 0
    mockChatByChatId = () => null
    mockFetchMe = vi.fn().mockResolvedValue()
    stubRouteAndRouter()
    stubStores()
  })

  afterEach(() => {
    vi.clearAllMocks()
  })

  afterAll(() => {
    vi.unstubAllGlobals()
    vi.resetModules()
  })

  async function getNavbar() {
    const { useNavbar } = await import('~/composables/useNavbar')
    return useNavbar()
  }

  it('online reflects the misc store', async () => {
    mockOnline = false
    const nav = await getNavbar()
    expect(nav.online.value).toBe(false)
  })

  it('newsCount/newsCountPlural read the newsfeed store count', async () => {
    const nav = await getNavbar()
    expect(nav.newsCount.value).toBe(3)
    expect(nav.newsCountPlural()).toBe('3 unread ChitChat posts')
  })

  it('browseCount clamps the message store count to 99', async () => {
    mockMessageCount = 150
    const nav = await getNavbar()
    expect(nav.browseCount.value).toBe(99)
  })

  it('browseCountPlural formats the raw message count', async () => {
    mockMessageCount = 1
    const nav = await getNavbar()
    expect(nav.browseCountPlural.value).toBe('1 new post')
  })

  it('activePostsCount/activePostsCountPlural reflect the message store', async () => {
    const nav = await getNavbar()
    expect(nav.activePostsCount.value).toBe(2)
    expect(nav.activePostsCountPlural.value).toBe('2 open posts')
  })

  it('communityEventCount/Plural reflect the community event store', async () => {
    const nav = await getNavbar()
    expect(nav.communityEventCount.value).toBe(1)
    expect(nav.communityEventCountPlural.value).toBe('1 community event')
  })

  it('volunteerOpportunityCount/Plural reflect the volunteering store', async () => {
    const nav = await getNavbar()
    expect(nav.volunteerOpportunityCount.value).toBe(4)
    expect(nav.volunteerOpportunityCountPlural.value()).toBe(
      '4 volunteer opportunities'
    )
  })

  describe('homePage', () => {
    it('is the landing page when logged out', async () => {
      const nav = await getNavbar()
      expect(nav.homePage.value).toBe('/')
    })

    it('is /browse when logged in with no remembered last page', async () => {
      mockAuthUser = { id: 1 }
      const nav = await getNavbar()
      expect(nav.homePage.value).toBe('/browse')
    })

    it('is /chitchat when the last home page was news', async () => {
      mockAuthUser = { id: 1 }
      mockMiscLastHomePage = 'news'
      const nav = await getNavbar()
      expect(nav.homePage.value).toBe('/chitchat')
    })

    it('is /myposts when the last home page was myposts', async () => {
      mockAuthUser = { id: 1 }
      mockMiscLastHomePage = 'myposts'
      const nav = await getNavbar()
      expect(nav.homePage.value).toBe('/myposts')
    })
  })

  describe('showBackButton', () => {
    it.each([
      ['/browse', false],
      ['/chitchat', false],
      ['/myposts', false],
      ['/', false],
      ['/explore/place/london', false],
      ['/message/123', true],
      ['/settings', true],
    ])('for path %s -> %s', async (path, expected) => {
      mockRoute = { path, params: {}, query: {} }
      const nav = await getNavbar()
      expect(nav.showBackButton.value).toBe(expected)
    })
  })

  describe('backButtonCount', () => {
    it('is 0 when not viewing a single chat', async () => {
      mockRoute = { path: '/browse', params: {}, query: {} }
      const nav = await getNavbar()
      expect(nav.backButtonCount.value).toBe(0)
    })

    it('subtracts the current chat unseen count from the overall chat badge', async () => {
      mockRoute = { path: '/chats/456', params: {}, query: {} }
      mockChatUnreadCount = 5
      mockChatByChatId = (id) => (id === 456 ? { unseen: 2 } : null)
      const nav = await getNavbar()
      expect(nav.backButtonCount.value).toBe(3)
    })

    it('handles an unknown chat id gracefully', async () => {
      mockRoute = { path: '/chats/999', params: {}, query: {} }
      mockChatUnreadCount = 5
      mockChatByChatId = () => null
      const nav = await getNavbar()
      expect(nav.backButtonCount.value).toBeNaN()
    })
  })

  describe('requestLogin', () => {
    it('sets forceLogin on the auth store', async () => {
      const nav = await getNavbar()
      nav.requestLogin()
      expect(mockAuthForceLogin).toBe(true)
    })
  })

  describe('logout', () => {
    it('logs out, clears forceLogin, and routes home', async () => {
      mockAuthForceLogin = true
      const nav = await getNavbar()
      await nav.logout()
      expect(mockAuthLogout).toHaveBeenCalled()
      expect(mockAuthForceLogin).toBe(false)
      expect(mockRouterPush).toHaveBeenCalledWith('/', true)
    })
  })

  describe('showAboutMe', () => {
    it('fetches the current member and opens the about-me modal', async () => {
      const nav = await getNavbar()
      await nav.showAboutMe()
      expect(mockFetchMe).toHaveBeenCalledWith(true)
      expect(nav.showAboutMeModal.value).toBe(true)
    })
  })

  describe('maybeReload', () => {
    it('reloads the page when already on the target route', async () => {
      mockCurrentRoutePath = '/browse'
      const reloadSpy = vi.fn()
      const originalLocation = window.location
      delete window.location
      window.location = { ...originalLocation, reload: reloadSpy }

      const nav = await getNavbar()
      nav.maybeReload('/browse')

      expect(reloadSpy).toHaveBeenCalledWith(true)
      window.location = originalLocation
    })

    it('does nothing when navigating to a different route', async () => {
      mockCurrentRoutePath = '/browse'
      const reloadSpy = vi.fn()
      const originalLocation = window.location
      delete window.location
      window.location = { ...originalLocation, reload: reloadSpy }

      const nav = await getNavbar()
      nav.maybeReload('/chitchat')

      expect(reloadSpy).not.toHaveBeenCalled()
      window.location = originalLocation
    })
  })

  describe('backButton', () => {
    it('goes to the chat list from a single chat', async () => {
      mockCurrentRoutePath = '/chats/123'
      const nav = await getNavbar()
      nav.backButton()
      expect(mockRouterPush).toHaveBeenCalledWith('/chats')
    })

    it('goes home from the chat list', async () => {
      mockCurrentRoutePath = '/chats'
      const nav = await getNavbar()
      nav.backButton()
      expect(mockRouterPush).toHaveBeenCalledWith('/')
    })

    it('goes home from the give mobile photos page to avoid a redirect loop', async () => {
      mockCurrentRoutePath = '/give/mobile/photos'
      const nav = await getNavbar()
      nav.backButton()
      expect(mockRouterPush).toHaveBeenCalledWith('/')
    })

    it('goes home from the find mobile photos page to avoid a redirect loop', async () => {
      mockCurrentRoutePath = '/find/mobile/photos'
      const nav = await getNavbar()
      nav.backButton()
      expect(mockRouterPush).toHaveBeenCalledWith('/')
    })

    it('otherwise calls router.back()', async () => {
      mockCurrentRoutePath = '/settings'
      const nav = await getNavbar()
      nav.backButton()
      expect(mockRouterBack).toHaveBeenCalled()
    })

    it('falls back to pushing home when router.back() throws', async () => {
      mockCurrentRoutePath = '/settings'
      const nav = await getNavbar()
      mockRouterBack.mockImplementation(() => {
        throw new Error('no history')
      })
      nav.backButton()
      expect(mockRouterPush).toHaveBeenCalledWith('/')
    })
  })
})
