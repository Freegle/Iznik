/**
 * Links opened from outside the app. Android hands the app a VIEW intent for
 * www.ilovefreegle.org links and the freegle:// scheme (AndroidManifest.xml); Capacitor
 * raises appUrlOpen, and stores/mobile.js turns the path into a router push.
 */

const { test, expect } = require('../fixtures')
const { timeouts } = require('../config')
const device = require('./device')

test.describe('Deep links', () => {
  test('an ilovefreegle.org link opens that page in the app', async ({
    page,
  }) => {
    await page.gotoAndVerify('/', { maxRetries: 1 })

    await device.openUrl('https://www.ilovefreegle.org/explore')
    await device.waitForForeground()

    await expect
      .poll(() => page.url(), { timeout: timeouts.ui.appearance })
      .toMatch(/\/explore(\/|\?|$)/)
  })

  test('a link with a path parameter opens that page', async ({
    page,
    testEnv,
  }) => {
    await page.gotoAndVerify('/', { maxRetries: 1 })
    const group = testEnv.group.name

    await device.openUrl(`https://www.ilovefreegle.org/explore/${group}`)
    await device.waitForForeground()

    await expect
      .poll(() => page.url(), { timeout: timeouts.ui.appearance })
      .toMatch(new RegExp(`/explore/${group}(\\?|$)`))
    await expect(page.locator('body')).toContainText(group, {
      timeout: timeouts.ui.appearance,
    })
  })
})
