import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

// Pins the option SHAPE handed to Sentry.init. The v10 SDK reads the Vue
// component-tracking knobs only from options.tracingOptions - passed as
// top-level keys (the v7 createTracingMixins style) they are silently ignored
// and per-component performance spans vanish with no error or log line.

const mockInit = vi.hoisted(() => vi.fn())

vi.mock('@sentry/vue', () => ({
  init: mockInit,
  browserTracingIntegration: vi.fn(() => ({ name: 'BrowserTracing' })),
  httpClientIntegration: vi.fn(() => ({ name: 'HttpClient' })),
  extraErrorDataIntegration: vi.fn(() => ({ name: 'ExtraErrorData' })),
  setTag: vi.fn(),
  setContext: vi.fn(),
  setUser: vi.fn(),
  addBreadcrumb: vi.fn(),
  captureException: vi.fn(),
  captureMessage: vi.fn(),
}))

vi.mock('~/stores/misc', () => ({ useMiscStore: vi.fn(() => ({})) }))
vi.mock('~/stores/auth', () => ({ useAuthStore: vi.fn(() => ({})) }))
vi.mock('~/composables/useSuppressException', () => ({
  suppressException: vi.fn(() => false),
  suppressSentryEvent: vi.fn(() => false),
}))
vi.mock('~/composables/useTrace', () => ({
  onTraceChange: vi.fn(),
  getTraceId: vi.fn(() => 'trace'),
  getSessionId: vi.fn(() => 'session'),
}))
const clientLogMock = vi.hoisted(() => ({
  pageView: vi.fn(),
  setRouteNameProvider: vi.fn(),
  setLoggingContextProvider: vi.fn(),
}))
const loggingCtx = vi.hoisted(() => ({
  startPage: vi.fn(),
  init: vi.fn(),
  getContext: vi.fn(() => ({ page_id: 'page_1' })),
}))
vi.mock('~/composables/useClientLog', () => ({
  useClientLog: vi.fn(() => ({
    setAuthStore: vi.fn(),
    sessionStart: vi.fn(),
    pageView: clientLogMock.pageView,
  })),
  setRouteNameProvider: clientLogMock.setRouteNameProvider,
  setLoggingContextProvider: clientLogMock.setLoggingContextProvider,
}))
vi.mock('~/stores/loggingContext', () => ({
  useLoggingContextStore: () => loggingCtx,
}))

describe('plugins/sentry.client', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    globalThis.__testRuntimeConfig = () => ({
      public: {
        SENTRY_DSN: 'https://key@sentry.example/1',
        COOKIEYES: null,
      },
    })
    globalThis.__testUseRouter = () => ({
      afterEach: vi.fn(),
      push: vi.fn(),
      currentRoute: { value: { path: '/' } },
    })
    // happy-dom reports itself as automated, and beforeSend sends nothing from
    // an automated browser. Every test is a person's browser unless it says not.
    Object.defineProperty(window.navigator, 'webdriver', {
      value: false,
      configurable: true,
    })
  })

  afterEach(() => {
    delete globalThis.__testRuntimeConfig
    delete globalThis.__testUseRouter
    delete window.navigator.webdriver
  })

  async function runPlugin() {
    const { default: plugin } = await import('~/plugins/sentry.client.ts')
    const nuxtApp = { vueApp: { mixin: vi.fn(), use: vi.fn() } }
    plugin(nuxtApp)
    return nuxtApp
  }

  it('initialises Sentry with component tracking nested in tracingOptions', async () => {
    await runPlugin()

    expect(mockInit).toHaveBeenCalledTimes(1)
    const options = mockInit.mock.calls[0][0]

    expect(options.tracingOptions).toEqual({
      trackComponents: true,
      timeout: 2000,
      hooks: ['activate', 'mount', 'update'],
    })
    // The broken flat form must not come back - v10 ignores these silently.
    expect(options).not.toHaveProperty('trackComponents')
    expect(options).not.toHaveProperty('hooks')
    expect(options.attachProps).toBe(true)
  })

  it('starts a logging-context page before logging the page view', async () => {
    let afterEach
    globalThis.__testUseRouter = () => ({
      afterEach: (cb) => {
        afterEach = cb
      },
      push: vi.fn(),
      currentRoute: { value: { path: '/', name: 'give' } },
    })
    await runPlugin()

    const order = []
    loggingCtx.startPage.mockImplementation(() => order.push('start'))
    clientLogMock.pageView.mockImplementation(() => order.push('view'))
    afterEach({ path: '/give', name: 'give', query: {} })

    expect(order).toEqual(['start', 'view'])
  })

  it('gives the client log the route name and logging context providers', async () => {
    globalThis.__testUseRouter = () => ({
      afterEach: vi.fn(),
      push: vi.fn(),
      currentRoute: { value: { path: '/', name: 'give-mobile-photos' } },
    })
    await runPlugin()

    const routeFn = clientLogMock.setRouteNameProvider.mock.calls[0][0]
    expect(routeFn()).toBe('give-mobile-photos')
    const ctxFn = clientLogMock.setLoggingContextProvider.mock.calls[0][0]
    expect(ctxFn()).toEqual({ page_id: 'page_1' })
  })

  it('passes the router into browserTracingIntegration', async () => {
    const Sentry = await import('@sentry/vue')
    await runPlugin()

    expect(Sentry.browserTracingIntegration).toHaveBeenCalledWith(
      expect.objectContaining({ router: expect.any(Object) })
    )
    const options = mockInit.mock.calls[0][0]
    expect(options.integrations).toHaveLength(3)
  })

  describe('beforeSend drops errors thrown inside Google ad scripts', () => {
    function errorWithStack(stack) {
      const err = new Error('int64')
      err.stack = stack
      return err
    }

    // Sentry NUXT3-DQ9: "Error: int64", every frame inside Google's own
    // ad-rendering telemetry (pagead2.googlesyndication.com/pagead/js/rum.js),
    // caught only because Sentry wraps every addEventListener callback on the page.
    it('returns null for a stack entirely inside pagead2.googlesyndication.com', async () => {
      await runPlugin()
      const options = mockInit.mock.calls[0][0]
      const stack = [
        'Error: int64',
        '    at Ot (https://pagead2.googlesyndication.com/pagead/js/rum.js:12:345)',
        '    at HTMLDocument.<anonymous> (https://pagead2.googlesyndication.com/pagead/js/rum.js:99:1)',
      ].join('\n')
      const result = options.beforeSend(
        { extra: {} },
        { originalException: errorWithStack(stack) }
      )
      expect(result).toBeNull()
    })

    it('still reports an error raised from our own bundle', async () => {
      await runPlugin()
      const options = mockInit.mock.calls[0][0]
      const stack = [
        'Error: boom',
        '    at setup (https://www.ilovefreegle.org/_nuxt/PostMap.abc123.js:1:2)',
      ].join('\n')
      const event = { extra: {} }
      const result = options.beforeSend(event, {
        originalException: errorWithStack(stack),
      })
      expect(result).toBe(event)
    })
  })

  // Sentry MODTOOLS "Error: pa": 1,689 events in a week from CI's
  // modtools-prod-local, tagged production, because Playwright's 'Desktop
  // Chrome' profile reports Windows Chrome and the HeadlessChrome check missed it.
  describe('beforeSend under automation', () => {
    const playwrightUserAgent =
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.7922.34 Safari/537.36'

    function setBrowser(userAgent, webdriver) {
      for (const [key, value] of Object.entries({ userAgent, webdriver })) {
        Object.defineProperty(window.navigator, key, {
          value,
          configurable: true,
        })
      }
    }

    afterEach(() => {
      // The own properties shadow happy-dom's; removing them brings its values back.
      delete window.navigator.userAgent
      delete window.navigator.webdriver
      vi.restoreAllMocks()
    })

    it('logs the exception to the console but does not send it', async () => {
      setBrowser(playwrightUserAgent, true)
      const consoleError = vi
        .spyOn(console, 'error')
        .mockImplementation(() => {})
      vi.spyOn(console, 'log').mockImplementation(() => {})
      await runPlugin()
      const options = mockInit.mock.calls[0][0]

      const result = options.beforeSend(
        { extra: {}, exception: {} },
        { originalException: new Error('pa') }
      )

      expect(result).toBeNull()
      expect(consoleError).toHaveBeenCalledWith(
        '[Exception for Sentry]: (Error: pa)',
        expect.anything()
      )
    })

    it('still sends from the same browser when a person is using it', async () => {
      setBrowser(playwrightUserAgent, false)
      vi.spyOn(console, 'error').mockImplementation(() => {})
      vi.spyOn(console, 'log').mockImplementation(() => {})
      await runPlugin()
      const options = mockInit.mock.calls[0][0]
      const event = { extra: {}, exception: {} }

      expect(
        options.beforeSend(event, { originalException: new Error('pa') })
      ).toBe(event)
    })
  })
})
