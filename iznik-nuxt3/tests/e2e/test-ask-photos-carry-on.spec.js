/**
 * Experiment ask-photos-carry-on: on the mobile Wanted photo step, arm 'b' shows an outlined
 * "No photo? Carry on" button in place of the small Skip link. Any other arm is control.
 *
 * The arm is forced by serving the live config (GET /config/ux_experiments) ourselves and seeding
 * the copy the page keeps in localStorage, so the arm is there on first mount whatever order the
 * fetch returns in (composables/useExperiment.js).
 */

const { test, expect } = require('./fixtures')
const { timeouts } = require('./config')

const EXPERIMENT = 'ask-photos-carry-on'
const PHOTOS_PATH = '/ask/mobile/photos'
const DETAILS_PATH = '/ask/mobile/details'

/**
 * Serve a live config in which every browser lands in `arms`, and seed the stored copy to match.
 * With no arms the experiment is absent from the config, which is control.
 */
async function forceConfig(page, arm) {
  const config = {}
  if (arm) {
    config[EXPERIMENT] = {
      arms: { [arm]: [0, 99] },
      // The page honours a running entry only if this is in the future and under 25 hours away.
      until: Math.floor(Date.now() / 1000) + 3600,
    }
  }

  await page.addInitScript(
    ({ config, name }) => {
      try {
        // Forget any earlier sticky arm, then store the config as a last good fetch.
        Object.keys(localStorage)
          .filter((k) => k.startsWith('freegle_ux_arm:'))
          .forEach((k) => localStorage.removeItem(k))
        localStorage.setItem(
          'freegle_ux_experiments',
          JSON.stringify({ fetchedAt: Date.now(), config })
        )
      } catch {
        // No storage: the route below still serves the config.
      }
      return name
    },
    { config, name: EXPERIMENT }
  )

  await page.route('**/config/ux_experiments*', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      headers: {
        'access-control-allow-origin': route.request().headers().origin || '*',
        'access-control-allow-credentials': 'true',
      },
      body: JSON.stringify([
        { key: 'ux_experiments', value: JSON.stringify(config) },
      ]),
    })
  )
}

test.describe('Wanted photo step: ask-photos-carry-on', () => {
  test('arm b shows "No photo? Carry on" instead of Skip and continues to details', async ({
    page,
  }) => {
    await forceConfig(page, 'b')
    await page.gotoAndVerify(PHOTOS_PATH, {
      timeout: timeouts.navigation.default,
    })

    const carryOn = page.getByTestId('carry-on-button')
    await expect(carryOn).toBeVisible({ timeout: timeouts.ui.appearance })
    await expect(carryOn).toContainText('No photo? Carry on')
    await expect(page.locator('.skip-link-btn')).toHaveCount(0)

    // If Vue has not hydrated yet the click does nothing, so retry until the navigation lands.
    await expect(async () => {
      if (page.url().includes(DETAILS_PATH)) return
      await carryOn.click()
      await expect(page).toHaveURL(`**${DETAILS_PATH}`, { timeout: 5000 })
    }).toPass({ timeout: timeouts.navigation.default })
  })

  test('control keeps the Skip link and no Carry on button', async ({
    page,
  }) => {
    await forceConfig(page, null)
    await page.gotoAndVerify(PHOTOS_PATH, {
      timeout: timeouts.navigation.default,
    })

    await expect(page.locator('.skip-link-btn')).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    await expect(page.getByTestId('carry-on-button')).toHaveCount(0)
  })
})
