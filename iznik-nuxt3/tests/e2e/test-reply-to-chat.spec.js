/**
 * Reply-to-Chat Flow Tests
 *
 * Clicking Reply on a message opens a chat-style reply pane as a full-screen
 * overlay on every breakpoint (mobile, tablet and desktop alike). The overlay
 * sits on top of wherever you are, so closing it returns you to exactly where
 * you were, and sending the reply lands you in the real chat.
 */

const { test, expect } = require('./fixtures')
const { loginViaHomepage, logoutIfLoggedIn } = require('./utils/user')
const {
  waitForAuthInLocalStorage,
  waitForAuthHydration,
  waitForNuxtHydration,
  waitForBreakpoint,
} = require('./utils/reply-helpers')
const { timeouts } = require('./config')

// Mobile viewport dimensions (below lg breakpoint = 992px)
const MOBILE_VIEWPORT = { width: 375, height: 812 }
const TABLET_VIEWPORT = { width: 768, height: 1024 }
const DESKTOP_VIEWPORT = { width: 1280, height: 800 }

test.describe('Reply-to-Chat - Mobile', () => {
  test('opens the reply overlay and sends a reply on mobile', async ({
    page,
    postMessage,
    testEnv,
    getTestEmail,
    withdrawPost,
  }) => {
    // Post a message first at default viewport (postMessage fixture needs desktop give page)
    const posterEmail = getTestEmail('poster-r2c-mob')
    const uniqueItem = `test-r2c-mobile-${Date.now()}`
    const result = await postMessage({
      type: 'OFFER',
      item: uniqueItem,
      description: 'Test item for reply-to-chat mobile flow',
      email: posterEmail,
    })
    expect(result.id).toBeTruthy()
    console.log(`[Test] Posted message ${result.id}`)

    // Log out from poster and login as the replier at mobile viewport
    await logoutIfLoggedIn(page)
    await page.setViewportSize(MOBILE_VIEWPORT)
    await loginViaHomepage(page, testEnv.user.email, 'freegle')
    await waitForAuthInLocalStorage(page)

    // Navigate to message page
    await page.gotoAndVerify(`/message/${result.id}`)
    await page.setViewportSize(MOBILE_VIEWPORT)
    await waitForAuthHydration(page)
    await waitForNuxtHydration(page)
    await waitForBreakpoint(page, 'xs')

    // Click Reply button
    const replyButton = page.locator('.reply-button:has-text("Reply")').first()
    await replyButton.waitFor({ state: 'visible', timeout: 30000 })
    await replyButton.click()
    console.log('[Test] Clicked Reply button on mobile')

    // The reply overlay opens in place - we stay on the message page.
    await expect(page.locator('.reply-overlay')).toBeVisible({ timeout: 30000 })
    expect(page.url()).toContain(`/message/${result.id}`)
    console.log('[Test] Reply overlay opened on mobile')

    // Verify the reply pane has the correct elements
    const replyTextarea = page.locator('textarea[name="reply"]')
    await replyTextarea.waitFor({ state: 'visible', timeout: 30000 })

    // Verify collection time field is present (OFFER message)
    const collectTextarea = page.locator('textarea[name="collect"]')
    await collectTextarea.waitFor({ state: 'visible', timeout: 10000 })

    // Verify the back button exists
    await expect(page.locator('.reply-card__back')).toBeVisible({
      timeout: 5000,
    })

    // Fill in reply and collection time
    await replyTextarea.fill('I would love this item, please!')
    await collectTextarea.fill('Available weekdays after 5pm')
    console.log('[Test] Filled reply form')

    // Click send
    const sendButton = page.locator('.composer-send-btn').first()
    await sendButton.waitFor({ state: 'visible', timeout: 10000 })
    await sendButton.click()
    console.log('[Test] Clicked Send')

    // Should navigate to /chats/:id (real chat)
    await page.waitForURL(/\/chats\/\d+/, {
      timeout: 60000,
    })
    console.log('[Test] Navigated to real chat after sending reply')
    expect(page.url()).toMatch(/\/chats\/\d+/)

    // Cleanup
    await logoutIfLoggedIn(page)
    await page.setViewportSize(DESKTOP_VIEWPORT)
    const loggedIn1 = await loginViaHomepage(page, posterEmail)
    if (loggedIn1) {
      await withdrawPost({ item: result.item })
    }
  })

  test('back button closes the overlay and stays on the message page', async ({
    page,
    postMessage,
    testEnv,
    getTestEmail,
    withdrawPost,
  }) => {
    const posterEmail = getTestEmail('poster-r2c-back')
    const uniqueItem = `test-r2c-back-${Date.now()}`
    const result = await postMessage({
      type: 'OFFER',
      item: uniqueItem,
      description: 'Test item for back button',
      email: posterEmail,
    })
    expect(result.id).toBeTruthy()

    await logoutIfLoggedIn(page)
    await page.setViewportSize(MOBILE_VIEWPORT)
    await loginViaHomepage(page, testEnv.user.email, 'freegle')
    await waitForAuthInLocalStorage(page)

    // Navigate to message and click Reply
    await page.gotoAndVerify(`/message/${result.id}`)
    await page.setViewportSize(MOBILE_VIEWPORT)
    await waitForAuthHydration(page)
    await waitForNuxtHydration(page)
    await waitForBreakpoint(page, 'xs')

    const replyButton = page.locator('.reply-button:has-text("Reply")').first()
    await replyButton.waitFor({ state: 'visible', timeout: 30000 })
    await replyButton.click()

    await expect(page.locator('.reply-overlay')).toBeVisible({ timeout: 30000 })

    // Click back button - this closes the overlay without navigating.
    const backBtn = page.locator('.reply-card__back')
    await backBtn.waitFor({ state: 'visible', timeout: 10000 })
    await backBtn.click()
    console.log('[Test] Clicked back button')

    // Overlay closes and we are still exactly where we were.
    await expect(page.locator('.reply-overlay')).toBeHidden({ timeout: 30000 })
    expect(page.url()).toContain(`/message/${result.id}`)
    console.log('[Test] Back at message page with overlay closed')

    // Cleanup
    await logoutIfLoggedIn(page)
    await page.setViewportSize(DESKTOP_VIEWPORT)
    const loggedIn2 = await loginViaHomepage(page, posterEmail)
    if (loggedIn2) {
      await withdrawPost({ item: result.item })
    }
  })
})

test.describe('Reply-to-Chat - Tablet', () => {
  test('opens the reply overlay on tablet viewport', async ({
    page,
    postMessage,
    testEnv,
    getTestEmail,
    withdrawPost,
  }) => {
    const posterEmail = getTestEmail('poster-r2c-tab')
    const uniqueItem = `test-r2c-tablet-${Date.now()}`
    const result = await postMessage({
      type: 'OFFER',
      item: uniqueItem,
      description: 'Test item for tablet reply-to-chat',
      email: posterEmail,
    })
    expect(result.id).toBeTruthy()

    await logoutIfLoggedIn(page)
    await page.setViewportSize(TABLET_VIEWPORT)
    await loginViaHomepage(page, testEnv.user.email, 'freegle')
    await waitForAuthInLocalStorage(page)

    await page.gotoAndVerify(`/message/${result.id}`)
    await page.setViewportSize(TABLET_VIEWPORT)
    await waitForAuthHydration(page)
    await waitForNuxtHydration(page)
    await waitForBreakpoint(page, 'md')

    const replyButton = page.locator('.reply-button:has-text("Reply")').first()
    await replyButton.waitFor({ state: 'visible', timeout: 30000 })
    await replyButton.click()

    // The reply overlay opens in place on tablet too.
    await expect(page.locator('.reply-overlay')).toBeVisible({ timeout: 30000 })
    expect(page.url()).toContain(`/message/${result.id}`)
    console.log('[Test] Tablet correctly opened the reply overlay')

    // Cleanup
    await logoutIfLoggedIn(page)
    await page.setViewportSize(DESKTOP_VIEWPORT)
    const loggedIn3 = await loginViaHomepage(page, posterEmail)
    if (loggedIn3) {
      await withdrawPost({ item: result.item })
    }
  })
})

test.describe('Reply-to-Chat - Desktop', () => {
  test('opens the reply overlay on desktop (consistent with mobile)', async ({
    page,
    postMessage,
    testEnv,
    getTestEmail,
    withdrawPost,
  }) => {
    const posterEmail = getTestEmail('poster-r2c-desk')
    const uniqueItem = `test-r2c-desktop-${Date.now()}`
    const result = await postMessage({
      type: 'OFFER',
      item: uniqueItem,
      description: 'Test item for desktop reply overlay',
      email: posterEmail,
    })
    expect(result.id).toBeTruthy()

    await logoutIfLoggedIn(page)
    await page.setViewportSize(DESKTOP_VIEWPORT)
    await loginViaHomepage(page, testEnv.user.email, 'freegle')
    await waitForAuthInLocalStorage(page)

    await page.gotoAndVerify(`/message/${result.id}`)
    await page.setViewportSize(DESKTOP_VIEWPORT)
    await waitForAuthHydration(page)
    await waitForNuxtHydration(page)
    await waitForBreakpoint(page, 'xl')

    const replyButton = page.locator('.reply-button:has-text("Reply")').first()
    await replyButton.waitFor({ state: 'visible', timeout: 30000 })
    await replyButton.click()
    console.log('[Test] Clicked Reply on desktop')

    // Desktop now opens the same chat-style overlay - we stay on the message page.
    await expect(page.locator('.reply-overlay')).toBeVisible({ timeout: 30000 })
    expect(page.url()).toContain(`/message/${result.id}`)
    expect(page.url()).not.toContain('/chats/reply')

    // The reply textarea should be visible in the overlay.
    await expect(page.locator('textarea[name="reply"]')).toBeVisible({
      timeout: 10000,
    })
    console.log('[Test] Desktop correctly opened the reply overlay')

    // Cleanup
    await logoutIfLoggedIn(page)
    const loggedIn4 = await loginViaHomepage(page, posterEmail)
    if (loggedIn4) {
      await withdrawPost({ item: result.item })
    }
  })
})

test.describe('Reply-to-Chat - WANTED message', () => {
  test('reply to WANTED message does not show collection time', async ({
    page,
    postMessage,
    testEnv,
    getTestEmail,
    withdrawPost,
  }) => {
    const posterEmail = getTestEmail('poster-r2c-want')
    const uniqueItem = `test-r2c-wanted-${Date.now()}`
    const result = await postMessage({
      type: 'WANTED',
      item: uniqueItem,
      description: 'Test WANTED item for reply-to-chat',
      email: posterEmail,
    })
    expect(result.id).toBeTruthy()

    await logoutIfLoggedIn(page)
    await page.setViewportSize(MOBILE_VIEWPORT)
    await loginViaHomepage(page, testEnv.user.email, 'freegle')
    await waitForAuthInLocalStorage(page)

    await page.gotoAndVerify(`/message/${result.id}`)
    await page.setViewportSize(MOBILE_VIEWPORT)
    await waitForAuthHydration(page)
    await waitForNuxtHydration(page)
    await waitForBreakpoint(page, 'xs')

    const replyButton = page.locator('.reply-button:has-text("Reply")').first()
    await replyButton.waitFor({ state: 'visible', timeout: 30000 })
    await replyButton.click()

    await expect(page.locator('.reply-overlay')).toBeVisible({ timeout: 30000 })

    // Reply textarea should be visible
    const replyTextarea = page.locator('textarea[name="reply"]')
    await replyTextarea.waitFor({ state: 'visible', timeout: 30000 })

    // Collection time field should NOT be visible for WANTED messages
    const collectTextarea = page.locator('textarea[name="collect"]')
    const collectVisible = await collectTextarea.isVisible().catch(() => false)
    expect(collectVisible).toBe(false)
    console.log('[Test] WANTED message correctly hides collection time field')

    // Cleanup
    await logoutIfLoggedIn(page)
    await page.setViewportSize(DESKTOP_VIEWPORT)
    const loggedIn5 = await loginViaHomepage(page, posterEmail)
    if (loggedIn5) {
      await withdrawPost({ item: result.item })
    }
  })
})

test.describe('Reply-to-Chat - Empty State', () => {
  test('shows empty state when no replyto param', async ({ page }) => {
    await logoutIfLoggedIn(page)
    await page.gotoAndVerify('/chats/reply')
    await waitForNuxtHydration(page)

    const emptyState = page.locator('.empty-state')
    await expect(emptyState).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    await expect(page.locator('text=No message to reply to.')).toBeVisible()
    await expect(emptyState.locator('a[href="/browse"]')).toBeVisible()
    console.log(
      '[Test] Empty state shown for /chats/reply without replyto param'
    )
  })

  test('shows empty state when replyto=0', async ({ page }) => {
    await logoutIfLoggedIn(page)
    await page.gotoAndVerify('/chats/reply?replyto=0')
    await waitForNuxtHydration(page)

    const emptyState = page.locator('.empty-state')
    await expect(emptyState).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    console.log('[Test] Empty state shown for replyto=0')
  })
})

test.describe('Reply-to-Chat - Logged Out', () => {
  test('shows email field on the reply pane when not logged in', async ({
    page,
    postMessage,
    getTestEmail,
    withdrawPost,
  }) => {
    const posterEmail = getTestEmail('poster-r2c-loggedout')
    const uniqueItem = `test-r2c-loggedout-${Date.now()}`
    const result = await postMessage({
      type: 'OFFER',
      item: uniqueItem,
      description: 'Test item for logged-out reply-to-chat',
      email: posterEmail,
    })
    expect(result.id).toBeTruthy()
    console.log(`[Test] Posted message ${result.id}`)

    // Ensure logged out, then deep-link straight to the reply pane.
    await logoutIfLoggedIn(page)
    await page.setViewportSize(MOBILE_VIEWPORT)
    await page.gotoAndVerify(`/chats/reply?replyto=${result.id}`)
    await waitForNuxtHydration(page)

    // Email validator should be visible for logged-out users
    const emailField = page.locator(
      '.test-email-reply-validator, input[type="email"]'
    )
    await expect(emailField.first()).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    console.log('[Test] Email field visible for logged-out user on reply pane')

    // Reply textarea should also be visible
    const replyTextarea = page.locator('textarea[name="reply"]')
    await expect(replyTextarea).toBeVisible({
      timeout: timeouts.ui.appearance,
    })

    // Cleanup
    await page.setViewportSize(DESKTOP_VIEWPORT)
    const loggedIn6 = await loginViaHomepage(page, posterEmail)
    if (loggedIn6) {
      await withdrawPost({ item: result.item })
    }
  })
})
