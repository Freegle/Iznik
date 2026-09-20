/**
 * UX-review capture for the bulk-offer ("clearance") composer.
 *
 * Not an assertion test — it logs in and screenshots each composer state at
 * desktop and mobile widths so the layout can be reviewed (and regressions
 * spotted) across viewports. Run via the status API; pull the attached PNGs
 * from the playwright container's test-results.
 */

const { test, expect } = require('./fixtures')
const { timeouts } = require('./config')
const { loginViaHomepage, logoutIfLoggedIn } = require('./utils/user')

const VIEWPORTS = [
  { w: 1280, h: 900, tag: 'desktop' },
  { w: 390, h: 844, tag: 'mobile' },
]

const ITEMS = [
  ['Office desk', '4', 'Good'],
  ['Swivel chair', '14', 'Used'],
  ['Desk lamp', '6', 'LikeNew'],
]

test.describe('Bulk composer UX review', () => {
  test('capture composer states at desktop and mobile', async ({
    page,
    testEnv,
    takeScreenshot,
  }) => {
    test.setTimeout(180000)
    expect(
      await loginViaHomepage(page, testEnv.user.email, 'freegle')
    ).toBeTruthy()
    const postcode = testEnv.postcode || 'PL1 2EX'

    const openComposer = async () => {
      await page.gotoAndVerify('/give/clearance', {
        timeout: timeouts.navigation.default,
      })
      await page.waitForTimeout(800)
    }
    const fillPostcode = async () => {
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
        .catch(() => {})
    }

    for (const vp of VIEWPORTS) {
      await page.setViewportSize({ width: vp.w, height: vp.h })

      // 1. Initial load — intro, postcode, type/upload choice (no table yet).
      await openComposer()
      // Regression guard: when a postcode is already set (e.g. restored from the
      // store on the 2nd viewport), the field must show its name, never the
      // location object stringified as "[object Object]".
      const pcVal = await page
        .locator('.pcinp, input[placeholder="Type postcode"]')
        .first()
        .inputValue()
        .catch(() => '')
      expect(
        pcVal,
        'postcode field must not render the location object'
      ).not.toContain('[object Object]')
      await takeScreenshot(`composer-initial-${vp.tag}`)

      // 2. Postcode entered + title filled.
      await fillPostcode()
      await page
        .getByTestId('clearance-title')
        .fill('Office clearance — everything must go')
      await page.waitForTimeout(500)
      await takeScreenshot(`composer-postcode-${vp.tag}`)

      // 3. Manual entry — items table with headings + photo rail.
      await page.getByTestId('mode-manual').click()
      for (let i = 0; i < ITEMS.length; i++) {
        if (i > 0) await page.getByTestId('add-item').click()
        await page.getByTestId('item-name-0').fill(ITEMS[i][0])
        await page.getByTestId('item-qty-0').fill(ITEMS[i][1])
        await page.getByTestId('item-condition-0').selectOption(ITEMS[i][2])
      }
      await page.waitForTimeout(400)
      await takeScreenshot(`composer-manual-${vp.tag}`)

      // Accessibility audit (once) — list every form control and where its
      // accessible name comes from. "placeholder-only" / "NONE" are problems:
      // screen readers announce no usable label.
      if (vp.tag === 'desktop') {
        const a11y = await page.evaluate(() => {
          const source = (el) => {
            if (el.getAttribute('aria-label')) return 'aria-label'
            if (el.getAttribute('aria-labelledby')) return 'aria-labelledby'
            if (el.labels && el.labels.length) return 'label'
            if (el.getAttribute('title')) return 'title'
            if (el.getAttribute('placeholder')) return 'placeholder-only'
            return 'NONE'
          }
          return [...document.querySelectorAll('input,select,textarea')]
            .filter((el) => el.type !== 'hidden' && el.offsetParent !== null)
            .map((el) => ({
              tag: el.tagName.toLowerCase(),
              type: el.type || '',
              testid: el.getAttribute('data-testid') || '',
              ph: (el.getAttribute('placeholder') || '').slice(0, 20),
              src: source(el),
            }))
        })
        console.log('[a11y-composer] ' + JSON.stringify(a11y, null, 0))
        // Guard: every bulk-specific control (our data-testids) must have a real
        // accessible name — a label or aria-label, not placeholder-only/none.
        const labelled = (s) =>
          s === 'aria-label' || s === 'aria-labelledby' || s === 'label'
        const unlabelled = a11y.filter(
          (c) => /^(clearance-|item-|slot-)/.test(c.testid) && !labelled(c.src)
        )
        expect(
          unlabelled,
          `bulk controls missing an accessible name: ${JSON.stringify(
            unlabelled
          )}`
        ).toHaveLength(0)
      }

      // 4. Upload mode — reload to reset the choice gate, then pick upload.
      await openComposer()
      await fillPostcode()
      await page.getByTestId('mode-upload').click()
      await page.waitForTimeout(400)
      await takeScreenshot(`composer-upload-${vp.tag}`)
    }
  })

  // Catalogue views — post a real bulk offer, then check both the owner's view
  // and a non-owner recipient's view. The recipient's one-line rows are the most
  // overflow-prone bit on a phone, and it's what most people actually see.
  test('catalogue: owner and responder views do not overflow', async ({
    page,
    testEnv,
    takeScreenshot,
  }) => {
    test.setTimeout(240000)
    // Post the offer as the owner so the message genuinely exists (CI has no
    // seeded data) — then view it from both sides.
    expect(
      await loginViaHomepage(page, testEnv.user.email, 'freegle')
    ).toBeTruthy()
    const msgId = await postBulkOffer(page, testEnv.postcode || 'PL1 2EX')
    expect(msgId, 'a bulk offer should have been posted').toBeTruthy()

    // Owner's own view of the offer.
    await captureCatalogue(page, takeScreenshot, msgId, 'owner')

    // A non-owner recipient sees the structured picker.
    await logoutIfLoggedIn(page)
    expect(
      await loginViaHomepage(page, testEnv.user2.email, 'freegle')
    ).toBeTruthy()
    await captureCatalogue(page, takeScreenshot, msgId, 'responder')
  })
})

// Post a minimal bulk offer via the composer (caller is already logged in) and
// return the new message id. Self-contained so the catalogue checks run in CI.
async function postBulkOffer(page, postcode) {
  await page.gotoAndVerify('/give/clearance', {
    timeout: timeouts.navigation.default,
  })
  const pc = page.locator('.pcinp, input[placeholder="Type postcode"]').first()
  await pc.waitFor({ state: 'visible', timeout: timeouts.ui.appearance })
  await pc.fill(postcode)
  await page
    .locator('.validation-tick, .fa-check-circle, .v-icon[icon="check-circle"]')
    .first()
    .waitFor({ state: 'visible', timeout: timeouts.api.default })
    .catch(() => {})
  await page
    .getByTestId('clearance-title')
    .fill('Office clearance — everything must go')
  await page.getByTestId('mode-manual').click()
  for (let i = 0; i < ITEMS.length; i++) {
    if (i > 0) await page.getByTestId('add-item').click()
    await page.getByTestId('item-name-0').fill(ITEMS[i][0])
    await page.getByTestId('item-qty-0').fill(ITEMS[i][1])
    await page.getByTestId('item-condition-0').selectOption(ITEMS[i][2])
  }
  const putResponse = page.waitForResponse(
    (r) =>
      r.url().includes('/api/message') &&
      r.request().method() === 'PUT' &&
      r.status() === 200,
    { timeout: timeouts.api.default }
  )
  await page.getByTestId('clearance-submit').click()
  const resp = await putResponse
  const msgId = (await resp.json().catch(() => ({})))?.id
  await page
    .waitForURL(/\/myposts/, { timeout: timeouts.navigation.default })
    .catch(() => {})
  return msgId
}

// Open a bulk message, screenshot the catalogue at each viewport, and assert it
// never forces horizontal page scroll. `who` namespaces the screenshots.
async function captureCatalogue(page, takeScreenshot, msgId, who) {
  for (const vp of VIEWPORTS) {
    await page.setViewportSize({ width: vp.w, height: vp.h })
    await page.gotoAndVerify(`/message/${msgId}`, {
      timeout: timeouts.navigation.default,
    })
    // Wait for the catalogue to render (toggles for a recipient, interest
    // summary for the owner) — non-fatal if neither is present.
    await page
      .locator('.bitem')
      .first()
      .waitFor({ state: 'visible', timeout: timeouts.ui.appearance })
      .catch(() => {})
    await page.waitForTimeout(600)
    // For the recipient, turn the first item on so the quantity dropdown shows
    // — that's the most layout-sensitive state to review/measure.
    if (who === 'responder') {
      const firstPick = page.locator('[data-testid^="pick-"]').first()
      if (await firstPick.count()) {
        await firstPick.click().catch(() => {})
        await page.waitForTimeout(300)
      }
    }
    await takeScreenshot(`${who}-${vp.tag}`)

    // A recipient sees the structured catalogue, so the server-generated text
    // summary must not also appear (no duplication). The owner's view shows the
    // text instead (it has no catalogue), so only assert this for the recipient.
    if (who === 'responder') {
      const bodyText = await page.locator('body').innerText()
      expect(
        bodyText,
        `${vp.tag}: recipient must not see the duplicate text item list`
      ).not.toContain('Items available in this offer:')
    }

    // Measure real horizontal overflow at this width (the definitive check —
    // element screenshots can report natural width beyond the viewport).
    const m = await page.evaluate(() => {
      const row = document.querySelector('.bitem')
      return {
        docScroll: document.documentElement.scrollWidth,
        inner: window.innerWidth,
        rowScroll: row ? row.scrollWidth : 0,
        rowClient: row ? row.clientWidth : 0,
      }
    })
    console.log(
      `[overflow ${who} ${vp.tag}] doc.scrollWidth=${m.docScroll} innerWidth=${m.inner} ` +
        `row.scrollWidth=${m.rowScroll} row.clientWidth=${m.rowClient} ` +
        `overflow=${m.docScroll - m.inner}px`
    )
    // The catalogue must not force horizontal page scroll (allow a 2px fudge
    // for sub-pixel rounding). Guards the mobile row-overflow regression.
    expect(
      m.docScroll,
      `${who} ${vp.tag}: bulk catalogue must not overflow the viewport`
    ).toBeLessThanOrEqual(m.inner + 2)

    // Clean element capture of just the catalogue rows — the full-page shot
    // has the fixed bottom-nav rendered mid-page, which hides the rows.
    const list = page.locator('.bulkitems').first()
    if (await list.count()) {
      await list.scrollIntoViewIfNeeded().catch(() => {})
      await list
        .screenshot({ path: `/app/test-results/${who}-rows-${vp.tag}.png` })
        .catch(() => {})
    }
  }
}
