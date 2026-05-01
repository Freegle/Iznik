// @ts-check
/**
 * Tests for ModTools pending messages display and notification counts.
 */

const { test, expect } = require('./fixtures')
const { timeouts, environment } = require('./config')
const { loginViaModTools } = require('./utils/user')

const MODTOOLS_URL = environment.modtoolsBaseUrl

// Helper: dismiss any overlay modals (cake modal, etc.) that block interaction.
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

// Helper: select a group with pending messages.
async function selectGroupWithPendingMessages(page, groupSelect) {
  let targetGroupValue = null
  await expect
    .poll(
      async () => {
        const options = await groupSelect.locator('option').all()
        for (const option of options) {
          const text = await option.textContent()
          const value = await option.getAttribute('value')
          if (value && value !== '0' && /\(\d+\)/.test(text)) {
            targetGroupValue = value
            return true
          }
        }
        return false
      },
      {
        message: 'Waiting for group options with pending message counts',
        timeout: timeouts.navigation.slowPage,
      }
    )
    .toBe(true)
  await groupSelect.selectOption(targetGroupValue)
  return targetGroupValue
}

test.describe('ModTools Pending Messages', () => {
  test('pending messages show text content, not "This message is blank"', async ({
    page,
    testEnv,
    testEmail,
    postMessage,
    withdrawPost,
  }) => {
    // Issue #29: pending messages display "This message is blank"
    // Post a message first so there is guaranteed pending content to view.
    const item = `test-pending-blank-${Date.now()}`
    const posted = await postMessage({
      type: 'OFFER',
      item,
      description: 'Test item for pending messages blank check',
      email: testEmail,
    })
    expect(posted.id).toBeTruthy()

    await loginViaModTools(page, testEnv.mod.email)

    await page.goto(`${MODTOOLS_URL}/messages/pending`, {
      timeout: timeouts.navigation.initial,
    })

    const groupSelect = page.locator('#communitieslist')
    await expect(groupSelect).toBeVisible({
      timeout: timeouts.navigation.slowPage,
    })

    await dismissAllModals(page)

    // Select a group with pending messages
    await selectGroupWithPendingMessages(page, groupSelect)

    // Wait for message cards to load
    const messageCards = page.locator('.card')
    await expect(messageCards.first()).toBeVisible({
      timeout: timeouts.navigation.slowPage,
    })

    // Verify no message shows "This message is blank"
    const bodyText = await page.textContent('body')
    expect(bodyText).not.toContain('This message is blank')

    // Cleanup
    await withdrawPost({ item: posted.item })
  })

  test('pending messages show correct group membership, not "not on any community"', async ({
    page,
    testEnv,
    testEmail,
    postMessage,
    withdrawPost,
  }) => {
    // Issue #19: member info shows "not on any community"
    // Post a message first so there is guaranteed pending content to view.
    const item = `test-pending-membership-${Date.now()}`
    const posted = await postMessage({
      type: 'OFFER',
      item,
      description: 'Test item for pending messages membership check',
      email: testEmail,
    })
    expect(posted.id).toBeTruthy()

    await loginViaModTools(page, testEnv.mod.email)

    await page.goto(`${MODTOOLS_URL}/messages/pending`, {
      timeout: timeouts.navigation.initial,
    })

    const groupSelect = page.locator('#communitieslist')
    await expect(groupSelect).toBeVisible({
      timeout: timeouts.navigation.slowPage,
    })

    await dismissAllModals(page)

    // Select a group with pending messages
    await selectGroupWithPendingMessages(page, groupSelect)

    // Wait for message cards to load
    const messageCards = page.locator('.card')
    await expect(messageCards.first()).toBeVisible({
      timeout: timeouts.navigation.slowPage,
    })

    // The member info area should not say "not on any community"
    const bodyText = await page.textContent('body')
    expect(bodyText).not.toContain('not on any community')

    // Cleanup
    await withdrawPost({ item: posted.item })
  })

  test('notification count badge is reasonable', async ({ page, testEnv }) => {
    // Issue #17: notification count is wildly wrong
    await loginViaModTools(page, testEnv.mod.email)

    await page.goto(`${MODTOOLS_URL}/modtools/dashboard`, {
      timeout: timeouts.navigation.initial,
    })

    await page.waitForLoadState('domcontentloaded', {
      timeout: timeouts.navigation.default,
    })

    await dismissAllModals(page)

    // Look for notification badges in the navbar
    const badges = page.locator(
      '.badge, [class*="notification"] .badge, nav .badge'
    )

    if (
      await badges
        .first()
        .isVisible({ timeout: timeouts.ui.appearance })
        .catch(() => false)
    ) {
      const count = await badges.count()
      for (let i = 0; i < count; i++) {
        const text = await badges.nth(i).textContent()
        const num = parseInt(text.trim(), 10)
        if (!isNaN(num)) {
          // Notification counts should be reasonable (not in the millions)
          expect(num).toBeLessThan(10000)
          expect(num).toBeGreaterThanOrEqual(0)
        }
      }
    }

    await assertNoErrors(page)
  })

  test('post list does not jump or scroll unexpectedly on load', async ({
    page,
    testEnv,
    testEmail,
    postMessage,
    withdrawPost,
  }) => {
    // Regression test: post list UI should not jump/scroll when messages load
    // This verifies that layout shift is prevented through stable height reservation
    const item = `test-pending-jump-${Date.now()}`
    const posted = await postMessage({
      type: 'OFFER',
      item,
      description: 'Test item for layout stability check',
      email: testEmail,
    })
    expect(posted.id).toBeTruthy()

    await loginViaModTools(page, testEnv.mod.email)

    await page.goto(`${MODTOOLS_URL}/messages/pending`, {
      timeout: timeouts.navigation.initial,
    })

    const groupSelect = page.locator('#communitieslist')
    await expect(groupSelect).toBeVisible({
      timeout: timeouts.navigation.slowPage,
    })

    await dismissAllModals(page)

    // Get initial scroll position before selecting group
    const initialScrollY = await page.evaluate(() => window.scrollY)

    // Select a group with pending messages (this triggers message loading)
    await selectGroupWithPendingMessages(page, groupSelect)

    // Wait for message cards to load
    const messageCards = page.locator('.card')
    await expect(messageCards.first()).toBeVisible({
      timeout: timeouts.navigation.slowPage,
    })

    // Measure scroll position after messages load
    const scrollAfterLoad = await page.evaluate(() => window.scrollY)

    // The scroll position should not jump significantly (allow small variance for rendering)
    // A jump would be > 100px, so we allow a small tolerance
    const scrollDelta = Math.abs(scrollAfterLoad - initialScrollY)
    expect(scrollDelta).toBeLessThan(100)

    // Verify the infinite-loading wrapper has reserved height
    const infiniteLoadingWrapper = page.locator('.infinite-loading-wrapper')
    if (
      await infiniteLoadingWrapper
        .isVisible({ timeout: timeouts.ui.appearance })
        .catch(() => false)
    ) {
      const boundingBox = await infiniteLoadingWrapper.boundingBox()
      expect(boundingBox).toBeTruthy()
      expect(boundingBox.height).toBeGreaterThanOrEqual(70)
    }

    // Cleanup
    await withdrawPost({ item: posted.item })
  })
})
