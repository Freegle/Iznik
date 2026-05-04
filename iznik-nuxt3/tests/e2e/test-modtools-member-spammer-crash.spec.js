// @ts-check
/**
 * Test for ModTools Members filtering crash when spammer object doesn't exist.
 *
 * Bug: When filtering Members by 'Approved' status with 'Banned' flag,
 * ModSpammer component crashes with "Cannot read property 'reason' of undefined"
 * because the backend returns members without spammer data, and the component
 * tries to access spammer.reason without checking if spammer exists.
 */

const { test, expect } = require('./fixtures')
const { timeouts, environment } = require('./config')
const { loginViaModTools } = require('./utils/user')

const MODTOOLS_URL = environment.modtoolsBaseUrl

test.describe('ModTools Members Spammer Crash', () => {
  test('should not crash when filtering members by Approved+Banned with missing spammer data', async ({
    page,
    testEnv,
  }) => {
    await loginViaModTools(page, testEnv.mod.email)

    // Navigate to members list
    await page.goto(`${MODTOOLS_URL}/members`, {
      waitUntil: 'domcontentloaded',
      timeout: timeouts.navigation.initial,
    })

    // Wait for the members page to load
    await expect(page.locator('.members-list, [data-testid="members-list"]')).toBeVisible({
      timeout: timeouts.navigation.slowPage,
    })

    // Apply filter for 'Approved' status - look for status filter dropdown or button
    const statusFilterButton = page.locator(
      'button:has-text("Approved"), [data-testid="status-filter"], .status-filter'
    ).first()

    if (await statusFilterButton.isVisible()) {
      await statusFilterButton.click()
    }

    // Apply filter for 'Banned' flag - look for banned filter
    const bannedFilterButton = page.locator(
      'button:has-text("Banned"), [data-testid="banned-filter"], .banned-filter'
    ).first()

    if (await bannedFilterButton.isVisible()) {
      await bannedFilterButton.click()
    }

    // The test passes if we get here without a 500 error or component crash
    // Check that the page didn't show an error or HTTP 500
    const errorMsg = page.locator(
      'text=/Error|500|Internal Server Error/i'
    )

    // Wait a moment for any errors to appear
    await page.waitForTimeout(1000)

    // Assert that we didn't get an error
    await expect(errorMsg).not.toBeVisible()
  })
})
