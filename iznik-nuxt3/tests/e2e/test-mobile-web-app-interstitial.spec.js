/**
 * Experiment mobile-web-app-interstitial: on arrival from a phone browser, arm 'b' sees a full-screen
 * page asking them to install the app; control sees nothing. Shown once, then not again for 30 days.
 *
 * The arm is forced by serving the live config (GET /config/ux_experiments) ourselves and seeding
 * the copy the page keeps in localStorage, so the arm is there on first mount whatever order the
 * fetch returns in (composables/useExperiment.js).
 */

const { test, expect } = require('./fixtures')
const { timeouts } = require('./config')

const EXPERIMENT = 'mobile-web-app-interstitial'
const PATH = '/about'

const ANDROID =
  'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36'
const IPHONE =
  'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1'
const IPAD =
  'Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1'

/**
 * Serve a live config in which every browser lands in `arms`, and seed the stored copy to match.
 * With no arms the experiment is absent from the config, which is control.
 */
async function forceConfig(page, arm) {
  const config = {}
  if (arm) {
    config[EXPERIMENT] = {
      arms: { [arm]: [0, 99] },
      // The page honours a running entry only if this is in the future and under 25 hours away.
      until: Math.floor(Date.now() / 1000) + 3600,
    }
  }

  await page.addInitScript(
    ({ config }) => {
      try {
        // Forget any earlier sticky arm, then store the config as a last good fetch.
        Object.keys(localStorage)
          .filter((k) => k.startsWith('freegle_ux_arm:'))
          .forEach((k) => localStorage.removeItem(k))
        localStorage.setItem(
          'freegle_ux_experiments',
          JSON.stringify({ fetchedAt: Date.now(), config })
        )
      } catch {
        // No storage: the route below still serves the config.
      }
    },
    { config }
  )

  await page.route('**/config/ux_experiments*', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      headers: {
        'access-control-allow-origin': route.request().headers().origin || '*',
        'access-control-allow-credentials': 'true',
      },
      body: JSON.stringify([
        { key: 'ux_experiments', value: JSON.stringify(config) },
      ]),
    })
  )
}

/**
 * A fresh phone-sized context (the fixture's own context is a desktop one, and a user agent can only
 * be set when a context is made) with the arm forced, opened on `path`.
 */
async function openOnPhone(
  browser,
  { ua, width = 390, height = 844, arm, path = PATH }
) {
  const context = await browser.newContext({
    ignoreHTTPSErrors: true,
    userAgent: ua,
    viewport: { width, height },
    isMobile: true,
    hasTouch: true,
    baseURL: process.env.TEST_BASE_URL || 'http://freegle-prod-local.localhost',
  })
  const page = await context.newPage()
  await forceConfig(page, arm)
  await page.goto(path, {
    waitUntil: 'domcontentloaded',
    timeout: timeouts.navigation.default,
  })
  return { context, page }
}

test.describe('Phone browser: mobile-web-app-interstitial', () => {
  test('arm b shows the page with Google Play; Continue leaves the member on the page they came for', async ({
    browser,
  }) => {
    const { context, page } = await openOnPhone(browser, {
      ua: ANDROID,
      arm: 'b',
    })
    try {
      const dialog = page.getByRole('dialog', { name: 'Get the Freegle app' })
      await expect(dialog).toBeVisible({ timeout: timeouts.ui.appearance })
      await expect(dialog.getByRole('listitem')).toHaveCount(4)
      await expect(page.getByTestId('app-interstitial-store')).toHaveAttribute(
        'href',
        /play\.google\.com/
      )

      await page.getByTestId('app-interstitial-continue').click()
      await expect(dialog).toHaveCount(0)
      await expect(page).toHaveURL(new RegExp(PATH))

      // Once shown, a later visit (a reload here) does not show it again.
      await page.reload({ waitUntil: 'domcontentloaded' })
      await page.waitForTimeout(3000)
      await expect(page.getByRole('dialog')).toHaveCount(0)
    } finally {
      await context.close()
    }
  })

  test('Escape closes it', async ({ browser }) => {
    const { context, page } = await openOnPhone(browser, {
      ua: ANDROID,
      arm: 'b',
    })
    try {
      const dialog = page.getByRole('dialog', { name: 'Get the Freegle app' })
      await expect(dialog).toBeVisible({ timeout: timeouts.ui.appearance })
      await page.keyboard.press('Escape')
      await expect(dialog).toHaveCount(0)
    } finally {
      await context.close()
    }
  })

  test('works at 320px wide, with nothing running off the side', async ({
    browser,
  }) => {
    const { context, page } = await openOnPhone(browser, {
      ua: ANDROID,
      width: 320,
      height: 568,
      arm: 'b',
    })
    try {
      await expect(page.getByTestId('app-interstitial-store')).toBeVisible({
        timeout: timeouts.ui.appearance,
      })
      const overflow = await page.evaluate(() => {
        const el = document.querySelector('.app-interstitial')
        return el.scrollWidth > el.clientWidth
      })
      expect(overflow).toBe(false)
    } finally {
      await context.close()
    }
  })

  test('control sees nothing', async ({ browser }) => {
    const { context, page } = await openOnPhone(browser, {
      ua: ANDROID,
      arm: 'control',
    })
    try {
      await page.waitForTimeout(4000)
      await expect(page.getByRole('dialog')).toHaveCount(0)
    } finally {
      await context.close()
    }
  })

  test('an unsubscribe page is left alone', async ({ browser }) => {
    const { context, page } = await openOnPhone(browser, {
      ua: ANDROID,
      arm: 'b',
      path: '/unsubscribe',
    })
    try {
      await page.waitForTimeout(4000)
      await expect(page.getByTestId('app-interstitial-store')).toHaveCount(0)
    } finally {
      await context.close()
    }
  })

  test('an iPhone is pointed at the App Store', async ({ browser }) => {
    const { context, page } = await openOnPhone(browser, {
      ua: IPHONE,
      arm: 'b',
    })
    try {
      await expect(page.getByTestId('app-interstitial-store')).toHaveAttribute(
        'href',
        /apps\.apple\.com/,
        { timeout: timeouts.ui.appearance }
      )
    } finally {
      await context.close()
    }
  })

  test('a tablet is left alone', async ({ browser }) => {
    const { context, page } = await openOnPhone(browser, {
      ua: IPAD,
      width: 820,
      height: 1180,
      arm: 'b',
    })
    try {
      await page.waitForTimeout(4000)
      await expect(page.getByTestId('app-interstitial-store')).toHaveCount(0)
    } finally {
      await context.close()
    }
  })
})
