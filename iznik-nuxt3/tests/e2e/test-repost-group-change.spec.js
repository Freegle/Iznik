/**
 * Repost Group Change Test
 *
 * Tests that when a rejected post is edited and resent, the user can
 * change the group via the dropdown and the selection persists through
 * the posting flow.
 *
 * Bug: Previously, selecting a different group during repost kept
 * reverting to the original group.
 *
 * Isolation: Creates a fresh message within the test (no pre-created
 * testEnv.rejected.offer) to avoid stale state between CI runs.
 */

const { test, expect } = require('./fixtures')
const { timeouts, environment } = require('./config')
const { loginViaHomepage } = require('./utils/user')

const API_V2 = environment.apiV2BaseUrl

test.describe('Repost Group Change', () => {
  test('changing group dropdown during repost of rejected message should persist', async ({
    page,
    testEnv,
    postMessage,
  }) => {
    console.log('=== REPOST GROUP CHANGE TEST ===')
    console.log(`Mod: ${testEnv.mod.email}`)
    console.log(
      `Group1: ${testEnv.group.name} (${testEnv.group.id}), Group2: ${testEnv.group2.name} (${testEnv.group2.id})`
    )

    // Step 0: Get mod JWT and set testEnv.user to MODERATED so the fresh
    // message goes to PENDING (and can be rejected via API).
    const loginResp = await page.request.post(`${API_V2}/session`, {
      data: { email: testEnv.mod.email, password: 'freegle' },
    })
    const loginData = await loginResp.json()
    expect(loginData.jwt).toBeTruthy()
    const modJwt = loginData.jwt

    // Set testEnv.user's posting status to MODERATED in BOTH groups.
    // The postMessage postcode should auto-select group1 (which has a polygon),
    // but set MODERATED on both to handle any edge cases where group2 is selected.
    // This ensures postMessage creates a PENDING message that can be rejected.
    for (const gid of [testEnv.group.id, testEnv.group2.id]) {
      const moderateResp = await page.request.patch(`${API_V2}/memberships`, {
        data: {
          userid: Number(testEnv.user.id),
          groupid: Number(gid),
          ourPostingStatus: 'MODERATED',
        },
        headers: { Authorization: modJwt },
      })
      console.log(`Set user MODERATED on group ${gid}: ${moderateResp.status()}`)
      expect(moderateResp.ok()).toBeTruthy()
    }

    // Step 1: Login as testEnv.user before posting. testEnv.user is a
    // pre-existing account; filling a registered email on whoami without being
    // logged in fails to navigate to /myposts. Login also ensures both
    // testEnv.group and testEnv.group2 appear in the whereami dropdown via
    // myGroups (group2 has no polygon so only appears for logged-in members).
    console.log('\n--- Step 1: Login as testEnv.user ---')
    await loginViaHomepage(page, testEnv.user.email, 'freegle')

    // Post a fresh offer as testEnv.user.
    console.log('\n--- Step 1b: Post fresh offer as testEnv.user ---')
    const posted = await postMessage({
      type: 'OFFER',
      item: `RepostTest ${Date.now()}`,
      description: 'For repost group change test',
      email: testEnv.user.email,
    })
    console.log(`Posted: id=${posted.id}, item=${posted.item}`)

    // Step 1c: Get the actual group the message was posted to, then reject it.
    console.log('\n--- Step 1c: Get message group and reject via API ---')

    let msgData = null
    await expect
      .poll(
        async () => {
          const resp = await page.request.get(`${API_V2}/message/${posted.id}`, {
            headers: { Authorization: modJwt },
          })
          msgData = await resp.json()
          return msgData?.groups?.length > 0
        },
        {
          message: 'Waiting for message to have groups populated',
          timeout: timeouts.api.slowApi,
          intervals: [500, 500, 1000, 1000, 2000],
        }
      )
      .toBe(true)

    const actualGroupId = msgData?.groups?.[0]?.groupid ?? testEnv.group.id
    console.log(`Message actual groupid: ${actualGroupId}`)

    // Reject with a subject so the message moves to collection='Rejected'
    // (not deleted), enabling the "Edit & Resend" button on My Posts.
    const rejectResp = await page.request.post(`${API_V2}/message`, {
      data: {
        action: 'Reject',
        id: Number(posted.id),
        groupid: Number(actualGroupId),
        subject: 'Test rejection for repost group change test',
      },
      headers: { Authorization: modJwt },
    })
    console.log(`Reject status: ${rejectResp.status()}`)
    expect(rejectResp.ok()).toBeTruthy()
    console.log('Message rejected')

    // Step 2: Navigate to My Posts. postMessage already left us here;
    // reload to pick up the updated Rejected status.
    console.log('\n--- Step 2: Reload My Posts and find rejected message ---')
    await page.goto('/myposts', {
      timeout: timeouts.navigation.default,
      waitUntil: 'domcontentloaded',
    })

    // Step 3: Wait for the specific rejected message card (by ID) and its
    // "Edit & Resend" button.
    const messageCard = page.locator(
      `.message-card[data-message-id="${posted.id}"]`
    )
    const editResendBtn = messageCard
      .locator('button:has-text("Edit & Resend")')
      .first()
    await expect(editResendBtn).toBeVisible({ timeout: timeouts.ui.appearance })
    console.log('Found rejected message with Edit & Resend button')
    await editResendBtn.click()
    console.log('Clicked Edit & Resend')

    // Step 4: Should navigate to /give with pre-filled data.
    await expect(page).toHaveURL(/\/give/, {
      timeout: timeouts.navigation.default,
    })

    // Click Next to go to whereami page.
    const nextToWhereami = page.locator('.next-btn:has-text("Next")').first()
    await expect(nextToWhereami).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    await nextToWhereami.click()

    // Step 5: On whereami page — verify group dropdown.
    await expect(page).toHaveURL(/whereami/, {
      timeout: timeouts.navigation.default,
    })
    console.log('On whereami page')

    // Wait for the group dropdown to appear.
    const groupDropdown = page.locator('select').first()
    await expect(groupDropdown).toBeVisible({ timeout: timeouts.ui.appearance })

    // Get the initial selected value and all options.
    const initialGroupId = await groupDropdown.inputValue()
    const optionElements = groupDropdown.locator('option')
    const optionCount = await optionElements.count()
    console.log(
      `Group dropdown: ${optionCount} options, initial selection: ${initialGroupId}`
    )

    // The repost should pre-select the message's original group (actualGroupId).
    expect(initialGroupId).toBe(String(actualGroupId))
    console.log('Correct initial group pre-selected from rejected message')

    // We need at least 2 groups to test changing. testEnv.user is a member
    // of both testEnv groups, so both appear in the dropdown when logged in.
    expect(optionCount).toBeGreaterThanOrEqual(2)

    // Get all option values.
    const allValues = await optionElements.evaluateAll((opts) =>
      opts.map((o) => ({ value: o.value, text: o.textContent }))
    )
    console.log('Available groups:', JSON.stringify(allValues))

    // Step 6: Select a different group.
    const targetGroup = allValues.find((v) => v.value !== initialGroupId)
    expect(targetGroup).toBeTruthy()
    console.log(
      `Changing from ${initialGroupId} to ${targetGroup.value} (${targetGroup.text})`
    )

    await groupDropdown.selectOption(targetGroup.value)

    // Verify the change took effect immediately.
    await expect(groupDropdown).toHaveValue(targetGroup.value)
    console.log('Group changed successfully')

    // Step 7: Wait for any async operations (ComposeGroup.onMounted refetches
    // postcode and user, PostCode.onMounted re-resolves the postcode) that
    // could reset the group. Poll the dropdown value to catch any revert.
    await expect
      .poll(
        async () => {
          return await groupDropdown.inputValue()
        },
        {
          message:
            'Group dropdown reverted to original — the bug is reproduced',
          intervals: [200, 200, 200, 200, 200, 500, 500, 500],
          timeout: 3000,
        }
      )
      .toBe(targetGroup.value)

    console.log('Group selection persisted after async operations')

    // Step 8: Navigate forward to options page and back to verify persistence.
    const nextToOptions = page.locator('.next-btn:has-text("Next")').first()
    await expect(nextToOptions).toBeVisible({ timeout: timeouts.ui.appearance })
    await nextToOptions.click()

    await expect(page).toHaveURL(/\/give\/options/, {
      timeout: timeouts.navigation.default,
    })
    console.log('On options page')

    // Navigate back to whereami.
    await page.goBack()
    await expect(page).toHaveURL(/whereami/, {
      timeout: timeouts.navigation.default,
    })

    // Verify the group is still the one we selected.
    const groupAfterNav = page.locator('select').first()
    await expect(groupAfterNav).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    await expect(groupAfterNav).toHaveValue(targetGroup.value)
    console.log('Group selection persisted after navigation')
  })
})
