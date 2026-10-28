import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { APIError, MaintenanceError } from '~/api/APIErrors'

const mocks = vi.hoisted(() => ({
  captureException: vi.fn(),
  setErrorDetails: vi.fn(),
  suppressException: vi.fn(() => false),
}))

vi.mock('@sentry/browser', () => ({
  captureException: mocks.captureException,
}))
// The plugin only needs the error classes, not the whole API layer.
vi.mock('@/api/BaseAPI', () => import('~/api/APIErrors'))
vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({ setErrorDetails: mocks.setErrorDetails }),
}))
vi.mock('~/composables/useSuppressException', () => ({
  suppressException: mocks.suppressException,
}))

// Nuxt installs its own Vue error handler for hydration, which turns any error
// into the error page, and the plugin wraps it. An API error should show the
// "Something went wrong" notice instead, and after hydration nothing should
// reach Nuxt's handler.
describe('something-went-wrong plugin', () => {
  let nuxtApp
  let nuxtHandler

  async function load({ isHydrating = false } = {}) {
    nuxtHandler = vi.fn()
    nuxtApp = {
      isHydrating,
      vueApp: { config: { errorHandler: nuxtHandler } },
    }
    const mod = await import('~/plugins/something-went-wrong.client.js')
    mod.default(nuxtApp)
    return nuxtApp.vueApp.config.errorHandler
  }

  const refusal = () =>
    new APIError({ request: {}, response: { status: 401 } }, 'refused')

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
    const err = refusal()
    expect(handler(err, vm, 'component event handler')).toBe(true)
    expect(mocks.setErrorDetails).toHaveBeenCalledWith(err)
    expect(mocks.captureException).toHaveBeenCalledWith(err)
    expect(nuxtHandler).not.toHaveBeenCalled()
  })

  it('passes other errors to the Nuxt handler while hydrating', async () => {
    const handler = await load({ isHydrating: true })
    const err = new TypeError('bug')
    handler(err, {}, 'setup function')
    expect(nuxtHandler).toHaveBeenCalledWith(err, {}, 'setup function')
    expect(mocks.setErrorDetails).not.toHaveBeenCalled()
  })

  it('keeps other errors off the error page after hydration', async () => {
    // The plugin loads while Nuxt is still hydrating, so the check has to
    // happen when the error arrives, not when the plugin is set up.
    const handler = await load({ isHydrating: true })
    nuxtApp.isHydrating = false
    handler(new TypeError('bug'), {}, 'component event handler')
    expect(nuxtHandler).not.toHaveBeenCalled()
    expect(mocks.setErrorDetails).not.toHaveBeenCalled()
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
