// @ts-check
/**
 * Tests for the Settings page functionality
 * Focuses on email level settings and their persistence
 */
const { test, expect } = require('./fixtures')
const { timeouts } = require('./config')
const { signUpViaHomepage, logoutIfLoggedIn } = require('./utils/user')

// Helper function to test email level settings
async function testEmailLevelSetting(page, testEmail, level, takeScreenshot) {
  console.log(`Testing email level: ${level.text}`)

  // Sign up to access settings page
  await page.gotoAndVerify('/')
  const signupResult = await signUpViaHomepage(page, testEmail, 'Test User')
  expect(signupResult).toBeTruthy()

  // Navigate to settings page
  await page.gotoAndVerify('/settings')

  // Get the email level select element
  const emailLevelSelect = page.locator('.email-select')
  await emailLevelSelect.waitFor({
    state: 'visible',
    timeout: timeouts.ui.appearance,
  })

  // Handle edge case where select value default is equal to target value.
  const selectedLevel = await emailLevelSelect.inputValue()
  if (selectedLevel === level.value) {
    // Change to something else first so we can test setting it back
    const savePromise1 = page.waitForResponse(
      (response) =>
        response.url().includes('/api/session') &&
        response.status() === 200 &&
        response.request().method() === 'PATCH'
    )
    await emailLevelSelect.selectOption('None')
    await savePromise1
    console.log('Reset to None first since default matches target')
  }

  // Wait for the PATCH that saves the setting (V2 API uses PATCH for session updates)
  const savePromise = page.waitForResponse(
    (response) =>
      response.url().includes('/api/session') &&
      response.status() === 200 &&
      response.request().method() === 'PATCH'
  )
  await emailLevelSelect.selectOption(level.value)
  await savePromise
  console.log(`Setting saved via PATCH, now verifying with reload`)

  // Reload the page to verify persistence
  await page.reload()

  // Verify the selected value persisted (allow time for component to hydrate)
  await expect(emailLevelSelect).toHaveValue(level.value, {
    timeout: timeouts.ui.appearance,
  })

  console.log(`✓ Email level ${level.text} saved and persisted correctly`)

  // If not 'None', check for advanced settings functionality
  if (level.value !== 'None') {
    // Look for the "Show advanced settings" button
    console.log('Checking advanced settings...')
    const advancedButton = page.locator('text=Show advanced settings')

    // Click to show advanced settings
    await advancedButton.click()

    // Look for email frequency settings in advanced view
    const emailFrequencySection = page.locator(
      'text=Choose OFFER/WANTED frequency:'
    )

    if (
      await emailFrequencySection
        .isVisible({ timeout: 5000 })
        .catch(() => false)
    ) {
      // Get the current email frequency setting
      const frequencySelect = page
        .locator('select')
        .filter({
          hasText: /Immediate|1 hour|2 hours|4 hours|8 hours|Daily/,
        })
        .first()

      if (
        await frequencySelect.isVisible({ timeout: 5000 }).catch(() => false)
      ) {
        const currentFrequency = await frequencySelect.inputValue()
        console.log(
          `Current email frequency in advanced settings: ${currentFrequency}`
        )

        // Verify that the frequency setting is reasonable for the selected email level
        if (level.value === 'Basic') {
          // Basic should typically have longer intervals
          expect(['8', '24']).toContain(currentFrequency)
        } else if (level.value === 'Full') {
          // Full can have any frequency including immediate
          expect(['0', '1', '2', '4', '8', '24']).toContain(currentFrequency)
        }

        console.log(
          `✓ Email frequency matches expected range for ${level.text}`
        )
      }
    }
  }

  await logoutIfLoggedIn(page)
}

test.describe('Settings Page - Email Level Settings', () => {
  test('Email level "Off" saves correctly and persists after page reload', async ({
    page,
    testEmail,
    takeScreenshot,
  }) => {
    const level = { value: 'None', text: 'Off' }
    await testEmailLevelSetting(page, testEmail, level, takeScreenshot)
  })

  test('Email level "Basic" saves correctly and persists after page reload', async ({
    page,
    testEmail,
    takeScreenshot,
  }) => {
    const level = { value: 'Basic', text: 'Basic - limited emails' }
    await testEmailLevelSetting(page, testEmail, level, takeScreenshot)
  })

  test('Email level "Standard" saves correctly and persists after page reload', async ({
    page,
    testEmail,
    takeScreenshot,
  }) => {
    const level = { value: 'Full', text: 'Standard - all types of emails' }
    await testEmailLevelSetting(page, testEmail, level, takeScreenshot)
  })

  test('Advanced email settings toggle works correctly', async ({
    page,
    testEmail,
    takeScreenshot,
  }) => {
    // Sign up and navigate to settings
    await page.gotoAndVerify('/')
    const signupResult = await signUpViaHomepage(page, testEmail, 'Test User')
    expect(signupResult).toBeTruthy()

    await page.gotoAndVerify('/settings')

    // Wait for email settings section
    await page.waitForSelector('text=Email Settings', {
      timeout: timeouts.ui.appearance,
    })

    // Ensure we're not on 'None' setting (advanced settings not available for 'None')
    const emailLevelSelect = page.locator('.email-select')
    await emailLevelSelect.selectOption('Full')

    // Wait for the setting to be saved
    await page.waitForTimeout(timeouts.ui.settleTime)

    // Check if advanced settings button is visible
    const advancedButton = page.locator('text=Show advanced settings')

    if (await advancedButton.isVisible({ timeout: 5000 }).catch(() => false)) {
      console.log('Testing advanced settings toggle...')

      // Initially, advanced settings should be hidden
      const advancedSection = page.locator('text=Email me replies to my posts')
      await advancedSection.waitFor({
        state: 'hidden',
        timeout: timeouts.ui.appearance,
      })

      // Take screenshot before showing advanced settings
      await takeScreenshot('Advanced Settings Before Toggle')

      // Click to show advanced settings
      await advancedButton.click()

      // Advanced settings should now be visible
      await expect(advancedSection).toBeVisible()

      // Take screenshot after showing advanced settings
      await takeScreenshot('Advanced Settings After Toggle')

      console.log('✓ Advanced settings shown successfully')

      // Verify key advanced settings are present
      const expectedAdvancedSettings = [
        'Email me replies to my posts',
        'Copy of my sent messages',
        'ChitChat & notifications',
        "Freegle's messages about your posts",
        'Suggested posts for you',
        'Newsletters & stories',
        'Encouragement emails',
      ]

      for (const settingText of expectedAdvancedSettings) {
        const setting = page.locator(`*:has-text("${settingText}")`).first()
        await setting.waitFor({
          state: 'visible',
          timeout: timeouts.ui.appearance,
        })
        console.log(`✓ Found advanced setting: ${settingText}`)
      }

      console.log('✓ All expected advanced settings are visible')
    } else {
      console.log(
        'Advanced settings button not found - may already be in advanced mode'
      )
    }
  })

  test('Email settings validation and error handling', async ({
    page,
    testEmail,
    takeScreenshot,
  }) => {
    // Sign up and navigate to settings
    await page.gotoAndVerify('/')
    const signupResult = await signUpViaHomepage(page, testEmail, 'Test User')
    expect(signupResult).toBeTruthy()

    await page.gotoAndVerify('/settings')

    // Wait for email settings section
    await page.waitForSelector('text=Email Settings', {
      timeout: timeouts.ui.appearance,
    })

    // Test that appropriate warnings appear for 'None' setting
    const emailLevelSelect = page.locator('.email-select')

    // Take screenshot before setting to 'None'
    await takeScreenshot('Validation Before None Setting')

    await emailLevelSelect.selectOption('None')

    // Wait for the setting to be saved
    await page.waitForTimeout(timeouts.ui.settleTime)

    // Check for warning message about not getting emails
    const warningMessage = page.locator(
      '*:has-text("You won\'t get email notifications")'
    )
    await warningMessage
      .first()
      .waitFor({ state: 'visible', timeout: timeouts.ui.appearance })

    // Take screenshot showing the warning message
    await takeScreenshot('Validation None Setting Warning')

    console.log('✓ Warning message appears for "None" email setting')

    // Switch back to a setting that allows emails
    await emailLevelSelect.selectOption('Full')

    // Wait for the setting to be saved
    await page.waitForTimeout(timeouts.ui.settleTime)

    // Warning should be gone
    await warningMessage.waitFor({
      state: 'hidden',
      timeout: timeouts.ui.appearance,
    })

    // Take screenshot showing warning is gone with 'Full' setting
    await takeScreenshot('Validation Full Setting No Warning')

    console.log('✓ Warning message correctly hidden for "Full" setting')
  })
  /**
   * The "How far away" control, which answers two questions that used to share one setting:
   * how far away a post may be for me to see it, and how far away someone may be and still see
   * my posts. Linked by default; "Set separately" reveals the second slider.
   *
   * The behaviour worth an end-to-end test is the promise the whole design rests on: revealing
   * the second slider must not save anything. A unit test can assert that no save function was
   * called, but only this can assert that no PATCH left the browser.
   */
  test('splitting the distance sliders reveals a second one without saving anything', async ({
    page,
    takeScreenshot,
    testEmail,
  }) => {
    await page.gotoAndVerify('/')
    expect(
      await signUpViaHomepage(page, testEmail, 'Distance Split User')
    ).toBeTruthy()
    await page.gotoAndVerify('/settings')

    const feed = page.locator('.settings-section', { hasText: 'How far away' })
    await feed.waitFor({ state: 'visible', timeout: timeouts.ui.appearance })

    // Linked: one slider, and the copy says the single setting also governs who sees your posts.
    await expect(feed.locator('input[type="range"]')).toHaveCount(1)
    await expect(feed).toContainText('Also limits who sees your posts')
    await expect(feed).toContainText('road distance and travel time')
    await takeScreenshot('Distance sliders linked')

    // Watch for a save that writes the OUTBOUND keys specifically, rather than any PATCH at all:
    // the settings page has other things that can save, and an unrelated one landing in this
    // window would fail the test for the wrong reason. The claim being tested is narrow - that
    // revealing the second slider persists no outbound choice - so the check should be too.
    const outboundSaves = []
    page.on('request', (r) => {
      if (r.url().includes('/api/session') && r.method() === 'PATCH') {
        const body = r.postData() || ''
        if (body.includes('myPostsMax')) outboundSaves.push(body.slice(0, 200))
      }
    })

    await feed.getByRole('button', { name: 'Set separately' }).click()

    // Two sliders now, labelled for the two directions.
    await expect(feed.locator('input[type="range"]')).toHaveCount(2)
    await expect(feed).toContainText('Posts I see')
    await expect(feed).toContainText('Who sees my posts')
    await takeScreenshot('Distance sliders split')

    // The outbound slider reaches further than the inbound one: a post's reach grows to the
    // ripple ceiling whatever band its origin is in, while what this member SEES is held to
    // the distance their own surroundings justify.
    const maxima = await feed
      .locator('input[type="range"]')
      .evaluateAll((els) => els.map((el) => Number(el.max)))
    expect(maxima[1]).toBeGreaterThanOrEqual(maxima[0])

    // The promise: revealing it wrote no outbound choice.
    await page.waitForTimeout(timeouts.ui.settleTime)
    expect(
      outboundSaves,
      'revealing the second slider must not persist an outbound choice'
    ).toEqual([])

    // And it is not sticky, precisely because nothing was saved.
    await page.reload()
    await feed.waitFor({ state: 'visible', timeout: timeouts.ui.appearance })
    await expect(feed.locator('input[type="range"]')).toHaveCount(1)

    console.log(
      '✓ Split reveals a second slider and persists nothing until it is used'
    )
  })
})
