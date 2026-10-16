/**
 * The member question card (components/MicroVolunteeringUxPrompt.vue) on a phone.
 *
 * This spec fails when a card needs scrolling at 390 by 660 or at 360 by 640, and says by how much.
 * It checks the real card in the real modal, in the font the CI image has (DejaVu Sans, about 10
 * percent wider than the fonts phones use), so a card that passes here passes on a phone.
 * docs/developers/reference/ux-member-surveys.md.
 *
 * The questions are canned: the session says the member is old enough, trusted and in a community
 * that allows microvolunteering, and the microvolunteering API is answered from a queue of prompts
 * this spec writes, so no row is needed in the database and the answers it posts are kept to be
 * checked, never stored.
 *
 * What is measured, after every screen, is whether the body of the modal scrolls. The modal is capped
 * by CSS (assets/css/global.scss), so its own height never goes above the cap even when the content is
 * cut off: the pass or fail is "the body scrolls", with the figures in the message.
 */
const path = require('path')
const { test, expect } = require('./fixtures')
const { SCREENSHOTS_DIR } = require('./config')
const { loginViaHomepage } = require('./utils/user')

const PASSWORD = 'freegle'

// The room the card is checked against is the dialog's height minus the banner allowance
// (assets/css/global.scss and sticky-banner.scss), less this margin for the font a phone uses.
const FONT_MARGIN = 16
const MIN_BUTTON = 44

// ---- the canned questions, at the longest the limits allow ----

// Filler for the stress fixture only: a string of exactly n characters made of short words, so a
// wrapped line is a real one. The seed sets where in the pool it starts, so two seeds give two strings.
const POOL = [
  'photo',
  'upload',
  'fails',
  'on',
  'my',
  'phone',
  'each',
  'time',
  'I',
  'try',
  'it',
  'and',
  'then',
  'the',
  'page',
  'stops',
  'with',
  'no',
  'reason',
  'given',
]
function words(n, seed) {
  let s = ''
  let i = [...seed].reduce((sum, c) => sum + c.charCodeAt(0), 0)
  while (s.length < n) s += (s ? ' ' : '') + POOL[i++ % POOL.length]
  s = s.slice(0, n).trim()
  while (s.length < n) s += 'x'
  return s
}

const AREAS = [
  { key: 'give', label: 'Offering something' },
  { key: 'ask', label: 'Asking for something' },
  { key: 'reply', label: 'Replying to a post' },
  { key: 'chat_promise', label: 'Arranging a collection in chat' },
  { key: 'chat_outcome', label: 'Marking a post taken or received' },
  { key: 'login', label: 'Signing in or joining' },
  { key: 'settings', label: 'Settings and email choices' },
]
const AWARE_OPTIONS = [
  { key: 'yes', label: 'Yes' },
  { key: 'no', label: 'No' },
  { key: 'not_sure', label: 'Not sure' },
]
// 48 characters and 9 words: the longest question the limits allow, 14 words and 68 characters.
const LONGEST_ACTION = 'get a message when somebody replies to your post'
// A decoy asks about something Freegle does not do. This action is 46 characters and 9 words, so its
// question has 14 words too. Its reveal is the fixed line and then a reason of exactly 160 characters.
const DECOY_ACTION = 'pay to have your offer shown first to everyone'
const DECOY_REASON =
  'Freegle is free to use, so nobody can pay to have their offer shown first. Offers are shown to the people who live close by, in the order that they were posted.'
const DECOY_REVEAL = 'Freegle does not do this. ' + DECOY_REASON

// Real problem titles, as a member reads them in the follow-up: three of exactly 60 characters, the
// limit, for the longest case, and three of 40 for the normal case at 360 by 640.
const FOLLOW_UP_TITLES = {
  60: [
    'I have to sign in again each time I open Freegle on my phone',
    'My post is still waiting for approval after three whole days',
    'I get an email for every single reply and cannot turn it off',
  ],
  40: [
    'Photos take far too long to upload today',
    "People say they will come but then don't",
    'My post waited far too long for approval',
  ],
}

let nextId = 9100
const canned = (kind, over) => ({
  id: nextId++,
  kind,
  question: '',
  body: '',
  options: [],
  images: [],
  settings: {},
  ...over,
})

const openProblems = () =>
  canned('open', {
    question: 'Did you have a problem the last time you used Freegle?',
    options: [
      { key: 'nothing', label: 'I had no problems' },
      { key: 'dont_understand', label: "I don't understand" },
    ],
    settings: {
      preset: 'problems',
      randomise: false,
      placeholder: 'If yes, what happened?',
    },
  })
const openImprove = () =>
  canned('open', {
    question: 'What one thing would you change about Freegle?',
    options: [
      { key: 'nothing', label: "I can't think of one" },
      { key: 'dont_understand', label: "I don't understand" },
    ],
    settings: {
      preset: 'improve',
      randomise: false,
      placeholder: 'What would that help you do?',
    },
  })
// The four areas the server picked, including the longest label, in the order it sent them.
const areaSurvey = (display = ['give', 'ask', 'reply', 'chat_outcome']) =>
  canned('survey', {
    question: 'Which part of Freegle works best for you?',
    options: AREAS,
    display,
    settings: {
      show: 4,
      worst_label: 'Which other part most needs improving? (optional)',
      none_label: 'None of these',
      randomise: true,
    },
  })
const followUp = (titleLength = 60) => {
  const titles = FOLLOW_UP_TITLES[titleLength]
  return canned('survey', {
    question:
      'Which of these has been your biggest problem in the last 3 months?',
    options: titles.map((label, i) => ({ key: `problem:${i + 1}`, label })),
    display: titles.map((_, i) => `problem:${i + 1}`),
    settings: { show: 3, none_label: 'None of these' },
  })
}
const awareness = (action = LONGEST_ACTION, over = {}) =>
  canned('awareness', {
    question: `Do you know how to ${action}?`,
    options: AWARE_OPTIONS,
    ...over,
  })
const decoy = () =>
  awareness(DECOY_ACTION, {
    settings: { reveal: DECOY_REVEAL, link: '/settings' },
  })
// Nine long options: the control that proves this test can fail.
const tooTall = () =>
  canned('survey', {
    question: 'Which part of Freegle works best for you?',
    options: Array.from({ length: 9 }, (_, i) => ({
      key: `k${i}`,
      label: words(56, 'q' + i),
    })),
    display: Array.from({ length: 9 }, (_, i) => `k${i}`),
    settings: { show: 9 },
  })

// ---- the page ----

/**
 * After the member is logged in: the session says they may be asked (an old account, a trusted one, in
 * a community that allows it), and the microvolunteering API is served from the queue. A POST is kept
 * in `posted` and answered as the real API does.
 */
async function installCannedApi(page, queue, posted, groupId) {
  await page.route(
    (url) => url.pathname.endsWith('/session'),
    async (route) => {
      if (route.request().method() !== 'GET') return route.continue()
      const response = await route.fetch()
      let body
      try {
        body = await response.json()
      } catch {
        return route.fulfill({ response })
      }
      if (body.me) {
        body.me.added = '2020-01-01T00:00:00Z'
        body.me.trustlevel = 'Basic'
      }
      const groups = Array.isArray(body.groups) ? body.groups : []
      if (!groups.length) {
        groups.push({
          groupid: groupId || 1,
          role: 'Member',
          emailfrequency: 24,
          eventsallowed: 1,
          volunteeringallowed: 1,
          configid: null,
          added: '2020-01-01 00:00:00',
        })
      }
      groups.forEach((g) => {
        g.microvolunteeringallowed = 1
      })
      body.groups = groups
      return route.fulfill({ response, json: body })
    }
  )

  await page.route(
    (url) => url.pathname.endsWith('/microvolunteering'),
    async (route) => {
      const request = route.request()
      if (request.method() === 'POST') {
        try {
          posted.push(JSON.parse(request.postData() || '{}'))
        } catch {
          posted.push({ unreadable: true })
        }
        return route.fulfill({ json: { ret: 0, status: 'Success' } })
      }
      // Whatever types were asked for, the next canned question; none left is an empty answer.
      const next = queue.shift()
      return route.fulfill({
        json: next ? { type: 'UxPrompt', uxprompt: next } : {},
      })
    }
  )
}

/** Open a page that mounts the wrapper, asking again straight away. Never a timer: the card is waited for. */
async function openMessagePage(page, testEnv) {
  const offer = testEnv.messages && testEnv.messages.offer
  expect(
    offer,
    'the seeded test offer is missing from the test environment, so there is no message page to open'
  ).toBeTruthy()
  // The wrapper asks at most once an hour; take that memory out of the persisted store.
  await page.evaluate(() => {
    try {
      const raw = localStorage.getItem('misc')
      if (raw) {
        const misc = JSON.parse(raw)
        if (misc && misc.vals) delete misc.vals.microvolunteeringlastask
        localStorage.setItem('misc', JSON.stringify(misc))
      }
    } catch {
      // Nothing stored yet: nothing to remove.
    }
  })
  await page.gotoAndVerify(`/message/${offer}`, {
    waitUntil: 'domcontentloaded',
  })
}

/** Wait for the card of this prompt, drawn, with the fonts loaded, and no timer. */
async function waitForCard(page, prompt) {
  const card = page.locator('[data-testid="ux-card"]')
  await expect(
    card,
    `the card for prompt ${prompt.id} never appeared`
  ).toHaveAttribute('data-prompt-id', String(prompt.id), { timeout: 120000 })
  await page.evaluate(() => document.fonts.ready)
  // The font that decides the result is the one forced below, and it must have been used for the measure.
  await page.addStyleTag({
    content:
      '.modal-content { font-family: "DejaVu Sans", Verdana, sans-serif !important; } [id*="devtools"],[class*="devtools"]{display:none!important}',
  })
  await page.evaluate(() => document.fonts.ready)
  return card
}

/** The figures of the card on screen now. Read in the page, so nothing here is an estimate. */
function measureInPage() {
  const dialog = document.querySelector('.modal.show .modal-dialog')
  const content = document.querySelector('.modal.show .modal-content')
  const body = content.querySelector('.modal-body')
  const allowance = window.innerHeight >= 600 ? 123 : 73
  const limit = Math.floor(dialog.getBoundingClientRect().height) - allowance
  const natural =
    content.querySelector('.modal-header').offsetHeight +
    body.scrollHeight +
    content.querySelector('.modal-footer').offsetHeight +
    2
  const card = content.querySelector('[data-testid="ux-card"]')
  const box = card.getBoundingClientRect()
  return {
    viewport: `${window.innerWidth} by ${window.innerHeight}`,
    limit,
    natural,
    bodyScroll: body.scrollHeight - body.clientHeight,
    smallButtons: [...card.querySelectorAll('button')]
      .filter((b) => b.offsetHeight < 44)
      .map((b) => `${b.textContent.trim()} (${b.offsetHeight}px)`),
    wider: [...card.querySelectorAll('*')]
      .filter((e) => e.getBoundingClientRect().right > box.right + 1)
      .map(
        (e) => e.tagName.toLowerCase() + '.' + String(e.className).split(' ')[0]
      ),
    sideways: document.documentElement.scrollWidth - window.innerWidth,
    bodySideways: body.scrollWidth - body.clientWidth,
    pictures: card.querySelectorAll('img').length,
    note: /This is something members see/.test(content.textContent || ''),
    commentOpen: !!card.querySelector('[data-testid="comment-text"]'),
  }
}

/**
 * The card does not need scrolling: natural fits the room less the font margin, and the body does not
 * scroll. A state that must not scroll is measured with the comment box closed, and the card keeps an
 * open box across the steps, so an open box would be measured here and called the closed card.
 */
async function expectFits(page, label) {
  const m = await page.evaluate(measureInPage)
  const where = `${label} at ${m.viewport}: natural ${m.natural}px, room ${m.limit - FONT_MARGIN}px (limit ${m.limit} less ${FONT_MARGIN}), body scrolls by ${m.bodyScroll}px`
  // The figures go in the run's log, so the docs can quote the measured heights.
  console.log(`CARD ${where}`)
  expect(
    m.commentOpen,
    `${where}. The comment box is open: measure a state that must not scroll with it closed, and check an open box as usable.`
  ).toBe(false)
  expect(
    m.natural,
    `${where}. The card is too tall: tighten gaps and padding, never the limits.`
  ).toBeLessThanOrEqual(m.limit - FONT_MARGIN)
  expect(m.bodyScroll, `${where}. The body scrolls.`).toBeLessThanOrEqual(1)
  expect(m.smallButtons, `${label}: a button is under ${MIN_BUTTON}px`).toEqual(
    []
  )
  expect(m.wider, `${label}: something is wider than the card`).toEqual([])
  expect(m.sideways, `${label}: the page scrolls sideways`).toBeLessThanOrEqual(
    0
  )
  expect(
    m.bodySideways,
    `${label}: the modal scrolls sideways`
  ).toBeLessThanOrEqual(0)
  expect(m.pictures, `${label}: more than one picture`).toBeLessThanOrEqual(1)
  return m
}

/** Whether a button is in view, not covered, and clickable where it is drawn. */
async function inView(page, locator) {
  const box = await locator.boundingBox()
  if (!box) return { ok: false, why: 'it has no box' }
  const size = page.viewportSize()
  if (
    box.y < 0 ||
    box.y + box.height > size.height + 0.5 ||
    box.x < 0 ||
    box.x + box.width > size.width + 0.5
  ) {
    return {
      ok: false,
      why: `it is at ${Math.round(box.y)} to ${Math.round(box.y + box.height)} and the screen is ${size.height} high`,
    }
  }
  const topmost = await locator.evaluate((el) => {
    // A disabled button has pointer-events: none, which the page hit-tests straight through, so
    // it is switched on for this look and put back.
    const was = el.style.getPropertyValue('pointer-events')
    const priority = el.style.getPropertyPriority('pointer-events')
    el.style.setProperty('pointer-events', 'auto', 'important')
    const r = el.getBoundingClientRect()
    const hit = document.elementFromPoint(
      r.left + r.width / 2,
      r.top + r.height / 2
    )
    if (was) el.style.setProperty('pointer-events', was, priority)
    else el.style.removeProperty('pointer-events')
    return !!hit && (el === hit || el.contains(hit))
  })
  return topmost ? { ok: true } : { ok: false, why: 'something covers it' }
}

/** On a short screen the body may scroll, but the way to answer and the way to skip stay in view. */
async function expectUsable(page, label, { answer, other }) {
  for (const [name, locator] of [
    ['the main button', answer],
    ['the other button', other],
  ].filter(([, l]) => l)) {
    const seen = await inView(page, locator)
    expect(
      seen.ok,
      `${label} at ${page.viewportSize().width} by ${page.viewportSize().height}: ${name} is not usable: ${seen.why}`
    ).toBe(true)
  }
}

/**
 * A picture of the whole screen, one file per screen, in a folder the clean-up of a passing run leaves
 * alone. The buttons change colour over 150 ms, so the transitions are let finish first and the picture
 * shows the settled colours.
 */
async function shot(page, name) {
  await page.evaluate(() =>
    Promise.all(
      document
        .getAnimations()
        .filter((a) => a instanceof CSSTransition)
        .map((a) => a.finished.catch(() => {}))
    )
  )
  await page.screenshot({
    path: path.join(SCREENSHOTS_DIR, 'member-card', `${name}.png`),
  })
}

const BIG = { width: 390, height: 660 }
const SMALL = { width: 360, height: 640 }
const SHORT = [
  { width: 375, height: 553 },
  { width: 390, height: 400 },
]

const t = (page, id) => page.locator(`[data-testid="${id}"]`)

/** A card at 390 by 660: shown, measured, and then at the other sizes where it only has to be usable. */
async function fitsAndUsable(page, label, { answer, other, name }) {
  await page.setViewportSize(BIG)
  await expectFits(page, label)
  if (name) await shot(page, name)
  for (const size of SHORT) {
    await page.setViewportSize(size)
    await expectUsable(page, label, { answer, other })
  }
  await page.setViewportSize(BIG)
}

test.describe('The member question card on a phone', () => {
  test.describe.configure({ mode: 'serial' })
  test.setTimeout(900000)

  /** Log in as a member, at the phone size, and put the canned API in. */
  async function memberVisit(page, testEnv, prompts) {
    await page.setViewportSize(BIG)
    await loginViaHomepage(page, testEnv.user.email, PASSWORD)
    const posted = []
    const queue = [...prompts]
    await installCannedApi(
      page,
      queue,
      posted,
      testEnv.group && testEnv.group.id
    )
    await openMessagePage(page, testEnv)
    return { posted, queue }
  }

  test('the canned words are real, differ from each other and are as long as the limits say', () => {
    for (const [length, titles] of Object.entries(FOLLOW_UP_TITLES)) {
      expect(new Set(titles).size, `three different titles of ${length}`).toBe(
        3
      )
      for (const title of titles) {
        expect(title.length, `"${title}" is ${length} characters`).toBe(
          Number(length)
        )
      }
    }
    expect(followUp(60).options.map((o) => o.label)).toEqual(
      FOLLOW_UP_TITLES[60]
    )

    expect(DECOY_REASON.length, 'the reason after the fixed line').toBe(160)
    expect(
      DECOY_REVEAL.length,
      'the stored reveal: the fixed line, a space and the reason'
    ).toBe(186)
    expect(DECOY_ACTION.length).toBeLessThanOrEqual(50)
    expect(decoy().question.split(/\s+/)).toHaveLength(14)

    // The stress fixture is filler, but its nine options are not the same nine words.
    const filler = tooTall().options.map((o) => o.label)
    expect(new Set(filler).size, 'nine different long options').toBe(9)
    for (const label of filler) expect(label.length).toBe(56)
  })

  test('open questions fit 390 by 660 with the longest text, and are usable on a short screen', async ({
    page,
    testEnv,
  }) => {
    const first = openProblems()
    const second = openProblems()
    const third = openImprove()
    const { posted } = await memberVisit(page, testEnv, [first, second, third])

    // An empty open question.
    await waitForCard(page, first)
    const send = t(page, 'submit-prompt')
    const skip = t(page, 'skip-prompt')
    await expect(t(page, 'open-text')).toHaveAttribute(
      'placeholder',
      'If yes, what happened?'
    )
    await fitsAndUsable(page, 'open question, empty', {
      answer: send,
      other: skip,
      name: 'open-problems-empty',
    })

    // One desktop picture of the same card.
    await page.setViewportSize({ width: 1280, height: 800 })
    await shot(page, 'desktop-open-problems-empty')
    await page.setViewportSize(BIG)

    // 1,000 characters typed: the box does not grow, it scrolls inside.
    await t(page, 'open-text').fill('x'.repeat(1000))
    await fitsAndUsable(page, 'open question, 1,000 characters', {
      answer: send,
      other: skip,
      name: 'open-problems-1000-characters',
    })

    // At 390 by 400 with the box focused (the keyboard is up): the question, the box and Send are all in view.
    await page.setViewportSize(SHORT[1])
    await t(page, 'open-text').focus()
    for (const [what, locator] of [
      ['the question', page.locator('.ux-question')],
      ['the box', t(page, 'open-text')],
      ['Send', send],
    ]) {
      const seen = await inView(page, locator)
      expect(
        seen.ok,
        `with the box focused at 390 by 400, ${what} is not in view: ${seen.why}`
      ).toBe(true)
    }
    await page.setViewportSize(BIG)

    // Send posts the text, and the next question comes.
    await send.click()
    await waitForCard(page, second)
    expect(posted[0]).toMatchObject({
      ux_prompt_id: first.id,
      ux_answer: { text: 'x'.repeat(1000) },
    })
    expect(
      posted[0].ux_answer.shown,
      'an open answer is the text alone'
    ).toBeUndefined()

    // A way out picked.
    await t(page, 'escape-nothing').click()
    await expect(t(page, 'escape-nothing')).toHaveAttribute(
      'aria-pressed',
      'true'
    )
    await fitsAndUsable(page, 'open question, a way out picked', {
      answer: send,
      other: skip,
      name: 'open-problems-way-out-picked',
    })
    await send.click()
    await waitForCard(page, third)
    expect(posted[1]).toMatchObject({
      ux_prompt_id: second.id,
      ux_answer: { choice: 'nothing' },
    })

    // The longest of the three open questions, empty.
    await expect(t(page, 'open-text')).toHaveAttribute(
      'placeholder',
      'What would that help you do?'
    )
    await fitsAndUsable(page, 'open question about what to change', {
      answer: send,
      other: skip,
      name: 'open-improve-empty',
    })
    await skip.click()
    await expect
      .poll(() => posted.length, { message: 'the skip was not posted' })
      .toBe(3)
    expect(posted[2]).toMatchObject({
      ux_prompt_id: third.id,
      ux_answer: { skipped: true },
    })
  })

  test('the area survey fits both steps, and posts the picks with what was shown', async ({
    page,
    testEnv,
  }) => {
    const survey = areaSurvey()
    const { posted } = await memberVisit(page, testEnv, [survey])
    await waitForCard(page, survey)

    const next = t(page, 'step-next')
    const skip = t(page, 'skip-prompt')
    await expect(page.locator('[data-testid="ux-card"]')).toHaveAttribute(
      'data-step',
      '1'
    )
    await fitsAndUsable(page, 'area survey, step 1', {
      answer: next,
      other: skip,
      name: 'area-step-1-empty',
    })

    // One picked: the longest label.
    await t(page, 'best-chat_outcome').click()
    await expect(t(page, 'best-chat_outcome')).toHaveAttribute(
      'aria-pressed',
      'true'
    )
    await fitsAndUsable(page, 'area survey, step 1, one picked', {
      answer: next,
      other: skip,
      name: 'area-step-1-one-picked',
    })

    // Next shows the second question, with the other options and the first pick left out. Both steps are
    // measured with the comment box closed, as every state that must not scroll is.
    await next.click()
    await expect(page.locator('[data-testid="ux-card"]')).toHaveAttribute(
      'data-step',
      '2'
    )
    await expect(
      t(page, 'worst-chat_outcome'),
      'the first pick is not offered again'
    ).toHaveCount(0)
    await expect(t(page, 'worst-give')).toBeVisible()
    await expect(
      page.locator('[data-testid^="escape-"]'),
      'the second question has no way out'
    ).toHaveCount(0)
    const send = t(page, 'submit-prompt')
    const back = t(page, 'step-back')
    await fitsAndUsable(page, 'area survey, step 2', {
      answer: send,
      other: back,
      name: 'area-step-2-empty',
    })
    await t(page, 'worst-reply').click()
    await expect(t(page, 'worst-reply')).toHaveAttribute('aria-pressed', 'true')
    await fitsAndUsable(page, 'area survey, step 2, one picked', {
      answer: send,
      other: back,
      name: 'area-step-2-one-picked',
    })

    // Back keeps the first pick.
    await back.click()
    await expect(t(page, 'best-chat_outcome')).toHaveAttribute(
      'aria-pressed',
      'true'
    )

    // The comment box, open with 300 characters, is only checked to be usable: the body may scroll.
    await t(page, 'comment-toggle').click()
    await t(page, 'comment-text').fill('c'.repeat(300))
    for (const size of [BIG, ...SHORT]) {
      await page.setViewportSize(size)
      await expectUsable(page, 'area survey, step 1, comment open', {
        answer: next,
        other: skip,
      })
    }
    await page.setViewportSize(BIG)
    await shot(page, 'area-step-1-comment-open')

    // The comment stays when the member goes on to the second question, which is checked to be usable too.
    await next.click()
    await expect(page.locator('[data-testid="ux-card"]')).toHaveAttribute(
      'data-step',
      '2'
    )
    await expect(
      t(page, 'comment-text'),
      'the comment stays across the steps'
    ).toHaveValue('c'.repeat(300))
    for (const size of [BIG, ...SHORT]) {
      await page.setViewportSize(size)
      await expectUsable(page, 'area survey, step 2, comment open', {
        answer: send,
        other: back,
      })
    }
    await page.setViewportSize(BIG)

    // One POST at the end, from what was shown, with the comment as comments. A second pick replaces
    // the first, because there is only one.
    await t(page, 'worst-ask').click()
    await send.click()
    await expect
      .poll(() => posted.length, { message: 'the answer was not posted' })
      .toBe(1)
    expect(posted[0]).toMatchObject({
      ux_prompt_id: survey.id,
      ux_answer: {
        best: 'chat_outcome',
        worst: 'ask',
        shown: ['give', 'ask', 'reply', 'chat_outcome'],
      },
      comments: 'c'.repeat(300),
    })
    expect(posted).toHaveLength(1)
  })

  test('the follow-up and the awareness questions fit, and a decoy reveal fits', async ({
    page,
    testEnv,
  }) => {
    const follow = followUp(60)
    const aware = awareness()
    const decoyQuestion = decoy()
    const { posted } = await memberVisit(page, testEnv, [
      follow,
      aware,
      decoyQuestion,
    ])

    // The follow-up: three titles of exactly 60 characters, one pick, no second step.
    await waitForCard(page, follow)
    const send = t(page, 'submit-prompt')
    const skip = t(page, 'skip-prompt')
    await expect(page.locator('[data-testid="ux-card"]')).toHaveAttribute(
      'data-step',
      '1'
    )
    await expect(
      t(page, 'step-next'),
      'a follow-up has one question, so Send and not Next'
    ).toHaveCount(0)
    await fitsAndUsable(page, 'follow-up', {
      answer: send,
      other: skip,
      name: 'follow-up-empty',
    })
    await t(page, 'best-problem:2').click()
    await fitsAndUsable(page, 'follow-up, one picked', {
      answer: send,
      other: skip,
      name: 'follow-up-one-picked',
    })
    await send.click()
    await waitForCard(page, aware)
    expect(posted[0]).toMatchObject({
      ux_prompt_id: follow.id,
      ux_answer: {
        best: 'problem:2',
        shown: ['problem:1', 'problem:2', 'problem:3'],
      },
    })
    expect(
      posted[0].ux_answer.worst,
      'a follow-up takes one pick'
    ).toBeUndefined()

    // Awareness: the longest question, and Yes, No and Not sure in one row.
    await fitsAndUsable(page, 'awareness question', {
      answer: send,
      other: skip,
      name: 'awareness-empty',
    })
    const yes = t(page, 'choice-yes')
    const no = t(page, 'choice-no')
    const notSure = t(page, 'choice-not_sure')
    const rows = await Promise.all(
      [yes, no, notSure].map((l) => l.boundingBox())
    )
    expect(
      new Set(rows.map((b) => Math.round(b.y))).size,
      'Yes, No and Not sure are in one row'
    ).toBe(1)
    expect(
      new Set(rows.map((b) => Math.round(b.width))).size,
      'and are the same width'
    ).toBe(1)
    await no.click()
    await fitsAndUsable(page, 'awareness question, chosen', {
      answer: send,
      other: skip,
      name: 'awareness-chosen',
    })

    // The comment box, open, is only checked to be usable: the body may scroll.
    await t(page, 'comment-toggle').click()
    await t(page, 'comment-text').fill('d'.repeat(300))
    for (const size of [BIG, ...SHORT]) {
      await page.setViewportSize(size)
      await expectUsable(page, 'awareness question, comment open', {
        answer: send,
        other: skip,
      })
    }
    await page.setViewportSize(BIG)
    await shot(page, 'awareness-comment-open')

    await send.click()
    await waitForCard(page, decoyQuestion)
    expect(posted[1]).toMatchObject({
      ux_prompt_id: aware.id,
      ux_answer: { choice: 'no', shown: ['yes', 'no', 'not_sure'] },
      comments: 'd'.repeat(300),
    })

    // A decoy: the answer screen, which looks like any other awareness question and has no picture of
    // its own, then the reveal of 186 characters with its link.
    await fitsAndUsable(page, 'awareness decoy, the answer screen', {
      answer: send,
      other: skip,
    })
    await yes.click()
    await send.click()
    await expect(t(page, 'reveal')).toBeVisible()
    await expect(page.locator('[data-testid="ux-card"]')).toHaveAttribute(
      'data-step',
      'reveal'
    )
    expect(await t(page, 'reveal-text').innerText()).toBe(DECOY_REVEAL)
    const nextButton = t(page, 'reveal-next')
    const link = t(page, 'reveal-link')
    await expect(link).toHaveAttribute('href', '/settings')
    // The link is above Next; on a short screen it can scroll away, but Next stays.
    await expect(link).toBeVisible()
    await fitsAndUsable(page, 'awareness decoy, the reveal', {
      answer: nextButton,
      other: null,
      name: 'awareness-decoy-reveal',
    })
    expect(posted[2]).toMatchObject({
      ux_prompt_id: decoyQuestion.id,
      ux_answer: { choice: 'yes' },
    })
  })

  test('at 360 by 640 the preset questions with normal content do not scroll', async ({
    page,
    testEnv,
  }) => {
    const prompts = [
      openProblems(),
      areaSurvey(['give', 'ask', 'reply', 'login']),
      followUp(40),
      awareness('promise an item to someone in a chat'),
    ]
    await memberVisit(page, testEnv, prompts)
    await page.setViewportSize(SMALL)

    await waitForCard(page, prompts[0])
    await expectFits(page, 'open question, normal content')
    await t(page, 'skip-prompt').click()

    await waitForCard(page, prompts[1])
    await expectFits(page, 'area survey, step 1, normal content')
    await t(page, 'best-give').click()
    await t(page, 'step-next').click()
    await expect(page.locator('[data-testid="ux-card"]')).toHaveAttribute(
      'data-step',
      '2'
    )
    await expectFits(page, 'area survey, step 2, normal content')
    await t(page, 'submit-prompt').click()

    await waitForCard(page, prompts[2])
    await expectFits(page, 'follow-up, normal content')
    await t(page, 'skip-prompt').click()

    await waitForCard(page, prompts[3])
    await expectFits(page, 'awareness question, normal content')
  })

  test('a moderator sees the same card as a member, with no note, and it does not scroll', async ({
    page,
    testEnv,
  }) => {
    const heavy = areaSurvey()
    const reveal = decoy()
    await page.setViewportSize(BIG)
    await loginViaHomepage(page, testEnv.mod.email, PASSWORD)
    const posted = []
    await installCannedApi(
      page,
      [heavy, reveal],
      posted,
      testEnv.group && testEnv.group.id
    )
    await openMessagePage(page, testEnv)

    await waitForCard(page, heavy)
    const m = await expectFits(page, 'moderator, area survey, step 1')
    expect(
      m.note,
      'the note "This is something members see" is not drawn for a question about Freegle'
    ).toBe(false)
    await shot(page, 'moderator-area-step-1')
    await t(page, 'best-give').click()
    await t(page, 'step-next').click()
    await t(page, 'submit-prompt').click()

    await waitForCard(page, reveal)
    await t(page, 'choice-yes').click()
    await t(page, 'submit-prompt').click()
    await expect(t(page, 'reveal')).toBeVisible()
    const r = await expectFits(page, 'moderator, decoy reveal')
    expect(r.note).toBe(false)
  })

  test('the control: a card with nine long options is taller than the room, so this test can fail', async ({
    page,
    testEnv,
  }) => {
    const tall = tooTall()
    await memberVisit(page, testEnv, [tall])
    await waitForCard(page, tall)

    const m = await page.evaluate(measureInPage)
    console.log(
      `CARD control, nine long options at ${m.viewport}: natural ${m.natural}px, room ${m.limit - FONT_MARGIN}px, body scrolls by ${m.bodyScroll}px`
    )
    expect(
      m.natural,
      `the control fits (natural ${m.natural}px, room ${m.limit - FONT_MARGIN}px): the measure cannot tell a card that scrolls from one that does not`
    ).toBeGreaterThan(m.limit - FONT_MARGIN)
    expect(m.bodyScroll).toBeGreaterThan(1)
    // And the frame is still usable: the way to skip stays in view while the body scrolls.
    await expectUsable(page, 'the control', {
      answer: t(page, 'submit-prompt'),
      other: t(page, 'skip-prompt'),
    })
  })
})
