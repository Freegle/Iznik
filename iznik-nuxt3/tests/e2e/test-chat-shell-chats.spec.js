// @ts-check
// The chat list and a member chat inside the shell, for a signed-in member.
const { test, expect } = require('./fixtures')
const { timeouts, environment } = require('./config')

const CHAT_COOKIE = (baseURL) => ({
  name: 'freegle-ui-mode',
  value: 'chat',
  domain: new URL(baseURL).hostname,
  path: '/',
})

// Sign in on the classic browse page, which opens the login box itself, then switch
// the member to the chat shell: the same cookie name, so adding it replaces the
// classic choice.
async function signInThenChat(page, context, baseURL, email) {
  await page.goto('/browse', { timeout: timeouts.navigation.initial })
  const modal = page.locator('#loginModal')
  await modal
    .first()
    .waitFor({ state: 'visible', timeout: timeouts.ui.appearance })
  const switchToLogin = page.locator(
    '#loginModal .test-already-a-freegler, #loginModal :text("Log in")'
  )
  if (
    await switchToLogin
      .first()
      .isVisible()
      .catch(() => false)
  ) {
    await switchToLogin
      .first()
      .click()
      .catch(() => {})
  }
  await page.locator('#loginModal input[type="email"]').first().fill(email)
  await page
    .locator('#loginModal input[type="password"]')
    .first()
    .fill(environment.unmodded_password)
  await page
    .locator(
      '#loginform button[type="submit"], #loginModal button:has-text("Log in")'
    )
    .first()
    .click()
  await modal
    .first()
    .waitFor({ state: 'hidden', timeout: timeouts.ui.appearance })
  await context.addCookies([CHAT_COOKIE(baseURL)])
}

test.describe('Chat shell: chats', () => {
  test('a signed-in member lands on the chat list with Freegle pinned and filters', async ({
    page,
    context,
    baseURL,
    existingTestEmail,
  }) => {
    test.setTimeout(timeouts.background)
    await signInThenChat(page, context, baseURL, existingTestEmail)
    await page.goto('/chats', { timeout: timeouts.navigation.initial })
    await expect(page.getByTestId('row-freegle')).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    await expect(page.getByTestId('row-chitchat')).toBeVisible()
    await expect(page.getByTestId('filter-all')).toBeVisible()
    await expect(page.getByTestId('filter-unread')).toBeVisible()
    await expect(page.getByTestId('filter-people')).toBeVisible()
    await page.getByTestId('filter-people').click()
    await expect(page.getByTestId('row-freegle')).toHaveCount(0)
    await page.getByTestId('filter-all').click()
    await page.getByTestId('row-freegle').click()
    await expect(page.getByTestId('chat-transcript')).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    await expect(page.getByTestId('shell-back')).toBeVisible()
  })

  test('ChitChat opens as a group chat with a composer', async ({
    page,
    context,
    baseURL,
    existingTestEmail,
  }) => {
    test.setTimeout(timeouts.background)
    await signInThenChat(page, context, baseURL, existingTestEmail)
    await page.goto('/chitchat', { timeout: timeouts.navigation.initial })
    await expect(page.getByTestId('chitchat-stream')).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    await expect(page.getByTestId('composer-input')).toBeVisible()
    await expect(page.getByTestId('shell-header')).toContainText('ChitChat')
  })
})
