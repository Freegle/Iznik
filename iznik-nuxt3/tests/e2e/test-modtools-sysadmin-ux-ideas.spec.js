// @ts-check
/**
 * The Ideas section of the SysAdmin UX tab (docs/developers/reference/ux-ideas.md): a line for each
 * funnel and then a funnel's ideas by score, an idea opened from a linked address, the member problem an
 * idea answers, a route the server does not have yet, and a phone.
 *
 * The ideas route is served by a stub, because a browser would otherwise only ever draw an empty list.
 * The stub shape is the Go response in iznik-server-go/ux/ideas.go, and the stub does what the route
 * does with its parameters: it filters, orders by score and then id, counts over every idea, and says how
 * many matched before it cut the list to the limit.
 */

const { test, expect } = require('./fixtures')
const { timeouts, environment } = require('./config')
const { loginViaModTools } = require('./utils/user')

const MODTOOLS_URL = environment.modtoolsBaseUrl

const FUNNELS = [
  'give',
  'ask',
  'reply',
  'chat_promise',
  'chat_outcome',
  'login',
  'settings',
]

const idea = (id, flow, title, score, over = {}) => ({
  id,
  flow,
  title,
  rationale: `Why ${id}: people stall here. ${title}.`,
  expected: `More people finish (${id}).`,
  evidence: {
    category: 'copy',
    expected_points: 4,
    confidence: 0.5,
    locality: 5,
    risk: 'Fewer photos.',
    files: ['iznik-nuxt3/components/PostPhoto.vue'],
    change: 'A plain skip link.',
  },
  score,
  status: 'open',
  type: 'experiment',
  source: 'ai',
  problem_id: null,
  problem_title: null,
  problem: null,
  attempts: 0,
  experiment: null,
  created_at: '2026-10-14 09:00:00',
  ...over,
})

const IDEAS = [
  idea(3, 'give', 'Ask to sign in first', 8.2, {
    problem_id: 12,
    problem_title: 'Photo uploads keep failing',
    problem: { id: 12, title: 'Photo uploads keep failing' },
  }),
  idea(4, 'give', 'Show a progress bar', 6, { type: 'fix' }),
  idea(5, 'give', 'Shorter first screen', 2.5, {
    problem_title: 'The first screen asks for too much',
  }),
  idea(
    6,
    'give',
    'A very long title that goes on and on so that it has to wrap on a phone, with no spaces missing and a few more words after that',
    1.25
  ),
  idea(7, 'ask', 'Remember the last postcode', 3),
  idea(8, 'login', 'Offer the email first', 5, {
    status: 'scheduled',
    experiment: {
      id: 4,
      name: 'login-email-first',
      title: 'Email before password',
      status: 'draft',
    },
  }),
  idea(9, 'reply', 'Say what happens next', 4, {
    status: 'done',
    // Adopted a few days ago, so it is still inside its holdout.
    experiment: {
      id: 5,
      name: 'reply-what-happens-next',
      title: 'What happens next, after a reply',
      status: 'adopted',
      holdout_until: '2030-01-01 00:00:00',
    },
  }),
  idea(10, 'settings', 'Group the switches', 2, { status: 'rejected' }),
]

/** What GET /modtools/ux/ideas answers for these query parameters. */
function answer(list, params) {
  const counts = { open: 0, scheduled: 0, running: 0, done: 0, rejected: 0 }
  for (const i of list) counts[i.status]++
  const byBest = (a, b) => b.score - a.score || a.id - b.id
  const flows = FUNNELS.map((flow) => {
    const waiting = list
      .filter((i) => i.flow === flow && i.status === 'open')
      .sort(byBest)
    return {
      flow,
      open: waiting.length,
      best: waiting.length
        ? {
            id: waiting[0].id,
            title: waiting[0].title,
            score: waiting[0].score,
          }
        : null,
    }
  })
  let ideas = [...list].sort(byBest)
  if (params.get('flow')) {
    ideas = ideas.filter((i) => i.flow === params.get('flow'))
  }
  if (params.get('status')) {
    ideas = ideas.filter((i) => i.status === params.get('status'))
  }
  if (params.get('type')) {
    ideas = ideas.filter((i) => i.type === params.get('type'))
  }
  if (params.get('problem_id')) {
    ideas = ideas.filter(
      (i) => String(i.problem_id) === params.get('problem_id')
    )
  }
  const matching = ideas.length
  ideas = ideas.slice(0, Number(params.get('limit') || 200))
  return { counts, flows, matching, ideas }
}

/** What the section asked for in a request: the parameters it chose, without the ones the API class adds. */
const askedFor = (call) =>
  Object.fromEntries(
    ['status', 'flow', 'type', 'problem_id', 'limit']
      .filter((k) => k in call)
      .map((k) => [k, call[k]])
  )

/**
 * Serve the ideas route. `calls` records the query of every request. With `missing`, the route answers
 * 404, as the site does when it is live before the Go API.
 */
async function serveIdeas(page, { list = IDEAS, missing = false } = {}) {
  const calls = []
  await page.route(/\/modtools\/ux\/ideas(\?|$)/, (route) => {
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

    if (req.method() === 'OPTIONS') {
      return route.fulfill({ status: 204, headers: cors })
    }
    if (missing) return json(404, { error: 404, message: 'Not Found' })

    const params = new URL(req.url()).searchParams
    calls.push(Object.fromEntries(params.entries()))
    return json(200, answer(list, params))
  })
  return { calls }
}

const tab = (page) => page.locator('.sysadmin-ux')
const section = (page) => tab(page).locator('.ux-ideas')
const rows = (page) => section(page).locator('.ux-idea-row')
const navLink = (page, label) =>
  tab(page).locator('nav[aria-label="UX sections"] a', { hasText: label })

async function open(page, query = '') {
  await page.goto(
    `${MODTOOLS_URL}/sysadmin?tab=ux&ux_section=ideas${query ? '&' + query : ''}`,
    { timeout: timeouts.navigation.initial }
  )
  await expect(tab(page).locator('nav[aria-label="UX sections"]')).toBeVisible({
    timeout: timeouts.ui.appearance,
  })
}

test.describe('ModTools SysAdmin UX tab: Ideas', () => {
  test('shows a line for each funnel, then a funnel’s ideas by score', async ({
    page,
    testEnv,
  }) => {
    await loginViaModTools(page, testEnv.mod.email)
    const served = await serveIdeas(page)
    await open(page)

    // The strip counts every idea, with Waiting chosen.
    const chips = section(page).locator('.chip')
    await expect(chips).toHaveCount(5, { timeout: timeouts.ui.appearance })
    await expect(chips).toHaveText([
      'Waiting (5)',
      'Queued (1)',
      'Being tried (0)',
      'Tried (1)',
      'Set aside (1)',
    ])
    await expect(section(page).locator('.chip.active')).toHaveText(
      'Waiting (5)'
    )
    // The score is explained once, in words, under the strip.
    await expect(section(page).locator('.score-note')).toHaveText(
      'Score is the expected gain, times how sure, times how small the change is. The highest score is listed first.'
    )

    // A line for each funnel in the order members meet them, and no idea is listed yet.
    const lines = section(page).locator('.funnel-link')
    await expect(lines).toHaveText([
      'Offering something. 4 waiting. Best: Ask to sign in first (score 8.2)',
      'Asking for something. 1 waiting. Best: Remember the last postcode (score 3)',
      'Replying to a post. No ideas waiting.',
      'Promising an item. No ideas waiting.',
      'Marking taken or received. No ideas waiting.',
      'Signing in or joining. No ideas waiting.',
      'Settings. No ideas waiting.',
    ])
    await expect(rows(page)).toHaveCount(0)
    // Only the totals were asked for: one idea, not the list.
    expect(askedFor(served.calls[0])).toEqual({ status: 'open', limit: '1' })

    // Choosing a funnel keeps it in the address and lists its waiting ideas, best score first.
    await lines.first().click()
    await expect(rows(page)).toHaveCount(4, { timeout: timeouts.ui.appearance })
    await expect(rows(page).locator('.title')).toHaveText([
      'Ask to sign in first',
      'Show a progress bar',
      'Shorter first screen',
      /A very long title/,
    ])
    await expect(rows(page).locator('.score')).toHaveText([
      'Score 8.2',
      'Score 6',
      'Score 2.5',
      'Score 1.25',
    ])
    // The word is on the screen for everybody: it has a width of its own, which a word hidden from the
    // eye and kept for a screen reader does not.
    const label = await rows(page).first().locator('.score-label').boundingBox()
    expect(label.width).toBeGreaterThan(20)
    await expect(rows(page).first().locator('.visually-hidden')).toHaveCount(0)
    await expect(rows(page).locator('.type')).toHaveText([
      'Experiment',
      'Fix',
      'Experiment',
      'Experiment',
    ])
    await expect(section(page).locator('.funnel-notice')).toHaveText(
      'Showing Offering only. Show all'
    )
    await expect(section(page).locator('.flow-select')).toHaveValue('give')
    expect(new URL(page.url()).searchParams.get('ux_flow')).toBe('give')
    expect(askedFor(served.calls.at(-1))).toEqual({
      status: 'open',
      flow: 'give',
    })

    // The strip is still over every idea.
    await expect(chips).toHaveText([
      'Waiting (5)',
      'Queued (1)',
      'Being tried (0)',
      'Tried (1)',
      'Set aside (1)',
    ])

    // The type select narrows the list and the address follows.
    await section(page).locator('.type-select').selectOption('fix')
    await expect(rows(page)).toHaveCount(1, { timeout: timeouts.ui.appearance })
    await expect(rows(page).locator('.title')).toHaveText([
      'Show a progress bar',
    ])
    expect(new URL(page.url()).searchParams.get('ux_itype')).toBe('fix')

    // Show all goes back to the lines.
    await section(page).getByRole('link', { name: 'Show all' }).click()
    await expect(section(page).locator('.funnel-link')).toHaveCount(7, {
      timeout: timeouts.ui.appearance,
    })
    expect(new URL(page.url()).searchParams.get('ux_flow')).toBeNull()
  })

  test('lists the ideas of another status straight away, naming the funnel on each', async ({
    page,
    testEnv,
  }) => {
    await loginViaModTools(page, testEnv.mod.email)
    await serveIdeas(page)
    await open(page)

    await section(page)
      .getByRole('button', { name: /^Tried/ })
      .click()
    await expect(rows(page)).toHaveCount(1, { timeout: timeouts.ui.appearance })
    await expect(rows(page).locator('.title')).toHaveText([
      'Say what happens next',
    ])
    await expect(rows(page).locator('.funnel')).toHaveText(['Replying'])
    await expect(rows(page).locator('.status')).toHaveText(['Tried'])
    expect(new URL(page.url()).searchParams.get('ux_istatus')).toBe('done')
    await expect(section(page).locator('.funnel-link')).toHaveCount(0)

    // Waiting takes the status out of the address again.
    await section(page)
      .getByRole('button', { name: /^Waiting/ })
      .click()
    await expect(section(page).locator('.funnel-link')).toHaveCount(7, {
      timeout: timeouts.ui.appearance,
    })
    expect(new URL(page.url()).searchParams.get('ux_istatus')).toBeNull()
  })

  test('opens an idea from a linked address', async ({ page, testEnv }) => {
    await loginViaModTools(page, testEnv.mod.email)
    await serveIdeas(page)
    await open(page, 'ux_flow=give&ux_idea=3')

    const detail = section(page).locator('.ux-idea-detail')
    await expect(detail).toBeVisible({ timeout: timeouts.ui.appearance })
    await expect(detail).toHaveCount(1)
    await expect(detail.locator('h6')).toHaveText([
      'What changes',
      'Why',
      'What we expect',
      'Evidence',
      'The problem it answers',
    ])
    await expect(detail.locator('.change')).toContainText('A plain skip link.')
    await expect(detail.locator('.why')).toContainText(
      'Why 3: people stall here.'
    )
    await expect(detail.locator('.expect')).toContainText(
      'More people finish (3).'
    )
    await expect(detail.locator('.evidence')).toContainText(
      'Kind of change: Wording'
    )
    await expect(detail.locator('.evidence')).toContainText(
      'Expected gain: 4 more people finish in every 100 who start'
    )
    await expect(detail.locator('.evidence')).toContainText('How sure: 50%')
    await expect(detail.locator('.evidence')).toContainText(
      'Files: iznik-nuxt3/components/PostPhoto.vue'
    )
    await expect(detail.locator('.source')).toHaveText(
      'From the idea generator'
    )
    await expect(rows(page).first()).toHaveAttribute('aria-expanded', 'true')
    await expect(rows(page).nth(1)).toHaveAttribute('aria-expanded', 'false')

    // Pressing the open row closes it, and the address follows. Pressing another opens it.
    await rows(page).first().click()
    await expect(section(page).locator('.ux-idea-detail')).toHaveCount(0)
    expect(new URL(page.url()).searchParams.get('ux_idea')).toBeNull()
    await rows(page).nth(1).click()
    await expect(section(page).locator('.ux-idea-detail')).toContainText(
      'Why 4:'
    )
    expect(new URL(page.url()).searchParams.get('ux_idea')).toBe('4')
  })

  test('shows the problem an idea answers, and the generator’s own words when no member raised it', async ({
    page,
    testEnv,
  }) => {
    await loginViaModTools(page, testEnv.mod.email)
    await serveIdeas(page)

    // Tied to a member problem: its title. No link, because the What members say section is not in
    // this tab yet.
    await open(page, 'ux_flow=give&ux_idea=3')
    const problem = section(page).locator('.ux-idea-detail .problem')
    await expect(problem).toBeVisible({ timeout: timeouts.ui.appearance })
    await expect(problem.locator('.problem-title')).toHaveText(
      'Photo uploads keep failing'
    )
    await expect(problem.locator('.problem-link')).toHaveCount(0)

    // Not tied: the title the generator gave the problem it found.
    await open(page, 'ux_flow=give&ux_idea=5')
    await expect(
      section(page).locator('.ux-idea-detail .problem .problem-title')
    ).toHaveText('The first screen asks for too much', {
      timeout: timeouts.ui.appearance,
    })

    // No problem at all: no block.
    await open(page, 'ux_flow=give&ux_idea=4')
    await expect(section(page).locator('.ux-idea-detail')).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    await expect(section(page).locator('.ux-idea-detail .problem')).toHaveCount(
      0
    )
  })

  test('shows the experiment an idea became, with no link when that section is not there', async ({
    page,
    testEnv,
  }) => {
    await loginViaModTools(page, testEnv.mod.email)
    await serveIdeas(page)
    await open(page, 'ux_istatus=scheduled&ux_idea=8')

    const experiment = section(page).locator('.ux-idea-detail .experiment')
    await expect(experiment).toBeVisible({ timeout: timeouts.ui.appearance })
    await expect(experiment.locator('.experiment-title')).toHaveText(
      'Email before password'
    )
    await expect(experiment.locator('.experiment-status')).toHaveText(
      'Not started'
    )
    await expect(experiment.locator('.experiment-link')).toHaveCount(0)
    await expect(rows(page).locator('.status')).toHaveText(['Queued'])
  })

  test('words an adopted experiment as the Experiments section does, kept for now while its holdout lasts', async ({
    page,
    testEnv,
  }) => {
    await loginViaModTools(page, testEnv.mod.email)
    await serveIdeas(page)
    await open(page, 'ux_istatus=done&ux_idea=9')

    const experiment = section(page).locator('.ux-idea-detail .experiment')
    await expect(experiment).toBeVisible({ timeout: timeouts.ui.appearance })
    await expect(experiment.locator('.experiment-title')).toHaveText(
      'What happens next, after a reply'
    )
    await expect(experiment.locator('.experiment-status')).toHaveText(
      'Kept for now'
    )
  })

  test('has a funnel select on another status, and says when the list is cut', async ({
    page,
    testEnv,
  }) => {
    // 205 tried ideas: the stub, like the route, sends 200 and says how many matched.
    const many = Array.from({ length: 205 }, (_, i) =>
      idea(100 + i, i < 150 ? 'give' : 'ask', `Tried idea ${i}`, 5 - i / 1000, {
        status: 'done',
      })
    )
    await loginViaModTools(page, testEnv.mod.email)
    const served = await serveIdeas(page, { list: many })
    await open(page, 'ux_istatus=done')

    await expect(rows(page)).toHaveCount(200, {
      timeout: timeouts.ui.appearance,
    })
    await expect(section(page).locator('.cut-notice')).toHaveText(
      'Showing the first 200 of 205. Choose a funnel or a type to see fewer.'
    )
    await expect(section(page).locator('.flow-select')).toHaveValue('')

    // One funnel's tried ideas are one choice away, and the notice goes when the list is whole.
    await section(page).locator('.flow-select').selectOption('ask')
    await expect(rows(page)).toHaveCount(55, {
      timeout: timeouts.ui.appearance,
    })
    await expect(section(page).locator('.cut-notice')).toHaveCount(0)
    await expect(section(page).locator('.funnel-notice')).toHaveText(
      'Showing Asking only. Show all'
    )
    expect(new URL(page.url()).searchParams.get('ux_flow')).toBe('ask')
    expect(new URL(page.url()).searchParams.get('ux_istatus')).toBe('done')
    expect(askedFor(served.calls.at(-1))).toEqual({
      status: 'done',
      flow: 'ask',
    })

    // And back to all of them.
    await section(page).locator('.flow-select').selectOption('')
    await expect(rows(page)).toHaveCount(200, {
      timeout: timeouts.ui.appearance,
    })
    expect(new URL(page.url()).searchParams.get('ux_flow')).toBeNull()
  })

  // A site that is live before the Go API gets a 404. The Go API live before the SQL that makes the ideas
  // table answers 501, which the store reads the same way (stores/uxIdeas.spec.js) and the route's own test
  // checks (TestUxIdeasWithoutTheIdeasTableIsNotSwitchedOn). It is not played here, because this harness
  // fails a test on the console error of any failed load that its list does not name, and 501 is not named.
  test('says that this part is not switched on when the route is missing', async ({
    page,
    testEnv,
  }) => {
    await loginViaModTools(page, testEnv.mod.email)
    await serveIdeas(page, { missing: true })
    await open(page)

    await expect(section(page).locator('.not-switched-on')).toHaveText(
      'This part is not switched on yet.',
      { timeout: timeouts.ui.appearance }
    )
    await expect(section(page).locator('.chip')).toHaveCount(0)
    await expect(section(page).locator('.notice-message')).toHaveCount(0)
    // The rest of the tab still works.
    await navLink(page, 'Overview').click()
    await expect(section(page)).toHaveCount(0)
  })

  test('says there are no ideas yet when there are none', async ({
    page,
    testEnv,
  }) => {
    await loginViaModTools(page, testEnv.mod.email)
    await serveIdeas(page, { list: [] })
    await open(page)

    await expect(section(page).locator('.none')).toHaveText(
      'No ideas yet. The idea generator adds them once a day.',
      { timeout: timeouts.ui.appearance }
    )
    await expect(section(page).locator('.chip')).toHaveCount(0)
  })

  test('has no filter bar, and says the filters do not apply', async ({
    page,
    testEnv,
  }) => {
    await loginViaModTools(page, testEnv.mod.email)
    await serveIdeas(page)
    await open(page)

    await expect(section(page).locator('.chip').first()).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    await expect(tab(page).locator('.ux-pillar-note')).toBeVisible()
    await expect(navLink(page, 'Ideas')).toHaveAttribute('aria-current', 'page')
  })

  test.describe('on a phone', () => {
    // The ModTools menu takes room at this width, so the page itself is wider than the screen in every
    // section of the tab. What matters is the section: nothing in it, an open idea's reasons too,
    // reaches past the room it was given.
    const fit = (page) =>
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

    test('wraps a long title on two lines of chips and words, inside the section', async ({
      page,
      testEnv,
    }) => {
      await page.setViewportSize({ width: 390, height: 844 })
      await loginViaModTools(page, testEnv.mod.email)
      await serveIdeas(page)
      await open(page, 'ux_flow=give&ux_idea=6')

      await expect(section(page).locator('.ux-idea-detail')).toBeVisible({
        timeout: timeouts.ui.appearance,
      })
      // The width is the phone's: the section is narrower than 390.
      const room = await section(page).evaluate(
        (el) => el.getBoundingClientRect().width
      )
      expect(room).toBeLessThanOrEqual(390)

      const row = rows(page).nth(3)
      const box = async (sel) =>
        (await row.locator(sel).boundingBox()) || { y: -1, height: 0 }
      const type = await box('.type')
      const title = await box('.title')
      // The chips, then the title below them, and the title is a sentence that takes several lines.
      expect(title.y).toBeGreaterThan(type.y + type.height - 2)
      const lineHeight = await row
        .locator('.title')
        .evaluate((el) => parseFloat(getComputedStyle(el).lineHeight))
      expect(title.height / lineHeight).toBeGreaterThan(2)

      expect(await fit(page)).toEqual({ past: [], overflow: 0 })
    })

    test('keeps the strip of counts and the funnel lines inside the section', async ({
      page,
      testEnv,
    }) => {
      await page.setViewportSize({ width: 390, height: 844 })
      await loginViaModTools(page, testEnv.mod.email)
      await serveIdeas(page)
      await open(page)

      await expect(section(page).locator('.chip').first()).toBeVisible({
        timeout: timeouts.ui.appearance,
      })
      await expect(section(page).locator('.funnel-link')).toHaveCount(7)
      // The five chips are wider together than the room, so they wrap onto a second line.
      const tops = await section(page)
        .locator('.chip')
        .evaluateAll((els) => [
          ...new Set(els.map((e) => Math.round(e.getBoundingClientRect().top))),
        ])
      expect(tops.length).toBeGreaterThan(1)
      expect(await fit(page)).toEqual({ past: [], overflow: 0 })
    })
  })
})
