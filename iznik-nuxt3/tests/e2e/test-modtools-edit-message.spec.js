/**
 * ModTools Edit Message Tests
 * Tests editing a message's subject/title from the pending messages page.
 * Uses the pre-created pending message from create-test-env.php.
 */

const { test, expect } = require('./fixtures')
const { timeouts } = require('./config')
const { loginViaModTools } = require('./utils/user')

const { environment } = require('./config')
const MODTOOLS_URL = environment.modtoolsBaseUrl

async function dismissAllModals(page) {
  // Dismiss modals and wait for backdrop to be fully removed.
  // Vue may re-render backdrops, so retry until clear.
  for (let i = 0; i < 5; i++) {
    const removed = await page.evaluate(() => {
      let found = false
      document
        .querySelectorAll('.modal.show, .modal[style*="display: block"]')
        .forEach((el) => {
          el.classList.remove('show')
          el.style.display = 'none'
          found = true
        })
      document.querySelectorAll('.modal-backdrop').forEach((el) => {
        el.remove()
        found = true
      })
      document.body.classList.remove('modal-open')
      document.body.style.removeProperty('overflow')
      document.body.style.removeProperty('padding-right')
      return found
    })
    if (!removed) break
    await page.waitForTimeout(300)
  }
}

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

test.describe('ModTools Edit Message', () => {
  test('editing a pending message title should save successfully', async ({
    page,
    testEnv,
  }) => {
    expect(testEnv.pending.offer).toBeTruthy()
    console.log(
      `Using pending message ${testEnv.pending.offer} on group ${testEnv.group.name}`
    )

    await loginViaModTools(page, testEnv.mod.email)

    await page.goto(`${MODTOOLS_URL}/messages/pending`, {
      timeout: timeouts.navigation.initial,
    })

    const groupSelect = page.locator('#communitieslist')
    await expect(groupSelect).toBeVisible({
      timeout: timeouts.navigation.slowPage,
    })

    await dismissAllModals(page)

    // Ensure no modal backdrop remains — wait until it's gone from the DOM.
    await page
      .waitForFunction(() => !document.querySelector('.modal-backdrop'), {
        timeout: 10000,
      })
      .catch(() => {
        // If still there after waiting, force-remove it
        page.evaluate(() => {
          document
            .querySelectorAll('.modal-backdrop')
            .forEach((el) => el.remove())
          document.body.classList.remove('modal-open')
        })
      })

    // Select a group that has pending messages
    await selectGroupWithPendingMessages(page, groupSelect)

    // Wait for message cards to appear
    const messageCards = page.locator('.card')
    await expect(messageCards.first()).toBeVisible({
      timeout: timeouts.navigation.slowPage,
    })

    // Find and click the Edit button on the first message
    const editButton = page
      .locator('button:has-text("Edit"), .btn:has-text("Edit")')
      .first()
    await expect(editButton).toBeVisible({ timeout: timeouts.ui.appearance })
    // Dismiss any late-appearing modals before clicking
    await dismissAllModals(page)
    await editButton.click()

    // Wait for the edit form — the subject input appears (either well-structured
    // item/location inputs or a plain subject input with size="lg")
    const subjectInput = page
      .locator('input[size="lg"], input.form-control-lg')
      .first()
    await expect(subjectInput).toBeVisible({ timeout: timeouts.ui.appearance })

    // Read the original subject
    const originalSubject = await subjectInput.inputValue()
    console.log(`Original subject: "${originalSubject}"`)

    // Change the subject
    const newSubject = `Edited ${Date.now()}`
    await subjectInput.clear()
    await subjectInput.fill(newSubject)

    // Click Save
    const saveButton = page
      .locator('button:has-text("Save"), .btn-primary:has-text("Save")')
      .first()
    await expect(saveButton).toBeVisible({ timeout: timeouts.ui.appearance })
    await saveButton.click()

    // Wait for edit mode to close — the Save button should disappear.
    // If it doesn't, the save failed (spinner stuck — bug we're testing for).
    await expect(saveButton).not.toBeVisible({
      timeout: timeouts.api.default,
    })

    // Verify the edited text appears in the page. For well-structured messages,
    // the subject is rebuilt as "TYPE: item (location)", so the item name
    // should appear somewhere in the message display.
    await expect(page.locator(`text=/${newSubject}/i`).first()).toBeVisible({
      timeout: timeouts.ui.appearance,
    })

    console.log(`Successfully edited message to: "${newSubject}"`)
  })

  // An "Edit and Message" standard message adds text to the post and messages the
  // poster in one go: one PATCH /message carrying both.
  test('an Edit and Message standard message edits the post and messages the poster', async ({
    page,
    testEnv,
  }) => {
    // The first test edits the pending offer and it leaves the pending queue, so this
    // one uses the env's other pending post.
    const pendingId = testEnv.pending.wanted
    expect(pendingId).toBeTruthy()
    const API_V2 = environment.apiV2BaseUrl
    const stamp = Date.now()
    const title = `PW Knives ${stamp}`
    const added = `You must be 18 or over to collect ${stamp}.`
    const reply = `We have added a note about knives to your post ${stamp}.`

    // Set up a config holding the standard message, used by the mod on the test group.
    const loginResp = await page.request.post(`${API_V2}/session`, {
      data: { email: testEnv.mod.email, password: 'freegle' },
    })
    const { jwt } = await loginResp.json()
    expect(jwt).toBeTruthy()
    const headers = { Authorization: jwt }

    const cfgResp = await page.request.post(`${API_V2}/modtools/modconfig`, {
      data: { name: `PW EditAndMessage ${stamp}` },
      headers,
    })
    expect(cfgResp.ok()).toBeTruthy()
    const configid = (await cfgResp.json()).id
    expect(configid).toBeTruthy()

    const stdmsgResp = await page.request.post(`${API_V2}/modtools/stdmsg`, {
      data: {
        configid,
        title,
        action: 'Edit and Message',
        body: added,
        insert: 'Bottom',
        replybody: reply,
      },
      headers,
    })
    expect(stdmsgResp.ok()).toBeTruthy()

    const memberResp = await page.request.patch(`${API_V2}/memberships`, {
      data: {
        userid: Number(testEnv.mod.id),
        groupid: Number(testEnv.group.id),
        configid,
      },
      headers,
    })
    expect(memberResp.ok()).toBeTruthy()

    const msgResp = await page.request.get(`${API_V2}/message/${pendingId}`, {
      headers,
    })
    const msg = await msgResp.json()
    expect(msg.subject).toBeTruthy()

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
          (await groupSelect.locator('option').all()).length > 1 &&
          (await groupSelect
            .locator(`option[value="${testEnv.group.id}"]`)
            .count()) > 0,
        { timeout: timeouts.navigation.slowPage }
      )
      .toBe(true)
    await groupSelect.selectOption(String(testEnv.group.id))

    // Match the card on the message id, not the subject: the card can show the
    // pre-edit subject while the API already returns the edited one.
    const card = page
      .locator('.card')
      .filter({ hasText: new RegExp(`#${pendingId}(?!\\d)`) })
      .first()
    await expect(card).toBeVisible({ timeout: timeouts.navigation.slowPage })

    const stdmsgButton = card.locator(`button:has-text("${title}")`).first()
    await expect(stdmsgButton).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    await dismissAllModals(page)
    await stdmsgButton.click()

    const modal = page.locator('#stdmsgmodal')
    await expect(modal.locator('text=Post will read')).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    const replyBox = modal.locator('[data-test="stdmsg-reply-body"]')
    await expect(replyBox).toHaveValue(reply, {
      timeout: timeouts.ui.appearance,
    })

    const patchPromise = page.waitForResponse(
      (r) =>
        r.url().includes('/message') &&
        !r.url().includes('/messages') &&
        r.request().method() === 'PATCH',
      { timeout: timeouts.api.slowApi }
    )
    await modal.locator('button:has-text("Save Edit and Send")').click()
    const patchResponse = await patchPromise
    expect(patchResponse.status()).toBe(200)

    const sent = patchResponse.request().postDataJSON()
    expect(sent.replybody).toBe(reply)
    expect(sent.textbody).toContain(added)

    await expect(modal.locator('text=Post will read')).not.toBeVisible({
      timeout: timeouts.api.default,
    })

    const afterResp = await page.request.get(`${API_V2}/message/${pendingId}`, {
      headers,
    })
    const after = await afterResp.json()
    expect(after.textbody).toContain(added)
  })
})
