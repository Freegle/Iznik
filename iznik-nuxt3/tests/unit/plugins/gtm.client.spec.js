import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

// Pins the GTM plugin that replaced @zadigetvoltaire/nuxt-gtm (whose Nuxt-3
// compatibility gate made @nuxt/kit silently skip it on Nuxt 4, so gtm.js
// stopped loading in production where GTM_ID is set).

const mockCreateGtm = vi.hoisted(() => vi.fn(() => ({ install: () => {} })))

vi.mock('@gtm-support/vue-gtm', () => ({
  createGtm: mockCreateGtm,
}))

// The #app mock's useRuntimeConfig defers to globalThis.__testRuntimeConfig.
function setGtmConfig(gtm) {
  globalThis.__testRuntimeConfig = () => ({ public: { gtm } })
}

async function runPlugin() {
  const { default: plugin } = await import('~/plugins/gtm.client.ts')
  const nuxtApp = { vueApp: { use: vi.fn() } }
  // The #app mock's defineNuxtPlugin returns the function unchanged.
  plugin(nuxtApp)
  return nuxtApp
}

describe('plugins/gtm.client', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    delete globalThis.__testRuntimeConfig
  })

  it('does nothing when GTM is not configured', async () => {
    setGtmConfig(undefined)
    const nuxtApp = await runPlugin()
    expect(nuxtApp.vueApp.use).not.toHaveBeenCalled()
    expect(mockCreateGtm).not.toHaveBeenCalled()
  })

  it('does nothing when GTM is configured but disabled', async () => {
    setGtmConfig({ id: 'GTM-TEST', enabled: false })
    const nuxtApp = await runPlugin()
    expect(nuxtApp.vueApp.use).not.toHaveBeenCalled()
  })

  it('installs createGtm with the runtime config options when enabled', async () => {
    setGtmConfig({
      id: 'GTM-TEST',
      enabled: true,
      defer: true,
      compatibility: true,
      enableRouterSync: false,
    })
    const nuxtApp = await runPlugin()

    expect(nuxtApp.vueApp.use).toHaveBeenCalledTimes(1)
    expect(mockCreateGtm).toHaveBeenCalledWith(
      expect.objectContaining({
        id: 'GTM-TEST',
        enabled: true,
        defer: true,
        // enableRouterSync false → no router wiring
        vueRouter: undefined,
      })
    )
  })

  it('passes a router when enableRouterSync is set', async () => {
    setGtmConfig({ id: 'GTM-TEST', enabled: true, enableRouterSync: true })
    await runPlugin()

    const opts = mockCreateGtm.mock.calls[0][0]
    expect(opts.vueRouter).toBeTruthy()
  })
})
