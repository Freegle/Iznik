/**
 * Experiment rate-on-return: on arrival, a signed-in member in arm 'b' who handed an item over in the
 * last 14 days and has not rated the other person is asked to, in a modal; control sees nothing. Shown
 * at most once a day on a browser.
 *
 * The arm is forced by serving the live config (GET /config/ux_experiments) ourselves and seeding the
 * copy the page keeps in localStorage (composables/useExperiment.js). Who to rate comes from
 * GET /user/unrated; the first part of the test serves that too, naming a real member (the one who
 * replied to the post), so the thumbs up goes to the real API. The last part lets the real endpoint
 * answer: this member handed nothing over, so nothing is asked.
 */

const { test, expect } = require('./fixtures')
const { timeouts } = require('./config')
const { loginViaHomepage, logoutIfLoggedIn } = require('./utils/user')

const EXPERIMENT = 'rate-on-return'
const PATH = '/browse'

function cors(route) {
  return {
    'access-control-allow-origin': route.request().headers().origin || '*',
    'access-control-allow-credentials': 'true',
  }
}

/**
 * Serve a live config in which every browser lands in `arm`, seed the stored copy to match, and
 * forget the day gate and any skips, so the arrival under test is a first one.
 */
async function forceConfig(page, arm) {
  const config = {}
  if (arm) {
    config[EXPERIMENT] = {
      arms: { [arm]: [0, 99] },
      until: Math.floor(Date.now() / 1000) + 3600,
    }
  }

  // The init script runs on every document, so the day gate and the skips are cleared once per
  // call (marked by `stamp`), not on every arrival: a later arrival must see the gate the first
  // one set.
  const stamp = String(Date.now())
  await page.addInitScript(
    ({ config, stamp }) => {
      try {
        Object.keys(localStorage)
          .filter((k) => k.startsWith('freegle_ux_arm:'))
          .forEach((k) => localStorage.removeItem(k))
        const marker = 'freegle_rate_on_return_test_' + stamp
        if (!localStorage.getItem(marker)) {
          localStorage.setItem(marker, '1')
          localStorage.removeItem('freegle_rating_nudge_last')
          localStorage.removeItem('freegle_rating_nudge_skipped')
          sessionStorage.removeItem('freegle_rating_nudge_last')
        }
        localStorage.setItem(
          'freegle_ux_experiments',
          JSON.stringify({ fetchedAt: Date.now(), config })
        )
      } catch {
        // No storage: the route below still serves the config.
      }
    },
    { config, stamp }
  )

  await page.unroute('**/config/ux_experiments*')
  await page.route('**/config/ux_experiments*', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      headers: cors(route),
      body: JSON.stringify([
        { key: 'ux_experiments', value: JSON.stringify(config) },
      ]),
    })
  )
}

async function serveUnrated(page, people) {
  await page.unroute('**/user/unrated*')
  await page.route('**/user/unrated*', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      headers: cors(route),
      body: JSON.stringify(people),
    })
  )
}

function nudge(page) {
  return page.locator('.modal-dialog').filter({ hasText: 'How did it go?' })
}

async function arrive(page) {
  await page.goto(PATH, {
    waitUntil: 'domcontentloaded',
    timeout: timeouts.navigation.default,
  })
}

test.describe('rate-on-return', () => {
  test('arm b is asked on arrival, a thumbs up reaches the server, and a day passes before the next ask', async ({
    page,
    testEmail,
    getTestEmail,
    postMessage,
    withdrawPost,
    replyToMessageWithSignup,
  }) => {
    test.setTimeout(timeouts.background * 4)

    const uniqueItem = `rate-on-return-${Date.now()}-${Math.random()
      .toString(36)
      .substr(2, 5)}`

    const result = await postMessage({
      type: 'OFFER',
      item: uniqueItem,
      description: `rate-on-return test post at ${new Date().toISOString()}`,
      email: testEmail,
    })
    expect(result.id).toBeTruthy()

    // Somebody replies, so there is a real member to rate.
    const takerEmail = getTestEmail('taker')
    await logoutIfLoggedIn(page)
    expect(
      await replyToMessageWithSignup({
        messageId: result.id,
        itemName: result.item,
        email: takerEmail,
      })
    ).toBeTruthy()

    await logoutIfLoggedIn(page)
    expect(await loginViaHomepage(page, testEmail)).toBeTruthy()

    // The taker's id is only shown in the chat header. The reply is processed in the background, so
    // the chat can take a while to appear.
    const chatEntry = page.locator('.chat-entry').first()
    let found = false
    for (let attempt = 1; attempt <= 20 && !found; attempt++) {
      await page.gotoAndVerify('/chats')
      try {
        await chatEntry.waitFor({ state: 'visible', timeout: 15000 })
        found = true
      } catch (e) {
        // Not processed yet.
      }
    }
    expect(found).toBeTruthy()
    await chatEntry.click()
    await page.waitForTimeout(timeouts.ui.settleTime)

    const contactDetails = page.locator(
      '.modal-content:has-text("Contact details")'
    )
    try {
      await contactDetails.waitFor({ state: 'visible', timeout: 5000 })
      await contactDetails.locator('button:has-text("Cancel")').click()
      await contactDetails.waitFor({ state: 'hidden', timeout: 5000 })
    } catch (e) {
      // It did not appear.
    }

    const ratings = page
      .locator('.user-ratings')
      .filter({ visible: true })
      .first()
    await ratings.waitFor({ state: 'visible', timeout: timeouts.ui.appearance })
    const takerId = Number(await ratings.getAttribute('data-debug-id'))
    expect(takerId).toBeGreaterThan(0)

    // Arm b, and the server says this member gave the item to the taker.
    await forceConfig(page, 'b')
    await serveUnrated(page, [
      {
        userid: takerId,
        msgid: result.id,
        subject: `OFFER: ${result.item}`,
        item: result.item,
        gave: true,
        timestamp: new Date().toISOString(),
      },
    ])
    await arrive(page)

    const dialog = nudge(page)
    await expect(dialog).toBeVisible({ timeout: timeouts.ui.appearance })
    await expect(dialog).toContainText('You gave them')
    await expect(dialog).toContainText(result.item)
    await expect(dialog.locator('.person')).toHaveCount(1)

    // The thumbs up goes to the real API, and the refetch that follows shows it as the member's own.
    const posted = page.waitForResponse(
      (r) =>
        r.request().method() === 'POST' &&
        /\/user(\?|$)/.test(r.url()) &&
        r.status() === 200,
      { timeout: timeouts.api.default }
    )
    const refetched = page.waitForResponse(
      (r) =>
        r.request().method() === 'GET' &&
        r.url().includes('/user/') &&
        r.url().includes(String(takerId)) &&
        r.status() === 200,
      { timeout: timeouts.api.default }
    )
    await dialog.locator('.rate-up').click()
    await posted
    const body = await (await refetched).json()
    const taker = Array.isArray(body)
      ? body.find((u) => u.id === takerId)
      : body
    expect(taker?.info?.ratings?.Mine).toBe('Up')

    // Nobody left: thanks, and the modal goes.
    await expect(dialog).toContainText('Thanks!')
    await expect(dialog).toBeHidden({ timeout: timeouts.ui.appearance })

    // Another arrival the same day, with the server still naming someone, asks nothing.
    await arrive(page)
    await page.waitForTimeout(3000)
    await expect(nudge(page)).toHaveCount(0)

    // Control, on a first arrival, asks nothing.
    await forceConfig(page, 'control')
    await arrive(page)
    await page.waitForTimeout(3000)
    await expect(nudge(page)).toHaveCount(0)

    // The real endpoint: this member handed nothing over, so arm b has nobody to ask about.
    await page.unroute('**/user/unrated*')
    await forceConfig(page, 'b')
    const unrated = page.waitForResponse(
      (r) => r.url().includes('/user/unrated'),
      { timeout: timeouts.api.default }
    )
    await arrive(page)
    const answer = await unrated
    expect(answer.status()).toBe(200)
    expect(await answer.json()).toEqual([])
    await page.waitForTimeout(2000)
    await expect(nudge(page)).toHaveCount(0)

    await withdrawPost({ item: result.item })
  })
})
