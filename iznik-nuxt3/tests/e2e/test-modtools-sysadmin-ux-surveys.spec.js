// @ts-check
/**
 * The What members say section of the SysAdmin UX tab (docs/developers/reference/ux-member-surveys.md):
 * the problems in groups that are closed until opened, a group's list with Show more, a problem with
 * what members wrote behind a toggle, a link that restores an open problem, the search and the funnel
 * in the address, what works, the questions asked, a route the server does not have yet, and a phone.
 *
 * The four survey routes are served by stubs, because a browser would otherwise only ever draw the
 * empty states. The stub shapes are the Go responses in iznik-server-go/ux/surveys.go.
 */

const { test, expect } = require('./fixtures')
const { timeouts, environment } = require('./config')
const { loginViaModTools } = require('./utils/user')

const MODTOOLS_URL = environment.modtoolsBaseUrl

const PHOTOS = 'Photos and posting'
const LONG = 'Collection and no-shows'

const group = (name, problems, mentions, id, title, count) => ({
  name,
  problems,
  mentions,
  biggest: { id, title, mentions: count, answers: 120 },
})

const GROUPS = {
  answers: 120,
  left_over: 9,
  groups: [
    group(LONG, 45, 120, 100, 'People do not turn up', 21),
    group(PHOTOS, 3, 41, 4, 'Photos fail to upload', 14),
    group('Finding things', 1, 6, 9, 'Search misses things', 6),
    group('Other', 1, 5, 11, 'The site is slow at weekends', 5),
  ],
}

const problem = (over) => ({
  id: 5,
  title: 'The post starts again',
  description: '',
  flow: 'give',
  group: PHOTOS,
  mentions: 9,
  answers: 120,
  asked: 0,
  weight: null,
  most: null,
  times_shown: null,
  quotes: [],
  suggestions: [],
  status: 'open',
  ...over,
})

const PHOTO_PROBLEMS = [
  problem({
    id: 4,
    title: 'Photos fail to upload',
    description:
      'On a slow phone the photo step fails and the post starts again.',
    mentions: 14,
    asked: 80,
    weight: 0.3,
    most: 12,
    times_shown: 40,
    quotes: [
      { answer_id: 1, span: 'the photo bit took ages and then failed' },
      { answer_id: 2, span: "couldn't get the picture to upload" },
    ],
    suggestions: [
      { id: 21, title: 'Let me add photos later', mentions: 5 },
      { id: 22, title: 'Resize the photo for me', mentions: 2 },
    ],
  }),
  problem({ id: 5 }),
  problem({
    id: 6,
    title: 'Photos come out sideways',
    flow: null,
    mentions: 4,
  }),
]

// A long group: 45 problems.
const LONG_PROBLEMS = Array.from({ length: 45 }, (_, i) =>
  problem({
    id: 100 + i,
    title: `Collection problem ${i + 1}`,
    group: LONG,
    mentions: 21 - Math.floor(i / 3),
  })
)

const VALUES = {
  values: [
    {
      id: 30,
      title: 'It is free',
      mentions: 30,
      answers: 100,
      quotes: [{ answer_id: 3, span: 'free is the whole point' }],
    },
    { id: 31, title: 'Local people', mentions: 24, answers: 120, quotes: [] },
  ],
}

const survey = (over) => ({
  prompt_id: 1,
  kind: 'survey',
  flow: '',
  question: 'Which part of Freegle works best for you?',
  status: 'open',
  responses: 40,
  tallies: [],
  dont_care: 0,
  dont_understand: 0,
  nothing: 0,
  none: 0,
  skipped: 0,
  analysed: false,
  analysed_answers: 0,
  comments: [],
  yes: 0,
  no: 0,
  not_sure: 0,
  decoy: false,
  right_share: 0,
  ...over,
})

const QUESTIONS = {
  questions: [
    survey({
      prompt_id: 14,
      question: 'Which of these has been your biggest problem?',
      responses: 64,
      tallies: [
        {
          key: 'problem:4',
          label: 'Photos fail to upload',
          most: 9,
          least: 0,
          times_shown: 12,
          count: 0,
        },
      ],
    }),
    survey({
      prompt_id: 13,
      kind: 'awareness',
      flow: 'give',
      question: 'Do you know how to pay to be shown first?',
      status: 'closed',
      responses: 20,
      yes: 8,
      no: 10,
      not_sure: 2,
      decoy: true,
    }),
    survey({
      prompt_id: 12,
      kind: 'awareness',
      flow: 'chat_promise',
      question: 'Do you know how to promise an item in a chat?',
      status: 'closed',
      responses: 10,
      yes: 4,
      no: 5,
      not_sure: 1,
    }),
    survey({
      prompt_id: 11,
      kind: 'open',
      question: 'Did you have a problem the last time you used Freegle?',
      status: 'closed',
      responses: 100,
      nothing: 30,
      dont_understand: 10,
      analysed: true,
    }),
    survey({
      prompt_id: 9,
      tallies: [
        {
          key: 'give',
          label: 'Offering something',
          most: 20,
          least: 4,
          times_shown: 0,
          count: 0,
        },
      ],
    }),
  ],
  decoy_yes_rate: 0.4,
  decoy_answers: 20,
}

/** The contrast ratio of two colours written as the browser gives them: rgb(r, g, b). */
function contrast(a, b) {
  const channels = (c) =>
    c
      .match(/\d+(\.\d+)?/g)
      .slice(0, 3)
      .map(Number)
  const lum = (c) => {
    const [r, g, bl] = channels(c).map((v) => {
      const s = v / 255
      return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
    })
    return 0.2126 * r + 0.7152 * g + 0.0722 * bl
  }
  const [hi, lo] = [lum(a), lum(b)].sort((x, y) => y - x)
  return (hi + 0.05) / (lo + 0.05)
}

/**
 * Serve the four survey routes. `calls` records every read in order, with its query. With `missing`,
 * every route answers 404, as the site does when it is live before the Go API.
 */
async function serveSurveys(page, { missing = false } = {}) {
  const calls = []
  await page.route(
    /\/modtools\/ux\/(problem-groups|problems|values|questions)(\?|$)/,
    (route) => {
      const req = route.request()
      const cors = {
        'access-control-allow-origin': req.headers().origin || '*',
        'access-control-allow-headers': '*',
        'access-control-allow-methods': '*',
        'access-control-allow-credentials': 'true',
      }
      const json = (status, body) =>
        route.fulfill({
          status,
          headers: cors,
          contentType: 'application/json',
          body: JSON.stringify(body),
        })
      if (req.method() === 'OPTIONS')
        return route.fulfill({ status: 204, headers: cors })

      const url = new URL(req.url())
      const which = url.pathname.split('/').pop()
      calls.push({ which, params: url.searchParams })
      if (missing) return json(404, { error: 404, message: 'Not Found' })

      if (which === 'problem-groups') return json(200, GROUPS)
      if (which === 'values') return json(200, VALUES)
      if (which === 'questions') return json(200, QUESTIONS)

      // The problems under one group, as many as the limit asks for, and how many there are.
      const all =
        url.searchParams.get('group') === LONG ? LONG_PROBLEMS : PHOTO_PROBLEMS
      const limit = Number(url.searchParams.get('limit') || 30)
      return json(200, { total: all.length, problems: all.slice(0, limit) })
    }
  )
  return { calls }
}

const tab = (page) => page.locator('.sysadmin-ux')
const section = (page) => tab(page).locator('.ux-members')
const groupRows = (page) => section(page).locator('.group-row')
const problemRows = (page) => section(page).locator('.problem-row')
const navLink = (page, label) =>
  tab(page).locator('nav[aria-label="UX sections"] a', { hasText: label })

async function open(page, query = '') {
  await page.goto(
    `${MODTOOLS_URL}/sysadmin?tab=ux&ux_section=members${query ? '&' + query : ''}`,
    { timeout: timeouts.navigation.initial }
  )
  await expect(tab(page).locator('nav[aria-label="UX sections"]')).toBeVisible({
    timeout: timeouts.ui.appearance,
  })
}

const param = (page, name) => new URL(page.url()).searchParams.get(name)

test.describe('ModTools SysAdmin UX tab: What members say', () => {
  test('lists the groups closed, with the biggest problem in each', async ({
    page,
    testEnv,
  }) => {
    await loginViaModTools(page, testEnv.mod.email)
    await serveSurveys(page)
    await open(page)

    await expect(groupRows(page)).toHaveCount(4, {
      timeout: timeouts.ui.appearance,
    })
    // The server's order, with Other last.
    await expect(groupRows(page).locator('.name')).toHaveText([
      LONG,
      PHOTOS,
      'Finding things',
      'Other',
    ])
    await expect(groupRows(page).first().locator('.counts')).toHaveText(
      '45 problems, 120 mentions'
    )
    await expect(groupRows(page).nth(1).locator('.biggest')).toHaveText(
      'Biggest: Photos fail to upload (14 of 120 written answers, 12%)'
    )
    await expect(section(page).locator('.summary')).toHaveText(
      '50 problems in 4 groups, from 120 written answers. 5 questions asked.'
    )
    await expect(section(page).locator('.left-over')).toHaveText(
      '9 answers did not match any problem.'
    )
    // Closed: nothing is listed until a group is opened, and the explanation is behind a button.
    await expect(groupRows(page).first()).toHaveAttribute(
      'aria-expanded',
      'false'
    )
    await expect(section(page).locator('.group-body')).toHaveCount(0)
    await expect(section(page).locator('.caution')).toContainText(
      'These are the members who chose to answer.'
    )
    await expect(section(page).locator('.how')).toHaveCount(0)
    await section(page)
      .getByRole('button', { name: 'How to read these numbers' })
      .click()
    await expect(section(page).locator('.how')).toContainText(
      'They chose to answer'
    )
  })

  test('opens a group, then a problem, and shows its quotes behind a toggle', async ({
    page,
    testEnv,
  }) => {
    await loginViaModTools(page, testEnv.mod.email)
    await serveSurveys(page)
    await open(page)

    await groupRows(page).filter({ hasText: PHOTOS }).click()
    await expect(problemRows(page)).toHaveCount(3, {
      timeout: timeouts.ui.appearance,
    })
    expect(param(page, 'ux_group')).toBe(PHOTOS)
    // One group at a time: opening this one left the others closed.
    await expect(section(page).locator('.group-body')).toHaveCount(1)
    await expect(problemRows(page).first().locator('.of')).toHaveText(
      '14 of 120 (12%)'
    )

    await problemRows(page).first().click()
    const detail = section(page).locator('.ux-problem-detail')
    await expect(detail).toBeVisible({ timeout: timeouts.ui.appearance })
    expect(param(page, 'ux_problem')).toBe('4')
    await expect(detail).toContainText(
      'On a slow phone the photo step fails and the post starts again.'
    )
    await expect(detail.locator('.mentioned')).toHaveText(
      'Mentioned in 14 of the 120 written answers (12%).'
    )
    await expect(detail.locator('.ranked')).toHaveText(
      'Other members were shown it 40 times and 12 picked it as the biggest problem.'
    )
    await expect(detail.locator('.suggestion')).toHaveText([
      'Let me add photos later (5)',
      'Resize the photo for me (2)',
    ])
    await expect(detail.locator('.about')).toHaveText(
      'About: Offering something'
    )

    // The words are behind a toggle, with the count.
    await expect(
      detail.getByText('the photo bit took ages and then failed')
    ).toHaveCount(0)
    const wrote = detail.getByRole('button', {
      name: /What members wrote \(2\)/,
    })
    // A style for switches in the site draws a pill, with a border and a fixed width, round anything
    // with the class toggle, and it is in the production bundle. The button is a plain link: its own
    // shape, and its words no wider than its box.
    const shape = await wrote.evaluate((el) => {
      const s = getComputedStyle(el)
      return {
        radius: s.borderTopLeftRadius,
        border: s.borderTopColor,
        overflow: el.scrollWidth - el.clientWidth,
      }
    })
    expect(shape.radius).not.toBe('999px')
    expect(shape.border).toBe('rgba(0, 0, 0, 0)')
    expect(shape.overflow).toBeLessThanOrEqual(1)
    await wrote.click()
    await expect(
      detail.getByText('the photo bit took ages and then failed')
    ).toBeVisible()
    await expect(
      detail.getByText("couldn't get the picture to upload")
    ).toBeVisible()

    // Pressing the open problem closes it, and the address follows.
    await problemRows(page).first().click()
    await expect(detail).toHaveCount(0)
    expect(param(page, 'ux_problem')).toBeNull()
    expect(param(page, 'ux_group')).toBe(PHOTOS)

    // Pressing the open group closes it.
    await groupRows(page).filter({ hasText: PHOTOS }).click()
    await expect(section(page).locator('.group-body')).toHaveCount(0)
    expect(param(page, 'ux_group')).toBeNull()
  })

  test('shows more problems in a long group', async ({ page, testEnv }) => {
    await loginViaModTools(page, testEnv.mod.email)
    const served = await serveSurveys(page)
    await open(page)

    await groupRows(page).filter({ hasText: LONG }).click()
    await expect(problemRows(page)).toHaveCount(30, {
      timeout: timeouts.ui.appearance,
    })
    await expect(section(page).locator('.showing')).toHaveText(
      'Showing 30 of 45.'
    )

    await section(page).getByRole('button', { name: 'Show more' }).click()
    await expect(problemRows(page)).toHaveCount(45, {
      timeout: timeouts.ui.appearance,
    })
    await expect(section(page).locator('.showing')).toHaveText(
      'Showing all 45.'
    )
    await expect(
      section(page).getByRole('button', { name: 'Show more' })
    ).toHaveCount(0)

    // The second request for the group asked for 60, the first for 30.
    const asked = served.calls
      .filter((c) => c.which === 'problems')
      .map((c) => c.params.get('limit'))
    expect(asked).toEqual(['30', '60'])
  })

  test('restores a linked problem', async ({ page, testEnv }) => {
    await loginViaModTools(page, testEnv.mod.email)
    await serveSurveys(page)
    await open(page, `ux_group=${encodeURIComponent(PHOTOS)}&ux_problem=4`)

    const detail = section(page).locator('.ux-problem-detail')
    await expect(detail).toBeVisible({ timeout: timeouts.ui.appearance })
    await expect(detail).toContainText(
      'Mentioned in 14 of the 120 written answers'
    )
    await expect(groupRows(page).filter({ hasText: PHOTOS })).toHaveAttribute(
      'aria-expanded',
      'true'
    )
    await expect(problemRows(page).first()).toHaveAttribute(
      'aria-expanded',
      'true'
    )
    await expect(groupRows(page).filter({ hasText: LONG })).toHaveAttribute(
      'aria-expanded',
      'false'
    )
  })

  test('opens a problem that the first 30 of its group do not reach', async ({
    page,
    testEnv,
  }) => {
    await loginViaModTools(page, testEnv.mod.email)
    const served = await serveSurveys(page)
    await open(page, `ux_group=${encodeURIComponent(LONG)}&ux_problem=140`)

    await expect(section(page).locator('.ux-problem-detail')).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    await expect(problemRows(page)).toHaveCount(45)
    const asked = served.calls
      .filter((c) => c.which === 'problems')
      .map((c) => c.params.get('limit'))
    expect(asked).toEqual(['30', '100'])
  })

  test("keeps a search for can't in the address and sends it as typed", async ({
    page,
    testEnv,
  }) => {
    await loginViaModTools(page, testEnv.mod.email)
    const served = await serveSurveys(page)
    await open(page)
    await expect(groupRows(page)).toHaveCount(4, {
      timeout: timeouts.ui.appearance,
    })

    await section(page).getByLabel('Search:').fill("can't")
    await section(page).getByLabel('Search:').press('Enter')
    await expect.poll(() => param(page, 'ux_q')).toBe("can't")
    await expect
      .poll(() =>
        served.calls.some(
          (c) => c.which === 'problem-groups' && c.params.get('q') === "can't"
        )
      )
      .toBe(true)

    // A reload restores the box and asks again with the same text.
    await page.reload()
    await expect(section(page).getByLabel('Search:')).toHaveValue("can't", {
      timeout: timeouts.ui.appearance,
    })
  })

  test('narrows to a funnel, says so, and shows everything again', async ({
    page,
    testEnv,
  }) => {
    await loginViaModTools(page, testEnv.mod.email)
    const served = await serveSurveys(page)
    await open(page, 'ux_flow=give')

    await expect(section(page).locator('.funnel-notice')).toHaveText(
      'Showing Offering only. Show all',
      { timeout: timeouts.ui.appearance }
    )
    await expect(section(page).getByLabel('Funnel:')).toHaveValue('give')
    expect(
      served.calls
        .filter((c) => c.which === 'problem-groups')
        .at(-1)
        .params.get('flow')
    ).toBe('give')

    // Not tied to a funnel is the value none, asked for as flow=none.
    await section(page).getByLabel('Funnel:').selectOption('none')
    await expect.poll(() => param(page, 'ux_flow')).toBe('none')
    await expect(section(page).locator('.funnel-notice')).toContainText(
      'Showing problems not tied to a funnel only.'
    )
    await expect
      .poll(() =>
        served.calls.some(
          (c) => c.which === 'problem-groups' && c.params.get('flow') === 'none'
        )
      )
      .toBe(true)

    await section(page).locator('.funnel-notice').getByText('Show all').click()
    await expect(section(page).locator('.funnel-notice')).toHaveCount(0)
    expect(param(page, 'ux_flow')).toBeNull()
  })

  test('is reached from a funnel, with that funnel chosen', async ({
    page,
    testEnv,
  }) => {
    await loginViaModTools(page, testEnv.mod.email)
    const served = await serveSurveys(page)
    await page.goto(
      `${MODTOOLS_URL}/sysadmin?tab=ux&ux_section=funnels&ux_flow=give`,
      {
        timeout: timeouts.navigation.initial,
      }
    )
    await expect(navLink(page, 'What members say')).toBeVisible({
      timeout: timeouts.ui.appearance,
    })

    await tab(page)
      .locator('.also')
      .getByRole('link', { name: 'What members say' })
      .click()
    await expect(section(page).locator('.funnel-notice')).toHaveText(
      'Showing Offering only. Show all',
      { timeout: timeouts.ui.appearance }
    )
    expect(param(page, 'ux_section')).toBe('members')
    expect(param(page, 'ux_flow')).toBe('give')
    expect(
      served.calls
        .filter((c) => c.which === 'problem-groups')
        .at(-1)
        .params.get('flow')
    ).toBe('give')

    // Leaving for another section from the nav leaves the funnel behind.
    await navLink(page, 'Pages and features').click()
    await expect.poll(() => param(page, 'ux_section')).toBe('pages')
    expect(param(page, 'ux_flow')).toBeNull()
  })

  test('lists what works with its counts and the words behind a toggle', async ({
    page,
    testEnv,
  }) => {
    await loginViaModTools(page, testEnv.mod.email)
    await serveSurveys(page)
    await open(page, 'ux_view=values')

    const values = section(page).locator('.value')
    await expect(values).toHaveCount(2, { timeout: timeouts.ui.appearance })
    await expect(values.first()).toContainText('It is free: 30 of 100 (30%)')
    await expect(values.nth(1)).toContainText('Local people: 24 of 120 (20%)')
    await expect(
      values.first().getByText('free is the whole point')
    ).toHaveCount(0)
    await values
      .first()
      .getByRole('button', { name: /What members wrote \(1\)/ })
      .click()
    await expect(
      values.first().getByText('free is the whole point')
    ).toBeVisible()
    await expect(
      section(page).getByRole('link', { name: 'What works' })
    ).toHaveAttribute('aria-current', 'page')
  })

  test('lists the questions one line each, and opens one from the address', async ({
    page,
    testEnv,
  }) => {
    await loginViaModTools(page, testEnv.mod.email)
    await serveSurveys(page)
    await open(page, 'ux_view=questions')

    const rows = section(page).locator('.question-row')
    await expect(rows).toHaveCount(5, { timeout: timeouts.ui.appearance })
    await expect(rows.locator('.kind')).toHaveText([
      'Problem ranking',
      'Awareness',
      'Awareness',
      'Written answers',
      'Area ranking',
    ])
    await expect(rows.first().locator('.answered')).toHaveText('64 answered')
    await expect(rows.first().locator('.status')).toHaveText('Open')
    await expect(rows.nth(1).locator('.status')).toHaveText('Closed')
    await expect(section(page).locator('.question-body')).toHaveCount(0)

    // Opening a line shows how it did; for a real feature that includes the yes-saying check.
    await rows.nth(2).click()
    const body = section(page).locator('.question-body')
    await expect(body).toBeVisible({ timeout: timeouts.ui.appearance })
    expect(param(page, 'ux_question')).toBe('12')
    await expect(body.locator('.right-answer')).toHaveText(
      'Right answer (Yes): 4 of 10.'
    )
    await expect(body.locator('.decoy-check')).toContainText(
      'across 1 decoy question about things Freegle does not do, 8 of 20 answers were Yes.'
    )

    // The decoy says it is one, and has no yes-saying line of its own.
    await rows.nth(1).click()
    await expect(section(page).locator('.question-body')).toHaveCount(1)
    await expect(section(page).locator('.decoy-line')).toHaveText(
      'Decoy: Freegle does not do this.'
    )
    await expect(section(page).locator('.decoy-check')).toHaveCount(0)
  })

  test('says not switched on when a route is missing, and the other sections still work', async ({
    page,
    testEnv,
  }) => {
    await loginViaModTools(page, testEnv.mod.email)
    await serveSurveys(page, { missing: true })
    const errors = []
    page.on('pageerror', (error) => errors.push(error.message))
    await open(page)

    await expect(section(page)).toContainText(
      'This part is not switched on yet.',
      {
        timeout: timeouts.ui.appearance,
      }
    )
    await expect(groupRows(page)).toHaveCount(0)
    await expect(section(page).locator('.summary')).toHaveCount(0)

    await section(page).getByRole('link', { name: 'What works' }).click()
    await expect(section(page).locator('.ux-values')).toContainText(
      'This part is not switched on yet.',
      { timeout: timeouts.ui.appearance }
    )
    await section(page).getByRole('link', { name: 'Questions' }).click()
    await expect(section(page).locator('.ux-questions')).toContainText(
      'This part is not switched on yet.',
      { timeout: timeouts.ui.appearance }
    )

    await navLink(page, 'Pages and features').click()
    await expect(tab(page).getByText('Friction', { exact: true })).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    expect(errors).toHaveLength(0)
  })

  test('draws the open and closed words dark enough to read', async ({
    page,
    testEnv,
  }) => {
    // ModTools forces its own light green on .bg-success, so the chips must not use it.
    await loginViaModTools(page, testEnv.mod.email)
    await serveSurveys(page)
    await open(page, 'ux_view=questions')

    const rows = section(page).locator('.question-row')
    await expect(rows).toHaveCount(5, { timeout: timeouts.ui.appearance })
    for (const [index, word] of [
      [0, 'Open'],
      [1, 'Closed'],
    ]) {
      const chip = rows.nth(index).locator('.status')
      await expect(chip).toHaveText(word)
      const colours = await chip.evaluate((el) => {
        const s = getComputedStyle(el)
        return { text: s.color, back: s.backgroundColor }
      })
      expect(contrast(colours.text, colours.back), word).toBeGreaterThanOrEqual(
        4.5
      )
    }
  })

  test('puts the counts of a group on a second line at 390 wide, with nothing wider than the section', async ({
    page,
    testEnv,
  }) => {
    await page.setViewportSize({ width: 390, height: 844 })
    await loginViaModTools(page, testEnv.mod.email)
    await serveSurveys(page)
    await open(page, `ux_group=${encodeURIComponent(PHOTOS)}&ux_problem=4`)

    await expect(section(page).locator('.ux-problem-detail')).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    const row = groupRows(page).first()
    const box = async (sel) =>
      (await row.locator(sel).boundingBox()) || { y: -1, height: 0 }
    const name = await box('.name')
    const counts = await box('.counts')
    const biggest = await box('.biggest')
    // The name, then the counts, then the biggest problem: each below the one before.
    expect(counts.y).toBeGreaterThan(name.y + name.height - 2)
    expect(biggest.y).toBeGreaterThan(counts.y + counts.height - 2)

    // The ModTools menu takes room at this width, so the page itself is wider than the screen in every
    // section of the tab. What matters here is the section: nothing in it, the open problem's details
    // too, reaches past the room it was given.
    const fit = () =>
      section(page).evaluate((el) => {
        const room = el.getBoundingClientRect()
        const past = []
        for (const child of el.querySelectorAll('*')) {
          const b = child.getBoundingClientRect()
          if (
            b.width > 0 &&
            (b.right > room.right + 1 || b.left < room.left - 1)
          ) {
            past.push(
              `${child.tagName.toLowerCase()}.${String(child.className).slice(0, 40)}`
            )
          }
        }
        return { past, overflow: el.scrollWidth - el.clientWidth }
      })
    expect(await fit()).toEqual({ past: [], overflow: 0 })

    // The questions are the longest lines.
    await section(page).getByRole('link', { name: 'Questions' }).click()
    await expect(section(page).locator('.question-row')).toHaveCount(5, {
      timeout: timeouts.ui.appearance,
    })
    await section(page).locator('.question-row').nth(2).click()
    await expect(section(page).locator('.question-body')).toBeVisible()
    expect(await fit()).toEqual({ past: [], overflow: 0 })
  })

  // The ModTools menu is open when the page loads, at every width, and takes about 217 pixels of the
  // window. A list is then far narrower than the window, so what decides the layout of a row is the
  // width of the list. At 800 wide the list is about 580, and a row with its counts beside the biggest
  // problem left that text a column of 90 pixels or so.
  test('puts the counts of a group and the kind of a question on a second line when the list is narrow, whatever the window', async ({
    page,
    testEnv,
  }) => {
    await page.setViewportSize({ width: 800, height: 900 })
    await loginViaModTools(page, testEnv.mod.email)
    await serveSurveys(page)
    await open(page)
    await expect(groupRows(page)).toHaveCount(4, {
      timeout: timeouts.ui.appearance,
    })

    const widthOf = async (loc) => (await loc.boundingBox())?.width || 0
    const boxes = async (row, names) => {
      const out = {}
      for (const n of names) {
        out[n] = (await row.locator(`.${n}`).boundingBox()) || {
          x: 0,
          y: -1,
          width: 0,
          height: 0,
        }
      }
      return out
    }
    // The menu is what narrows the list. If it were closed the list would be wider than the window
    // allows for, and this would not be trying what it is here to try.
    expect(await widthOf(section(page))).toBeLessThan(736)

    const row = groupRows(page).first()
    let g = await boxes(row, ['name', 'counts', 'biggest'])
    expect(g.counts.y).toBeGreaterThan(g.name.y + g.name.height - 2)
    expect(g.biggest.y).toBeGreaterThan(g.counts.y + g.counts.height - 2)
    expect(g.biggest.width).toBeGreaterThan(250)

    // A click on the page writes the address, which closes the menu below 992 wide and gives the list
    // its room. The questions are opened by loading the page again, as a copied link would.
    await open(page, 'ux_view=questions')
    const rows = section(page).locator('.question-row')
    await expect(rows).toHaveCount(5, { timeout: timeouts.ui.appearance })
    expect(await widthOf(section(page))).toBeLessThan(736)
    let q = await boxes(rows.first(), ['text', 'kind', 'answered'])
    expect(q.kind.y).toBeGreaterThan(q.text.y + q.text.height - 2)
    expect(q.text.width).toBeGreaterThan(400)

    // The same rows in a list that is wide: the name, the biggest problem and the counts share a line,
    // and so do the question and its kind, how many answered and its state.
    await page.setViewportSize({ width: 1280, height: 900 })
    await expect
      .poll(async () => widthOf(section(page)), {
        timeout: timeouts.ui.appearance,
      })
      .toBeGreaterThan(800)
    q = await boxes(rows.first(), ['text', 'kind', 'answered'])
    expect(Math.abs(q.kind.y - q.text.y)).toBeLessThan(12)
    expect(Math.abs(q.answered.y - q.text.y)).toBeLessThan(12)
    await section(page).getByRole('link', { name: 'Problems' }).click()
    await expect(groupRows(page)).toHaveCount(4, {
      timeout: timeouts.ui.appearance,
    })
    g = await boxes(groupRows(page).first(), ['name', 'counts', 'biggest'])
    expect(Math.abs(g.counts.y - g.name.y)).toBeLessThan(12)
    expect(Math.abs(g.biggest.y - g.name.y)).toBeLessThan(12)
  })

  // The row of sections scrolls sideways on a phone. The open section must be in it, not off one edge,
  // whether it is a long name or the last of the row, and with four sections the last is out of sight.
  test('shows the open section in the row of sections when the screen is narrow, including the last', async ({
    page,
    testEnv,
  }) => {
    await page.setViewportSize({ width: 390, height: 844 })
    await loginViaModTools(page, testEnv.mod.email)
    await serveSurveys(page)

    const nav = tab(page).locator('nav[aria-label="UX sections"]')
    const inView = async () =>
      nav.evaluate((el) => {
        const link = el.querySelector('a[aria-current="page"]')
        const room = el.getBoundingClientRect()
        const at = link.getBoundingClientRect()
        return {
          label: link.textContent.trim(),
          overflows: el.scrollWidth > el.clientWidth,
          hiddenLeft: Math.max(0, Math.round(room.left - at.left)),
          hiddenRight: Math.max(0, Math.round(at.right - room.right)),
        }
      })

    await open(page)
    await expect(navLink(page, 'What members say')).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    // There is more in the row than the room, so this is about the row scrolling.
    expect((await inView()).overflows).toBe(true)
    await expect.poll(inView).toMatchObject({
      label: 'What members say',
      hiddenLeft: 0,
      hiddenRight: 0,
    })

    // A link to the last section opens with it in view.
    await page.goto(`${MODTOOLS_URL}/sysadmin?tab=ux&ux_section=pages`, {
      timeout: timeouts.navigation.initial,
    })
    await expect(nav).toBeVisible({ timeout: timeouts.ui.appearance })
    await expect.poll(inView).toMatchObject({
      label: 'Pages and features',
      hiddenLeft: 0,
      hiddenRight: 0,
    })

    // Going back along the row brings an earlier section into view as well.
    await navLink(page, 'Overview').click()
    await expect.poll(() => param(page, 'ux_section')).not.toBe('pages')
    await expect.poll(inView).toMatchObject({
      label: 'Overview',
      hiddenLeft: 0,
      hiddenRight: 0,
    })
  })
})
