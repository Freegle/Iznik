/**
 * Reply Flow Tests - New User Registration (Tests 2.1, 2.2, 2.3)
 *
 * These tests cover the reply flow for new users who register during reply.
 * Each test creates unique users - can run in PARALLEL.
 */

const { test, expect } = require('./fixtures')

const { loginViaHomepage, logoutIfLoggedIn } = require('./utils/user')

test.describe('Reply Flow - New User Registration', () => {
  test('2.1 can register and reply from Message Page', async ({
    page,
    postMessage,
    testEmail,
    getTestEmail,
    replyToMessageWithSignup,
    withdrawPost,
  }) => {
    // Use the existing fixture which handles new user registration
    const uniqueItem = `test-newuser-msg-${Date.now()}`
    const result = await postMessage({
      type: 'OFFER',
      item: uniqueItem,
      description: 'Test item for new user registration reply',

      email: testEmail,
    })
    expect(result.id).toBeTruthy()

    // Clear session to simulate new user
    await logoutIfLoggedIn(page)

    // Use the fixture to reply with signup
    const replyEmail = getTestEmail('newuser')
    await replyToMessageWithSignup({
      messageId: result.id,
      itemName: result.item,
      email: replyEmail,
    })

    console.log('[Test] New user registration from message page successful')

    // Cleanup
    await logoutIfLoggedIn(page)
    await loginViaHomepage(page, testEmail)
    await withdrawPost({ item: result.item })
  })

})
