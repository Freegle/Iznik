// @ts-check
/**
 * The offline indicator in the navbar shows when the one-second poll of
 * /apiv2/online fails. In CI that only ever happened on a load-induced blip,
 * so OfflineIndicator.vue was covered on some runs and not others. This makes
 * the poll fail on purpose, checks the indicator, then lets it recover.
 */

const { test, expect } = require('./fixtures')
const { timeouts } = require('./config')

test.describe('Offline indicator', () => {
  test('shows while the online poll fails and hides when it recovers', async ({
    page,
  }) => {
    page.addAllowedErrorPattern(/Failed to fetch|net::ERR_FAILED|Gone offline/)

    await page.gotoAndVerify('/', { timeout: timeouts.navigation.initial })

    const indicator = page.locator('[title="Network is offline"]').first()
    await expect(indicator).toBeHidden()

    // Every poll now fails, so the store marks us offline on its next tick.
    // The poll goes to <APIv2>/online, and APIv2 ends in /api on the CI stack.
    const onlinePoll = /\/online(\?.*)?$/
    await page.route(onlinePoll, (route) => route.abort('failed'))
    await expect(indicator).toBeVisible({ timeout: timeouts.api.default })

    // Let the poll through again: the store only clears the flag on a 200.
    await page.unroute(onlinePoll)
    await expect(indicator).toBeHidden({ timeout: timeouts.api.default })
  })
})
