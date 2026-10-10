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

  test('dashboard loads without errors', async ({ page, testEnv }) => {
    await loginViaModTools(page, testEnv.mod.email)

    // Collect page errors
    const errors = []
    page.on('pageerror', (error) => {
      errors.push(error.message)
    })

    await page.goto(`${MODTOOLS_URL}/`, {
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
})
