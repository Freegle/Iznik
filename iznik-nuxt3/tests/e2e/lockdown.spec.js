// @ts-check
/**
 * Lockdown switch - cross-stack drill (plans/active/2026-09-27-lockdown-switch.md
 * section 10.11): "press; A replies to B; B sees nothing, A sees a sent
 * message; a moderator cannot reject and can approve; lift; B sees it; exactly
 * one email is spooled."
 *
 * IMPORTANT - shared environment risk: this test presses a REAL, site-wide
 * lockdown. `test.describe.configure({ mode: 'serial' })` only serialises the
 * tests inside this one file - it does nothing to stop the other spec files
 * Playwright runs at the same time in its other workers (6 by default, 11 on
 * the self-hosted runner) against the same Docker containers. While this test
 * has the lockdown pressed, every other spec running concurrently in this
 * environment has its posts, replies, ChitChat and email held too. Do not run
 * this file alongside the rest of the suite in a shared environment without
 * checking first that nothing else is using it.
 *
 * It runs as the teardown of the lockdown-order project in
 * playwright.config.js, so every other project has finished before it
 * presses.
 */

const fs = require('fs')
const path = require('path')
const { test, expect } = require('./fixtures')
const { timeouts, environment, SCREENSHOTS_DIR } = require('./config')
const {
  loginViaModTools,
  loginViaHomepage,
  logoutIfLoggedIn,
} = require('./utils/user')
const {
  waitForAuthInLocalStorage,
  waitForAuthHydration,
  clickReplyButton,
  fillReplyForm,
  clickSendAndWait,
} = require('./utils/reply-helpers')

const MODTOOLS_URL = environment.modtoolsBaseUrl

// PR screenshots (plans/active/2026-09-27-lockdown-switch.md sections 10/11)
// are taken here, at real moments in the one test that presses a real
// lockdown, rather than in a separate spec faking the state with
// page.route() - a faked screenshot would show made-up numbers. Cheap and
// non-failing: a screenshot never gates the test, it just rides along on
// assertions that are already there for correctness.
//
// They go in a 'pr' subdirectory of SCREENSHOTS_DIR, not the directory
// itself: fixtures.js registers a global test.afterAll that calls
// cleanupScreenshots(), which unlinks every top-level *.png in
// SCREENSHOTS_DIR whenever the run exits clean (process.exitCode 0 or
// undefined). That fired straight after this file's one test passed and
// deleted these screenshots within seconds of them being written - no
// error, nothing in the logs, just an empty directory afterwards.
// cleanupScreenshots() is not recursive, so a subdirectory is invisible to
// it.
const PR_SCREENSHOTS_DIR = path.join(SCREENSHOTS_DIR, 'pr')
if (!fs.existsSync(PR_SCREENSHOTS_DIR)) {
  fs.mkdirSync(PR_SCREENSHOTS_DIR, { recursive: true })
}

// The notice is free text; this is the "Spam attack" starting wording from
// modtools/utils/lockdownAreas.js, typed in as Support would.
const SECURITY_NOTICE_TEXT =
  "We're dealing with a spam attack. Messages may be delayed. If you received a " +
  "message about vouchers or payments, please don't click the link."

// Helper: dismiss any overlay modals that block interaction (duplicated per
// file, matching the convention in test-modtools-pending-messages.spec.js and
// test-modtools-spammers.spec.js).
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

// Helper: click the "Confirm" button inside whichever confirm-style modal is
// currently visible and contains the given content locator. The lift-all and
// close modals in ModSupportLockdown.vue don't carry their own data-testid on
// the confirm button (unlike the press dialog), so they're found by scoping
// to the visible modal that holds the named content div.
async function confirmVisibleModal(page, contentTestId) {
  const content = page.getByTestId(contentTestId)
  await expect(content).toBeVisible({ timeout: timeouts.ui.appearance })
  const modal = page.locator('.modal.show', { has: content })
  await modal.getByRole('button', { name: 'Confirm' }).click()
}

// Helper: select the option in the ModTools community dropdown whose visible
// text starts with the given group name (e.g. "FreeglePlayground (2)").
// Mirrors selectGroupWithPendingMessages in test-modtools-pending-messages.spec.js,
// but matches a known group by name instead of "any group with a count" -
// needed here because other concurrently-running specs may have their own
// pending posts sitting in other groups.
async function selectGroupByName(page, groupSelect, name) {
  let targetValue = null
  await expect
    .poll(
      async () => {
        const options = await groupSelect.locator('option').all()
        for (const option of options) {
          const text = await option.textContent()
          if (text && text.trim().startsWith(name)) {
            targetValue = await option.getAttribute('value')
            return true
          }
        }
        return false
      },
      {
        message: `Waiting for a "${name}" option in the community list`,
        timeout: timeouts.navigation.slowPage,
      }
    )
    .toBe(true)
  await groupSelect.selectOption(targetValue)
  return targetValue
}

test.describe('Lockdown switch', () => {
  test.describe.configure({ mode: 'serial' })

  // Captured inside the test so afterAll can clean up even if the test fails
  // partway through - testEnv itself is test-scoped and unavailable in afterAll.
  let pressedModEmail = null

  test.afterAll(async ({ browser }) => {
    if (!pressedModEmail) return

    // Safety net: if the test failed before reaching its own lift/close
    // steps, make sure the lockdown doesn't stay pressed for whoever runs
    // next. Every call is wrapped so a failure here can never mask the real
    // test failure - matches the JWT-extraction + direct-PATCH cleanup
    // pattern in test-modtools-spammers.spec.js.
    const context = await browser.newContext()
    const page = await context.newPage()
    try {
      await loginViaModTools(page, pressedModEmail)
      const jwt = await page.evaluate(() => {
        try {
          return JSON.parse(localStorage.getItem('auth') || '{}')?.auth?.jwt
        } catch (e) {
          return null
        }
      })
      if (jwt) {
        const { environment: env } = require('./config')
        await page.request
          .patch(`${env.apiV2BaseUrl}/lockdown`, {
            data: { action: 'liftall' },
            headers: { Authorization: jwt },
          })
          .catch(() => {})
        await page.request
          .patch(`${env.apiV2BaseUrl}/lockdown`, {
            data: { action: 'close', endnote: 'afterAll safety-net cleanup' },
            headers: { Authorization: jwt },
          })
          .catch(() => {})
      }
    } catch (e) {
      console.log(`[Lockdown afterAll] cleanup attempt failed: ${e.message}`)
    } finally {
      await context.close()
    }
  })

  test('press holds writes site-wide; member sees nothing; moderator has only Approve; lift restores delivery', async ({
    page,
    browser,
    testEnv,
    getTestEmail,
    postMessage,
    withdrawPost,
  }) => {
    // This test spans at least one real batch chat-processing cycle
    // (~1 minute) on top of several full login/logout cycles - give it more
    // room than the Playwright default.
    test.setTimeout(timeouts.background + timeouts.navigation.slowPage * 4)

    const posterEmail = getTestEmail('lockdown-poster')
    const heldItemEmail = getTestEmail('lockdown-seconditem')
    const item = `test-lockdown-reply-${Date.now()}`
    const heldItem = `test-lockdown-held-${Date.now()}`
    const replyText = `Lockdown e2e reply ${Date.now()}`

    // Step 1: B's post exists before the press, so there's something for A to
    // reply to.
    const posted = await postMessage({
      type: 'OFFER',
      item,
      description: 'Lockdown e2e: post to reply to',
      email: posterEmail,
    })
    expect(posted.id).toBeTruthy()
    console.log(`[Lockdown] Posted B's message ${posted.id}`)

    // Step 2: press the lockdown as a Support/Admin moderator.
    //
    // This runs on its own browser context (modPage), never on the member's
    // `page`. A real moderator and a real member are never the same browser
    // tab, and sharing one here bit us: clearSessionData()'s
    // localStorage.clear() only ever reaches the origin currently loaded, so
    // calling logoutIfLoggedIn(page) right after this step - while `page` was
    // still on the ModTools origin - could never clear the member site's JWT
    // from Step 1, and the next postMessage() found B's session still live
    // under a different identity than the one it expected. A context per
    // persona removes the gap by construction and matches how this is really
    // used.
    const modContext = await browser.newContext()
    const modPage = await modContext.newPage()

    pressedModEmail = testEnv.mod.email
    await loginViaModTools(modPage, testEnv.mod.email)
    await modPage.goto(`${MODTOOLS_URL}/support?tab=lockdown`, {
      timeout: timeouts.navigation.initial,
    })
    await dismissAllModals(modPage)

    const reasonBox = modPage.getByTestId('lockdown-reason')
    await reasonBox.waitFor({
      state: 'visible',
      timeout: timeouts.ui.appearance,
    })
    await reasonBox.fill('Lockdown e2e drill - security incident simulation')

    await modPage
      .getByTestId('lockdown-press-notice')
      .fill(SECURITY_NOTICE_TEXT)

    // Before pressing, the page itself says what will happen.
    await expect(
      modPage.getByTestId('lockdown-press-explanation')
    ).toContainText('Messages one member sends another wait.')
    await modPage.screenshot({
      path: path.join(PR_SCREENSHOTS_DIR, 'lockdown-0-before-pressing.png'),
      fullPage: true,
    })
    console.log('[Lockdown screenshots] what will happen, before pressing')

    const pressButton = modPage.getByTestId('lockdown-press-button')
    await expect(pressButton).toBeEnabled({ timeout: timeouts.ui.appearance })
    await pressButton.click()

    const confirmInput = modPage.getByTestId('lockdown-confirm-input')
    await confirmInput.waitFor({
      state: 'visible',
      timeout: timeouts.ui.appearance,
    })
    await confirmInput.fill('LOCKDOWN')

    const confirmPressButton = modPage.getByTestId('lockdown-confirm-press')
    await expect(confirmPressButton).toBeEnabled({
      timeout: timeouts.ui.appearance,
    })

    // ConfirmModal's b-modal is `scrollable` (its own internal modal-body
    // scroll, independent of the page), and the confirm input sits below the
    // list of areas. Filling it a moment ago auto-scrolled that internal
    // scroll box to keep the input in view, which leaves the first area
    // scrolled out of the modal before the screenshot - fullPage captures the
    // outer document, not the modal's own scroll position, so it does not
    // help. Scroll the first area back into view for the shot.
    await modPage
      .getByTestId('lockdown-confirm-modal')
      .locator('li')
      .first()
      .scrollIntoViewIfNeeded()

    await modPage.screenshot({
      path: path.join(
        PR_SCREENSHOTS_DIR,
        'lockdown-1-press-confirm-dialog.png'
      ),
      fullPage: true,
    })
    console.log('[Lockdown screenshots] press confirm dialog with LOCKDOWN')

    await confirmPressButton.click()

    await expect(modPage.getByTestId('lockdown-active-banner')).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    await expect(modPage.getByTestId('lockdown-taking-effect')).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    await expect(modPage.getByTestId('lockdown-taking-effect-api')).toBeVisible(
      {
        timeout: timeouts.ui.appearance,
      }
    )
    console.log('[Lockdown] Pressed - active banner and Taking effect visible')

    // Give real batch loops a chance to ack before the screenshot, so it
    // shows genuine "took N seconds" rows rather than every loop still
    // spinning on "waiting" - wait for at least one loop row to have
    // caught up (ModSupportLockdownTakingEffect.vue's ack.caughtup branch),
    // rather than the always-present API line, which never says "took".
    await expect
      .poll(
        async () =>
          modPage
            .locator('[data-testid^="lockdown-taking-effect-"]')
            .filter({ hasText: 'took' })
            .count(),
        {
          message: 'Waiting for at least one batch loop to ack the press',
          timeout: timeouts.background,
        }
      )
      .toBeGreaterThan(0)

    await modPage.screenshot({
      path: path.join(PR_SCREENSHOTS_DIR, 'lockdown-2-support-tab-active.png'),
      fullPage: true,
    })
    console.log('[Lockdown screenshots] Support tab active, Taking effect')

    // Step 3: a post made while the lockdown holds writes stays Pending, same
    // as any other new post - it just never gets promoted or approved for
    // anyone but a moderator to see while it holds. `page` has never left the
    // member site, so this logout is scoped correctly and actually clears B's
    // session before A's held post goes up under a different identity.
    await logoutIfLoggedIn(page)
    const heldPost = await postMessage({
      type: 'OFFER',
      item: heldItem,
      description: 'Lockdown e2e: created while pressed, stays held',
      email: heldItemEmail,
    })
    expect(heldPost.id).toBeTruthy()
    console.log(`[Lockdown] Posted held item ${heldPost.id} while pressed`)

    // Step 4: A replies to B's post. A sees it as sent (routed to /chats/...,
    // exactly like a normal reply) - the plan is explicit that nothing in the
    // member UI tells the author their message is held.
    await logoutIfLoggedIn(page)
    await loginViaHomepage(page, testEnv.user.email, 'freegle')
    await waitForAuthInLocalStorage(page)

    // The member-facing security notice: fed by the unauthenticated
    // GET /lockdown on the navbar's poll, so it should appear for a signed-in
    // member too, not just an anonymous visitor. Bounded by the background
    // timeout since the exact fetch timing (immediate vs up to a 60s poll)
    // wasn't nailed down while writing this.
    await expect(page.getByText(SECURITY_NOTICE_TEXT)).toBeVisible({
      timeout: timeouts.background,
    })
    console.log('[Lockdown] Member-facing security notice visible')

    await page.screenshot({
      path: path.join(PR_SCREENSHOTS_DIR, 'lockdown-3-member-notice.png'),
      fullPage: true,
    })
    console.log('[Lockdown screenshots] member-facing security notice')

    // Same notice at phone width, since the notice sits in the page flow
    // (never overlaying page controls) and that's worth checking narrow.
    // Reset to a normal desktop size afterwards - later steps interact with
    // the reply form and don't need a mobile layout.
    await page.setViewportSize({ width: 390, height: 844 })
    await expect(page.getByText(SECURITY_NOTICE_TEXT)).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    await page.screenshot({
      path: path.join(
        PR_SCREENSHOTS_DIR,
        'lockdown-4-member-notice-mobile.png'
      ),
      fullPage: true,
    })
    console.log(
      '[Lockdown screenshots] member-facing security notice, phone width'
    )
    await page.setViewportSize({ width: 1280, height: 800 })

    await page.gotoAndVerify(`/message/${posted.id}`, { maxRetries: 1 })
    await waitForAuthHydration(page)
    await clickReplyButton(page)
    await fillReplyForm(page, {
      replyText,
      collectText: 'Lockdown e2e collect text',
    })
    await clickSendAndWait(page)
    expect(page.url()).toContain('/chats/')
    const chatUrl = page.url()
    console.log(`[Lockdown] A sent reply, routed to ${chatUrl}`)

    // Step 5: B sees nothing. The chat room exists (B is a participant), but
    // FetchChatMessages only shows the other party rows with
    // processingsuccessful = 1, and chats:process-incoming (which flips that)
    // declines to run while the chat surface is held. So B, looking at the
    // very same chat, should not see the reply text at all.
    //
    // ChatPane.vue calls fetchMessages() without awaiting it, so
    // gotoAndVerify's hydration check (the global loading indicator only)
    // and waitForAuthHydration both resolve before the chat's own message
    // fetch has necessarily landed. Asserting not-visible before that fetch
    // completes would pass regardless of whether the reply is really being
    // filtered out - it would prove nothing. Wait for the real
    // GET .../chat/<id>/message response first.
    await logoutIfLoggedIn(page)
    await loginViaHomepage(page, posterEmail)
    await waitForAuthInLocalStorage(page)
    const bFirstLoad = page.waitForResponse(
      (r) =>
        /\/chat\/\d+\/message(\?|$)/.test(r.url()) &&
        r.request().method() === 'GET',
      { timeout: timeouts.api.slowApi }
    )
    await page.gotoAndVerify(chatUrl, { maxRetries: 1 })
    await waitForAuthHydration(page)
    await bFirstLoad
    await expect(page.getByText(replyText)).not.toBeVisible({
      timeout: timeouts.assertion.normal,
    })
    console.log('[Lockdown] B sees nothing - reply text not present')

    // Step 6: moderator sees the banner, and the held post's card shows only
    // the basic Approve button - Reject/Hold/Delete are gated off by
    // modsHeld in ModMessageButtons.vue. This must be a plain, non-exempt
    // Moderator (not testEnv.mod, which is Admin/Support and so exempt from
    // the hold) or the "only Approve" assertion would trivially pass for the
    // wrong reason. Still on modPage - no need to touch `page`'s (member)
    // session at all.
    await logoutIfLoggedIn(modPage)
    await loginViaModTools(modPage, testEnv.plainMod.email)
    await modPage.goto(`${MODTOOLS_URL}/messages/pending`, {
      timeout: timeouts.navigation.initial,
    })
    await dismissAllModals(modPage)

    await expect(modPage.getByText(/Lockdown is active/)).toBeVisible({
      timeout: timeouts.navigation.slowPage,
    })

    const groupSelect = modPage.locator('#communitieslist')
    await expect(groupSelect).toBeVisible({
      timeout: timeouts.navigation.slowPage,
    })
    await selectGroupByName(modPage, groupSelect, environment.testgroup)

    const heldCard = modPage.locator('.card', { hasText: heldItem }).first()
    await expect(heldCard).toBeVisible({
      timeout: timeouts.navigation.slowPage,
    })
    await expect(heldCard.getByRole('button', { name: 'Approve' })).toBeVisible(
      { timeout: timeouts.ui.appearance }
    )
    await expect(heldCard.getByRole('button', { name: 'Reject' })).toHaveCount(
      0
    )
    await expect(heldCard.getByRole('button', { name: 'Hold' })).toHaveCount(0)
    await expect(heldCard.getByRole('button', { name: 'Delete' })).toHaveCount(
      0
    )
    console.log('[Lockdown] Moderator card shows only Approve')

    // The "only Approve" gating above is instant (modsHeld, set synchronously
    // by the press). The "Held by lockdown" label is not: it depends on
    // lockdown:tick writing this post's lockdown_holds row, which - like the
    // chat processor - runs on its own per-minute schedule, not on press. Wait for it with the same background timeout
    // used for the "Taking effect" loops above, rather than assuming it is
    // already there by the time the pending queue is checked.
    // The pending queue does not refetch a post it already has, so reload
    // until the card is rendered from a fetch made after the hold row exists.
    await expect
      .poll(
        async () => {
          if (await heldCard.getByText(/Held by lockdown/).isVisible()) {
            return true
          }
          await modPage.reload({ timeout: timeouts.navigation.default })
          await selectGroupByName(
            modPage,
            modPage.locator('#communitieslist'),
            environment.testgroup
          )
          await heldCard
            .waitFor({
              state: 'visible',
              timeout: timeouts.navigation.slowPage,
            })
            .catch(() => {})
          return heldCard
            .getByText(/Held by lockdown/)
            .isVisible()
            .catch(() => false)
        },
        {
          message: 'Waiting for the Held by lockdown label on the pending card',
          timeout: timeouts.background,
          intervals: [10000, 15000, 20000],
        }
      )
      .toBe(true)
    console.log(
      '[Lockdown] Held-by-lockdown label appeared on the pending card'
    )

    await heldCard.scrollIntoViewIfNeeded()
    // Not fullPage: a fullPage screenshot re-renders the sticky navbar at
    // wherever it "stuck" partway down the stitched image, landing it mid-shot.
    // A plain viewport screenshot with the card already scrolled into view
    // shows the same card without that artefact.
    await modPage.screenshot({
      path: path.join(
        PR_SCREENSHOTS_DIR,
        'lockdown-5-modtools-approve-only.png'
      ),
    })
    console.log(
      '[Lockdown screenshots] ModTools banner and Approve-only pending card'
    )

    // Step 7: lift everything and close, exactly as a Support user would.
    // modPage is still signed in as plainMod from Step 6, deliberately not
    // Support/Admin, so Support Tools refuses it ("You don't have access to
    // Support Tools") and the liftall button never appears. Sign back in as
    // the Support/Admin moderator who pressed it before going there.
    await logoutIfLoggedIn(modPage)
    await loginViaModTools(modPage, pressedModEmail)
    await modPage.goto(`${MODTOOLS_URL}/support?tab=lockdown`, {
      timeout: timeouts.navigation.initial,
    })
    await dismissAllModals(modPage)

    // What is held: A's reply is listed, searchable, with its sender. The
    // hold row is written by lockdown:tick, so search again until it lands.
    await modPage.getByTestId('lockdown-subtab-held').click()
    const heldRow = modPage
      .getByTestId('lockdown-held-row')
      .filter({ hasText: replyText })
    await modPage.getByTestId('lockdown-held-search').fill(replyText)
    await expect
      .poll(
        async () => {
          await modPage.getByTestId('lockdown-held-search-button').click()
          return heldRow
            .waitFor({ state: 'visible', timeout: timeouts.ui.appearance })
            .then(() => true)
            .catch(() => false)
        },
        {
          message: "Waiting for A's reply to be listed under What is held",
          timeout: timeouts.background,
          intervals: [5000, 10000],
        }
      )
      .toBe(true)
    await modPage.getByTestId('lockdown-held-search').fill('')
    await modPage.getByTestId('lockdown-held-search-button').click()
    await expect(heldRow).toBeVisible({ timeout: timeouts.ui.appearance })
    await modPage.screenshot({
      path: path.join(PR_SCREENSHOTS_DIR, 'lockdown-6-what-is-held.png'),
      fullPage: true,
    })
    console.log('[Lockdown screenshots] What is held subtab')

    // Controls: the chat count includes A's reply.
    await modPage.getByTestId('lockdown-subtab-controls').click()
    await expect
      .poll(
        async () => {
          const text = await modPage
            .getByTestId('lockdown-count-chat')
            .textContent()
          return Number((text || '').replace(/\D/g, ''))
        },
        {
          message: 'Waiting for the held chat count to include the reply',
          timeout: timeouts.background,
        }
      )
      .toBeGreaterThan(0)
    // Nothing has been lifted yet, so there is no Releasing and no Close.
    await expect(modPage.getByTestId('lockdown-release')).toHaveCount(0)
    await expect(modPage.getByTestId('lockdown-close')).toHaveCount(0)

    // Lift one area on its own: its status changes in words, and Releasing
    // appears and fills up until chat has caught up.
    await modPage.getByTestId('lockdown-surface-button-chat').click()
    await expect(
      modPage.getByTestId('lockdown-surface-status-chat')
    ).toHaveText('Running', { timeout: timeouts.ui.appearance })
    await expect(
      modPage.getByTestId('lockdown-surface-button-chat')
    ).toHaveText('Hold again')
    console.log('[Lockdown] Lifted chat on its own')

    await expect(
      modPage.getByTestId('lockdown-release-progress-chat')
    ).toHaveText(/^\s*(\d+) of \1 gone through\s*$/, {
      timeout: timeouts.background,
    })
    await expect(modPage.getByTestId('lockdown-release-done')).toBeVisible()
    await modPage.screenshot({
      path: path.join(PR_SCREENSHOTS_DIR, 'lockdown-7-controls-held.png'),
      fullPage: true,
    })
    console.log('[Lockdown] Releasing shows chat caught up')

    await modPage.getByTestId('lockdown-liftall-button').click()
    await confirmVisibleModal(modPage, 'lockdown-liftall-confirm')

    // Close only appears once every area is lifted and the held post has
    // gone through the content check too.
    const closeNote = modPage.getByTestId('lockdown-close-note')
    await closeNote.waitFor({
      state: 'visible',
      timeout: timeouts.background,
    })
    await closeNote.fill('Lockdown e2e drill complete')
    await modPage.getByTestId('lockdown-close-button').click()
    await confirmVisibleModal(modPage, 'lockdown-close-confirm')

    // Back to the not-pressed state.
    await expect(modPage.getByTestId('lockdown-press-button')).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    console.log('[Lockdown] Lifted and closed')

    // Everything had gone through before Close was offered, so there is no
    // Releasing left to show.
    await expect(modPage.getByTestId('lockdown-release')).toHaveCount(0)
    console.log('[Lockdown] Closed with nothing left releasing')

    await modPage.screenshot({
      path: path.join(PR_SCREENSHOTS_DIR, 'lockdown-8-support-tab-closed.png'),
      fullPage: true,
    })
    console.log('[Lockdown screenshots] Support tab, lifted and closed')

    // The moderator persona's work is done - close its context so nothing
    // from here on can accidentally touch it. `page` (the member site) never
    // visited ModTools, so it needs no equivalent cleanup before Step 8.
    await modContext.close()

    // Step 8: B now sees the reply, once the next chat-processing pass (every
    // minute) has caught up. Poll rather than sleep, reloading the chat page
    // each time - the chat isn't otherwise live-updating.
    //
    // Each reload has to wait for the real chat-messages fetch to land
    // before checking visibility, for the same reason as Step 5:
    // domcontentloaded fires before ChatPane.vue's un-awaited
    // fetchMessages() resolves, so checking straight after goto races that
    // fetch and can never observe true - the poll would retry forever on a
    // check that was doomed before the reply even had a chance to appear.
    await logoutIfLoggedIn(page)
    await loginViaHomepage(page, posterEmail)
    await waitForAuthInLocalStorage(page)
    await expect
      .poll(
        async () => {
          const reloadFetched = page
            .waitForResponse(
              (r) =>
                /\/chat\/\d+\/message(\?|$)/.test(r.url()) &&
                r.request().method() === 'GET',
              { timeout: timeouts.navigation.default }
            )
            .catch(() => null)
          await page.goto(chatUrl, {
            waitUntil: 'domcontentloaded',
            timeout: timeouts.navigation.default,
          })
          await reloadFetched
          try {
            await expect(page.getByText(replyText)).toBeVisible({
              timeout: timeouts.assertion.normal,
            })
            return true
          } catch {
            return false
          }
        },
        {
          message: 'Waiting for B to see the reply after lift',
          timeout: timeouts.background,
          intervals: [10000, 15000, 20000],
        }
      )
      .toBe(true)
    console.log('[Lockdown] B now sees the reply after lift')

    // Cleanup: withdraw both posts.
    await logoutIfLoggedIn(page)
    await loginViaHomepage(page, posterEmail)
    await withdrawPost({ item })

    await logoutIfLoggedIn(page)
    await loginViaHomepage(page, heldItemEmail)
    await withdrawPost({ item: heldItem })
  })
})
// sync-marker-2 1790634727221852341
