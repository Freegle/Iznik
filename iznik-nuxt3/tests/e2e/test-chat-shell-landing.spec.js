// @ts-check
// The chat shell as the front door: landing is a chat with Freegle, the three actions
// are always to hand, taps move the conversation, and classic Freegle is one tap away.
const { test, expect } = require('./fixtures')
const { timeouts } = require('./config')

const CHAT_COOKIE = (baseURL) => ({
  name: 'freegle-ui-mode',
  value: 'chat',
  domain: new URL(baseURL).hostname,
  path: '/',
})

test.describe('Chat shell landing', () => {
  // page is asked for first so the page fixture's sign-out (which puts the classic
  // cookie back) has run before the chat choice is set.
  test.beforeEach(async ({ page, context, baseURL }) => {
    await page.context().clearCookies()
    await context.addCookies([CHAT_COOKIE(baseURL)])
  })

  test('lands in the Freegle chat with the opening line, actions and composer', async ({
    page,
  }) => {
    await page.goto('/', { timeout: timeouts.navigation.initial })
    await expect(page).toHaveTitle("Don't throw it away, give it away!")
    const shell = page.getByTestId('chat-shell')
    await expect(shell).toBeVisible()
    await expect(
      shell.getByText('Hello. Got something to give away, or after something?')
    ).toBeVisible()
    await expect(page.getByTestId('chip-give')).toBeVisible()
    await expect(page.getByTestId('chip-ask')).toBeVisible()
    await expect(page.getByTestId('chip-nearby')).toBeVisible()
    await expect(page.getByTestId('composer-input')).toBeVisible()
    // No classic navbar over the shell.
    await expect(page.locator('header nav.navbar')).toHaveCount(0)
  })

  test('tapping Give starts the give flow and asks for a photo; cancel returns to the hub', async ({
    page,
  }) => {
    await page.goto('/', { timeout: timeouts.navigation.initial })
    await page.getByTestId('chip-give').click()
    await expect(page.getByTestId('flow-progress')).toContainText('Giving', {
      timeout: timeouts.ui.appearance,
    })
    await expect(page.getByTestId('chip-no_photo')).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    await expect(page.getByTestId('chip-add_photo')).toBeVisible()
    await page.getByTestId('chip-no_photo').click()
    // The next question is what the item is; the member's own tap is echoed as a bubble.
    await expect(page.getByTestId('flow-progress')).toContainText('2 of 7', {
      timeout: timeouts.ui.appearance,
    })
    await expect(
      page.locator('[data-testid="bubble-me"]').last()
    ).toContainText('No photo')
    await page.getByTestId('flow-cancel').click()
    await expect(page.getByTestId('flow-progress')).toHaveCount(0, {
      timeout: timeouts.ui.appearance,
    })
    await expect(page.getByTestId('chip-give')).toBeVisible()
  })

  test('typing what you have fills the item and skips to the next open question', async ({
    page,
  }) => {
    await page.goto('/', { timeout: timeouts.navigation.initial })
    await page.getByTestId('chip-give').click()
    await page.getByTestId('chip-no_photo').click()
    await expect(page.getByTestId('flow-progress')).toContainText('2 of 7', {
      timeout: timeouts.ui.appearance,
    })
    await page.getByTestId('composer-input').fill('grey three seater sofa')
    await page.getByTestId('composer-send').click()
    await expect(page.getByTestId('flow-progress')).toContainText('sofa', {
      timeout: timeouts.ui.appearance,
    })
    await expect(page.getByTestId('flow-progress')).toContainText('3 of 7')
    await expect(page.getByTestId('chip-skip')).toHaveCount(0) // no real photo, so the description is required
  })

  test('the desktop frame is a phone-sized column with footer links', async ({
    page,
  }) => {
    await page.setViewportSize({ width: 1440, height: 900 })
    await page.goto('/', { timeout: timeouts.navigation.initial })
    const box = await page.getByTestId('chat-shell').boundingBox()
    expect(box.width).toBeLessThan(420)
    expect(box.width).toBeGreaterThan(360)
    await expect(page.locator('.shell-footer')).toBeVisible()
    await expect(
      page.locator('.shell-footer').getByText('Privacy')
    ).toBeVisible()
  })

  test('the phone view fills the screen', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 })
    await page.goto('/', { timeout: timeouts.navigation.initial })
    const box = await page.getByTestId('chat-shell').boundingBox()
    expect(Math.round(box.width)).toBe(390)
    await expect(page.locator('.shell-footer')).toBeHidden()
  })

  test('Classic Freegle in the menu switches to the classic landing and remembers it', async ({
    page,
  }) => {
    await page.goto('/', { timeout: timeouts.navigation.initial })
    await page.locator('.shell-menu button').first().click()
    await page.getByTestId('menu-classic').click()
    await expect(page).toHaveURL(/\/browse/, {
      timeout: timeouts.navigation.initial,
    })
    await page.goto('/', { timeout: timeouts.navigation.initial })
    await expect(page.locator('.action-btn:has-text("Give")')).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    await expect(page.getByTestId('chat-shell')).toHaveCount(0)

    // And the classic front page offers the way back.
    await page.getByTestId('switch-to-chat').click()
    await expect(page.getByTestId('chat-shell')).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    await expect(page.getByTestId('chip-give')).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
  })

  test('the server renders chat and classic for back-to-back requests, whichever came first', async ({
    request,
    baseURL,
  }) => {
    // The production image caches server-rendered pages for a minute. The cache is keyed
    // by the mode cookie, so the first visitor in a minute must not decide for the next.
    const host = new URL(baseURL).host
    for (const [mode, marker] of [
      ['chat', 'data-testid="chat-shell"'],
      ['classic', 'action-btn action-btn--give'],
      ['chat', 'data-testid="chat-shell"'],
    ]) {
      const res = await request.get('/', {
        headers: { Cookie: `freegle-ui-mode=${mode}`, Host: host },
      })
      expect(res.status()).toBe(200)
      const html = await res.text()
      expect(html, `${mode} request should render ${mode}`).toContain(marker)
    }
  })
})
