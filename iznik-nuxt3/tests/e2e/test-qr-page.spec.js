/**
 * Tests for the QR code generator page (pages/qr.vue), which had no e2e
 * coverage at all. The page has three mutually-exclusive branches gated on
 * auth/mod state:
 *   - not logged in: a "log in to continue" prompt
 *   - logged in, not a mod: a "volunteers only" warning
 *   - logged in as a mod: the actual QR generator (qr-code-styling), which
 *     reacts to URL input changes and supports a PNG download
 * All three are exercised here, plus the generator's empty-URL and
 * URL-change branches.
 */

const { test, expect } = require('./fixtures')
const { timeouts } = require('./config')
const { loginViaHomepage } = require('./utils/user')

test.describe('QR code generator page (/qr)', () => {
  test('not logged in: shows a login prompt, and clicking it opens the login modal', async ({
    page,
  }) => {
    await page.gotoAndVerify('/qr')

    const page_ = page.locator('.qr-page')
    await expect(
      page_.getByText(
        'This page is only available to Freegle volunteers. Please log in to continue.'
      )
    ).toBeVisible({ timeout: timeouts.ui.appearance })

    // Neither of the other two branches' content is present.
    await expect(page_.locator('#qr-url')).toHaveCount(0)
    await expect(
      page_.getByText(
        'Sorry, this page is only available to Freegle volunteers.'
      )
    ).toHaveCount(0)

    await page_.locator('button:has-text("Log in to continue")').click()

    await expect(page.locator('#loginModal')).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    console.log('Logged-out /qr correctly prompted login and opened the modal')
  })

  test('logged in as a regular (non-mod) user: shows the volunteers-only message, not the generator', async ({
    page,
    testEnv,
  }) => {
    await loginViaHomepage(page, testEnv.user.email, 'freegle')

    await page.gotoAndVerify('/qr')

    const page_ = page.locator('.qr-page')
    await expect(
      page_.getByText(
        'Sorry, this page is only available to Freegle volunteers.'
      )
    ).toBeVisible({ timeout: timeouts.ui.appearance })

    // Neither the generator nor the logged-out prompt is present.
    await expect(page_.locator('#qr-url')).toHaveCount(0)
    await expect(
      page_.locator('button:has-text("Log in to continue")')
    ).toHaveCount(0)
    console.log(
      'Logged-in non-mod /qr correctly showed the volunteers-only message'
    )
  })

  test('logged in as a mod: generates a QR code, reacts to URL changes, and supports download', async ({
    page,
    testEnv,
  }) => {
    await loginViaHomepage(page, testEnv.mod.email, 'freegle')

    await page.gotoAndVerify('/qr')

    const urlInput = page.locator('#qr-url')
    await expect(urlInput).toBeVisible({ timeout: timeouts.ui.appearance })
    await expect(urlInput).toHaveValue('https://www.ilovefreegle.org')

    // The default URL renders a code on mount (hasCode branch).
    const qrCanvas = page.locator('.qr-target canvas')
    await expect(qrCanvas).toBeVisible({ timeout: timeouts.assertion.slow })

    const downloadBtn = page.locator('button:has-text("Download PNG")')
    await expect(downloadBtn).toBeEnabled()
    console.log('Mod /qr correctly generated a code for the default URL')

    // Clearing the URL removes the code and shows the empty-state prompt
    // (the !url / hasCode=false branches).
    await urlInput.fill('')
    await expect(
      page.getByText('Enter a link above to see your QR code.')
    ).toBeVisible({ timeout: timeouts.ui.appearance })
    await expect(downloadBtn).toBeDisabled()
    console.log('Clearing the URL correctly hid the code and disabled download')

    // Entering a new URL re-renders the code (the url watcher branch).
    await urlInput.fill('https://www.ilovefreegle.org/give')
    await expect(qrCanvas).toBeVisible({ timeout: timeouts.assertion.slow })
    await expect(downloadBtn).toBeEnabled()
    console.log('Changing the URL correctly re-rendered the code')

    // Download produces a PNG (the download() branch).
    const downloadPromise = page.waitForEvent('download')
    await downloadBtn.click()
    const download = await downloadPromise
    expect(download.suggestedFilename()).toContain('freegle-qr')
    console.log(
      `Download button correctly produced a file: ${download.suggestedFilename()}`
    )
  })
})
