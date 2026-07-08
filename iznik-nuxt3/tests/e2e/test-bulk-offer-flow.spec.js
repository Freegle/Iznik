/**
 * End-to-end walkthrough of the bulk offer ("clearance") feature:
 *  - an offerer logs in, lists several items (typed in), uploads photos and
 *    drags each onto its item, adds collection times, and posts;
 *  - two repliers then open the post and turn on the items they'd like —
 *    overlapping on one item, differing on the others.
 *
 * Recorded as a single continuous flow so the video shows the whole journey.
 */

const path = require('path')
const { test, expect } = require('./fixtures')
const { timeouts } = require('./config')
const { loginViaHomepage, logoutIfLoggedIn } = require('./utils/user')
const { waitForNuxtHydration } = require('./utils/reply-helpers')

const ASSET = (f) => path.join(__dirname, 'assets', f)

test.describe('Bulk offer (clearance) end-to-end', () => {
  // Video is recorded via the context fixture (recordVideo) for the whole flow.
  test('offerer posts a clearance; two repliers pick items; offerer allocates and completes', async ({
    page,
    testEnv,
    takeScreenshot,
  }) => {
    test.setTimeout(240000)

    const offerer = testEnv.user
    const replier1 = testEnv.user2
    const replier2 = testEnv.mod
    const postcode = testEnv.postcode || 'PL1 2EX'

    const items = [
      { name: 'Office desk', qty: '4', condition: 'Good' },
      { name: 'Swivel chair', qty: '14', condition: 'Used' },
      { name: 'Desk lamp', qty: '6', condition: 'LikeNew' },
    ]

    // ---------------------------------------------------------------
    // OFFERER: build and post the clearance
    // ---------------------------------------------------------------
    expect(await loginViaHomepage(page, offerer.email, 'freegle')).toBeTruthy()
    console.log(`Logged in as offerer ${offerer.email}`)

    await page.gotoAndVerify('/give/clearance', {
      timeout: timeouts.navigation.default,
    })

    // Where are you? — enter postcode, wait for it to resolve a community.
    const pc = page
      .locator('.pcinp, input[placeholder="Type postcode"]')
      .first()
    await pc.waitFor({ state: 'visible', timeout: timeouts.ui.appearance })
    await pc.fill(postcode)
    await page
      .locator(
        '.validation-tick, .fa-check-circle, .v-icon[icon="check-circle"]'
      )
      .first()
      .waitFor({ state: 'visible', timeout: timeouts.api.default })
    console.log('Postcode resolved')

    // What are you offering? — the overall title (required to post).
    await page
      .getByTestId('clearance-title')
      .fill('Office clearance — everything must go')

    // The items: choose "Type them in".
    await page.getByTestId('mode-manual').click()

    // New rows append below the existing ones, so item i lives at row i.
    for (let i = 0; i < items.length; i++) {
      if (i > 0) await page.getByTestId('add-item').click()
      await page.getByTestId(`item-name-${i}`).fill(items[i].name)
      await page.getByTestId(`item-qty-${i}`).fill(items[i].qty)
      await page
        .getByTestId(`item-condition-${i}`)
        .selectOption(items[i].condition)
    }
    console.log(`Listed ${items.length} items`)
    await takeScreenshot('bulk-items-listed')

    // Photos: one per item via the per-row uploader. Each row's camera button
    // opens a file chooser tied to that row; the picked file uploads and shows
    // as a thumbnail in that row's photo cell. (This also exercises the dev
    // image pipeline, asserted again on the replier side below.)
    for (let i = 0; i < items.length; i++) {
      const [chooser] = await Promise.all([
        page.waitForEvent('filechooser'),
        page.getByTestId(`item-addphoto-${i}`).click(),
      ])
      await chooser.setFiles(ASSET(`item${i + 1}.png`))
      await expect(
        page.locator(`[data-testid="item-photocell-${i}"] .pthumb`)
      ).toBeVisible({ timeout: timeouts.api.default })
    }
    await takeScreenshot('bulk-photos-assigned')
    console.log('Photos assigned to items')

    // Collection times.
    await page.getByTestId('slot-0').fill('Tue 7 Apr, 10am-4pm')
    await page.getByTestId('add-slot').click()
    await page.getByTestId('slot-1').fill('Wed 8 Apr, 10am-4pm')

    // Access instructions (only revealed to a replier once promised an item).
    await page
      .getByTestId('clearance-access')
      .fill('Side door, buzz flat 3 — given to you once we confirm.')

    await takeScreenshot('bulk-ready-to-post')

    // Post — capture the created message id from the PUT response.
    const putResponse = page.waitForResponse(
      (r) =>
        r.url().includes('/api/message') &&
        r.request().method() === 'PUT' &&
        r.status() === 200
    )
    await page.getByTestId('clearance-submit').click()
    const resp = await putResponse
    const msgId = (await resp.json().catch(() => ({})))?.id
    console.log(`Posted clearance, message id ${msgId}`)
    expect(msgId).toBeTruthy()

    await page.waitForURL(/\/myposts/, {
      timeout: timeouts.navigation.default,
    })

    // ---------------------------------------------------------------
    // REPLIER 1: picks the desk and the chair
    // ---------------------------------------------------------------
    await logoutIfLoggedIn(page)
    expect(await loginViaHomepage(page, replier1.email, 'freegle')).toBeTruthy()
    console.log(`Logged in as replier1 ${replier1.email}`)
    await registerInterest(page, msgId, [0, 1], takeScreenshot, 'r1')

    // ---------------------------------------------------------------
    // REPLIER 2: picks the chair (overlap) and the lamp
    // ---------------------------------------------------------------
    await logoutIfLoggedIn(page)
    expect(await loginViaHomepage(page, replier2.email, 'freegle')).toBeTruthy()
    console.log(`Logged in as replier2 ${replier2.email}`)
    await registerInterest(page, msgId, [1, 2], takeScreenshot, 'r2')

    // ---------------------------------------------------------------
    // OFFERER: see the replies, allocate an item, and complete collection
    // ---------------------------------------------------------------
    await logoutIfLoggedIn(page)
    expect(await loginViaHomepage(page, offerer.email, 'freegle')).toBeTruthy()
    console.log(`Logged back in as offerer ${offerer.email}`)

    // Reach the management page from My Posts.
    await page.gotoAndVerify('/myposts', {
      timeout: timeouts.navigation.default,
    })
    const manageLink = page.getByTestId('manage-' + msgId)
    await manageLink.waitFor({
      state: 'visible',
      timeout: timeouts.ui.appearance,
    })
    await manageLink.click()
    await page.waitForURL(new RegExp('/clearance/' + msgId), {
      timeout: timeouts.navigation.default,
    })

    // The manager lists each item and the people who replied to it.
    await page
      .getByTestId('clearance-manager')
      .waitFor({ state: 'visible', timeout: timeouts.ui.appearance })
    await page
      .locator('[data-testid^="candidate-"]')
      .first()
      .waitFor({ state: 'visible', timeout: timeouts.api.default })
    await takeScreenshot('bulk-manage-replies')

    // Allocate: reserve an item to the first candidate (promises it to them).
    const reserveBtn = page
      .locator('[data-testid^="action-"][data-testid$="-Reserved"]')
      .first()
    await reserveBtn.waitFor({
      state: 'visible',
      timeout: timeouts.ui.appearance,
    })
    await reserveBtn.click()
    await page.waitForTimeout(1000)
    await takeScreenshot('bulk-manage-allocated')

    // Complete: mark it collected.
    const collectBtn = page
      .locator('[data-testid^="action-"][data-testid$="-Collected"]')
      .first()
    await collectBtn.waitFor({
      state: 'visible',
      timeout: timeouts.ui.appearance,
    })
    await collectBtn.click()
    await page.waitForTimeout(1000)
    await takeScreenshot('bulk-manage-collected')
    console.log('Offerer allocated and completed an item')

    console.log('Bulk offer end-to-end flow complete')
  })
})

// Open the offer, turn on the items at the given indices, choose a collection
// slot, and register interest.
async function registerInterest(page, msgId, indices, takeScreenshot, who) {
  await page.gotoAndVerify(`/message/${msgId}`, {
    timeout: timeouts.navigation.default,
  })
  // The toggles, photos AND the register button all render server-side, so
  // their visibility says nothing about interactivity. Every interaction
  // below relies on Vue click handlers (the empty-submit error in particular
  // only renders after a live handler sets bulkTriedRegister) - clicking
  // before hydration lands on inert SSR DOM and silently does nothing, which
  // is exactly how this test failed on a loaded CI box.
  await waitForNuxtHydration(page)
  // Wait for the bulk catalogue toggles to render.
  const toggles = page.locator('[data-testid^="pick-"]')
  await toggles
    .first()
    .waitFor({ state: 'visible', timeout: timeouts.ui.appearance })

  // Every item photo that renders must actually load — i.e. the dev image
  // pipeline (apiv2 delivery URL → resizer → local tusd) really serves it.
  // Items without a photo show an icon instead, so we only assert on <img>s.
  const photoImgs = page.locator('.bitem__photo img')
  const photoCount = await photoImgs.count()
  for (let i = 0; i < photoCount; i++) {
    await expect
      .poll(
        () =>
          photoImgs
            .nth(i)
            .evaluate((img) => img.complete && img.naturalWidth > 0),
        {
          message: `${who}: item photo ${i} should load, not be broken`,
          timeout: timeouts.api.default,
        }
      )
      .toBeTruthy()
  }
  console.log(`${who}: ${photoCount} item photo(s) rendered and loaded`)

  // Registering with nothing chosen must show a clear error, not silently fail.
  if (who === 'r1') {
    const reg = page.getByTestId('register-interest')
    await reg.scrollIntoViewIfNeeded()
    await reg.click()
    await expect(page.getByTestId('register-error')).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    await takeScreenshot('bulk-empty-submit-error')
    console.log(`${who}: empty-submit error shown as expected`)
  }

  for (const idx of indices) {
    const toggle = toggles.nth(idx)
    await toggle.scrollIntoViewIfNeeded()
    await toggle.click()
  }
  await takeScreenshot(`bulk-${who}-items-picked`)

  // Tick the first collection time if the picker is shown (multi-select).
  const slot = page
    .locator('[data-testid="slot-picker"] input[type="checkbox"]')
    .first()
  if (await slot.count()) await slot.check().catch(() => {})

  const register = page.getByTestId('register-interest')
  await register.scrollIntoViewIfNeeded()
  const interestResponse = page.waitForResponse(
    (r) =>
      r.url().includes('/api/message') &&
      r.request().method() === 'POST' &&
      r.status() === 200,
    { timeout: timeouts.api.default }
  )
  await register.click()
  await interestResponse.catch(() => {})
  await takeScreenshot(`bulk-${who}-registered`)
  console.log(`${who} registered interest in items ${indices.join(',')}`)
}
