import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

let mockPlatform = 'web'
vi.mock('@capacitor/core', () => ({
  Capacitor: {
    getPlatform: () => mockPlatform,
    isNativePlatform: () => mockPlatform !== 'web',
  },
}))

const mockTextZoomGetPreferred = vi.fn()
const mockTextZoomSet = vi.fn()
vi.mock('@capacitor/text-zoom', () => ({
  TextZoom: {
    getPreferred: (...args) => mockTextZoomGetPreferred(...args),
    set: (...args) => mockTextZoomSet(...args),
  },
}))

const mockChatSend = vi.fn()
const mockChatMarkRead = vi.fn()
const mockConfigFetchv2 = vi.fn()
vi.mock('~/api', () => ({
  default: () => ({
    chat: {
      send: mockChatSend,
      markRead: mockChatMarkRead,
    },
    config: {
      fetchv2: mockConfigFetchv2,
    },
  }),
}))

const mockSavePushId = vi.fn()
const mockClearRelated = vi.fn()
const mockLogout = vi.fn()
const mockLogin = vi.fn()
const mockForget = vi.fn()
let mockAuthUser = null
vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({
    savePushId: mockSavePushId,
    clearRelated: mockClearRelated,
    logout: mockLogout,
    login: mockLogin,
    forget: mockForget,
    user: mockAuthUser,
    forceLogin: false,
    loggedInEver: false,
  }),
}))

const mockFetchChats = vi.fn()
const mockFetchMessages = vi.fn()
vi.mock('~/stores/chat', () => ({
  useChatStore: () => ({
    fetchChats: mockFetchChats,
    fetchMessages: mockFetchMessages,
  }),
}))

vi.mock('~/stores/notification', () => ({
  useNotificationStore: () => ({
    fetchCount: vi.fn(),
  }),
}))

const mockSessionStart = vi.fn()
const mockSetAppVersion = vi.fn()
vi.mock('~/composables/useClientLog', () => ({
  setAppVersion: (...args) => mockSetAppVersion(...args),
  useClientLog: () => ({
    sessionStart: (...args) => mockSessionStart(...args),
  }),
}))

vi.mock('~/stores/debug', () => ({
  useDebugStore: () => ({
    info: vi.fn(),
    debug: vi.fn(),
    warn: vi.fn(),
    error: vi.fn(),
  }),
}))

let mockMobileVersion = '1.0.0'
vi.stubGlobal('useRuntimeConfig', () => ({
  public: { MOBILE_VERSION: mockMobileVersion },
}))

const mockRouterPush = vi.fn()
vi.stubGlobal('useRouter', () => ({
  push: mockRouterPush,
  currentRoute: { value: { path: '/' } },
}))

describe('mobile store', () => {
  let useMobileStore

  beforeEach(async () => {
    vi.clearAllMocks()
    mockPlatform = 'web'
    mockAuthUser = null
    mockMobileVersion = '1.0.0'
    setActivePinia(createPinia())
    const mod = await import('~/stores/mobile')
    useMobileStore = mod.useMobileStore
  })

  describe('initial state', () => {
    it('starts with correct defaults', () => {
      const store = useMobileStore()
      expect(store.isApp).toBe(false)
      expect(store.mobileVersion).toBe(false)
      expect(store.deviceinfo).toBeNull()
      expect(store.isiOS).toBe(false)
      expect(store.lastBadgeCount).toBe(-1)
      expect(store.modtools).toBe(false)
      expect(store.inlineReply).toBe(false)
      expect(store.chatid).toBe(false)
      expect(store.pushed).toBe(false)
      expect(store.route).toBe(false)
      expect(store.appupdaterequired).toBe(false)
    })
  })

  describe('init', () => {
    it('detects web platform', () => {
      const store = useMobileStore()
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      mockPlatform = 'web'
      store.init({ public: { MOBILE_VERSION: '1.0.0' } })
      expect(store.isApp).toBe(false)
      expect(store.isiOS).toBe(false)
      logSpy.mockRestore()
    })

    it('detects iOS platform', () => {
      const store = useMobileStore()
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      mockPlatform = 'ios'
      // Mock initApp to avoid Capacitor imports. It is async and init()
      // attaches a .catch() to it, so the stub must return a promise.
      store.initApp = vi.fn().mockResolvedValue(undefined)
      store.init({ public: { MOBILE_VERSION: '2.0.0' } })
      expect(store.isApp).toBe(true)
      expect(store.isiOS).toBe(true)
      expect(store.mobileVersion).toBe('2.0.0')
      expect(store.initApp).toHaveBeenCalled()
      logSpy.mockRestore()
    })

    it('detects Android platform', () => {
      const store = useMobileStore()
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      mockPlatform = 'android'
      store.initApp = vi.fn().mockResolvedValue(undefined)
      store.init({ public: { MOBILE_VERSION: '2.0.0' } })
      expect(store.isApp).toBe(true)
      expect(store.isiOS).toBe(false)
      logSpy.mockRestore()
    })

    // initApp() is deliberately not awaited, so a rejection has nowhere to go.
    // Left uncaught it is invisible on device, which is how a failed config
    // fetch silently cost the app its back-button handler.
    it('catches an initApp rejection instead of leaving it unhandled', async () => {
      const store = useMobileStore()
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      const unhandled = vi.fn()
      process.on('unhandledRejection', unhandled)
      mockPlatform = 'android'
      store.initApp = vi.fn().mockRejectedValue(new Error('init exploded'))

      expect(() =>
        store.init({ public: { MOBILE_VERSION: '2.0.0' } })
      ).not.toThrow()
      await new Promise((resolve) => setTimeout(resolve, 0))

      process.off('unhandledRejection', unhandled)
      expect(unhandled).not.toHaveBeenCalled()
      expect(logSpy).toHaveBeenCalledWith('initApp failed:', 'init exploded')
      logSpy.mockRestore()
    })
  })

  describe('extractQueryStringParams', () => {
    it('parses query string parameters', () => {
      const store = useMobileStore()
      const result = store.extractQueryStringParams(
        'https://example.com/path?u=123&k=abc'
      )
      expect(result).toEqual({ u: '123', k: 'abc' })
    })

    it('decodes URL-encoded values', () => {
      const store = useMobileStore()
      const result = store.extractQueryStringParams(
        'https://example.com?name=hello+world&email=a%40b.com'
      )
      expect(result.name).toBe('hello world')
      expect(result.email).toBe('a@b.com')
    })

    it('replaces dots with underscores in keys', () => {
      const store = useMobileStore()
      const result = store.extractQueryStringParams(
        'https://example.com?my.key=value'
      )
      expect(result.my_key).toBe('value')
    })

    it('returns false when no query string', () => {
      const store = useMobileStore()
      const result = store.extractQueryStringParams('https://example.com/path')
      expect(result).toBe(false)
    })

    it('handles empty query string', () => {
      const store = useMobileStore()
      const result = store.extractQueryStringParams('https://example.com?')
      expect(result).toEqual({})
    })

    it('handles params without values', () => {
      const store = useMobileStore()
      const result = store.extractQueryStringParams(
        'https://example.com?flag&key=val'
      )
      expect(result.flag).toBe('')
      expect(result.key).toBe('val')
    })
  })

  describe('versionOutOfDate', () => {
    it('returns true when required version is higher', () => {
      const store = useMobileStore()
      mockMobileVersion = '1.0.0'
      expect(store.versionOutOfDate('2.0.0')).toBe(true)
    })

    it('returns false when versions are equal', () => {
      const store = useMobileStore()
      mockMobileVersion = '1.0.0'
      expect(store.versionOutOfDate('1.0.0')).toBe(false)
    })

    it('returns false when current version is higher', () => {
      const store = useMobileStore()
      mockMobileVersion = '2.0.0'
      expect(store.versionOutOfDate('1.0.0')).toBe(false)
    })

    it('compares minor versions correctly', () => {
      const store = useMobileStore()
      mockMobileVersion = '1.2.0'
      expect(store.versionOutOfDate('1.3.0')).toBe(true)
    })

    it('compares patch versions correctly', () => {
      const store = useMobileStore()
      mockMobileVersion = '1.0.1'
      expect(store.versionOutOfDate('1.0.2')).toBe(true)
    })

    it('handles higher major but lower minor', () => {
      const store = useMobileStore()
      mockMobileVersion = '2.5.0'
      expect(store.versionOutOfDate('1.9.9')).toBe(false)
    })

    it('returns false for null/empty version', () => {
      const store = useMobileStore()
      expect(store.versionOutOfDate(null)).toBe(false)
      expect(store.versionOutOfDate('')).toBe(false)
    })
  })

  describe('setBadgeCount', () => {
    it('skips when not an app', async () => {
      const store = useMobileStore()
      store.isApp = false
      const mockBadge = { set: vi.fn() }
      await store.setBadgeCount(5, mockBadge)
      expect(mockBadge.set).not.toHaveBeenCalled()
    })

    it('sets badge when count changes', async () => {
      const store = useMobileStore()
      store.isApp = true
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      const mockBadge = { set: vi.fn() }
      await store.setBadgeCount(5, mockBadge)
      expect(mockBadge.set).toHaveBeenCalledWith({ count: 5 })
      expect(store.lastBadgeCount).toBe(5)
      logSpy.mockRestore()
    })

    it('skips when count is same as last', async () => {
      const store = useMobileStore()
      store.isApp = true
      store.lastBadgeCount = 5
      const mockBadge = { set: vi.fn() }
      await store.setBadgeCount(5, mockBadge)
      expect(mockBadge.set).not.toHaveBeenCalled()
    })

    it('treats NaN as 0', async () => {
      const store = useMobileStore()
      store.isApp = true
      store.lastBadgeCount = 5
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      const mockBadge = { set: vi.fn() }
      await store.setBadgeCount(NaN, mockBadge)
      expect(mockBadge.set).toHaveBeenCalledWith({ count: 0 })
      logSpy.mockRestore()
    })
  })

  describe('handleNotification', () => {
    let store

    beforeEach(() => {
      store = useMobileStore()
      store.isApp = true
      store.config = { public: {} }
    })

    it('returns early when notification is null', async () => {
      const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      await store.handleNotification(null, {}, {})
      expect(errorSpy).toHaveBeenCalled()
      errorSpy.mockRestore()
      logSpy.mockRestore()
    })

    it('returns early when notification.data is missing', async () => {
      const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      await store.handleNotification({}, {}, {})
      expect(errorSpy).toHaveBeenCalled()
      errorSpy.mockRestore()
      logSpy.mockRestore()
    })

    it('ignores legacy notifications without channel_id', async () => {
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      await store.handleNotification({ data: { route: '/chats/1' } }, {}, {})
      expect(store.route).toBe(false)
      logSpy.mockRestore()
    })

    it('sets modtools flag from notification data', async () => {
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      // Mock App import
      vi.mock('@capacitor/app', () => ({
        App: { getState: () => Promise.resolve({ isActive: false }) },
      }))

      await store.handleNotification(
        {
          data: {
            channel_id: 'chat_messages',
            modtools: '1',
            badge: '3',
            route: '/chats/1',
          },
        },
        {},
        { set: vi.fn() }
      )
      expect(store.modtools).toBe(true)
      logSpy.mockRestore()
    })

    it('refreshes chats on foreground push', async () => {
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})

      await store.handleNotification(
        {
          data: {
            channel_id: 'chat_messages',
            foreground: true,
            badge: '0',
            modtools: '0',
            chatids: '42',
          },
        },
        {},
        { set: vi.fn() }
      )
      expect(mockFetchChats).toHaveBeenCalledWith(null, false)
      expect(mockFetchMessages).toHaveBeenCalledWith(42)
      logSpy.mockRestore()
    })
  })

  describe('handleReplyAction', () => {
    it('sends reply via API', async () => {
      const store = useMobileStore()
      store.config = { public: {} }
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      mockChatSend.mockResolvedValue({})

      await store.handleReplyAction({ data: { chatids: '42' } }, 'Hello!')

      expect(mockChatSend).toHaveBeenCalledWith({
        roomid: 42,
        message: 'Hello!',
      })
      logSpy.mockRestore()
    })

    it('does nothing when no notification data', async () => {
      const store = useMobileStore()
      store.config = { public: {} }
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})

      await store.handleReplyAction(null, 'Hello!')
      expect(mockChatSend).not.toHaveBeenCalled()

      logSpy.mockRestore()
      errorSpy.mockRestore()
    })

    it('does nothing when no chat ID', async () => {
      const store = useMobileStore()
      store.config = { public: {} }
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})

      await store.handleReplyAction({ data: {} }, 'Hello!')
      expect(mockChatSend).not.toHaveBeenCalled()

      logSpy.mockRestore()
      errorSpy.mockRestore()
    })
  })

  describe('handleMarkReadAction', () => {
    it('marks chat as read via API', async () => {
      const store = useMobileStore()
      store.isApp = true
      store.config = { public: {} }
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      mockChatMarkRead.mockResolvedValue({})

      // Mock Badge import
      vi.doMock('@capawesome/capacitor-badge', () => ({
        Badge: { set: vi.fn() },
      }))

      await store.handleMarkReadAction({
        data: { chatids: '42', messageid: '999', badge: '3' },
      })

      expect(mockChatMarkRead).toHaveBeenCalledWith(42, 999, false)
      logSpy.mockRestore()
    })

    it('does nothing when no notification data', async () => {
      const store = useMobileStore()
      store.config = { public: {} }
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})

      await store.handleMarkReadAction(null)
      expect(mockChatMarkRead).not.toHaveBeenCalled()

      logSpy.mockRestore()
      errorSpy.mockRestore()
    })

    it('does nothing when no message ID', async () => {
      const store = useMobileStore()
      store.config = { public: {} }
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})

      await store.handleMarkReadAction({
        data: { chatids: '42' },
      })
      expect(mockChatMarkRead).not.toHaveBeenCalled()

      logSpy.mockRestore()
      errorSpy.mockRestore()
    })
  })

  describe('checkForAppUpdate', () => {
    it('sets appupdaterequired when version is outdated', async () => {
      const store = useMobileStore()
      store.config = { public: {} }
      store.isiOS = false
      mockMobileVersion = '1.0.0'
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})

      mockConfigFetchv2.mockResolvedValue([{ value: '2.0.0' }])

      await store.checkForAppUpdate()
      expect(store.appupdaterequired).toBe(true)
      expect(store.apprequiredversion).toBe('2.0.0')
      logSpy.mockRestore()
    })

    it('does not set appupdaterequired when version is current', async () => {
      const store = useMobileStore()
      store.config = { public: {} }
      store.isiOS = false
      mockMobileVersion = '2.0.0'

      mockConfigFetchv2.mockResolvedValue([{ value: '1.0.0' }])

      await store.checkForAppUpdate()
      expect(store.appupdaterequired).toBe(false)
    })

    // This check is best-effort and runs partway through initApp(), which has
    // no try/catch and is called without await or .catch(). When the config
    // fetch threw, the rejection escaped initApp() as a silent unhandled
    // rejection and every later step was skipped — including
    // initBackButton(), so no JS backButton listener was ever registered and
    // Capacitor's native default swallowed the back gesture at the root. A
    // failed version check must never cost the app its back button.
    it('swallows an API failure instead of rejecting', async () => {
      const store = useMobileStore()
      store.config = { public: {} }
      store.isiOS = false

      mockConfigFetchv2.mockRejectedValue(new Error('Network request failed'))

      await expect(store.checkForAppUpdate()).resolves.toBeUndefined()
      expect(store.appupdaterequired).toBe(false)
    })

    it('uses iOS key when on iOS', async () => {
      const store = useMobileStore()
      store.config = { public: {} }
      store.isiOS = true

      mockConfigFetchv2.mockResolvedValue([{ value: '1.0.0' }])

      await store.checkForAppUpdate()
      expect(mockConfigFetchv2).toHaveBeenCalledWith(
        'app_fd_version_ios_required'
      )
    })

    it('uses Android key when not iOS', async () => {
      const store = useMobileStore()
      store.config = { public: {} }
      store.isiOS = false

      mockConfigFetchv2.mockResolvedValue([{ value: '1.0.0' }])

      await store.checkForAppUpdate()
      expect(mockConfigFetchv2).toHaveBeenCalledWith(
        'app_fd_version_android_required'
      )
    })

    it('handles empty API response', async () => {
      const store = useMobileStore()
      store.config = { public: {} }
      store.isiOS = false

      mockConfigFetchv2.mockResolvedValue(null)

      await store.checkForAppUpdate()
      expect(store.appupdaterequired).toBe(false)
    })
  })

  describe('getDeviceInfo', () => {
    it('builds device info string', async () => {
      const store = useMobileStore()
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})

      const mockDevice = {
        getInfo: vi.fn().mockResolvedValue({
          manufacturer: 'Samsung',
          model: 'Galaxy S21',
          platform: 'android',
          osVersion: '13',
          webViewVersion: '120.0',
        }),
        getId: vi.fn().mockResolvedValue({ identifier: 'device-123' }),
      }

      await store.getDeviceInfo(mockDevice)

      expect(store.deviceuserinfo).toBe(
        'Samsung Galaxy S21 android 13 WebView 120.0'
      )
      expect(store.devicePersistentId).toBe('device-123')
      expect(store.isiOS).toBe(false)
      expect(store.osVersion).toBe('13')
      logSpy.mockRestore()
    })

    it('skips duplicate webViewVersion when same as osVersion', async () => {
      const store = useMobileStore()
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})

      const mockDevice = {
        getInfo: vi.fn().mockResolvedValue({
          manufacturer: 'Apple',
          model: 'iPhone',
          platform: 'ios',
          osVersion: '17.0',
          webViewVersion: '17.0',
        }),
        getId: vi.fn().mockResolvedValue({ identifier: 'ios-456' }),
      }

      await store.getDeviceInfo(mockDevice)

      expect(store.deviceuserinfo).toBe('Apple iPhone ios 17.0')
      expect(store.isiOS).toBe(true)
      logSpy.mockRestore()
    })

    it('handles missing optional fields', async () => {
      const store = useMobileStore()
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})

      const mockDevice = {
        getInfo: vi.fn().mockResolvedValue({
          platform: 'android',
          osVersion: '13',
        }),
        getId: vi.fn().mockResolvedValue({ identifier: 'dev-789' }),
      }

      await store.getDeviceInfo(mockDevice)

      expect(store.deviceuserinfo).toBe('android 13')
      logSpy.mockRestore()
    })
  })

  describe('initDeepLinks', () => {
    let store
    let capturedListener
    let mockApp

    beforeEach(() => {
      store = useMobileStore()
      capturedListener = null
      mockApp = {
        addListener: vi.fn().mockImplementation((event, fn) => {
          if (event === 'appUrlOpen') capturedListener = fn
        }),
      }
      // initDeepLinks guards with `if (process.client)`; enable it for tests.
      import.meta.client = true
    })

    afterEach(() => {
      delete import.meta.client
    })

    async function triggerDeepLink(url) {
      vi.useFakeTimers()
      store.initDeepLinks(mockApp)
      expect(capturedListener).not.toBeNull()
      await capturedListener({ url })
      vi.runAllTimers()
    }

    it('navigates to /chats/:id for a current-format chat deep link', async () => {
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})

      await triggerDeepLink('https://www.ilovefreegle.org/chats/12345')

      expect(mockRouterPush).toHaveBeenCalledWith('/chats/12345')
      logSpy.mockRestore()
    })

    it('rewrites old /chat/:id deep link to /chats/:id', async () => {
      // AssertFlip: before the fix initDeepLinks has no /chat/ → /chats/ replacement,
      // so push is called with '/chat/12345' (wrong). After fix it is '/chats/12345'.
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})

      await triggerDeepLink('https://www.ilovefreegle.org/chat/12345')

      expect(mockRouterPush).toHaveBeenCalledWith('/chats/12345')
      logSpy.mockRestore()
    })

    it('does not navigate for a URL that does not contain ilovefreegle.org', async () => {
      // AssertFlip: before the fix `ilfpos !== false` is always true (indexOf never
      // returns false), so a freegle:// URL navigates to substring(15) = '/12345'.
      // After fix `ilfpos !== -1` correctly skips non-ilovefreegle.org URLs.
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})

      await triggerDeepLink('freegle://chats/12345')

      expect(mockRouterPush).not.toHaveBeenCalled()
      logSpy.mockRestore()
    })
  })

  describe('reRegisterPush', () => {
    function makePushPlugin(receive = 'granted') {
      return {
        checkPermissions: vi.fn().mockResolvedValue({ receive }),
        register: vi.fn().mockResolvedValue(undefined),
      }
    }

    beforeEach(() => {
      // reRegisterPush guards with `if (!process.client ...)`; enable the
      // client environment so the checkPermissions/register path runs (same
      // pattern as the initDeepLinks and other native-guarded blocks above).
      import.meta.client = true
    })

    afterEach(() => {
      delete import.meta.client
    })

    it('calls register() on a native app with a stored plugin and granted permission', async () => {
      const store = useMobileStore()
      store.isApp = true
      const plugin = makePushPlugin('granted')
      store.pushPlugin = plugin

      await store.reRegisterPush()

      expect(plugin.checkPermissions).toHaveBeenCalled()
      expect(plugin.register).toHaveBeenCalled()
    })

    it('does NOT call register() when not on the app', async () => {
      const store = useMobileStore()
      store.isApp = false
      const plugin = makePushPlugin('granted')
      store.pushPlugin = plugin

      await store.reRegisterPush()

      expect(plugin.register).not.toHaveBeenCalled()
    })

    it('does NOT call register() when no plugin reference is stored', async () => {
      const store = useMobileStore()
      store.isApp = true
      store.pushPlugin = null

      // Should simply no-op without throwing.
      await expect(store.reRegisterPush()).resolves.toBeUndefined()
    })

    it('does NOT call register() when permission is not granted', async () => {
      const store = useMobileStore()
      store.isApp = true
      const plugin = makePushPlugin('denied')
      store.pushPlugin = plugin

      await store.reRegisterPush()

      expect(plugin.checkPermissions).toHaveBeenCalled()
      expect(plugin.register).not.toHaveBeenCalled()
    })

    it('swallows errors thrown during re-registration', async () => {
      const store = useMobileStore()
      store.isApp = true
      const plugin = {
        checkPermissions: vi.fn().mockResolvedValue({ receive: 'granted' }),
        register: vi.fn().mockRejectedValue(new Error('boom')),
      }
      store.pushPlugin = plugin

      await expect(store.reRegisterPush()).resolves.toBeUndefined()
    })
  })

  describe('initWakeUpActions resume handler', () => {
    let store
    let capturedListener
    let mockApp

    beforeEach(() => {
      store = useMobileStore()
      capturedListener = null
      mockApp = {
        addListener: vi.fn().mockImplementation((event, fn) => {
          if (event === 'resume') capturedListener = fn
        }),
      }
      import.meta.client = true
    })

    afterEach(() => {
      delete import.meta.client
    })

    it('re-registers push on resume when on a native app', async () => {
      store.isApp = true
      const plugin = {
        checkPermissions: vi.fn().mockResolvedValue({ receive: 'granted' }),
        register: vi.fn().mockResolvedValue(undefined),
      }
      store.pushPlugin = plugin

      store.initWakeUpActions(mockApp)
      expect(capturedListener).not.toBeNull()

      await capturedListener({})
      // Allow the async reRegisterPush() to settle.
      await Promise.resolve()
      await Promise.resolve()

      expect(mockFetchChats).toHaveBeenCalledWith(null, false)
      expect(plugin.register).toHaveBeenCalled()
    })

    it('does not re-register push on resume when not on the app', async () => {
      store.isApp = false
      const plugin = {
        checkPermissions: vi.fn().mockResolvedValue({ receive: 'granted' }),
        register: vi.fn().mockResolvedValue(undefined),
      }
      store.pushPlugin = plugin

      store.initWakeUpActions(mockApp)
      await capturedListener({})
      await Promise.resolve()
      await Promise.resolve()

      // Existing resume behaviour still runs.
      expect(mockFetchChats).toHaveBeenCalledWith(null, false)
      // But registration is not re-triggered off-app.
      expect(plugin.register).not.toHaveBeenCalled()
    })
  })

  describe('initBackButton hardware back handler', () => {
    let store
    let capturedListener
    let mockApp
    let historyBackSpy

    beforeEach(() => {
      store = useMobileStore()
      capturedListener = null
      mockApp = {
        addListener: vi.fn().mockImplementation((event, fn) => {
          if (event === 'backButton') capturedListener = fn
        }),
        minimizeApp: vi.fn(),
      }
      historyBackSpy = vi
        .spyOn(window.history, 'back')
        .mockImplementation(() => {})
      process.client = true
    })

    afterEach(() => {
      historyBackSpy.mockRestore()
      delete process.client
    })

    it('registers a backButton listener', () => {
      store.initBackButton(mockApp)
      expect(mockApp.addListener).toHaveBeenCalledWith(
        'backButton',
        expect.any(Function)
      )
      expect(capturedListener).not.toBeNull()
    })

    it('navigates back in history when the webview can go back', () => {
      store.initBackButton(mockApp)
      capturedListener({ canGoBack: true })
      expect(historyBackSpy).toHaveBeenCalled()
      expect(mockApp.minimizeApp).not.toHaveBeenCalled()
    })

    it('minimizes the app at the root of the history', () => {
      store.initBackButton(mockApp)
      capturedListener({ canGoBack: false })
      expect(historyBackSpy).not.toHaveBeenCalled()
      expect(mockApp.minimizeApp).toHaveBeenCalled()
    })
  })

  describe('handleNotification — new_posts channel', () => {
    let store

    beforeEach(() => {
      store = useMobileStore()
      store.isApp = true
      store.config = { public: {} }
    })

    // The new_posts push arrives with channel_id='new_posts' and route='/browse'.
    // When the app is in the background (okToMove=true), tapping should navigate
    // to /browse via router.push — exactly like any other routable push.
    it('routes to /browse when new_posts push is tapped (background)', async () => {
      vi.mock('@capacitor/app', () => ({
        App: { getState: () => Promise.resolve({ isActive: false }) },
      }))
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})

      await store.handleNotification(
        {
          okToMove: true,
          data: {
            channel_id: 'new_posts',
            category: 'NEW_POSTS',
            badge: '7',
            modtools: 'false',
            route: '/browse',
            notId: '200000001',
            count: '7',
          },
        },
        { removeAllDeliveredNotifications: vi.fn() },
        { set: vi.fn() }
      )

      expect(mockRouterPush).toHaveBeenCalledWith('/browse')
      logSpy.mockRestore()
    })

    // When the new_posts push arrives in the FOREGROUND we must NOT trigger
    // fetchChats — that's only meaningful for chat notifications.
    it('does NOT refresh chats when a new_posts push arrives in the foreground', async () => {
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})

      await store.handleNotification(
        {
          data: {
            channel_id: 'new_posts',
            category: 'NEW_POSTS',
            foreground: true,
            badge: '7',
            modtools: 'false',
            route: '/browse',
            notId: '200000001',
            count: '7',
          },
        },
        { removeAllDeliveredNotifications: vi.fn() },
        { set: vi.fn() }
      )

      // fetchChats must NOT be called — new_posts pushes carry no chat data
      expect(mockFetchChats).not.toHaveBeenCalled()
      logSpy.mockRestore()
    })

    // A foreground new_posts push should not trigger fetchMessages either.
    it('does NOT fetch messages when a new_posts push arrives in the foreground', async () => {
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})

      await store.handleNotification(
        {
          data: {
            channel_id: 'new_posts',
            foreground: true,
            badge: '3',
            modtools: 'false',
            route: '/browse',
          },
        },
        { removeAllDeliveredNotifications: vi.fn() },
        { set: vi.fn() }
      )

      expect(mockFetchMessages).not.toHaveBeenCalled()
      logSpy.mockRestore()
    })

    // Badge update must still fire on a new_posts push (the count field
    // carries the number of new items, used as the app badge value).
    it('updates badge count from a new_posts push', async () => {
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      const mockBadge = { set: vi.fn() }
      store.lastBadgeCount = -1

      await store.handleNotification(
        {
          data: {
            channel_id: 'new_posts',
            badge: '5',
            modtools: 'false',
            route: '/browse',
          },
        },
        { removeAllDeliveredNotifications: vi.fn() },
        mockBadge
      )

      // data.count is set from parseInt(data.badge) inside handleNotification
      expect(mockBadge.set).toHaveBeenCalledWith({ count: 5 })
      logSpy.mockRestore()
    })

    // A regular chat push in the foreground should still refresh chats
    // (regression guard — verifies the channel_id guard is specific to new_posts).
    it('still refreshes chats for a foreground chat_messages push', async () => {
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})

      await store.handleNotification(
        {
          data: {
            channel_id: 'chat_messages',
            foreground: true,
            badge: '2',
            modtools: '0',
          },
        },
        { removeAllDeliveredNotifications: vi.fn() },
        { set: vi.fn() }
      )

      expect(mockFetchChats).toHaveBeenCalledWith(null, false)
      logSpy.mockRestore()
    })
  })

  describe('handleNotification duplicate-navigation guard', () => {
    it('skips router.push when already on the target chat route', async () => {
      // AssertFlip: before the fix, router.currentRoute.path is accessed directly
      // on the Vue Router 4 Ref — which is undefined — so the guard never fires
      // and push is always called. After fix, router.currentRoute.value.path is
      // used and the guard correctly prevents redundant navigation.
      vi.stubGlobal('useRouter', () => ({
        push: mockRouterPush,
        currentRoute: { value: { path: '/chats/12345' } },
      }))

      vi.mock('@capacitor/app', () => ({
        App: { getState: () => Promise.resolve({ isActive: false }) },
      }))

      const store = useMobileStore()
      store.config = { public: {} }
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})

      await store.handleNotification(
        {
          okToMove: true,
          data: {
            channel_id: 'chat_messages',
            badge: '0',
            modtools: '0',
            route: '/chats/12345',
          },
        },
        { removeAllDeliveredNotifications: vi.fn() },
        { set: vi.fn() }
      )

      expect(mockRouterPush).not.toHaveBeenCalled()

      // Restore default mock for subsequent tests.
      vi.stubGlobal('useRouter', () => ({
        push: mockRouterPush,
        currentRoute: { value: { path: '/' } },
      }))
      logSpy.mockRestore()
    })
  })

  // The OS accessibility text-size setting is a PERMANENT preference: WKWebView
  // ignores iOS Dynamic Type for web content, so without applying the preferred
  // zoom the app renders at a fixed size whatever the member set in Settings.
  // (Distinct from pinch zoom, which is a transient gesture.)
  describe('initTextZoom', () => {
    function fakeApp() {
      const listeners = {}
      return {
        addListener: vi.fn((event, cb) => {
          listeners[event] = cb
        }),
        fire: (event) => listeners[event]?.(),
      }
    }

    it('applies the preferred zoom on startup', async () => {
      mockTextZoomGetPreferred.mockResolvedValue({ value: 1.3 })
      const store = useMobileStore()

      await store.initTextZoom(fakeApp())

      expect(mockTextZoomSet).toHaveBeenCalledWith({ value: 1.3 })
    })

    it('re-applies when the app resumes with a changed setting', async () => {
      mockTextZoomGetPreferred.mockResolvedValue({ value: 1.0 })
      const store = useMobileStore()
      const app = fakeApp()

      await store.initTextZoom(app)
      expect(app.addListener).toHaveBeenCalledWith(
        'resume',
        expect.any(Function)
      )

      // Member changes the OS setting while we're backgrounded.
      mockTextZoomGetPreferred.mockResolvedValue({ value: 1.15 })
      await app.fire('resume')

      expect(mockTextZoomSet).toHaveBeenLastCalledWith({ value: 1.15 })
    })

    it('does not set a zoom when the preferred value is unusable', async () => {
      mockTextZoomGetPreferred.mockResolvedValue({ value: 0 })
      const store = useMobileStore()

      await store.initTextZoom(fakeApp())

      expect(mockTextZoomSet).not.toHaveBeenCalled()
    })

    // Samsung's "Accessibility > Font size and style" setting (distinct from the
    // standard Display font-size slider, whose max is 1.3 - see the "applies the
    // preferred zoom on startup" test above) can push Configuration.fontScale up
    // to ~3.0. Applying that directly to the WebView blows up ModTools' fixed-width
    // navbar/left-menu shell (min-width/px-based, not designed to reflow at 2-3x
    // text size), which is what topic 10010/1 reported as the screen "narrower" /
    // differently sized after upgrading the app. Clamp to the standard slider's
    // max so extreme accessibility settings can't break the shell chrome.
    it('clamps an extreme preferred zoom so the ModTools shell does not break', async () => {
      mockTextZoomGetPreferred.mockResolvedValue({ value: 2.6 })
      const store = useMobileStore()

      await store.initTextZoom(fakeApp())

      expect(mockTextZoomSet).toHaveBeenCalledWith({ value: 1.3 })
    })

    it('survives the plugin being unavailable', async () => {
      mockTextZoomGetPreferred.mockRejectedValue(new Error('not implemented'))
      const store = useMobileStore()

      // Must not throw - an old installed build without the plugin still boots.
      await store.initTextZoom(fakeApp())

      expect(mockTextZoomSet).not.toHaveBeenCalled()
    })
  })

  describe('logAppSession', () => {
    // Support reads the app version a member is running from the session_start
    // client log. It can only be logged once Capacitor has answered, which is
    // long after the logging plugin starts - see the comment on logAppSession.
    it('logs a session_start carrying the Capacitor device info', () => {
      const store = useMobileStore()
      store.deviceinfo = { model: 'SM-S928B', platform: 'android' }

      store.logAppSession()

      expect(mockSessionStart).toHaveBeenCalledTimes(1)
      expect(mockSessionStart).toHaveBeenCalledWith(
        {},
        { model: 'SM-S928B', platform: 'android' }
      )
    })

    it('passes no app_version override, so the real native version wins', () => {
      // The previous attempt at this passed MOBILE_VERSION - a hand-bumped web
      // build constant, not the version installed on the device - which
      // overrode the real one.
      const store = useMobileStore()
      store.mobileVersion = '9.9.9'
      store.deviceinfo = { platform: 'ios' }

      store.logAppSession()

      const [context] = mockSessionStart.mock.calls[0]
      expect(context).not.toHaveProperty('app_version')
    })

    it('never breaks app startup if logging throws', () => {
      mockSessionStart.mockImplementationOnce(() => {
        throw new Error('logging blew up')
      })
      const store = useMobileStore()

      expect(() => store.logAppSession()).not.toThrow()
    })
  })
})
