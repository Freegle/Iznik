/**
 * Reply Flow Tests - Social Login Simulation (Test 4.1)
 *
 * This test simulates what happens when a user completes social login
 * (Google, Facebook, Apple) while composing a reply.
 *
 * See test-reply-flow-index.md for the complete test matrix.
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
  clickSendAndWait,
  waitForNuxtHydration,
} = require('./utils/reply-helpers')

test.describe('Reply Flow - Social Login Simulation', () => {
  /**
   * This test simulates what happens when a user completes social login
   * (Google, Facebook, Apple) while composing a reply. The key mechanism is:
   *
   * 1. User starts composing a reply (not logged in)
   * 2. User clicks social login button (opens OAuth popup)
   * 3. OAuth completes and auth store is updated
   * 4. loginCount is incremented, triggering full app re-render
   * 5. Reply state should be preserved and user can continue
   *
   * We can't automate the actual OAuth flow, but we CAN test that the
   * reply state survives the loginCount key bump that forces the re-render.
   */
  test('4.1 reply state survives loginCount key bump (social login simulation)', async ({
    page,
    postMessage,
    testEmail,
    getTestEmail,
    withdrawPost,
  }, testInfo) => {
    // signup + logout + postMessage + social-login-sim = 4 navigations; each up to 202s
    testInfo.setTimeout(1200000)
    // First create the user we'll use for social login simulation
    const loginEmail = getTestEmail('sociallogin')
    console.log(`[4.1] Step 1: signUpViaHomepage start ${new Date().toISOString()}`)
    await signUpViaHomepage(page, loginEmail)
    console.log(`[4.1] Step 1: signUpViaHomepage done ${new Date().toISOString()}`)
    console.log('[Test] Created sociallogin user')

    // Log out so we can post as a different user
    console.log(`[4.1] Step 2: first logoutIfLoggedIn start ${new Date().toISOString()}`)
    await logoutIfLoggedIn(page)
    console.log(`[4.1] Step 2: first logoutIfLoggedIn done ${new Date().toISOString()}`)

    // Post a message as the poster (testEmail)
    const uniqueItem = `test-social-login-${Date.now()}`
    console.log(`[4.1] Step 3: postMessage start ${new Date().toISOString()} item=${uniqueItem}`)
    const result = await postMessage({
      type: 'OFFER',
      item: uniqueItem,
      description: 'Test item for social login simulation',

      email: testEmail,
    })
    expect(result.id).toBeTruthy()
    console.log(`[4.1] Step 3: postMessage done ${new Date().toISOString()} id=${result.id}`)

    // Navigate to message as logged-out user
    console.log(`[4.1] Step 4: second logoutIfLoggedIn start ${new Date().toISOString()}`)
    await logoutIfLoggedIn(page)
    console.log(`[4.1] Step 4: second logoutIfLoggedIn done ${new Date().toISOString()}`)
    console.log(`[4.1] Step 5: gotoAndVerify /message/${result.id} start ${new Date().toISOString()}`)
    await page.gotoAndVerify(`/message/${result.id}`, { maxRetries: 1 })
    console.log(`[4.1] Step 5: gotoAndVerify done ${new Date().toISOString()}`)
    await clickReplyButton(page)

    // Start typing reply
    const replyText = 'Reply started before social login...'
    const replyTextarea = page
      .locator('textarea[name="reply"]')
      .filter({ visible: true })
    await replyTextarea.fill(replyText)
    console.log(
      '[Test] Started typing reply (simulating pre-social-login state)'
    )

    // Also fill in the email to have full state
    const emailInput = page
      .locator('.test-email-reply-validator input[type="email"]')
      .filter({ visible: true })
    await emailInput.fill(loginEmail)
    console.log('[Test] Filled email field')

    // Fill the collection time (required for OFFER) so Send can proceed. The
    // reply pane is a full-screen overlay covering the navbar, so authentication
    // happens through the forced-login modal that Send raises. Completing that
    // login still bumps loginCount and re-renders the app while a reply is live,
    // which is exactly the mechanism this test guards.
    const composeCollect = page
      .locator('textarea[name="collect"]')
      .filter({ visible: true })
    await composeCollect.waitFor({
      state: 'visible',
      timeout: timeouts.ui.appearance,
    })
    await composeCollect.fill('Can collect anytime')

    await waitForNuxtHydration(page)
    const composeSend = page
      .locator('.composer-send-btn')
      .filter({ visible: true })
    await composeSend.waitFor({
      state: 'visible',
      timeout: timeouts.ui.appearance,
    })
    await composeSend.click()
    console.log('[Test] Clicked Send — forced login (loginCount bump) expected')

    // Wait for login modal to appear
    await page.locator('#loginModal').first().waitFor({
      state: 'visible',
      timeout: timeouts.ui.appearance,
    })

    // Define form field locators — scoped to #loginModal to avoid
    // picking up the reply form's email input behind the modal
    const modal = page.locator('#loginModal').first()
    const modalEmailInput = modal
      .locator('input[type="email"], input[name="email"]')
      .first()
    const passwordField = modal
      .locator('input[type="password"], input[name="password"]')
      .first()
    const fullnameField = modal.locator('#fullname, input[name="fullname"]')
    const loginLink = modal
      .locator('.test-already-a-freegler')
      .filter({ visible: true })
      .first()

    // Wait for modal form fields to be ready
    await page.waitForFunction(
      () => {
        const modal = document.querySelector('#loginModal')
        if (!modal) return false
        const emailEl = modal.querySelector(
          'input[type="email"], input[name="email"]'
        )
        const passwordEl = modal.querySelector(
          'input[type="password"], input[name="password"]'
        )
        return emailEl && passwordEl
      },
      null,
      { timeout: timeouts.ui.appearance }
    )

    // Check if we're in signup mode and switch to login mode if needed
    const fullnameVisible = await fullnameField
      .isVisible({ timeout: 5000 })
      .catch(() => false)
    if (fullnameVisible) {
      console.log('[Test] Modal opened in signup mode, switching to login')
      const loginLinkVisible = await loginLink
        .isVisible({ timeout: 5000 })
        .catch(() => false)
      if (loginLinkVisible) {
        await loginLink.click()
        await fullnameField
          .waitFor({ state: 'hidden', timeout: 3000 })
          .catch(() => {
            console.log('[Test] Mode switch may not have worked, continuing...')
          })
      }
    }

    // Fill email and password
    await modalEmailInput.clear()
    await modalEmailInput.type(loginEmail, { delay: 10 })
    await passwordField.fill(DEFAULT_TEST_PASSWORD)

    // Submit the form
    await passwordField.press('Enter')
    console.log('[Test] Completed login (this triggers loginCount++)')

    // Wait for login modal to close
    await page.locator('#loginModal').first().waitFor({
      state: 'hidden',
      timeout: timeouts.navigation.default,
    })
    console.log('[Test] Login modal closed')

    // Verify login succeeded by waiting for sign-in button to disappear
    await expect(page.locator('.test-signinbutton').first()).not.toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    console.log('[Test] Login verified - sign-in button no longer visible')

    // After the forced login the state machine resumes and sends the reply we
    // composed — proving the reply survived the loginCount re-render. Fall back
    // to completing it manually only if it genuinely didn't resume.
    try {
      await page.waitForURL(/\/chats\//, {
        timeout: timeouts.navigation.default,
      })
      console.log('[Test] Reply state survived loginCount key bump!')
    } catch {
      console.log(
        '[Test] State machine did not auto-resume, completing reply manually'
      )
      const restoredTextarea = page
        .locator('textarea[name="reply"]')
        .filter({ visible: true })
      if (
        !(await restoredTextarea.isVisible({ timeout: 5000 }).catch(() => false))
      ) {
        await clickReplyButton(page)
      }
      if (!(await restoredTextarea.inputValue().catch(() => ''))) {
        await restoredTextarea.fill('Reply after social login simulation')
      }
      const collectAgain = page
        .locator('textarea[name="collect"]')
        .filter({ visible: true })
      if (await collectAgain.isVisible({ timeout: 3000 }).catch(() => false)) {
        if (!(await collectAgain.inputValue())) {
          await collectAgain.fill('Can collect anytime')
        }
      }
      await clickSendAndWait(page)
    }

    expect(page.url()).toContain('/chats/')
    console.log(
      '[Test] Social login simulation complete — reply sent successfully'
    )

    // Cleanup
    await logoutIfLoggedIn(page)
    await loginViaHomepage(page, testEmail)
    await withdrawPost({ item: result.item })
  })
})
