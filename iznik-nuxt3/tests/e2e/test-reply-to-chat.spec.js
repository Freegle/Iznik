/**
 * Reply-to-Chat Flow Tests
 *
 * Tests for the new UX where clicking Reply on mobile/tablet navigates
 * to a dedicated chat reply page (/chats/reply?replyto=MSG_ID) instead
 * of showing an inline reply section.
 *
 * Desktop (lg+) keeps the existing inline reply behavior.
 */

const { test, expect } = require('./fixtures')
const { loginViaHomepage, logoutIfLoggedIn } = require('./utils/user')
const {
  waitForAuthInLocalStorage,
  waitForAuthHydration,
  waitForNuxtHydration,
} = require('./utils/reply-helpers')

// Mobile viewport dimensions (below lg breakpoint = 992px)
const MOBILE_VIEWPORT = { width: 375, height: 812 }
const TABLET_VIEWPORT = { width: 768, height: 1024 }

test.describe('Reply-to-Chat - Mobile', () => {
  test('navigates to chat reply page when Reply clicked on mobile', async ({
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

    // Click Reply button
    const replyButton = page.locator('.reply-button:has-text("Reply")').first()
    await replyButton.waitFor({ state: 'visible', timeout: 30000 })
    await replyButton.click()
    console.log('[Test] Clicked Reply button on mobile')

    // Should navigate to /chats/reply?replyto=MSG_ID
    await page.waitForURL(/\/chats\/reply\?replyto=/, {
      timeout: 30000,
    })
    console.log('[Test] Navigated to chat reply page')
    expect(page.url()).toContain(`replyto=${result.id}`)

    // Verify the reply pane is visible with correct elements
    const replyTextarea = page.locator('textarea[name="reply"]')
    await replyTextarea.waitFor({ state: 'visible', timeout: 30000 })
    console.log('[Test] Reply textarea visible in chat reply pane')

    // Verify collection time field is present (OFFER message)
    const collectTextarea = page.locator('textarea[name="collect"]')
    await collectTextarea.waitFor({ state: 'visible', timeout: 10000 })
    console.log('[Test] Collection time field visible')

    // Verify the back button exists
    const backBtn = page.locator('.back-btn')
    await backBtn.waitFor({ state: 'visible', timeout: 5000 })

    // Fill in reply and collection time
    await replyTextarea.fill('I would love this item, please!')
    await collectTextarea.fill('Available weekdays after 5pm')
    console.log('[Test] Filled reply form')

    // Click send
    const sendButton = page
      .locator('.reply-send-btn, .btn:has-text("Send")')
      .first()
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
    await page.setViewportSize({ width: 1280, height: 720 })
    const loggedIn1 = await loginViaHomepage(page, posterEmail)
    if (loggedIn1) {
      await withdrawPost({ item: result.item })
    }
  })

  test('back button returns to message page', async ({
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

    const replyButton = page.locator('.reply-button:has-text("Reply")').first()
    await replyButton.waitFor({ state: 'visible', timeout: 30000 })
    await replyButton.click()

    await page.waitForURL(/\/chats\/reply\?replyto=/, { timeout: 30000 })

    // Click back button
    const backBtn = page.locator('.back-btn')
    await backBtn.waitFor({ state: 'visible', timeout: 10000 })
    await backBtn.click()
    console.log('[Test] Clicked back button')

    // Should navigate back to message page
    await page.waitForURL(/\/message\/\d+/, { timeout: 30000 })
    console.log('[Test] Back at message page')
    expect(page.url()).toContain(`/message/${result.id}`)

    // Cleanup
    await logoutIfLoggedIn(page)
    await page.setViewportSize({ width: 1280, height: 720 })
    const loggedIn2 = await loginViaHomepage(page, posterEmail)
    if (loggedIn2) {
      await withdrawPost({ item: result.item })
    }
  })
})

test.describe('Reply-to-Chat - Tablet', () => {
  test('navigates to chat reply page on tablet viewport', async ({
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

    const replyButton = page.locator('.reply-button:has-text("Reply")').first()
    await replyButton.waitFor({ state: 'visible', timeout: 30000 })
    await replyButton.click()

    // Should navigate to chat reply page (tablet is md breakpoint = below lg)
    await page.waitForURL(/\/chats\/reply\?replyto=/, { timeout: 30000 })
    expect(page.url()).toContain(`replyto=${result.id}`)
    console.log('[Test] Tablet correctly navigated to chat reply page')

    // Cleanup
    await logoutIfLoggedIn(page)
    await page.setViewportSize({ width: 1280, height: 720 })
    const loggedIn3 = await loginViaHomepage(page, posterEmail)
    if (loggedIn3) {
      await withdrawPost({ item: result.item })
    }
  })
})

test.describe('Reply-to-Chat - Desktop keeps inline', () => {
  test('desktop shows inline reply section (not chat page)', async ({
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
      description: 'Test item for desktop inline reply',
      email: posterEmail,
    })
    expect(result.id).toBeTruthy()

    await logoutIfLoggedIn(page)
    await loginViaHomepage(page, testEnv.user.email, 'freegle')
    await waitForAuthInLocalStorage(page)

    await page.gotoAndVerify(`/message/${result.id}`)
    await waitForAuthHydration(page)
    await waitForNuxtHydration(page)

    const replyButton = page.locator('.reply-button:has-text("Reply")').first()
    await replyButton.waitFor({ state: 'visible', timeout: 30000 })
    await replyButton.click()
    console.log('[Test] Clicked Reply on desktop')

    // On desktop, should NOT navigate to /chats/reply - should show inline form
    // Wait a moment to ensure no navigation happens
    await page.waitForTimeout(2000)
    expect(page.url()).not.toContain('/chats/reply')
    expect(page.url()).toContain(`/message/${result.id}`)

    // The inline reply textarea should be visible
    const replyTextarea = page.locator('textarea[name="reply"]')
    await replyTextarea.waitFor({ state: 'visible', timeout: 10000 })
    console.log('[Test] Desktop correctly shows inline reply section')

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

    const replyButton = page.locator('.reply-button:has-text("Reply")').first()
    await replyButton.waitFor({ state: 'visible', timeout: 30000 })
    await replyButton.click()

    await page.waitForURL(/\/chats\/reply\?replyto=/, { timeout: 30000 })

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
    await page.setViewportSize({ width: 1280, height: 720 })
    const loggedIn5 = await loginViaHomepage(page, posterEmail)
    if (loggedIn5) {
      await withdrawPost({ item: result.item })
    }
  })
})
