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
  // Target the BVN tab button directly (role="tab") rather than the h2 inside it.
  const tabBtn = page.locator('[role="tab"]:has-text("Standard Messages")')
  await expect(tabBtn).toBeVisible({ timeout: timeouts.ui.appearance })
  await tabBtn.click()
  // Wait for the active tab pane's content to become visible.
  await expect(page.locator('.tab-pane.active .scrollinplace')).toBeVisible({
    timeout: timeouts.ui.appearance,
  })
}

async function selectFirstConfig(page) {
  const configSelect = page.locator('.tab-pane.active .scrollinplace select').first()
  // Wait for the select to be in the DOM (it may be hidden by BVN; we use force).
  await configSelect.waitFor({ state: 'attached', timeout: timeouts.ui.appearance })
  const options = await configSelect.locator('option').all()
  for (const opt of options) {
    const val = await opt.getAttribute('value')
    if (val && val !== '' && val !== 'null' && parseInt(val) > 0) {
      await configSelect.selectOption(val, { force: true })
      return val
    }
  }
  return null
}

async function expandGeneralSettings(page) {
  // The "General Settings" accordion is closed by default.  Open it if not visible.
  // b-button with href="#" renders as <a>, not <button>, in Bootstrap Vue Next.
  const accordionContent = page.locator('#accordion-general')
  const alreadyOpen = await accordionContent.isVisible().catch(() => false)
  if (!alreadyOpen) {
    // Use .btn selector to match both <button> and <a class="btn"> renderings.
    const toggleBtn = page.locator('.tab-pane.active .btn:has-text("General Settings")')
    await toggleBtn.waitFor({ state: 'visible', timeout: timeouts.ui.appearance })
    await toggleBtn.click()
    await accordionContent.waitFor({ state: 'visible', timeout: timeouts.ui.appearance })
  }
}

async function saveConfigName(page, name) {
  await expandGeneralSettings(page)

  const nameField = page.locator('#accordion-general input[type="text"]').first()
  await nameField.waitFor({ state: 'visible', timeout: timeouts.ui.appearance })
  await nameField.clear()
  await nameField.fill(name)

  const saveBtn = page
    .locator('#accordion-general button')
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

    await expandGeneralSettings(page)

    // Read current name so we can restore it after the test
    const nameField = page.locator('#accordion-general input[type="text"]').first()
    await nameField.waitFor({ state: 'visible', timeout: timeouts.ui.appearance })
    const originalName = await nameField.inputValue()

    const testName = `TestConfig_${Date.now()}`
    await saveConfigName(page, testName)

    // Reload and navigate back to verify persistence
    await page.reload({ timeout: timeouts.navigation.default })
    await navigateToModConfigTab(page)
    await selectFirstConfig(page)
    await expandGeneralSettings(page)

    const nameFieldAfter = page.locator('#accordion-general input[type="text"]').first()
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
