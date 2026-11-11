/**
 * Reply Flow Tests - Existing User Forced Login (Tests 3.1, 3.2, 3.3)
 *
 * These tests cover the reply flow for existing users who are forced to login.
 * Each test creates unique users - can run in PARALLEL.
 */

const { test, expect } = require('./fixtures')
const { timeouts, DEFAULT_TEST_PASSWORD } = require('./config')
const {
  loginViaHomepage,
  logoutIfLoggedIn,
  signUpViaHomepage,
} = require('./utils/user')
const {
  clickReplyButton,
  fillReplyForm,
  waitForNuxtHydration,
} = require('./utils/reply-helpers')
const { waitForLoginModalClosed } = require('./utils/login-diagnostics')

/*
 * The forced-login modal opens in sign-up mode when this browser has never logged in
 * (loggedInEver goes when storage is cleared). Typing into that form fills the sign-up
 * fields, and keystrokes typed while it re-renders can be lost: job 42554 left the email
 * as "e" with the modal still saying "Join the Reuse Revolution!", so it never closed.
 * Switch to log in when needed, fill each field, and check what landed before submitting.
 */
async function completeForcedLogin(page, loginModal, email) {
  const loginLink = loginModal
    .locator('.test-already-a-freegler')
    .filter({ visible: true })
  if ((await loginLink.count()) > 0) {
    await loginLink.first().click()
    await expect(loginLink).toHaveCount(0, { timeout: timeouts.ui.appearance })
    console.log('[Test] Modal opened in sign-up mode; switched to log in')
  }

  const emailInput = loginModal.locator('input[type="email"]')
  await emailInput.waitFor({
    state: 'visible',
    timeout: timeouts.ui.appearance,
  })
  await emailInput.fill(email)
  await expect(emailInput).toHaveValue(email, {
    timeout: timeouts.ui.appearance,
  })
  console.log(`[Test] Filled login email: ${email}`)

  const passwordInput = loginModal.locator('input[type="password"]')
  await passwordInput.waitFor({
    state: 'visible',
    timeout: timeouts.ui.appearance,
  })
  await passwordInput.fill(DEFAULT_TEST_PASSWORD)
  await expect(passwordInput).toHaveValue(DEFAULT_TEST_PASSWORD, {
    timeout: timeouts.ui.appearance,
  })
  console.log('[Test] Filled login password')

  await passwordInput.press('Enter')
  console.log('[Test] Pressed Enter to submit login form')
}

test.describe('Reply Flow - Existing User Forced Login', () => {
  test('3.1 can login and reply from Message Page', async ({
    page,
    postMessage,
    testEmail,
    getTestEmail,
    withdrawPost,
  }, testInfo) => {
    // Multi-step flow: signup + 2x logout + postMessage can each take 135-202s under
    // parallel CI load. Default 600s budget is insufficient.
    testInfo.setTimeout(900000)
    // First create a user by signing up (this will be the "existing" user who will reply)
    const existingEmail = getTestEmail('existing')
    await signUpViaHomepage(page, existingEmail)

    await logoutIfLoggedIn(page)

    // Post a message as the poster (testEmail)
    const uniqueItem = `test-existing-msg-${Date.now()}`
    const result = await postMessage({
      type: 'OFFER',
      item: uniqueItem,
      description: 'Test item for existing user forced login reply',

      email: testEmail,
    })
    expect(result.id).toBeTruthy()

    await logoutIfLoggedIn(page)

    // Navigate to message page
    await page.gotoAndVerify(`/message/${result.id}`, { maxRetries: 1 })
    await clickReplyButton(page)

    // Fill in reply with existing user's email - should trigger login flow
    await fillReplyForm(page, {
      email: existingEmail,
      replyText: 'I want this item (existing user logging back in)!',
      collectText: 'Can collect anytime',
    })

    // Click send - this should trigger the forced login flow.
    // Wait for Vue hydration so the click handler is attached.
    await waitForNuxtHydration(page)

    const sendButton = page
      .locator('.composer-send-btn')
      .filter({ visible: true })
    await sendButton.waitFor({
      state: 'visible',
      timeout: timeouts.ui.appearance,
    })
    await sendButton.click()
    console.log('[Test] Clicked Send')

    const loginModal = page.locator('.modal-content').filter({
      hasText: 'Log in',
    })
    await loginModal.waitFor({
      state: 'visible',
      timeout: timeouts.ui.appearance,
    })
    console.log('[Test] Login modal appeared for existing user')

    await completeForcedLogin(page, loginModal, existingEmail)

    // Wait for login modal to close (login success)
    await waitForLoginModalClosed(page, loginModal, 'forced login')
    console.log('[Test] Login modal closed')

    // After forced login, the state machine should resume automatically.
    // Wait for navigation to /chats/ — only fall back to clicking Send again
    // if the state machine genuinely didn't resume (e.g. page refreshed).
    try {
      await page.waitForURL(/\/chats\//, {
        timeout: timeouts.navigation.default,
      })
    } catch {
      console.log(
        '[Test] State machine did not auto-resume, clicking Send again'
      )
      const sendButtonAgain = page
        .locator('.composer-send-btn')
        .filter({ visible: true })
      if (
        await sendButtonAgain.isVisible({ timeout: 5000 }).catch(() => false)
      ) {
        await sendButtonAgain.click()
        await page.waitForURL(/\/chats\//, {
          timeout: timeouts.navigation.default,
        })
      }
    }

    // Should navigate to chats after successful login and reply
    expect(page.url()).toContain('/chats/')
    console.log(
      '[Test] Existing user forced login from message page successful'
    )

    // Cleanup
    await logoutIfLoggedIn(page)
    await loginViaHomepage(page, testEmail)
    await withdrawPost({ item: result.item, expectReplies: 1 })
  })
})
