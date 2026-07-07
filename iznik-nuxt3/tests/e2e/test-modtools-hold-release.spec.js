// @ts-check
/**
 * Test that the Hold and Release buttons work on the ModTools pending page.
 *
 * Uses testEnv fixture for isolated test group and moderator.
 */

const { test, expect } = require('./fixtures')
const { timeouts, environment } = require('./config')
const { loginViaModTools } = require('./utils/user')

const MODTOOLS_URL = environment.modtoolsBaseUrl

test.describe('ModTools hold and release message', () => {
  test('holding a pending message should show Release button, releasing should restore Hold button', async ({
    page,
    testEnv,
  }) => {
    // Step 1: Log in via API and inject auth tokens
    await loginViaModTools(page, testEnv.mod.email)

    // Step 2: Navigate to pending messages
    await page.goto(`${MODTOOLS_URL}/messages/pending`, {
      timeout: timeouts.navigation.initial,
    })

    // Wait for group select to appear (indicates page is loaded and authenticated)
    const groupSelect = page.locator('#communitieslist')
    await expect(groupSelect).toBeVisible({
      timeout: timeouts.navigation.slowPage,
    })

    // Dismiss any modals that overlay the page after load.
    // Use JS to forcefully remove all modals and backdrops, including those
    // rendered via Vue teleport into #teleports.
    async function dismissAllModals() {
      await page.evaluate(() => {
        // Remove all visible modals
        document
          .querySelectorAll('.modal.show, .modal[style*="display: block"]')
          .forEach((el) => {
            el.classList.remove('show')
            el.style.display = 'none'
          })
        // Remove all backdrops (including those in #teleports)
        document
          .querySelectorAll('.modal-backdrop')
          .forEach((el) => el.remove())
        // Reset body state
        document.body.classList.remove('modal-open')
        document.body.style.removeProperty('overflow')
        document.body.style.removeProperty('padding-right')
      })
    }
    await dismissAllModals()
    await page.waitForTimeout(500)
    // Dismiss again in case Vue re-rendered a modal/backdrop
    await dismissAllModals()

    // Wait for our test group's option to be loaded in the select, then pick it.
    // The testEnv fixture guarantees this group has pending messages.
    // Polling for any group with work counts is fragile: counts load
    // asynchronously and can exceed the 202s timeout under parallel load.
    await expect(
      groupSelect.locator(`option[value="${testEnv.group.id}"]`)
    ).toBeAttached({ timeout: timeouts.navigation.slowPage })
    await groupSelect.selectOption(String(testEnv.group.id))

    // Wait for message cards to load
    const messageCards = page.locator('.card')
    await expect(messageCards.first()).toBeVisible({
      timeout: timeouts.navigation.slowPage,
    })

    // Listen for errors
    const errors = []
    page.on('pageerror', (error) => {
      errors.push(error.message)
    })

    // Step 3: Scope Hold/Release to the first message card
    const firstCard = messageCards.first()

    // Ensure clean state: if message was left held by a previous run, release it.
    // A held message shows 2 "Release" buttons (one warning notice, one action button),
    // so use count() to avoid strict-mode errors when both are present.
    const existingReleaseCount = await firstCard
      .locator('button:has-text("Release")')
      .count()
      .catch(() => 0)
    if (existingReleaseCount > 0) {
      await firstCard.locator('button:has-text("Release")').first().click()
      // Wait for Hold button to reappear after release
      await firstCard
        .locator('button:has-text("Hold")')
        .waitFor({ state: 'visible', timeout: timeouts.ui.appearance })
    }

    // Find and click the Hold button within the first card
    const holdButton = firstCard.locator('button:has-text("Hold")')
    await expect(holdButton).toBeVisible({
      timeout: timeouts.ui.appearance,
    })

    // Dismiss any late-appearing modals before clicking
    await dismissAllModals()
    await holdButton.click()

    // Handle confirmation dialog if it appears
    const confirmButton = page.locator(
      '.modal button:has-text("Confirm"), dialog button:has-text("Confirm")'
    )
    try {
      await confirmButton.first().waitFor({ state: 'visible', timeout: 5000 })
      await confirmButton.first().click()
    } catch {
      // No confirmation needed
    }

    // Step 4: After holding, the Release button should appear within the first card.
    // A held message shows 2 "Release" buttons (warning notice + action button), so
    // use .first() to pick one deterministically and avoid strict-mode violations.
    const releaseButton = firstCard
      .locator('button:has-text("Release")')
      .first()
    await expect(releaseButton).toBeVisible({
      timeout: timeouts.ui.appearance,
    })

    // The Hold button within the first card should no longer be visible
    await expect(holdButton).not.toBeVisible({
      timeout: timeouts.ui.appearance,
    })

    // Step 5: Click Release to restore the message
    await releaseButton.click()

    // Handle confirmation dialog if it appears
    try {
      await confirmButton.first().waitFor({ state: 'visible', timeout: 5000 })
      await confirmButton.first().click()
    } catch {
      // No confirmation needed
    }

    // Step 6: After releasing, the Hold button should reappear within the first card
    await expect(holdButton).toBeVisible({
      timeout: timeouts.ui.appearance,
    })

    // No page errors should have occurred
    expect(errors).toHaveLength(0)
  })
})
