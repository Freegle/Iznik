/**
 * The native side of the app, as the WebView sees it. This is the check that would have
 * caught the bug behind issue 14: @capacitor/screen-orientation was installed but not
 * listed in capacitor.config.ts includePlugins, so the app shipped without it and the
 * web code fell back silently. A plugin missing from the native build is absent from
 * Capacitor.PluginHeaders, whatever the JavaScript imports.
 */

const fs = require('fs')
const path = require('path')
const { test, expect } = require('../fixtures')
const { timeouts } = require('../config')

// capacitor.config.ts android.includePlugins, by the name each registers natively.
// cordova-plugin-calendar is a Cordova plugin and is not a Capacitor plugin header.
const REQUIRED_PLUGINS = [
  'App',
  'AppLauncher',
  'Badge',
  'Camera',
  'Device',
  'Network',
  'PushNotifications',
  'ScreenOrientation',
  'Share',
  'SocialLogin',
  'StatusBar',
  'Stripe',
  'TextZoom',
  // Registered by MainActivity itself (BlockStorePlugin), not from node_modules.
  'BlockStore',
]

async function capacitorState(page) {
  return page.evaluate(() => {
    const cap = window.Capacitor
    return {
      platform: cap ? cap.getPlatform() : null,
      native: cap ? cap.isNativePlatform() : false,
      headers:
        cap && cap.PluginHeaders ? cap.PluginHeaders.map((h) => h.name) : [],
    }
  })
}

test.describe('Native plugins', () => {
  test('the WebView runs inside the native app', async ({ page }) => {
    await page.gotoAndVerify('/', { maxRetries: 1 })
    const state = await capacitorState(page)
    expect(state.platform).toBe('android')
    expect(state.native).toBe(true)
  })

  test('every plugin in includePlugins is present natively', async ({
    page,
  }) => {
    await page.gotoAndVerify('/', { maxRetries: 1 })
    const { headers } = await capacitorState(page)
    const missing = REQUIRED_PLUGINS.filter((name) => !headers.includes(name))
    expect(
      missing,
      `plugins missing from the native build (present: ${headers.join(', ')})`
    ).toEqual([])
  })

  test('every plugin cap sync bundled survived the native build', async ({
    page,
  }) => {
    // capacitor.plugins.json is what `cap sync` wrote from the installed packages in
    // includePlugins; R8 or a registration failure is the only way one is bundled but
    // absent at runtime.
    const bundled = JSON.parse(
      fs.readFileSync(
        path.join(
          __dirname,
          '../../../android/app/src/main/assets/capacitor.plugins.json'
        ),
        'utf8'
      )
    ).map((entry) =>
      entry.classpath
        .split('.')
        .pop()
        .replace(/Plugin$/, '')
    )
    await page.gotoAndVerify('/', { maxRetries: 1 })
    const { headers } = await capacitorState(page)
    const missing = bundled.filter((name) => !headers.includes(name))
    expect(
      missing,
      `bundled plugins not registered at runtime (present: ${headers.join(', ')})`
    ).toEqual([])
  })

  test('screen orientation comes from the native plugin', async ({ page }) => {
    await page.gotoAndVerify('/', { maxRetries: 1 })
    const result = await page.evaluate(async () => {
      const plugin = window.Capacitor.Plugins.ScreenOrientation
      if (!plugin) return { error: 'ScreenOrientation not registered' }
      try {
        return await plugin.orientation()
      } catch (e) {
        return { error: e.message }
      }
    })
    expect(result.error).toBeUndefined()
    expect(result.type).toMatch(/^portrait/)
  })

  test('device and app info resolve natively', async ({ page }) => {
    await page.gotoAndVerify('/', { maxRetries: 1 })
    const info = await page.evaluate(async () => {
      const device = await window.Capacitor.Plugins.Device.getInfo()
      const app = await window.Capacitor.Plugins.App.getInfo()
      return {
        platform: device.platform,
        osVersion: device.osVersion,
        version: app.version,
        build: app.build,
      }
    })
    expect(info.platform).toBe('android')
    expect(info.version).toMatch(/^\d+\.\d+\.\d+$/)
    expect(String(info.build)).toMatch(/^\d+$/)
  })

  test('the app mounts its web bundle', async ({ page }) => {
    await page.gotoAndVerify('/', { maxRetries: 1 })
    await expect(page.locator('#__nuxt')).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    await expect(page.locator('body')).toContainText(/freegle/i, {
      timeout: timeouts.ui.appearance,
    })
  })
})
