// @ts-check
// A visitor gives something away entirely inside the chat: no photo, a typed item and
// description, a postcode, an email, the confirm card, Post. The existing compose
// store does the posting, so the post is real and the account is created as today.
const { test, expect } = require('./fixtures')
const { timeouts, environment } = require('./config')

const CHAT_COOKIE = (baseURL) => ({
  name: 'freegle-ui-mode',
  value: 'chat',
  domain: new URL(baseURL).hostname,
  path: '/',
})

test.describe('Chat shell: give something away', () => {
  test.beforeEach(async ({ context, baseURL }) => {
    await context.clearCookies()
    await context.addCookies([CHAT_COOKIE(baseURL)])
  })

  test('a new member posts an offer through the conversation', async ({
    page,
    testEmail,
  }) => {
    test.setTimeout(timeouts.background)
    await page.goto('/', { timeout: timeouts.navigation.initial })
    await page.getByTestId('chip-give').click()
    await page.getByTestId('chip-no_photo').click()
    await expect(page.getByTestId('flow-progress')).toContainText('2 of 7', {
      timeout: timeouts.ui.appearance,
    })

    // What is it.
    await page.getByTestId('composer-input').fill('Chat shell test sofa')
    await page.getByTestId('composer-send').click()
    await expect(page.getByTestId('flow-progress')).toContainText('3 of 7', {
      timeout: timeouts.ui.appearance,
    })

    // Anything people should know. No photo, so this is required and Skip is not offered.
    await expect(page.getByTestId('chip-skip')).toHaveCount(0)
    await page
      .getByTestId('composer-input')
      .fill('Grey three seater, good condition, collection from the flat')
    await page.getByTestId('composer-send').click()

    // Where: the postcode picker appears inside the chat.
    const pc = page.locator(
      '[data-testid="postcode-input"] .pcinp, [data-testid="postcode-input"] input'
    )
    await pc
      .first()
      .waitFor({ state: 'visible', timeout: timeouts.ui.appearance })
    await pc.first().fill(environment.postcode)
    // Picking the postcode confirms it and the flow moves on to email for a visitor.
    const email = page.locator(
      '[data-testid="email-input"] input[type="email"]'
    )
    await email.waitFor({ state: 'visible', timeout: timeouts.api.default })
    await email.fill(testEmail)
    await page
      .locator('[data-testid="email-input"] button[type="submit"]')
      .click()

    // Confirm card with what will go up, then Post.
    await expect(page.getByTestId('confirm-card')).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    await expect(page.getByTestId('confirm-item')).toContainText(
      'Chat shell test sofa'
    )
    await expect(page.getByTestId('confirm-description')).toContainText(
      'Grey three seater'
    )
    await page.getByTestId('chip-post').click()

    // Posted: Freegle says so and offers the post's chat.
    await expect(page.getByTestId('chip-yourposts')).toBeVisible({
      timeout: timeouts.background,
    })
    await expect(
      page.locator('[data-testid="bubble-freegle"]').last()
    ).toContainText(/freeglers/i)

    // The account is real: Your posts shows the new post.
    await page.getByTestId('chip-yourposts').click()
    await expect(page).toHaveURL(/\/chats\/posts/, {
      timeout: timeouts.navigation.initial,
    })
    await expect(page.getByTestId('yourposts-transcript')).toContainText(
      'Chat shell test sofa',
      { timeout: timeouts.background }
    )
  })
})
