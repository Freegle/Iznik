/**
 * Message page and the remembered home page.
 *
 * The / route redirects logged-in users to their remembered home page
 * (miscStore 'lasthomepage', set by the browse/chitchat/myposts pages). A
 * member whose remembered page was ChitChat who opened a post from a digest
 * email and pressed Back would unwind to / and be dropped in front of the
 * public ChitChat composer — which is how item replies ("please may I be
 * considered for this?") end up posted publicly on ChitChat instead of sent
 * to the poster. The message page therefore claims the home page for Browse.
 */

const { test, expect } = require('./fixtures')
const { timeouts } = require('./config')

test.describe('Message page claims Browse as the home page', () => {
  test('back to / after viewing a message lands on Browse, not ChitChat', async ({
    page,
    postMessage,
    testEmail,
  }) => {
    const uniqueItem = `test-homepage-${Date.now()}`
    const result = await postMessage({
      type: 'OFFER',
      item: uniqueItem,
      description: 'Test item for home page redirect behaviour',
      email: testEmail,
    })
    expect(result.id).toBeTruthy()

    // Make ChitChat the remembered home page, and prove the remembering
    // works: without this control step the real assertion below would pass
    // trivially if lasthomepage persistence were broken altogether.
    await page.gotoAndVerify('/chitchat', { maxRetries: 1 })
    await page.gotoAndVerify('/', { maxRetries: 1 })
    await expect(page).toHaveURL(/\/chitchat/, {
      timeout: timeouts.navigation.default,
    })

    // Open the message page directly, as a digest email link does.
    await page.gotoAndVerify(`/message/${result.id}`, { maxRetries: 1 })

    // Unwinding to / must now land on Browse (item context), not ChitChat.
    await page.gotoAndVerify('/', { maxRetries: 1 })
    await expect(page).toHaveURL(/\/browse/, {
      timeout: timeouts.navigation.default,
    })
  })
})
