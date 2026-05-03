const { test, expect } = require('./fixtures')
const { timeouts } = require('./config')
const { loginViaHomepage } = require('./utils/user')

test.describe('Mobile Chat Navbar', () => {
  test('shows mobile navbar and chat name at xs viewport', async ({
    page,
    testEnv,
    waitForNuxtPageLoad,
  }) => {
    // Set xs viewport before navigation so showMobileNavbar is true when the
    // chat page first loads — resizing after load can miss the initial render.
    await page.setViewportSize({ width: 375, height: 667 })

    // testEnv.user is created by create-test-env.php with password 'freegle'
    await loginViaHomepage(page, testEnv.user.email, 'freegle')

    await page.gotoAndVerify(`/chats/${testEnv.chats.user2user}`)
    await waitForNuxtPageLoad({ timeout: timeouts.navigation.default })

    // ChatMobileNavbar teleports into #chat-navbar-portal in app.vue
    await expect(page.locator('.nav-back-btn')).toBeVisible({
      timeout: timeouts.api.default,
    })
    await expect(page.locator('.chat-name-area')).toBeVisible({
      timeout: timeouts.api.default,
    })
  })
})
