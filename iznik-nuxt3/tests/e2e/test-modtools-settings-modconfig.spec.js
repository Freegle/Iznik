// @ts-check
/**
 * Tests for ModTools Settings > Standard Messages (ModConfig) tab.
 * Verifies that modconfig settings persist after page reload.
 */
const { test, expect } = require('./fixtures')
const { timeouts, environment } = require('./config')
const { loginViaModTools } = require('./utils/user')

const MODTOOLS_URL = environment.modtoolsBaseUrl
const API_V2 = environment.apiV2BaseUrl

// The test mod has no config in the seeded data, so make one. Without this the
// test found nothing to edit and passed without testing anything. Returns its id;
// the mod is an Admin and sees every config, so the test works on this one only.
async function createConfig(page, email) {
  const loginResp = await page.request.post(`${API_V2}/session`, {
    data: { email, password: 'freegle' },
  })
  const { jwt } = await loginResp.json()
  expect(jwt).toBeTruthy()

  const resp = await page.request.post(`${API_V2}/modtools/modconfig`, {
    data: { name: `PW Settings ${Date.now()}` },
    headers: { Authorization: jwt },
  })
  expect(resp.ok()).toBeTruthy()
  const { id } = await resp.json()
  expect(id).toBeTruthy()
  return String(id)
}

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

async function selectConfig(page, id) {
  const configSelect = page
    .locator('.tab-pane.active .scrollinplace select')
    .first()
  // Wait for the select to be in the DOM (it may be hidden by BVN; we use force).
  await configSelect.waitFor({
    state: 'attached',
    timeout: timeouts.ui.appearance,
  })
  await expect(configSelect.locator(`option[value="${id}"]`)).toHaveCount(1, {
    timeout: timeouts.ui.appearance,
  })
  // After a reload ModTools selects the config chosen last time, and choosing it
  // again fetches nothing.
  if ((await configSelect.inputValue()) === id) {
    return
  }
  const loaded = page.waitForResponse(
    (r) =>
      r.url().includes('/modtools/modconfig') &&
      r.url().includes(`id=${id}`) &&
      r.request().method() === 'GET'
  )
  await configSelect.selectOption(id, { force: true })
  await loaded
}

async function expandGeneralSettings(page) {
  // The "General Settings" accordion is closed by default.  Open it if not visible.
  const accordionContent = page.locator('#accordion-general')
  const alreadyOpen = await accordionContent.isVisible().catch(() => false)
  if (!alreadyOpen) {
    // The toggle is a button, not a link: a link to "#" clicked before the toggle
    // was ready took the page to the dashboard.
    const toggleBtn = page.locator(
      '.tab-pane.active .btn:has-text("General Settings")'
    )
    await toggleBtn.waitFor({
      state: 'visible',
      timeout: timeouts.ui.appearance,
    })
    await toggleBtn.click()
    await accordionContent.waitFor({
      state: 'visible',
      timeout: timeouts.ui.appearance,
    })
  }
}

async function saveConfigName(page, name) {
  await expandGeneralSettings(page)

  const nameField = page
    .locator('#accordion-general input[type="text"]')
    .first()
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
    const configId = await createConfig(page, testEnv.mod.email)
    await loginViaModTools(page, testEnv.mod.email)
    await navigateToModConfigTab(page)
    await selectConfig(page, configId)

    await expandGeneralSettings(page)

    // Read current name so we can restore it after the test
    const nameField = page
      .locator('#accordion-general input[type="text"]')
      .first()
    await nameField.waitFor({
      state: 'visible',
      timeout: timeouts.ui.appearance,
    })
    const originalName = await nameField.inputValue()

    const testName = `TestConfig_${Date.now()}`
    await saveConfigName(page, testName)

    // Reload and navigate back to verify persistence
    await page.reload({ timeout: timeouts.navigation.default })
    await navigateToModConfigTab(page)
    await selectConfig(page, configId)
    await expandGeneralSettings(page)

    const nameFieldAfter = page
      .locator('#accordion-general input[type="text"]')
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
