// @ts-check
/**
 * Regression test for ModSpammer crash bug.
 *
 * Bug: ModSpammer.vue line 5 accesses user.spammer.reason without checking if spammer exists.
 * This causes a crash when:
 * 1. Member is Approved (collection='Approved')
 * 2. Member is also Banned (has users_banned entry)
 * 3. Member has NO associated spammer record (or spammer is false/null)
 *
 * Expected: ModSpammer should safely handle undefined/null spammer data
 * Actual: TypeError - cannot read property 'reason' of undefined/false/null
 */

const { test, expect } = require('./fixtures')
const { timeouts, environment } = require('./config')
const { loginViaModTools } = require('./utils/user')

const MODTOOLS_URL = environment.modtoolsBaseUrl

test.describe('ModTools ModSpammer Crash - Approved+Banned Member Without Spammer Data', () => {
  test('Should handle member with Banned flag but no spammer data without crashing', async ({
    page,
    testEnv,
  }) => {
    // Collect JavaScript errors that would indicate the crash
    const jsErrors = []
    page.on('pageerror', (error) => {
      jsErrors.push({
        message: error.message,
        stack: error.stack,
      })
    })

    // Collect console errors
    const consoleErrors = []
    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        consoleErrors.push(msg.text())
      }
    })

    // Step 1: Login as system admin (required to see members list)
    await loginViaModTools(page, testEnv.systemAdmin.email)

    // Step 2: Navigate to members list
    await page.goto(
      `${MODTOOLS_URL}/members?groupid=${testEnv.group.id}&collection=Approved`,
      {
        timeout: timeouts.navigation.initial,
        waitUntil: 'domcontentloaded',
      }
    )

    // Step 3: Wait for members list to load
    await page.waitForTimeout(1000) // Give page time to render

    // Step 4: Check for the bug - ModSpammer trying to access undefined spammer.reason
    // This should NOT appear in console/errors even for members without spammer data
    const spammerErrorPatterns = [
      /Cannot read propert[^;]*'reason'/i,
      /Cannot read propert[^;]*'reason' of (null|undefined)/i,
      /spammer\.reason/i,
      /spammer is (not an object|null|undefined)/i,
    ]

    for (const pattern of spammerErrorPatterns) {
      for (const error of [...jsErrors, ...consoleErrors]) {
        const errorText =
          typeof error === 'string' ? error : error.message || ''
        expect(
          errorText,
          `Should not have spammer.reason access error matching ${pattern}`
        ).not.toMatch(pattern)
      }
    }

    // Step 5: Verify the page rendered without these specific errors
    const pageHtml = await page.evaluate(() => document.documentElement.outerHTML)
    expect(pageHtml).not.toContain('spammer.reason')
    expect(pageHtml).not.toContain('is not an object')

    // Step 6: Check that no fatal JavaScript errors occurred
    expect(jsErrors).toHaveLength(
      0,
      `JavaScript errors occurred: ${jsErrors.map((e) => e.message).join(', ')}`
    )
  })
})
