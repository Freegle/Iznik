// @ts-check
/**
 * Tests for ModTools pending messages display and notification counts.
 */

const { test, expect } = require('./fixtures')
const { timeouts, environment } = require('./config')
const { loginViaModTools } = require('./utils/user')

const MODTOOLS_URL = environment.modtoolsBaseUrl

// Helper: dismiss any overlay modals  that block interaction.
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

// Helper: select a group with pending messages.
async function selectGroupWithPendingMessages(page, groupSelect) {
  let targetGroupValue = null
  await expect
    .poll(
      async () => {
        const options = await groupSelect.locator('option').all()
        for (const option of options) {
          const text = await option.textContent()
          const value = await option.getAttribute('value')
          if (value && value !== '0' && /\(\d+\)/.test(text)) {
            targetGroupValue = value
            return true
          }
        }
        return false
      },
      {
        message: 'Waiting for group options with pending message counts',
        timeout: timeouts.navigation.slowPage,
      }
    )
    .toBe(true)
  await groupSelect.selectOption(targetGroupValue)
  return targetGroupValue
}

test.describe('ModTools Pending Messages', () => {
  test('pending messages show text content, not "This message is blank"', async ({
    page,
    testEnv,
    testEmail,
    postMessage,
    withdrawPost,
  }) => {
    // Issue #29: pending messages display "This message is blank"
    // Post a message first so there is guaranteed pending content to view.
    const item = `test-pending-blank-${Date.now()}`
    const posted = await postMessage({
      type: 'OFFER',
      item,
      description: 'Test item for pending messages blank check',
      email: testEmail,
    })
    expect(posted.id).toBeTruthy()

    await loginViaModTools(page, testEnv.mod.email)

    await page.goto(`${MODTOOLS_URL}/messages/pending`, {
      timeout: timeouts.navigation.initial,
    })

    const groupSelect = page.locator('#communitieslist')
    await expect(groupSelect).toBeVisible({
      timeout: timeouts.navigation.slowPage,
    })

    await dismissAllModals(page)

    // Select a group with pending messages
    await selectGroupWithPendingMessages(page, groupSelect)

    // Wait for message cards to load
    const messageCards = page.locator('.card')
    await expect(messageCards.first()).toBeVisible({
      timeout: timeouts.navigation.slowPage,
    })

    // Verify no message shows "This message is blank"
    const bodyText = await page.textContent('body')
    expect(bodyText).not.toContain('This message is blank')

    // Cleanup
    await withdrawPost({ item: posted.item })
  })

  test('pending messages show correct group membership, not "not on any community"', async ({
    page,
    testEnv,
    testEmail,
    postMessage,
    withdrawPost,
  }) => {
    // Issue #19: member info shows "not on any community"
    // Post a message first so there is guaranteed pending content to view.
    const item = `test-pending-membership-${Date.now()}`
    const posted = await postMessage({
      type: 'OFFER',
      item,
      description: 'Test item for pending messages membership check',
      email: testEmail,
    })
    expect(posted.id).toBeTruthy()

    await loginViaModTools(page, testEnv.mod.email)

    await page.goto(`${MODTOOLS_URL}/messages/pending`, {
      timeout: timeouts.navigation.initial,
    })

    const groupSelect = page.locator('#communitieslist')
    await expect(groupSelect).toBeVisible({
      timeout: timeouts.navigation.slowPage,
    })

    await dismissAllModals(page)

    // Select a group with pending messages
    await selectGroupWithPendingMessages(page, groupSelect)

    // Wait for message cards to load
    const messageCards = page.locator('.card')
    await expect(messageCards.first()).toBeVisible({
      timeout: timeouts.navigation.slowPage,
    })

    // The member info area should not say "not on any community"
    const bodyText = await page.textContent('body')
    expect(bodyText).not.toContain('not on any community')

    // Cleanup
    await withdrawPost({ item: posted.item })
  })

  test('notification count badge is reasonable', async ({ page, testEnv }) => {
    // Issue #17: notification count is wildly wrong
    await loginViaModTools(page, testEnv.mod.email)

    await page.goto(`${MODTOOLS_URL}/modtools/dashboard`, {
      timeout: timeouts.navigation.initial,
    })

    await page.waitForLoadState('domcontentloaded', {
      timeout: timeouts.navigation.default,
    })

    await dismissAllModals(page)

    // Look for notification badges in the navbar
    const badges = page.locator(
      '.badge, [class*="notification"] .badge, nav .badge'
    )

    if (
      await badges
        .first()
        .isVisible({ timeout: timeouts.ui.appearance })
        .catch(() => false)
    ) {
      const count = await badges.count()
      for (let i = 0; i < count; i++) {
        const text = await badges.nth(i).textContent()
        const num = parseInt(text.trim(), 10)
        if (!isNaN(num)) {
          // Notification counts should be reasonable (not in the millions)
          expect(num).toBeLessThan(10000)
          expect(num).toBeGreaterThanOrEqual(0)
        }
      }
    }

    await assertNoErrors(page)
  })

  // Discourse 10321: a poster can leave the community after posting, and the post
  // stays pending. A standard message that approves and unmoderates them has no
  // membership to change, and the approve must still go ahead.
  test('a standard message approves a post whose poster has left', async ({
    page,
    testEnv,
    testEmail,
  }) => {
    const API_V2 = environment.apiV2BaseUrl
    const groupid = Number(testEnv.group.id)
    const stamp = Date.now()
    const title = `PW Approve and unmod ${stamp}`

    // A config holding the standard message, used by the mod on the test group.
    const loginResp = await page.request.post(`${API_V2}/session`, {
      data: { email: testEnv.mod.email, password: 'freegle' },
    })
    const { jwt } = await loginResp.json()
    expect(jwt).toBeTruthy()
    const headers = { Authorization: jwt }

    const cfgResp = await page.request.post(`${API_V2}/modtools/modconfig`, {
      data: { name: `PW Poster left ${stamp}` },
      headers,
    })
    expect(cfgResp.ok()).toBeTruthy()
    const configid = (await cfgResp.json()).id
    expect(configid).toBeTruthy()

    const stdmsgResp = await page.request.post(`${API_V2}/modtools/stdmsg`, {
      data: {
        configid,
        title,
        action: 'Approve',
        body: 'Thanks for your post, it has been approved and will go out shortly.',
        newmodstatus: 'DEFAULT',
      },
      headers,
    })
    expect(stdmsgResp.ok()).toBeTruthy()

    const modResp = await page.request.patch(`${API_V2}/memberships`, {
      data: { userid: Number(testEnv.mod.id), groupid, configid },
      headers,
    })
    expect(modResp.ok()).toBeTruthy()

    // A new member posts on the test group, then leaves it.
    const submitResp = await page.request.put(`${API_V2}/message/submit`, {
      data: {
        type: 'Wanted',
        item: `pw-poster-left-${stamp}`,
        textbody: 'Looking for one of these, please.',
        groupid,
        email: testEmail,
      },
    })
    expect(submitResp.ok()).toBeTruthy()
    const submitted = await submitResp.json()
    const msgid = submitted.id
    expect(msgid).toBeTruthy()
    expect(submitted.jwt).toBeTruthy()

    const leaveResp = await page.request.delete(`${API_V2}/memberships`, {
      data: { groupid },
      headers: { Authorization: submitted.jwt },
    })
    expect(leaveResp.ok()).toBeTruthy()

    // The pending queue only shows a post once the content check has seen it.
    const statusUrl = process.env.STATUS_API_URL || 'http://localhost:8081'
    const ccResp = await page.request.post(
      `${statusUrl}/api/utility/run-contentcheck`
    )
    expect(ccResp.ok()).toBeTruthy()

    await loginViaModTools(page, testEnv.mod.email)
    await page.goto(`${MODTOOLS_URL}/messages/pending`, {
      timeout: timeouts.navigation.initial,
    })

    const groupSelect = page.locator('#communitieslist')
    await expect(groupSelect).toBeVisible({
      timeout: timeouts.navigation.slowPage,
    })
    await dismissAllModals(page)
    await expect
      .poll(
        async () =>
          (await groupSelect.locator(`option[value="${groupid}"]`).count()) > 0,
        { timeout: timeouts.navigation.slowPage }
      )
      .toBe(true)
    await groupSelect.selectOption(String(groupid))

    const card = page
      .locator('.card')
      .filter({ hasText: new RegExp(`#${msgid}(?!\\d)`) })
      .first()
    await expect(card).toBeVisible({ timeout: timeouts.navigation.slowPage })

    const stdmsgButton = card.locator(`button:has-text("${title}")`).first()
    await expect(stdmsgButton).toBeVisible({ timeout: timeouts.ui.appearance })
    await dismissAllModals(page)
    await stdmsgButton.click()

    // The message box says there is no membership to change, and does not offer to.
    const modal = page.locator('#stdmsgmodal')
    await expect(modal.locator('[data-test="stdmsg-not-member"]')).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    await expect(modal).not.toContainText('Change moderation status to')

    const membershipChanges = []
    page.on('request', (r) => {
      if (r.method() === 'PATCH' && r.url().includes('/memberships')) {
        membershipChanges.push(r.url())
      }
    })

    const isApprove = (r) => {
      if (r.request().method() !== 'POST' || !/\/message(\?|$)/.test(r.url())) {
        return false
      }
      try {
        return r.request().postDataJSON()?.action === 'Approve'
      } catch (e) {
        return false
      }
    }
    const approvePromise = page.waitForResponse(isApprove, {
      timeout: timeouts.api.slowApi,
    })
    await modal.locator('button:has-text("Send and Approve")').click()
    const approveResp = await approvePromise
    expect(approveResp.status()).toBe(200)

    await expect(modal).not.toBeVisible({ timeout: timeouts.api.default })
    expect(membershipChanges).toHaveLength(0)
    await assertNoErrors(page)

    const afterResp = await page.request.get(`${API_V2}/message/${msgid}`, {
      headers,
    })
    const after = await afterResp.json()
    expect(after.groups.find((g) => g.groupid === groupid)?.collection).toBe(
      'Approved'
    )
  })
})
