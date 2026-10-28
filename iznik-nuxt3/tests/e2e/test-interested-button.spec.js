/**
 * "I'm interested" button
 *
 * A signed-in member looking at someone else's offer gets an "I'm interested" button beside Reply when the
 * server puts them in the arm button of the experiment interested-button. scripts/test-fixtures-extra.sql
 * serves that arm to everyone. Pressing it saves the post, shows a one-line note, and the post then appears
 * under "Posts you're interested in" on My Posts, where Remove takes it off again. The API keeps its copy of
 * the experiment config rows for up to a minute, and a run resets the database as it starts, so this case
 * must not rely on being the very first request of a run.
 */

const { test, expect } = require('./fixtures')
const { loginViaHomepage, logoutIfLoggedIn } = require('./utils/user')
const {
  waitForAuthInLocalStorage,
  waitForAuthHydration,
  waitForNuxtHydration,
} = require('./utils/reply-helpers')
const { timeouts } = require('./config')

const DESKTOP_VIEWPORT = { width: 1280, height: 800 }

test.describe("I'm interested button", () => {
  test('marks an offer, finds it in My Posts and removes it', async ({
    page,
    postMessage,
    approvePost,
    testEnv,
    getTestEmail,
  }) => {
    // Post an offer as someone else, then sign in as the member who will mark it.
    const uniqueItem = `test-interested-${Date.now()}`
    const result = await postMessage({
      type: 'OFFER',
      item: uniqueItem,
      description: 'Test item for the interested button',
      email: getTestEmail('poster-interested'),
    })
    expect(result.id).toBeTruthy()

    // A fresh member's post is Pending, and the API offers the button only on an approved post.
    await approvePost(result.id)

    await logoutIfLoggedIn(page)
    await page.setViewportSize(DESKTOP_VIEWPORT)
    await loginViaHomepage(page, testEnv.user.email, 'freegle')
    await waitForAuthInLocalStorage(page)

    await page.gotoAndVerify(`/message/${result.id}`)
    await waitForAuthHydration(page)
    await waitForNuxtHydration(page)

    // The button is offered beside Reply.
    // The page asks the API once per load, and the API may still hold the pre-reset experiment rows for up to a
    // minute (see the header), so reload until the answer carries the arm.
    const interested = page.locator('[data-testid="interested-button"]').first()
    for (let attempt = 0; attempt < 3; attempt++) {
      if (await interested.isVisible()) break
      try {
        await expect(interested).toBeVisible({
          timeout: timeouts.ui.appearance,
        })
      } catch (e) {
        await page.gotoAndVerify(`/message/${result.id}`)
        await waitForAuthHydration(page)
        await waitForNuxtHydration(page)
      }
    }
    await expect(interested).toBeVisible({ timeout: timeouts.ui.appearance })
    await expect(page.locator('.reply-button').first()).toBeVisible()

    // Pressing it shows the marked state and the note, and the button goes.
    await interested.click()
    await expect(
      page.locator('[data-testid="interested-marked"]').first()
    ).toBeVisible({ timeout: timeouts.ui.appearance })
    await expect(
      page.locator('[data-testid="interested-note"]').first()
    ).toContainText('Find it in My Posts', {
      timeout: timeouts.ui.appearance,
    })
    await expect(interested).toHaveCount(0)

    // The post is listed on My Posts.
    await page.gotoAndVerify('/myposts')
    const section = page.locator('[data-testid="myposts-interested"]')
    await expect(section).toBeVisible({ timeout: timeouts.ui.appearance })
    await expect(section).toContainText("Posts you're interested in")
    const row = page.locator(`[data-testid="interested-row-${result.id}"]`)
    await expect(row).toBeVisible({ timeout: timeouts.ui.appearance })

    // Remove takes it off the list.
    await page.locator(`[data-testid="interested-remove-${result.id}"]`).click()
    await expect(row).toHaveCount(0, { timeout: timeouts.ui.appearance })
  })
})
