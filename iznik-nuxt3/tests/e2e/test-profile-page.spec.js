/**
 * e2e coverage for the public /profile/[id] page and the ProfileInfo
 * component it renders, which previously had no Playwright coverage at all:
 * - viewing your own profile (empty stats, no chat button)
 * - viewing another freegler's profile with an active OFFER and a working
 *   chat button
 * - viewing a profile while logged out (chat button hidden, ratings
 *   disabled) and the not-found state for an unknown id, including the
 *   "Go Back" button
 */

const { test, expect } = require('./fixtures')
const { timeouts } = require('./config')
const {
  signUpViaHomepage,
  loginViaHomepage,
  logoutIfLoggedIn,
} = require('./utils/user')

// Signs up via the homepage and captures the new user's id from the PUT
// /user response (registration returns { ret, status, id, jwt, persistent }).
async function signUpAndCaptureId(page, email, name) {
  const userCreated = page.waitForResponse(
    (r) =>
      r.url().includes('/user') &&
      r.request().method() === 'PUT' &&
      r.status() === 200,
    { timeout: timeouts.navigation.default }
  )
  const signupResult = await signUpViaHomepage(page, email, name)
  expect(signupResult).toBeTruthy()

  const body = await (await userCreated).json().catch(() => ({}))
  expect(body.id).toBeTruthy()
  return body.id
}

test.describe('Profile page (/profile/[id])', () => {
  test('own profile: hero info, all-zero stats, empty OFFER/WANTED state and no chat button', async ({
    page,
    testEmail,
  }) => {
    const myId = await signUpAndCaptureId(page, testEmail, 'Profile Owner')

    await page.gotoAndVerify(`/profile/${myId}`)

    await expect(page.locator('.user-name')).toContainText('Profile Owner', {
      timeout: timeouts.ui.appearance,
    })
    await expect(page.locator('.member-since')).toContainText(
      'Freegler since'
    )

    // A brand new signup has no location set, so the location row is absent.
    await expect(page.locator('.location-row')).toHaveCount(0)

    // Stats box: brand new account, everything is zero.
    const statValues = page.locator('.stats-box .stat-value')
    await expect(statValues).toHaveCount(4)
    const values = await statValues.allTextContents()
    for (const value of values) {
      expect(value.trim()).toBe('0')
    }

    // Own profile: id === myid, so the chat button is not rendered.
    await expect(page.locator('.message-btn')).toHaveCount(0)

    // No active OFFERs or WANTEDs yet — both sections show the empty state.
    await expect(page.locator('.no-posts')).toHaveCount(2)
    await expect(page.locator('.posts-grid')).toHaveCount(0)

    await logoutIfLoggedIn(page)
  })

  test("another freegler's profile: their active OFFER is listed, WANTED stays empty, and the chat button opens a chat", async ({
    page,
    testEmail,
    getTestEmail,
    postMessage,
    withdrawPost,
  }) => {
    const posterName = 'Profile Poster'
    const posterId = await signUpAndCaptureId(page, testEmail, posterName)

    const uniqueItem = `test-profile-offer-${Date.now()}-${Math.random()
      .toString(36)
      .substr(2, 5)}`

    const result = await postMessage({
      type: 'OFFER',
      item: uniqueItem,
      description: `Profile page e2e test post at ${new Date().toISOString()}`,
      email: testEmail,
    })
    expect(result.id).toBeTruthy()

    await logoutIfLoggedIn(page)

    const viewerEmail = getTestEmail('profileviewer')
    const viewerSignupResult = await signUpViaHomepage(
      page,
      viewerEmail,
      'Profile Viewer'
    )
    expect(viewerSignupResult).toBeTruthy()

    await page.gotoAndVerify(`/profile/${posterId}`)

    await expect(page.locator('.user-name')).toContainText(posterName, {
      timeout: timeouts.ui.appearance,
    })

    // The poster's active OFFER is listed in the posts grid.
    await expect(
      page.locator('.posts-grid').getByText(uniqueItem)
    ).toBeVisible({ timeout: timeouts.ui.appearance })

    // No WANTEDs, so that section shows the empty state.
    await expect(page.locator('.no-posts')).toHaveCount(1)

    // Viewing another user while logged in shows a working chat button.
    const chatButton = page.locator('.message-btn')
    await expect(chatButton).toBeVisible({ timeout: timeouts.ui.appearance })
    await chatButton.click()
    await page.waitForURL(/\/chats\//, {
      timeout: timeouts.navigation.default,
    })

    // Clean up: withdraw the post as the original poster.
    await logoutIfLoggedIn(page)
    const relogin = await loginViaHomepage(page, testEmail)
    expect(relogin).toBeTruthy()
    await withdrawPost({ item: uniqueItem })
    await logoutIfLoggedIn(page)
  })

  test('logged-out visitor: chat button hidden and ratings disabled on a real profile; an unknown id shows the not-found state and Go Back works', async ({
    page,
    testEmail,
  }) => {
    const ownerName = 'Anon Viewed User'
    const ownerId = await signUpAndCaptureId(page, testEmail, ownerName)

    // Go anonymous for the rest of the test.
    await logoutIfLoggedIn(page)

    await page.gotoAndVerify(`/profile/${ownerId}`)
    await expect(page.locator('.user-name')).toContainText(ownerName, {
      timeout: timeouts.ui.appearance,
    })

    // No chat button for a logged-out visitor (myid is falsy).
    await expect(page.locator('.message-btn')).toHaveCount(0)

    // Rating buttons are present but disabled when logged out.
    const ratingButtons = page.locator('.user-ratings button')
    await expect(ratingButtons.first()).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    const ratingButtonCount = await ratingButtons.count()
    expect(ratingButtonCount).toBeGreaterThan(0)
    for (let i = 0; i < ratingButtonCount; i++) {
      await expect(ratingButtons.nth(i)).toBeDisabled()
    }

    // Now hit an id that doesn't exist — the not-found error state.
    await page.gotoAndVerify('/profile/999999999')
    await expect(page.locator('.error-title')).toContainText(
      'Freegler not found',
      { timeout: timeouts.ui.appearance }
    )
    await expect(page.locator('.error-message')).toContainText(
      "couldn't find that profile"
    )

    // Go Back returns to the previously viewed real profile.
    await page.locator('.error-card button').click()
    await expect(page.locator('.user-name')).toContainText(ownerName, {
      timeout: timeouts.ui.appearance,
    })
  })
})
