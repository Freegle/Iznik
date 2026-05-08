// @ts-check
/**
 * Tests for ModTools Settings > Standard Messages (ModConfig) tab.
 * Verifies that modconfig settings persist after page reload.
 */
const { test, expect } = require('./fixtures')
const { timeouts, environment } = require('./config')
const { loginViaModTools } = require('./utils/user')

const MODTOOLS_URL = environment.modtoolsBaseUrl

async function navigateToModConfigTab(page) {
  await page.goto(`${MODTOOLS_URL}/settings`, {
    timeout: timeouts.navigation.initial,
  })
  const stdMsgTab = page.locator('h2:has-text("Standard Messages")')
  await stdMsgTab.waitFor({ state: 'visible', timeout: timeouts.ui.appearance })
  await stdMsgTab.click()
}

async function selectFirstConfig(page) {
  const configSelect = page.locator('.scrollinplace select').first()
  await configSelect.waitFor({ state: 'visible', timeout: timeouts.ui.appearance })
  const options = await configSelect.locator('option').all()
  for (const opt of options) {
    const val = await opt.getAttribute('value')
    if (val && val !== '' && val !== 'null' && parseInt(val) > 0) {
      await configSelect.selectOption(val)
      return val
    }
  }
  return null
}

async function saveConfigName(page, name) {
  const nameField = page
    .locator('.scrollinplace input[type="text"]')
    .first()
  await nameField.waitFor({ state: 'visible', timeout: timeouts.ui.appearance })
  await nameField.clear()
  await nameField.fill(name)

  const saveBtn = page
    .locator('.scrollinplace button')
    .filter({ hasText: /save/i })
    .first()

  const patchPromise = page.waitForResponse(
    (r) =>
      r.url().includes('/api/modtools/modconfig') &&
      r.request().method() === 'PATCH' &&
      r.status() === 200
  )
  await saveBtn.click()
  await patchPromise
}

test.describe('ModTools Settings - ModConfig persistence', () => {
  test('modconfig name change persists after reload', async ({
    page,
    testEnv,
  }) => {
    await loginViaModTools(page, testEnv.mod.email)
    await navigateToModConfigTab(page)

    const configId = await selectFirstConfig(page)
    if (!configId) {
      console.log('No modconfig available for test mod — skipping')
      return
    }

    // Read current name so we can restore it after the test
    const nameField = page.locator('.scrollinplace input[type="text"]').first()
    await nameField.waitFor({ state: 'visible', timeout: timeouts.ui.appearance })
    const originalName = await nameField.inputValue()

    const testName = `TestConfig_${Date.now()}`
    await saveConfigName(page, testName)

    // Reload and navigate back to verify persistence
    await page.reload({ timeout: timeouts.navigation.default })
    await navigateToModConfigTab(page)
    await selectFirstConfig(page)

    const nameFieldAfter = page
      .locator('.scrollinplace input[type="text"]')
      .first()
    await nameFieldAfter.waitFor({
      state: 'visible',
      timeout: timeouts.ui.appearance,
    })
    await expect(nameFieldAfter).toHaveValue(testName, {
      timeout: timeouts.ui.appearance,
    })

    // Restore original name
    await saveConfigName(page, originalName)
  })
})
