/**
 * UX telemetry contract tests (docs/developers/reference/ux-telemetry.md section 2).
 *
 * Walks a flow in a real browser with the POSTs to /clientlog intercepted, and asserts the ordered
 * sequence of flow events the browser sent. The names come from the constants in
 * composables/useFlowTelemetry.js, so renaming an event breaks this test rather than the rollup.
 */

const { test, expect } = require('./fixtures')
const { timeouts } = require('./config')
const {
  loginViaHomepage,
  logoutIfLoggedIn,
  signUpViaHomepage,
} = require('./utils/user')
const {
  clickReplyButton,
  fillReplyForm,
  clickSendAndWait,
} = require('./utils/reply-helpers')

// The event names of the contract, written out because this spec runs in node and the composable
// is an ES module of the app. tests/unit/composables/useFlowTelemetry.spec.js pins the constants
// to the same list, so the two cannot drift without a test failing.
const E = {
  START: 'flow_start',
  ENTER: 'step_enter',
  COMPLETE_STEP: 'step_complete',
  BACK: 'step_back',
  CHOICE: 'choice_switched',
  SUBMIT: 'flow_submit',
  RESULT: 'flow_submit_result',
  DONE: 'flow_complete',
  ABANDON: 'flow_abandon',
}

/** Collect every flow event the page posts to /clientlog, in order, and the API flow headers. */
function collectFlowEvents(page) {
  const events = []
  const flowHeaders = []

  page.on('request', (req) => {
    if (req.method() === 'POST' && req.url().includes('/clientlog')) {
      try {
        const body = JSON.parse(req.postData() || '{}')
        for (const log of body.logs || []) {
          if (log.event_type === 'flow') events.push(log)
        }
      } catch {
        // Not JSON: not ours.
      }
    }

    const instance = req.headers()['x-freegle-flow-instance']
    if (instance) {
      flowHeaders.push({ method: req.method(), url: req.url(), instance })
    }
  })

  return { events, flowHeaders }
}

// The event name, with the step for the events that are about one (step_enter, step_complete...).
const sequence = (events, flow) =>
  events
    .filter((e) => e.flow === flow)
    .map((e) =>
      e.action_name.startsWith('step_')
        ? `${e.action_name}:${e.step}`
        : e.action_name
    )

/** True when `wanted` appears in `actual` in order, other entries allowed between. */
function inOrder(actual, wanted) {
  let at = 0
  for (const item of actual) {
    if (item === wanted[at]) at++
    if (at === wanted.length) return true
  }
  return false
}

async function waitForEvent(events, flow, name) {
  await expect
    .poll(() => events.some((e) => e.flow === flow && e.action_name === name), {
      // The client batches log lines and sends them every five seconds.
      timeout: 30000,
      message: `waiting for ${flow} ${name}; saw ${JSON.stringify(
        sequence(events, flow)
      )}`,
    })
    .toBe(true)
}

test.describe('UX telemetry contract', () => {
  test('mobile give flow sends its steps in order and completes', async ({
    page,
    testEmail,
    postMessage,
    withdrawPost,
  }) => {
    await page.setViewportSize({ width: 390, height: 844 })
    const { events, flowHeaders } = collectFlowEvents(page)

    const item = `test-ux-telemetry-${Date.now()}`
    const result = await postMessage({
      type: 'OFFER',
      item,
      description: 'Created by the UX telemetry contract test',
      email: testEmail,
      mobile: true,
    })
    expect(result.id).toBeTruthy()

    await waitForEvent(events, 'give', E.DONE)

    const seq = sequence(events, 'give')
    console.log('give flow events:', JSON.stringify(seq))

    expect(seq).toEqual([
      E.START,
      `${E.ENTER}:give-mobile-photos`,
      `${E.COMPLETE_STEP}:give-mobile-photos`,
      `${E.ENTER}:give-mobile-details`,
      `${E.COMPLETE_STEP}:give-mobile-details`,
      `${E.ENTER}:give-mobile-options`,
      E.CHOICE,
      `${E.COMPLETE_STEP}:give-mobile-options`,
      `${E.ENTER}:give-mobile-whereami`,
      E.SUBMIT,
      E.RESULT,
      // The step it submitted from completes with the flow.
      `${E.COMPLETE_STEP}:give-mobile-whereami`,
      E.DONE,
    ])

    const give = events.filter((e) => e.flow === 'give')

    // One instance for the whole flow, with the step sequence counting up.
    expect(new Set(give.map((e) => e.flow_instance_id)).size).toBe(1)
    expect(give[0].flow_instance_id).toMatch(/^[0-9a-f-]{36}$/)
    const seqs = give.map((e) => e.step_seq)
    expect(seqs).toEqual([...seqs].sort((a, b) => a - b))

    // The envelope.
    expect(give.every((e) => e.event_type === 'flow')).toBe(true)
    expect(give.every((e) => e.variant === '')).toBe(true)
    expect(give[0].entry).toBeTruthy()
    expect(give.find((e) => e.action_name === E.CHOICE)).toMatchObject({
      field: 'delivery',
      from: 'off',
      to: 'on',
    })
    expect(give.find((e) => e.action_name === E.RESULT)).toMatchObject({
      ok: true,
      status: 200,
    })

    // Every line also carries the common fields.
    for (const e of give) {
      expect(e.device_class).toBe('mobile')
      expect(typeof e.logged_in).toBe('boolean')
      expect(e.page_route).toBeTruthy()
    }

    // No typed text anywhere in what was sent.
    expect(JSON.stringify(give)).not.toContain(item)
    expect(JSON.stringify(give)).not.toContain(testEmail)

    // The submit request was tagged with the instance, so the API line can be joined to it.
    expect(
      flowHeaders.some(
        (h) =>
          h.method === 'PUT' &&
          h.url.includes('/message') &&
          h.instance === give[0].flow_instance_id
      )
    ).toBe(true)

    // The cleanup helper drives the desktop layout.
    await page.setViewportSize({ width: 1280, height: 900 })
    await withdrawPost({ item: result.item })
  })

  test('leaving the give flow part way sends an abandon', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 })
    const { events } = collectFlowEvents(page)

    await page.gotoAndVerify('/give/mobile/photos', {
      timeout: timeouts.navigation.initial,
      waitUntil: 'domcontentloaded',
      maxRetries: 1,
    })
    await expect
      .poll(() => events.some((e) => e.action_name === 'step_enter'), {
        timeout: 30000,
      })
      .toBe(true)

    // A client side navigation, as a member tapping a link would make. (A full page load would be
    // a different reason: hidden.)
    await page.evaluate(() =>
      document
        .getElementById('__nuxt')
        .__vue_app__.config.globalProperties.$router.push('/')
    )

    await waitForEvent(events, 'give', E.ABANDON)
    const abandon = events.find(
      (e) => e.flow === 'give' && e.action_name === E.ABANDON
    )
    expect(abandon).toMatchObject({
      reason: 'navigated',
      last_step: 'give-mobile-photos',
    })
  })

  test('reply flow: typing, sending and completing', async ({
    page,
    postMessage,
    testEmail,
    getTestEmail,
    withdrawPost,
  }) => {
    const posterEmail = getTestEmail('ux-telemetry')
    const result = await postMessage({
      type: 'OFFER',
      item: `test-ux-reply-${Date.now()}`,
      description: 'Item for the UX telemetry reply test',
      email: posterEmail,
    })
    expect(result.id).toBeTruthy()

    await logoutIfLoggedIn(page)
    await signUpViaHomepage(page, testEmail)

    const { events } = collectFlowEvents(page)

    await page.gotoAndVerify(`/message/${result.id}`, { maxRetries: 1 })
    await clickReplyButton(page)
    await fillReplyForm(page, {
      replyText: 'Telemetry contract reply',
      collectText: 'Anytime',
    })
    await clickSendAndWait(page)

    await waitForEvent(events, 'reply', E.DONE)

    const seq = sequence(events, 'reply')
    console.log('reply flow events:', JSON.stringify(seq))

    expect(seq[0]).toBe(E.START)
    expect(seq.at(-1)).toBe(E.DONE)
    expect(
      inOrder(seq, [
        E.START,
        `${E.ENTER}:COMPOSING`,
        E.SUBMIT,
        `${E.ENTER}:VALIDATING`,
        E.RESULT,
        E.DONE,
      ])
    ).toBe(true)

    const reply = events.filter((e) => e.flow === 'reply')
    expect(new Set(reply.map((e) => e.flow_instance_id)).size).toBe(1)
    expect(reply.find((e) => e.action_name === E.RESULT)).toMatchObject({
      ok: true,
    })
    expect(JSON.stringify(reply)).not.toContain('Telemetry contract reply')

    await logoutIfLoggedIn(page)
    await loginViaHomepage(page, posterEmail)
    await withdrawPost({ item: result.item })
  })

  test('login flow: open, submit, complete', async ({
    page,
    postMessage,
    getTestEmail,
    withdrawPost,
  }) => {
    const email = getTestEmail('ux-login')
    const result = await postMessage({
      type: 'OFFER',
      item: `test-ux-login-${Date.now()}`,
      description: 'Item for the UX telemetry login test',
      email,
    })
    expect(result.id).toBeTruthy()

    await logoutIfLoggedIn(page)
    const { events } = collectFlowEvents(page)

    await loginViaHomepage(page, email)

    await waitForEvent(events, 'login', E.DONE)

    const seq = sequence(events, 'login')
    console.log('login flow events:', JSON.stringify(seq))

    expect(seq[0]).toBe(E.START)
    expect(seq.at(-1)).toBe(E.DONE)
    expect(
      inOrder(
        seq.map((s) => s.split(':')[0]),
        [E.START, E.ENTER, E.SUBMIT, E.RESULT, E.DONE]
      )
    ).toBe(true)

    const login = events.filter((e) => e.flow === 'login')
    expect(login.find((e) => e.action_name === E.RESULT)).toMatchObject({
      ok: true,
    })
    expect(JSON.stringify(login)).not.toContain(email)

    await withdrawPost({ item: result.item })
  })
})
