import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { APIError, MaintenanceError } from '~/api/APIErrors'

const mocks = vi.hoisted(() => ({
  captureException: vi.fn(),
  setErrorDetails: vi.fn(),
  suppressException: vi.fn(() => false),
  warn: vi.fn(),
}))

vi.mock('@sentry/browser', () => ({
  captureException: mocks.captureException,
}))
// The plugin only needs the error classes, not the whole API layer.
vi.mock('@/api/BaseAPI', () => import('~/api/APIErrors'))
vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({ setErrorDetails: mocks.setErrorDetails }),
}))
vi.mock('~/composables/useClientLog', () => ({
  warn: (...args) => mocks.warn(...args),
}))
vi.mock('~/composables/useSuppressException', () => ({
  suppressException: mocks.suppressException,
}))

// Nuxt installs its own Vue error handler for hydration, which turns any error
// into the error page, and the plugin wraps it. An API error should show the
// "Something went wrong" notice instead, and after hydration nothing should
// reach Nuxt's handler, though other errors must still reach Sentry.
describe('something-went-wrong plugin', () => {
  let nuxtApp
  let nuxtHandler
  let sentryHandler

  // Where Sentry starts first (the app, dev), it wraps Nuxt's handler, so the
  // handler the plugin saves is Sentry's.
  async function load({ isHydrating = false, sentryFirst = false } = {}) {
    nuxtHandler = vi.fn()
    sentryHandler = vi.fn((...args) => nuxtHandler(...args))
    nuxtApp = {
      isHydrating,
      vueApp: {
        config: { errorHandler: sentryFirst ? sentryHandler : nuxtHandler },
      },
    }
    const mod = await import('~/plugins/something-went-wrong.client.js')
    mod.default(nuxtApp)
    return nuxtApp.vueApp.config.errorHandler
  }

  // A call that is our bug (400), made by a signed-in member.
  const badRequest = () =>
    new APIError(
      {
        request: { method: 'POST', path: '/x?loggedInAs=1&requestid=2' },
        response: { status: 400 },
      },
      'bad'
    )

  const refusal = (path = '/chat?loggedInAs=1&requestid=2') =>
    new APIError(
      { request: { method: 'GET', path }, response: { status: 401 } },
      'refused'
    )

  beforeEach(() => {
    vi.clearAllMocks()
    vi.spyOn(console, 'log').mockImplementation(() => {})
    vi.spyOn(console, 'error').mockImplementation(() => {})
    // The plugin also registers an unhandledrejection listener; keep it out
    // of the test window.
    vi.spyOn(window, 'addEventListener').mockImplementation(() => {})
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it.each([
    ['a component', {}],
    ['no component', null],
  ])('shows the notice and reports an API error from %s', async (_n, vm) => {
    const handler = await load()
    const err = badRequest()
    expect(handler(err, vm, 'component event handler')).toBe(true)
    expect(mocks.setErrorDetails).toHaveBeenCalledWith(err)
    expect(mocks.captureException).toHaveBeenCalledWith(err)
    expect(nuxtHandler).not.toHaveBeenCalled()
  })

  it('shows the notice for a refusal to a signed-in member, but does not report it', async () => {
    const handler = await load()
    const err = refusal()
    expect(handler(err, {}, 'x')).toBe(true)
    expect(mocks.setErrorDetails).toHaveBeenCalledWith(err)
    expect(mocks.captureException).not.toHaveBeenCalled()
    expect(mocks.warn).toHaveBeenCalledWith(
      'API failure not sent to Sentry',
      expect.objectContaining({ api_status: 401, api_path: '/chat' })
    )
  })

  it('shows nothing for a signed-out visitor whose read was refused', async () => {
    const handler = await load()
    expect(handler(refusal('/chat?requestid=2'), {}, 'x')).toBe(true)
    expect(mocks.setErrorDetails).not.toHaveBeenCalled()
    expect(mocks.captureException).not.toHaveBeenCalled()
  })

  it('still shows the notice when a signed-out visitor write was refused', async () => {
    const handler = await load()
    const err = new APIError(
      {
        request: { method: 'POST', path: '/message?requestid=2' },
        response: { status: 401 },
      },
      'refused'
    )
    expect(handler(err, {}, 'x')).toBe(true)
    expect(mocks.setErrorDetails).toHaveBeenCalledWith(err)
  })

  it('does not report again an error BaseAPI already sent', async () => {
    const handler = await load()
    const err = badRequest()
    err.failureHandled = true
    err.sentryReported = true
    handler(err, {}, 'x')
    expect(mocks.captureException).not.toHaveBeenCalled()
  })

  describe('unhandled rejections', () => {
    let listener

    beforeEach(async () => {
      window.addEventListener.mockImplementation((name, cb) => {
        if (name === 'unhandledrejection') listener = cb
      })
      await load()
    })

    it('reports an Error', () => {
      const err = new Error('boom')
      listener({ reason: err })
      expect(mocks.captureException).toHaveBeenCalledWith(err)
    })

    it('reports an API error that is our bug, and not one that is an answer', () => {
      const bad = badRequest()
      listener({ reason: bad })
      expect(mocks.captureException).toHaveBeenCalledWith(bad)

      mocks.captureException.mockClear()
      listener({ reason: refusal() })
      expect(mocks.captureException).not.toHaveBeenCalled()
    })

    it.each([
      ['an empty object', {}, 'Object', []],
      ['an Event', new Event('error'), 'Event', ['isTrusted']],
      [
        'a CustomEvent',
        new CustomEvent('x', { detail: 1 }),
        'CustomEvent',
        ['isTrusted'],
      ],
    ])(
      'describes %s in the client log and keeps it out of Sentry',
      (_n, reason, ctor, keys) => {
        listener({ reason })
        expect(mocks.captureException).not.toHaveBeenCalled()
        expect(mocks.warn).toHaveBeenCalledWith(
          'Unhandled rejection that is not an Error',
          expect.objectContaining({
            event_type: 'unhandled_rejection_non_error',
            reason_type: 'object',
            reason_constructor: ctor,
            reason_keys: expect.any(Array),
          })
        )
      }
    )
  })

  it('passes other errors to the Nuxt handler while hydrating', async () => {
    const handler = await load({ isHydrating: true })
    const err = new TypeError('bug')
    handler(err, {}, 'setup function')
    expect(nuxtHandler).toHaveBeenCalledWith(err, {}, 'setup function')
    expect(mocks.setErrorDetails).not.toHaveBeenCalled()
  })

  it('reports other errors after hydration instead of showing the error page', async () => {
    // The plugin loads while Nuxt is still hydrating, so the check has to
    // happen when the error arrives, not when the plugin is set up.
    const handler = await load({ isHydrating: true })
    nuxtApp.isHydrating = false
    const err = new TypeError('bug')
    handler(err, {}, 'component event handler')
    expect(nuxtHandler).not.toHaveBeenCalled()
    expect(mocks.setErrorDetails).not.toHaveBeenCalled()
    expect(mocks.captureException).toHaveBeenCalledWith(err)
    expect(console.error).toHaveBeenCalledWith(err)
  })

  it('still reports other errors after hydration when Sentry started first', async () => {
    // Sentry's handler would report the error, but then pass it on to Nuxt's,
    // so after hydration the plugin has to report it itself.
    const handler = await load({ isHydrating: true, sentryFirst: true })
    nuxtApp.isHydrating = false
    const err = new TypeError('bug')
    handler(err, {}, 'component event handler')
    expect(sentryHandler).not.toHaveBeenCalled()
    expect(nuxtHandler).not.toHaveBeenCalled()
    expect(mocks.captureException).toHaveBeenCalledWith(err)
  })

  it('leaves suppressed errors alone', async () => {
    const handler = await load({ isHydrating: true })
    mocks.suppressException.mockReturnValueOnce(true)
    expect(handler(new Error('noise'), {}, 'x')).toBe(true)
    expect(nuxtHandler).not.toHaveBeenCalled()
  })

  it('still rethrows a maintenance error', async () => {
    const handler = await load()
    const err = new MaintenanceError({ request: {}, response: {} }, 'down')
    expect(() => handler(err, {}, 'x')).toThrow(err)
  })
})
