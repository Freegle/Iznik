// @ts-check
/**
 * Tests for ModTools Support Tools functionality.
 */

const { test, expect } = require('./fixtures')
const { timeouts, environment } = require('./config')
const { loginViaModTools } = require('./utils/user')

const MODTOOLS_URL = environment.modtoolsBaseUrl

// Helper: dismiss any overlay modals that block interaction.
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

// Helper: check page for common error indicators.
async function assertNoErrors(page) {
  const body = await page.textContent('body')
  expect(body).not.toContain('something went wrong')
  expect(body).not.toContain('Oh dear')
  expect(body).not.toContain('undefined is not an object')
  expect(body).not.toContain('Cannot read properties of undefined')
}

test.describe('ModTools Support Tools', () => {
  test('Support Tools user search does not crash', async ({
    page,
    testEnv,
  }) => {
    // Issue #8: Support Tools crashes on search
    await loginViaModTools(page, testEnv.mod.email)

    const errors = []
    page.on('pageerror', (error) => {
      errors.push(error.message)
    })

    await page.goto(`${MODTOOLS_URL}/support`, {
      timeout: timeouts.navigation.initial,
    })

    await page.waitForLoadState('domcontentloaded', {
      timeout: timeouts.navigation.default,
    })

    await dismissAllModals(page)

    // Look for a user search input and type a test query
    const searchInput = page.locator(
      'input[placeholder*="search" i], input[placeholder*="user" i], input[placeholder*="email" i], input[type="search"]'
    )

    if (
      await searchInput
        .first()
        .isVisible({ timeout: timeouts.ui.appearance })
        .catch(() => false)
    ) {
      await searchInput.first().fill(testEnv.user.email)
      // Wait a moment for search to execute
      await page.waitForTimeout(timeouts.ui.settleTime)
      await assertNoErrors(page)
    }

    expect(errors).toHaveLength(0)
  })

  test('Support Tools community search returns results', async ({
    page,
    testEnv,
  }) => {
    // Issue #8: Support Tools community search broken
    await loginViaModTools(page, testEnv.mod.email)

    const errors = []
    page.on('pageerror', (error) => {
      errors.push(error.message)
    })

    await page.goto(`${MODTOOLS_URL}/support`, {
      timeout: timeouts.navigation.initial,
    })

    await page.waitForLoadState('domcontentloaded', {
      timeout: timeouts.navigation.default,
    })

    await dismissAllModals(page)

    // Look for a community/group search input
    const searchInputs = page.locator(
      'input[placeholder*="community" i], input[placeholder*="group" i]'
    )

    if (
      await searchInputs
        .first()
        .isVisible({ timeout: timeouts.ui.appearance })
        .catch(() => false)
    ) {
      await searchInputs.first().fill('Freegle')
      await page.waitForTimeout(timeouts.ui.settleTime)

      // Should see some search results (autocomplete suggestions or result list)
      const results = page.locator(
        '.autocomplete-result, .dropdown-item, .list-group-item, [class*="suggestion"]'
      )
      await expect(results.first()).toBeVisible({
        timeout: timeouts.ui.autocomplete,
      })
    }

    expect(errors).toHaveLength(0)
  })

  test('Support Tools user record shows memberships after search', async ({
    page,
    testEnv,
  }) => {
    // Discourse #181/#192/#197: User record showing no memberships/data
    await loginViaModTools(page, testEnv.mod.email)

    await page.goto(`${MODTOOLS_URL}/support`, {
      timeout: timeouts.navigation.initial,
    })

    await page.waitForLoadState('domcontentloaded', {
      timeout: timeouts.navigation.default,
    })

    await dismissAllModals(page)

    // Search for the test user
    const searchInput = page.locator(
      'input[placeholder*="search" i], input[placeholder*="user" i], input[placeholder*="email" i], input[type="search"], input[type="text"]'
    )

    await searchInput.first().fill(testEnv.user.email)

    // Expanding the record fetches the support extras, each from its own
    // endpoint. Listen before clicking so none is missed.
    const extras = [
      'chatrooms',
      'emailhistory',
      'bans',
      'newsfeed',
      'applied',
      'membershiphistory',
      'logins',
    ]
    const extraResponses = extras.map((name) =>
      page.waitForResponse(
        (r) =>
          new RegExp(`/user/\\d+/${name}(\\?|$)`).test(r.url()) &&
          r.request().method() === 'GET',
        { timeout: timeouts.navigation.slowPage }
      )
    )

    // Click Find user
    const findButton = page.locator('button:has-text("Find user")')
    await findButton.click()

    // Wait for the Memberships heading to be visible (indicates user data loaded)
    const membershipsHeader = page.locator(
      'h3:has-text("Memberships"), h4:has-text("Memberships")'
    )
    await expect(membershipsHeader.first()).toBeVisible({
      timeout: timeouts.navigation.slowPage,
    })

    // The test user was created as a Member of the test group.
    // The membership card heading shows the role icon + group name.
    const membershipCard = page.locator(`h4:has-text("${testEnv.group.name}")`)
    await expect(membershipCard.first()).toBeVisible({
      timeout: timeouts.navigation.slowPage,
    })

    // Every extra must load. Without this the test ended as soon as the
    // memberships showed, and whether the chats, logins and history had
    // arrived (and so were exercised at all) depended on timing.
    for (const [i, response] of (await Promise.all(extraResponses)).entries()) {
      expect(response.status(), `GET /user/:id/${extras[i]}`).toBe(200)
    }
    // Let Vue render what arrived before the test ends.
    await page.evaluate(
      () => new Promise((resolve) => requestAnimationFrame(() => resolve()))
    )

    await assertNoErrors(page)
  })

  test('AI Support Helper starts the member snapshot when a member is picked', async ({
    page,
    testEnv,
  }) => {
    await loginViaModTools(page, testEnv.mod.email)

    // The helper is not part of the test stack, so its two calls are answered
    // here. The test is about what ModTools asks it for.
    const prefetched = []
    await page.route('**/api/prefetch-dump', async (route) => {
      prefetched.push(JSON.parse(route.request().postData() || '{}'))
      await route.fulfill({
        status: 202,
        contentType: 'application/json',
        body: JSON.stringify({ started: true }),
      })
    })
    await page.route('**/api/device-summary**', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ devices: [] }),
      })
    )

    await page.goto(`${MODTOOLS_URL}/support`, {
      timeout: timeouts.navigation.initial,
    })
    await page.waitForLoadState('domcontentloaded', {
      timeout: timeouts.navigation.default,
    })
    await dismissAllModals(page)

    await page.getByRole('tab', { name: 'AI Support Helper' }).click()
    const search = page.getByPlaceholder('Search by email, name, or user ID')
    await search.waitFor({ state: 'visible', timeout: timeouts.ui.appearance })
    await search.fill(testEnv.user.email)
    await search.press('Enter')

    // One match is picked straight away; several are listed to choose from.
    const results = page.locator('.user-result-item')
    await expect
      .poll(async () => prefetched.length + (await results.count()), {
        timeout: timeouts.api.default,
      })
      .toBeGreaterThan(0)
    if (prefetched.length === 0) {
      await results.filter({ hasText: testEnv.user.email }).first().click()
    }

    await expect
      .poll(() => prefetched.length, { timeout: timeouts.api.default })
      .toBe(1)
    expect(Number(prefetched[0].userId)).toBeGreaterThan(0)
    if (testEnv.user.id) {
      expect(Number(prefetched[0].userId)).toBe(Number(testEnv.user.id))
    }
  })
})
