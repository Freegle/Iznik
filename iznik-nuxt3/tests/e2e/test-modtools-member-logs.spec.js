// @ts-check
/**
 * Tests for ModTools member logs display and behavior.
 */

const { test, expect } = require('./fixtures')
const { timeouts, environment } = require('./config')
const { loginViaModTools } = require('./utils/user')

const MODTOOLS_URL = environment.modtoolsBaseUrl

// Helper: dismiss any overlay modals  that block interaction.
async function dismissAllModals(page) {
  await page.evaluate(() => {
    document
      .querySelectorAll('.modal.show, .modal[style*="display: block"]')
      .forEach((el) => {
        el.classList.remove('show')
        el.style.display = 'none'
      })
    document.querySelectorAll('.modal-backdrop').forEach((el) => el.remove())
    document.body.classList.remove('modal-open')
    document.body.style.removeProperty('overflow')
    document.body.style.removeProperty('padding-right')
  })
}

test.describe('ModTools Member Logs', () => {
  test('member logs do not loop infinitely and stop loading', async ({
    page,
    testEnv,
  }) => {
    // Issue #28: member logs loop infinitely, never stop loading
    await loginViaModTools(page, testEnv.mod.email)

    const errors = []
    page.on('pageerror', (error) => {
      errors.push(error.message)
    })

    // Navigate to approved members (always has data, unlike pending)
    await page.goto(`${MODTOOLS_URL}/members/approved`, {
      timeout: timeouts.navigation.initial,
    })

    const groupSelect = page.locator('#communitieslist')
    await expect(groupSelect).toBeVisible({
      timeout: timeouts.navigation.slowPage,
    })

    await dismissAllModals(page)

    // Select the first available group
    let targetGroupValue = null
    await expect
      .poll(
        async () => {
          const options = await groupSelect.locator('option').all()
          for (const option of options) {
            const value = await option.getAttribute('value')
            if (value && value !== '0' && !value.includes('Please')) {
              targetGroupValue = value
              return true
            }
          }
          return false
        },
        {
          message: 'Waiting for group options',
          timeout: timeouts.navigation.slowPage,
        }
      )
      .toBe(true)
    await groupSelect.selectOption(targetGroupValue)

    // Wait for member cards to load
    const memberCards = page.locator('.card, .list-group-item')
    await expect(memberCards.first()).toBeVisible({
      timeout: timeouts.navigation.slowPage,
    })

    await dismissAllModals(page)

    // A member card's own "View logs" button (ModMember.vue). The selector used to
    // include a:has-text("Logs"), which matched the sidebar's Logs link first, so
    // these tests clicked through to the Logs page and never opened a member's logs.
    const logsButton = page.getByRole('button', { name: 'View logs' })

    // The approved members of the test community always include someone, so the
    // logs control must be there. It used to be looked for with isVisible() inside
    // an if, which let the whole loop check pass without running when it was not.
    await expect(logsButton.first()).toBeVisible({
      timeout: timeouts.ui.appearance,
    })

    // Count fetches of the logs API only (GET /modtools/logs, which ModLogsModal's
    // infinite scroll calls once per chunk). Matching any URL with "log" in it also
    // counted the page's own CSS chunk and the app's POST /clientlog reports.
    // Listen before the click, so the modal's first fetches are counted too.
    let apiCallCount = 0
    let lastLogRequestAt = 0
    page.on('request', (request) => {
      if (request.url().includes('/modtools/logs')) {
        apiCallCount++
        lastLogRequestAt = Date.now()
      }
    })

    const firstLogsFetch = page.waitForRequest(
      (request) => request.url().includes('/modtools/logs'),
      { timeout: timeouts.ui.appearance }
    )
    const clickedAt = Date.now()
    await logsButton.first().click()

    // Opening the modal must fetch the logs; a modal that never asks has not
    // been tested for looping at all.
    await firstLogsFetch

    // A looping modal keeps fetching; a healthy one fetches its chunk or two and
    // goes quiet. Watch for at least fifteen seconds from the click, and until no
    // logs fetch has been seen for five seconds, or stop early once the count has
    // blown past the limit (the loop, caught). Fifteen seconds still sees a loop
    // that fetches every few seconds, where the old fixed wait took 67.
    const MIN_WATCH_MS = 15000
    const QUIET_MS = 5000
    await expect
      .poll(
        () =>
          apiCallCount >= 20 ||
          (Date.now() - clickedAt >= MIN_WATCH_MS &&
            Date.now() - lastLogRequestAt >= QUIET_MS),
        { timeout: timeouts.ui.appearance, intervals: [250] }
      )
      .toBe(true)

    // If loading infinitely, apiCallCount would be very high (>20).
    // A reasonable load should make fewer than 20 log API calls.
    expect(apiCallCount).toBeGreaterThan(0)
    expect(apiCallCount).toBeLessThan(20)

    expect(errors).toHaveLength(0)
  })

  test('member logs show subject lines for messages', async ({
    page,
    testEnv,
  }) => {
    // Issue #15: member logs missing subject lines
    await loginViaModTools(page, testEnv.mod.email)

    // Navigate to approved members (always has data)
    await page.goto(`${MODTOOLS_URL}/members/approved`, {
      timeout: timeouts.navigation.initial,
    })

    const groupSelect = page.locator('#communitieslist')
    await expect(groupSelect).toBeVisible({
      timeout: timeouts.navigation.slowPage,
    })

    await dismissAllModals(page)

    // Select the first available group
    let targetGroupValue = null
    await expect
      .poll(
        async () => {
          const options = await groupSelect.locator('option').all()
          for (const option of options) {
            const value = await option.getAttribute('value')
            if (value && value !== '0' && !value.includes('Please')) {
              targetGroupValue = value
              return true
            }
          }
          return false
        },
        {
          message: 'Waiting for group options',
          timeout: timeouts.navigation.slowPage,
        }
      )
      .toBe(true)
    await groupSelect.selectOption(targetGroupValue)

    // Wait for member cards to load
    const memberCards = page.locator('.card, .list-group-item')
    await expect(memberCards.first()).toBeVisible({
      timeout: timeouts.navigation.slowPage,
    })

    await dismissAllModals(page)

    // A member card's own "View logs" button (ModMember.vue). The selector used to
    // include a:has-text("Logs"), which matched the sidebar's Logs link first, so
    // these tests clicked through to the Logs page and never opened a member's logs.
    const logsButton = page.getByRole('button', { name: 'View logs' })

    await expect(logsButton.first()).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    const logsFetched = page.waitForResponse(
      (response) => response.url().includes('/modtools/logs'),
      { timeout: timeouts.ui.appearance }
    )
    await logsButton.first().click()
    await logsFetched

    // The member's logs modal must be open, and its entries must not show a
    // missing subject where a message is referenced.
    const logsModal = page.locator('.modal.show')
    await expect(logsModal).toBeVisible({ timeout: timeouts.ui.appearance })
    const logText = await logsModal.textContent()
    expect(logText).not.toContain('Subject: undefined')
    expect(logText).not.toContain('subject undefined')
  })
})
