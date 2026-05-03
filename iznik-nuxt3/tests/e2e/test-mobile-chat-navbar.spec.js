const { test, expect } = require('./fixtures')
const { timeouts } = require('./config')
const { loginViaHomepage } = require('./utils/user')

test.describe('Mobile Chat Navbar', () => {
  test('shows mobile navbar and chat name at xs viewport', async ({
    page,
    testEnv,
    waitForNuxtPageLoad,
  }) => {
    await loginViaHomepage(page, testEnv.user.email)

    await page.gotoAndVerify(`/chats/${testEnv.chats.user2user}`)
    await waitForNuxtPageLoad({ timeout: timeouts.navigation.default })

    // Resize to xs breakpoint — ChatMobileNavbar renders only at width < 576px
    await page.setViewportSize({ width: 375, height: 667 })

    // ChatMobileNavbar teleports into #chat-navbar-portal in app.vue
    await expect(page.locator('.nav-back-btn')).toBeVisible({
      timeout: timeouts.api.default,
    })
    await expect(page.locator('.chat-name-area')).toBeVisible({
      timeout: timeouts.api.default,
    })
  })
})
