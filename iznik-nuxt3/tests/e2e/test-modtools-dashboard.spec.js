// @ts-check
/**
 * Tests for ModTools dashboard and post-login group visibility.
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

test.describe('ModTools Dashboard', () => {
  test('after login, groups should be visible (not empty)', async ({
    page,
    testEnv,
  }) => {
    // Issue #2/#4: groups list empty after login
    await loginViaModTools(page, testEnv.mod.email)

    await page.goto(`${MODTOOLS_URL}/messages/pending`, {
      timeout: timeouts.navigation.initial,
    })

    await dismissAllModals(page)

    const groupSelect = page.locator('#communitieslist')
    await expect(groupSelect).toBeVisible({
      timeout: timeouts.navigation.slowPage,
    })

    // The group dropdown should have at least one group option (value !== '0').
    // Dismiss modals on each poll iteration as they can appear after initial page load.
    await expect
      .poll(
        async () => {
          await dismissAllModals(page)
          const options = await groupSelect.locator('option').all()
          let groupCount = 0
          for (const option of options) {
            const value = await option.getAttribute('value')
            if (value && value !== '0') {
              groupCount++
            }
          }
          return groupCount
        },
        {
          message: 'Expected at least one group in the dropdown',
          timeout: timeouts.navigation.slowPage,
        }
      )
      .toBeGreaterThan(0)
  })

  test('dashboard loads without errors', async ({ page, testEnv }) => {
    await loginViaModTools(page, testEnv.mod.email)

    // Collect page errors
    const errors = []
    page.on('pageerror', (error) => {
      errors.push(error.message)
    })

    await page.goto(`${MODTOOLS_URL}/modtools/dashboard`, {
      timeout: timeouts.navigation.initial,
    })

    // Wait for the page to have meaningful content
    await page.waitForLoadState('domcontentloaded', {
      timeout: timeouts.navigation.default,
    })

    await dismissAllModals(page)
    await assertNoErrors(page)
    expect(errors).toHaveLength(0)
  })

  // Every other ModTools test logs in and leaves / at once, so the home page's
  // async work (eight config fetches, then the dashboard, and ModYesterday's
  // backup check) finished on some CI runs and not others and its coverage
  // moved with no code change. This test waits for that work and ends on /.
  test('home dashboard renders and the test ends on /', async ({
    page,
    testEnv,
  }) => {
    // ModYesterday asks the live Yesterday host, which is not part of the CI
    // stack. Answer for it so the wait below never depends on that host.
    // Registered before login because the home page mounts during login.
    await page.route(
      'https://yesterday.ilovefreegle.org:8444/api/**',
      (route) =>
        route.fulfill({
          status: 200,
          contentType: 'application/json',
          headers: { 'access-control-allow-origin': '*' },
          body: JSON.stringify(
            route.request().url().endsWith('/restore-status')
              ? { inProgress: false, status: 'completed' }
              : {
                  // Today, so the backup is not reported as over two days old.
                  date: new Date().toISOString().slice(0, 10).replace(/-/g, ''),
                  backup_time: '03:00',
                }
          ),
        })
    )

    // testEnv.mod is seeded as Admin, which ModYesterday needs.
    await loginViaModTools(page, testEnv.mod.email)

    // The heading sits under v-if="start && end", set only after onMounted's
    // config fetches finish and update() runs.
    await expect(
      page.getByRole('heading', { name: /Active Freeglers/ })
    ).toBeVisible({ timeout: timeouts.navigation.slowPage })

    // ModYesterday shows its alert only once both of its fetches have settled.
    const yesterday = page.locator('.alert', {
      hasText: 'System backed up on Yesterday up to:',
    })
    await expect(yesterday).toBeVisible({ timeout: timeouts.ui.appearance })
    await expect(yesterday).toContainText(/\d{1,2} \w{3} \d{4}/)

    // No navigation: coverage is read from this page when the test ends.
    expect(new URL(page.url()).pathname).toBe('/')
  })
})
