/**
 * Posts of several items that Freegle split automatically
 * (docs/developers/reference/auto-split.md).
 *
 * The split itself is made by a batch job that asks Gemini, which a test run does not have. So
 * the test posts a real OFFER, and the browser is then served that post as if the job had split
 * it: the real API answers, and the item list is added to its answer. The actions on the items
 * (mark gone, edit, undo) are answered the same way, from the same state. Everything the member
 * sees is the real frontend; the API side of these actions has its own Go tests.
 */
const { test, expect } = require('./fixtures')
const { timeouts } = require('./config')
const { loginViaHomepage, logoutIfLoggedIn } = require('./utils/user')

function cors(route) {
  return {
    'access-control-allow-origin': route.request().headers().origin || '*',
    'access-control-allow-credentials': 'true',
  }
}

// The API's read of a single post, and nothing under it (/message/<id>/interested and friends).
// /api/ keeps the site's own page at /message/<id> out of it.
const messageRead = (msgid) => new RegExp(`/api/message/${msgid}(\\?|$)`)

/**
 * Serve the post as split into the items in `state`, until `state.undone`. Answers the item
 * actions from `state` so the next read shows what they did.
 */
async function serveSplit(page, msgid, state) {
  await page.route(messageRead(msgid), async (route) => {
    if (route.request().method() !== 'GET') {
      return route.fallback()
    }
    const response = await route.fetch()
    if (!(response.headers()['content-type'] || '').includes('json')) {
      return route.fulfill({ response })
    }
    const body = await response.json()
    const message = Array.isArray(body) ? body[0] : body
    if (message && !state.undone) {
      message.autosplit = true
      message.bulkcount = state.items.length
      message.bulkitems = state.items.map((item, position) => ({
        ...item,
        position,
        condition: 'Unknown',
        attachments: [],
        interestcount: 0,
        interestedquantity: 0,
      }))
      message.availableinitially = state.items.reduce(
        (n, i) => n + i.quantity,
        0
      )
      message.availablenow = state.items
        .filter((i) => i.available)
        .reduce((n, i) => n + i.quantity, 0)
    }
    await route.fulfill({ response, json: body })
  })

  await page.route(/\/api\/message(\?|$)/, async (route) => {
    const request = route.request()
    const data = request.postDataJSON?.() || null
    if (!data || Number(data.id) !== Number(msgid)) {
      return route.fallback()
    }
    const ok = (extra = {}) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        headers: cors(route),
        body: JSON.stringify({ ret: 0, status: 'Success', ...extra }),
      })

    if (request.method() === 'POST' && data.action === 'BulkItemAvailable') {
      const item = state.items.find((i) => i.id === data.bulkitemid)
      item.available = data.available
      state.calls.push(`available ${item.name} ${data.available}`)
      return ok()
    }
    if (request.method() === 'POST' && data.action === 'AutoSplitUndo') {
      state.undone = true
      state.calls.push('undo')
      return ok()
    }
    if (request.method() === 'PATCH' && data.bulkitems) {
      // The frontend sends only which items stay and how many of each.
      state.calls.push(`edit ${JSON.stringify(data.bulkitems)}`)
      state.items = data.bulkitems.map((b) => ({
        ...state.items.find((i) => i.id === b.id),
        quantity: b.quantity,
      }))
      return ok()
    }
    return route.fallback()
  })
}

test.describe('Posts split automatically into items', () => {
  test('the poster sees the items, marks one gone, edits and undoes; a replier says which they want', async ({
    page,
    testEmail,
    postMessage,
    withdrawPost,
  }) => {
    test.setTimeout(timeouts.background * 3)

    const item = `auto-split-${Date.now()}-${Math.random()
      .toString(36)
      .substr(2, 5)}`
    const result = await postMessage({
      type: 'OFFER',
      item,
      description: 'A wooden chair, a red lamp and a rug.',
      email: testEmail,
    })
    expect(result.id).toBeTruthy()

    const state = {
      undone: false,
      calls: [],
      items: [
        { id: 900001, name: 'wooden chair', quantity: 1, available: true },
        { id: 900002, name: 'red lamp', quantity: 1, available: true },
        { id: 900003, name: 'rug', quantity: 1, available: true },
      ],
    }
    await serveSplit(page, result.id, state)

    // My Posts: the notice, the list, and a button for each item.
    await page.gotoAndVerify('/myposts', {
      timeout: timeouts.navigation.initial,
    })
    const notice = page.locator('[data-testid="autosplit-notice"]').first()
    await expect(notice).toBeVisible({ timeout: timeouts.ui.appearance })
    await expect(notice).toContainText("We've listed this as 3 items")
    const section = page.locator('[data-testid="autosplit-section"]').first()
    await expect(section.locator('[data-testid^="item-9000"]')).toHaveCount(3)

    // Mark the lamp gone, and back.
    const lampToggle = section.locator('[data-testid="item-toggle-900002"]')
    await expect(lampToggle).toHaveText('Mark gone')
    await lampToggle.click()
    await expect(
      section
        .locator('[data-testid="item-900002"]')
        .getByText('Gone', { exact: true })
    ).toBeVisible({ timeout: timeouts.ui.appearance })
    await expect(lampToggle).toHaveText('Still here')
    await lampToggle.click()
    await expect(lampToggle).toHaveText('Mark gone', {
      timeout: timeouts.ui.appearance,
    })
    expect(state.calls).toEqual([
      'available red lamp false',
      'available red lamp true',
    ])

    // Edit: three rugs, no lamp.
    await section.locator('[data-testid="autosplit-edit"]').click()
    const modal = page
      .locator('.modal-dialog')
      .filter({ hasText: 'Edit items' })
    await expect(modal).toBeVisible({ timeout: timeouts.ui.appearance })
    await modal.locator('[data-testid="edit-remove-900002"]').click()
    await modal
      .locator('[data-testid="edit-item-900003"] input[type="number"]')
      .fill('3')
    await modal.locator('[data-testid="edit-items-save"]').click()
    await expect(section.locator('[data-testid^="item-9000"]')).toHaveCount(2, {
      timeout: timeouts.ui.appearance,
    })
    await expect(section.locator('[data-testid="item-900003"]')).toContainText(
      '× 3'
    )
    expect(state.calls[2]).toBe(
      'edit [{"id":900001,"quantity":1},{"id":900003,"quantity":3}]'
    )

    // Undo puts the post back as it was written.
    await section.locator('[data-testid="autosplit-undo"]').click()
    await expect(page.locator('[data-testid="autosplit-section"]')).toHaveCount(
      0,
      {
        timeout: timeouts.ui.appearance,
      }
    )
    expect(state.calls[3]).toBe('undo')

    // Someone else sees the list on the post page and says which they want in the reply.
    state.undone = false
    await logoutIfLoggedIn(page)
    await page.gotoAndVerify(`/message/${result.id}`, {
      timeout: timeouts.navigation.initial,
    })
    const list = page.locator('[data-testid="item-list"]').first()
    await expect(list).toBeVisible({ timeout: timeouts.ui.appearance })
    await expect(list).toContainText('2 things in this post')
    await expect(page.locator('[data-testid="register-interest"]')).toHaveCount(
      0
    )

    await page.locator('.reply-button').first().click()
    const choice = page.locator('[data-testid="reply-item-choice"]')
    await expect(choice).toBeVisible({ timeout: timeouts.ui.appearance })
    await choice.locator('[data-testid="reply-item-900001"]').click()
    await expect(
      page.locator('textarea[id^="replytomessage-"]').first()
    ).toHaveValue("I'd like: wooden chair")

    // Tidy up as the poster.
    await page.unrouteAll({ behavior: 'ignoreErrors' })
    expect(await loginViaHomepage(page, testEmail)).toBeTruthy()
    await withdrawPost({ item: result.item })
  })
})
