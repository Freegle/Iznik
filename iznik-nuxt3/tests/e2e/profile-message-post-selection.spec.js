/**
 * Messaging somebody from their profile (#11).
 *
 * People often reach a poster through their profile and press Message there,
 * so the message arrives with no post attached and the poster has to guess
 * which item it is about. When the profile's owner has posts up, Message now
 * asks which post the message is about first:
 * - picking a post sends the message as a reply to it, so the chat opens on an
 *   "interested" message that names the post
 * - "Something else" opens a plain chat, as before
 * - a profile with nothing up skips the question and opens the chat directly
 */
const { test, expect } = require('./fixtures')
const { timeouts, environment } = require('./config')
const {
  signUpViaHomepage,
  loginViaHomepage,
  loginViaModTools,
  logoutIfLoggedIn,
} = require('./utils/user')

const API_V2 = environment.apiV2BaseUrl

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

// A brand-new member's first post is held for moderation; approve it as a mod
// would so it is live on the profile (same workaround as test-profile-page).
async function approveAsMod(page, testEnv, msgId) {
  await loginViaModTools(page, testEnv.mod.email)
  const modJwt = await page.evaluate(() => {
    const auth = JSON.parse(localStorage.getItem('auth') || '{}')
    return auth?.auth?.jwt
  })

  let actualGroupId = null
  await expect
    .poll(
      async () => {
        const resp = await page.request.get(`${API_V2}/message/${msgId}`, {
          headers: { Authorization: modJwt },
        })
        const msgData = await resp.json()
        actualGroupId = msgData?.groups?.[0]?.groupid ?? null
        return actualGroupId !== null
      },
      {
        message: 'Waiting for message to have groups populated',
        timeout: timeouts.api.slowApi,
        intervals: [500, 500, 1000, 1000, 2000],
      }
    )
    .toBe(true)

  const approveResp = await page.request.post(`${API_V2}/message`, {
    data: {
      id: Number(msgId),
      action: 'Approve',
      groupid: Number(actualGroupId),
    },
    headers: { Authorization: modJwt },
  })
  expect(approveResp.ok()).toBeTruthy()
  await logoutIfLoggedIn(page)
}

test.describe('Message from a profile asks which post it is about', () => {
  test('a post can be chosen and the chat opens on a reply to it; Something else opens a plain chat', async ({
    page,
    testEmail,
    testEnv,
    getTestEmail,
    postMessage,
    withdrawPost,
  }) => {
    const posterName = 'Post Picker Poster'
    const posterId = await signUpAndCaptureId(page, testEmail, posterName)

    const uniqueItem = `test-postpick-${Date.now()}-${Math.random()
      .toString(36)
      .substr(2, 5)}`

    const result = await postMessage({
      type: 'OFFER',
      item: uniqueItem,
      description: `Post selection e2e test post at ${new Date().toISOString()}`,
      email: testEmail,
    })
    expect(result.id).toBeTruthy()
    await logoutIfLoggedIn(page)
    await approveAsMod(page, testEnv, result.id)

    const viewerEmail = getTestEmail('postpickviewer')
    expect(
      await signUpViaHomepage(page, viewerEmail, 'Post Picker Viewer')
    ).toBeTruthy()

    await page.gotoAndVerify(`/profile/${posterId}`)
    await expect(page.locator('.user-name')).toContainText(posterName, {
      timeout: timeouts.ui.appearance,
    })
    await expect(
      page.locator('.posts-grid .content-subject').getByText(uniqueItem)
    ).toBeVisible({ timeout: timeouts.ui.appearance })

    // Message asks which post, listing the offer and Something else.
    await page.locator('.message-btn').click()
    const modal = page.locator('.post-selection-modal')
    await expect(modal).toBeVisible({ timeout: timeouts.ui.appearance })
    await expect(modal.getByText(uniqueItem)).toBeVisible()
    await expect(modal.getByText('Something else')).toBeVisible()

    // Pick the post, write the message, continue: the chat opens on a reply
    // that names the post.
    const messageText = `Is the ${uniqueItem} still available?`
    await modal.locator('input[type="radio"]').first().check()
    await modal.locator('textarea').fill(messageText)
    await modal.getByRole('button', { name: 'Continue' }).click()
    await page.waitForURL(/\/chats\/\d+/, {
      timeout: timeouts.navigation.default,
    })
    await expect(page.locator('.chat-message-interested').first()).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    await expect(
      page.locator('.chat-message-interested').first()
    ).toContainText(uniqueItem)
    await expect(page.locator('body')).toContainText(messageText)

    // Back on the profile, Something else opens the chat with no post attached.
    await page.gotoAndVerify(`/profile/${posterId}`)
    await page.locator('.message-btn').click()
    await expect(modal).toBeVisible({ timeout: timeouts.ui.appearance })
    await modal.locator('input[value="other"]').check()
    await modal.getByRole('button', { name: 'Continue' }).click()
    await page.waitForURL(/\/chats\/\d+/, {
      timeout: timeouts.navigation.default,
    })

    // Clean up: withdraw the post as the original poster.
    await logoutIfLoggedIn(page)
    expect(await loginViaHomepage(page, testEmail)).toBeTruthy()
    await withdrawPost({ item: uniqueItem })
    await logoutIfLoggedIn(page)
  })

  test('a profile with nothing up opens the chat straight away', async ({
    page,
    testEmail,
    getTestEmail,
  }) => {
    const quietId = await signUpAndCaptureId(page, testEmail, 'Quiet Member')
    await logoutIfLoggedIn(page)

    const viewerEmail = getTestEmail('postpickviewer2')
    expect(
      await signUpViaHomepage(page, viewerEmail, 'Post Picker Viewer Two')
    ).toBeTruthy()

    await page.gotoAndVerify(`/profile/${quietId}`)
    await expect(page.locator('.user-name')).toContainText('Quiet Member', {
      timeout: timeouts.ui.appearance,
    })

    await page.locator('.message-btn').click()
    await page.waitForURL(/\/chats\/\d+/, {
      timeout: timeouts.navigation.default,
    })
    await expect(page.locator('.post-selection-modal')).toHaveCount(0)
  })
})
