// @ts-check
/**
 * Tests for the ModTools Chat Review page (modtools/pages/chats/review.vue)
 * and its collapsible help panel (modtools/components/ModHelpChatReview.vue).
 *
 * The review queue itself is populated asynchronously - a message only shows
 * up here after chats:process-incoming (run on a schedule) flags it via a
 * content check - so the seeded test database never has anything pending
 * review. These tests cover the parts of the page that are always reachable
 * regardless of queue contents: the empty-queue state, getting to the page
 * via the sidebar nav link (as opposed to a direct URL, which exercises a
 * different code path in ModMenuItemLeft's click handler), and the help
 * panel toggle, none of which had any e2e coverage before.
 */

const { test, expect } = require('./fixtures')
const { timeouts, environment } = require('./config')
const { loginViaModTools } = require('./utils/user')

const MODTOOLS_URL = environment.modtoolsBaseUrl

// Helper: dismiss any overlay modals that block interaction.
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

// Helper: navigate to the Chat Review page directly and wait for the initial
// review-queue fetch to settle.
async function gotoChatReview(page) {
  const errors = []
  page.on('pageerror', (error) => {
    errors.push(error.message)
  })

  await page
    .goto(`${MODTOOLS_URL}/chats/review`, {
      timeout: timeouts.navigation.initial,
      waitUntil: 'domcontentloaded',
    })
    .catch((e) => {
      if (!e.message.includes('ERR_ABORTED')) throw e
    })

  await page
    .waitForURL(`${MODTOOLS_URL}/chats/review**`, {
      timeout: timeouts.navigation.slowPage,
    })
    .catch(() => {})

  // Wait for the loading spinner(s) to disappear - confirms the onMounted
  // fetchReviewChatsMT call has completed.
  const spinner = page.locator('.spinner-border').first()
  if (
    await spinner
      .isVisible({ timeout: timeouts.ui.appearance })
      .catch(() => false)
  ) {
    await expect(spinner).not.toBeVisible({
      timeout: timeouts.navigation.slowPage,
    })
  }

  await dismissAllModals(page)
  return errors
}

test.describe('ModTools Chat Review', () => {
  test('empty review queue shows no messages, no errors, and no Delete All button', async ({
    page,
    testEnv,
  }) => {
    await loginViaModTools(page, testEnv.mod.email)
    const errors = await gotoChatReview(page)

    // The fixtures never seed a chat_messages row with reviewrequired=1, so
    // the queue is reliably empty - no ModChatReview card should render, and
    // the "Delete All" bulk-action button (which only shows for >1 visible
    // message) must stay hidden rather than throwing on an empty list.
    await expect(page.getByRole('button', { name: 'Delete All' })).toHaveCount(
      0
    )
    await expect(
      page.getByRole('button', { name: 'Approve - Not Spam' })
    ).toHaveCount(0)

    await assertNoErrors(page)
    expect(errors).toHaveLength(0)
  })

  test('help panel is collapsed by default, expands with full guidance, and collapses again', async ({
    page,
    testEnv,
  }) => {
    await loginViaModTools(page, testEnv.mod.email)
    await gotoChatReview(page)

    const helpToggle = page.getByRole('button', { name: 'Help', exact: true })
    const hideHelpButton = page.getByRole('button', { name: 'Hide Help' })
    const guidanceText = page.getByText(/worry words/i)
    const wikiLink = page.getByRole('link', { name: 'the wiki' })

    // Collapsed by default (ModHelpChatReview calls hide() on mount).
    await expect(helpToggle).toBeVisible({ timeout: timeouts.ui.appearance })
    await expect(hideHelpButton).toHaveCount(0)
    await expect(guidanceText).toHaveCount(0)

    // Expand.
    await helpToggle.click()
    await expect(hideHelpButton).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    await expect(guidanceText).toBeVisible()
    await expect(page.getByText(/Quicker Chat Review/i).first()).toBeVisible()
    await expect(page.getByText(/Add Moderator message/i)).toBeVisible()
    await expect(wikiLink).toHaveAttribute(
      'href',
      'https://wiki.ilovefreegle.org/Spammers#Chat_Review'
    )
    await expect(helpToggle).toHaveCount(0)

    // Collapse again.
    await hideHelpButton.click()
    await expect(helpToggle).toBeVisible({ timeout: timeouts.ui.appearance })
    await expect(hideHelpButton).toHaveCount(0)
    await expect(guidanceText).toHaveCount(0)

    await assertNoErrors(page)
  })

  test('sidebar nav link reaches Chat Review, marks it active, and re-clicking the active link does not navigate away', async ({
    page,
    testEnv,
  }) => {
    await loginViaModTools(page, testEnv.mod.email)

    const errors = []
    page.on('pageerror', (error) => {
      errors.push(error.message)
    })

    // Start somewhere else in ModTools so the nav click is a real route
    // change (router.push branch of ModMenuItemLeft's click handler).
    await page
      .goto(`${MODTOOLS_URL}/chats`, {
        timeout: timeouts.navigation.initial,
        waitUntil: 'domcontentloaded',
      })
      .catch((e) => {
        if (!e.message.includes('ERR_ABORTED')) throw e
      })
    await dismissAllModals(page)

    const chatReviewLink = page.getByRole('link', {
      name: 'Chat Review',
      exact: true,
    })
    await chatReviewLink.waitFor({
      state: 'visible',
      timeout: timeouts.ui.appearance,
    })
    await chatReviewLink.click()

    await page.waitForURL(`${MODTOOLS_URL}/chats/review**`, {
      timeout: timeouts.navigation.default,
    })

    // The nav item's wrapping div (ModMenuItemLeft's root, class "ps-1")
    // picks up an "active" class once the route matches its link (getClass
    // computed).
    const navItem = page.locator('div.ps-1', { has: chatReviewLink })
    await expect(navItem).toHaveClass(/active/)

    // Clicking the link again while already on that route takes the
    // "click on current route" branch (checkWork, no router.push) rather
    // than navigating away.
    await chatReviewLink.click()
    await expect(page).toHaveURL(/\/chats\/review\/?(\?.*)?$/)

    await dismissAllModals(page)
    await assertNoErrors(page)
    expect(errors).toHaveLength(0)
  })
})
