// @ts-check
/**
 * The Experiments section of the SysAdmin UX tab (docs/developers/reference/ux-experiments.md): the
 * list, one row open from the address, the form that makes an experiment and starts it, the question
 * before a stop, a route the server does not have yet, the count in the nav, and a phone.
 *
 * The experiment routes are served by stubs, because a browser would otherwise only ever draw an empty
 * list. The stub shapes are the Go responses in iznik-server-go/ux/experiments.go. A POST adds the
 * draft to the list, as the server does, and a PATCH only records the request, as the server queues it
 * for the batch.
 */

const { test, expect } = require('./fixtures')
const { timeouts, environment } = require('./config')
const { loginViaModTools } = require('./utils/user')

const MODTOOLS_URL = environment.modtoolsBaseUrl

const RUNNING = {
  id: 3,
  flow: 'give',
  name: 'give-photos-skip',
  title: 'Make skipping photos obvious',
  hypothesis: 'People give up at the camera button.',
  change: 'A plain link above the camera button.',
  expected: 'More people reach the next step.',
  arms: { control: [0, 49], b: [50, 99] },
  status: 'running',
  started_at: '2026-10-14 10:00:00',
  ends_at: '2026-10-15 10:00:00',
  decided_at: null,
  decision: null,
  result: {
    read_at: '2026-10-14T16:30:00+00:00',
    arms: {
      control: { starts: 200, completes: 92 },
      b: { starts: 210, completes: 107 },
    },
    primary: {
      b: {
        control_rate: 0.46,
        variant_rate: 0.51,
        diff_points: 5.4,
        p_better: 0.91,
        enough: true,
        observed_share: 51,
        expected_share: 50,
        share_off: false,
      },
    },
    guardrails: [
      {
        arm: 'b',
        name: 'flow:login',
        label: 'finishing "Signing in or joining"',
        control_rate: 0.73,
        variant_rate: 0.72,
        p_worse: 0.2,
        k: 4,
      },
    ],
    allocation: { control: 50, b: 50 },
  },
  pr_url: 'https://github.com/Freegle/Iznik/pull/1800',
  cleanup_pr_url: null,
  created_at: '2026-10-14 08:00:00',
  design: { touches: ['give', 'login'], expected_points: 5 },
  latest_read: {
    read_at: '2026-10-14 16:30:00',
    hours: 6.5,
    summary:
      'After 6.5 hours: 210 people started "Offering something" with the change and 200 without.',
  },
  hours: 6.5,
  cleanup_overdue: false,
  holdout_until: null,
}

const KEPT = {
  ...RUNNING,
  id: 2,
  name: 'give-shorter-first-screen',
  title: 'Shorter first screen',
  change: 'The postcode moves after the photos.',
  status: 'adopted',
  started_at: '2026-10-10 09:00:00',
  ends_at: '2026-10-11 09:00:00',
  decided_at: '2026-10-11 09:00:00',
  decision:
    'Adopted at the end: the change was probably better. 400 people started "Offering something" with the change and 400 without.',
  pr_url: 'https://github.com/Freegle/Iznik/pull/1790',
  latest_read: null,
  hours: 24,
}

const DRAFT = {
  id: 5,
  flow: 'settings',
  name: 'settings-clearer-labels',
  title: 'Clearer labels on the settings page',
  hypothesis: 'People do not know what the switches do.',
  change: 'Each switch gets a line that says what it does.',
  expected: 'Fewer people leave without saving.',
  arms: { control: [0, 49], b: [50, 99] },
  status: 'draft',
  started_at: null,
  ends_at: null,
  decided_at: null,
  decision: null,
  result: null,
  pr_url: null,
  cleanup_pr_url: null,
  created_at: '2026-10-13 08:00:00',
  design: null,
  latest_read: null,
  hours: 0,
  cleanup_overdue: false,
  holdout_until: null,
}

// Taken off by a guardrail whatever the main result: 97 percent likely better, yet taken off because
// the share of submissions that failed was probably worse. The figures alone read as a contradiction.
const GUARDRAIL_REASON =
  'Reverted at the end: the share of submissions that failed was probably worse with the change (probability 99%), whatever the main result.'
const TAKEN_OFF = {
  ...KEPT,
  id: 4,
  name: 'give-cheaper-first-screen',
  title: 'Cheaper first screen',
  status: 'reverted',
  decided_at: '2026-10-12 09:00:00',
  decision: `${GUARDRAIL_REASON} 398 started with the change and 400 without; 53% finished against 46% (+7.1 points); probability the change is better: 97%.`,
  result: {
    ...RUNNING.result,
    arms: {
      control: { starts: 400, completes: 184 },
      b: { starts: 398, completes: 211 },
    },
    primary: {
      b: {
        control_rate: 0.46,
        variant_rate: 0.53,
        diff_points: 7.1,
        p_better: 0.97,
        enough: true,
        observed_share: 50,
        expected_share: 50,
        share_off: false,
      },
    },
    guardrails: [
      {
        arm: 'b',
        name: 'submit_failed',
        label: 'the share of submissions that failed',
        control_rate: 0.051,
        variant_rate: 0.089,
        p_worse: 0.99,
        k: 4,
      },
    ],
  },
  pr_url: null,
  latest_read: null,
}

// Experiments decided on the server, counted from the database by a metric source instead of from Loki.
// The route sends their design and result as stored (iznik-server-go/ux/experiments.go), so these are
// the shapes UxExperimentService writes: the text each version shows in design.copy, and the source's own
// nouns and how many were exposed and counted in result.words and result.detail.
const EMAIL = {
  ...RUNNING,
  id: 21,
  flow: 'reply',
  name: 'digest-subject',
  title: 'A plainer digest subject',
  hypothesis: 'A subject that says what is inside gets more clicks.',
  change: 'The subject of the daily digest names the items in it.',
  expected: 'More members click a link in the digest.',
  arms: { control: [0, 49], items: [50, 99] },
  started_at: '2026-10-20 10:00:00',
  ends_at: '2026-10-22 10:00:00',
  pr_url: 'https://github.com/Freegle/Iznik/pull/1810',
  design: {
    channel: 'email',
    type: 'UnifiedDigestDaily',
    source: 'email_click',
    window_hours: 24,
    intake_hours: 24,
    max_hours: 48,
    fixed_split: true,
    touches: [],
    expected_points: 1,
    copy: {
      control: 'Your daily digest',
      items: 'Sofa, bike and 14 more near you',
    },
  },
  result: {
    read_at: '2026-10-21T16:30:00+00:00',
    arms: {
      control: { starts: 12389, completes: 1239 },
      items: { starts: 12402, completes: 2232 },
    },
    primary: {
      items: {
        control_rate: 0.1,
        variant_rate: 0.18,
        diff_points: 8,
        p_better: 0.97,
        enough: true,
        observed_share: 50,
        expected_share: 50,
        share_off: false,
      },
    },
    guardrails: [
      {
        arm: 'items',
        name: 'unsubscribe',
        label: 'the share of members who unsubscribed',
        control_rate: 0.002,
        variant_rate: 0.002,
        p_worse: 0.1,
        k: 1,
        margin_points: 0.3,
      },
    ],
    allocation: { control: 50, items: 50 },
    words: {
      who: 'members',
      started: 'were counted',
      finished: 'clicked a link within a day',
      tab_started: 'Counted',
      tab_finished: 'Clicked within a day',
      note: 'Each member is counted once, for the first email they were sent, and only when that was at least a day ago.',
    },
    detail: {
      exposed: { control: 12500, items: 12512 },
      counted: { control: 12389, items: 12402 },
    },
  },
  latest_read: null,
  hours: 30.5,
}

const PUSH = {
  ...EMAIL,
  id: 22,
  name: 'push-posts-title',
  title: 'A shorter title on the daily posts push',
  hypothesis: 'A shorter title is read on the lock screen.',
  change:
    'The title of the daily posts push gives the count and the first item.',
  expected: 'More members open the site after the push.',
  pr_url: null,
  design: {
    channel: 'push',
    type: 'daily_new_posts',
    source: 'active_after',
    window_hours: 24,
    intake_hours: 24,
    max_hours: 48,
    fixed_split: true,
    touches: [],
    expected_points: 2,
    copy: {
      control: 'New posts near you',
      items: '12 new posts, including a sofa',
    },
  },
  result: {
    ...EMAIL.result,
    words: {
      who: 'members',
      started: 'were counted',
      finished: 'were active within a day',
      tab_started: 'Counted',
      tab_finished: 'Active within a day',
      note: "Active means the site or app loaded the member's notifications, which it does when they have something unread or open the bell. Each member is counted once, for the first notification they were sent, and only when that was at least a day ago. The hour it was sent counts.",
    },
  },
}

// A finished experiment for each day, the newest first, to push one past the first 25 rows.
const OLD = (i) => ({
  ...KEPT,
  id: 100 + i,
  name: `old-${i}`,
  title: `Old experiment ${i}`,
  flow: 'give',
  status: 'stopped',
  decided_at: `2026-09-${String(30 - i).padStart(2, '0')} 10:00:00`,
  decision: 'Stopped by hand.',
  pr_url: null,
  latest_read: null,
})

const FUNNELS = [
  'give',
  'ask',
  'login',
  'reply',
  'chat_promise',
  'chat_outcome',
  'settings',
]

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

/** The answer of GET /modtools/ux/experiments: an entry for every funnel, running apart from the rest. */
function answer(list) {
  return {
    running_count: list.filter((e) => e.status === 'running').length,
    max_running: 5,
    flows: FUNNELS.map((flow) => ({
      flow,
      running: list.filter((e) => e.flow === flow && e.status === 'running'),
      history: list.filter((e) => e.flow === flow && e.status !== 'running'),
    })),
  }
}

/**
 * Serve the experiment routes. `calls` records every POST and PATCH in order, with its body. With
 * `missing`, every route answers 404, as the site does when it is live before the Go API.
 */
async function serveExperiments(
  page,
  { list = [RUNNING, KEPT, DRAFT], missing = false } = {}
) {
  const calls = []
  const state = { list: structuredClone(list) }
  await page.route(/\/modtools\/ux\/experiments(\/\d+)?(\?|$)/, (route) => {
    const req = route.request()
    const method = req.method()
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

    if (method === 'OPTIONS')
      return route.fulfill({ status: 204, headers: cors })
    if (missing) return json(404, { error: 404, message: 'Not Found' })

    if (method === 'GET') return json(200, answer(state.list))

    const body = req.postDataJSON()
    calls.push({ method, url: req.url(), body })
    if (method === 'POST') {
      state.list.push({
        ...DRAFT,
        id: 11,
        flow: body.flow,
        name: body.name,
        title: body.title,
        hypothesis: body.hypothesis,
        change: body.change,
        expected: body.expected,
        design: body.design,
        created_at: '2026-10-15 09:00:00',
      })
      return json(200, { id: 11, status: 'draft' })
    }
    const id = Number(req.url().match(/experiments\/(\d+)/)?.[1])
    return json(200, { queued: true, action: body.action, id })
  })
  return { calls, state }
}

const tab = (page) => page.locator('.sysadmin-ux')
const section = (page) => tab(page).locator('.ux-experiments')
const rows = (page) => section(page).locator('.ux-exp-row')
const navLink = (page, label) =>
  tab(page).locator('nav[aria-label="UX sections"] a', { hasText: label })

async function open(page, query = '') {
  await page.goto(
    `${MODTOOLS_URL}/sysadmin?tab=ux&ux_section=experiments${query ? '&' + query : ''}`,
    { timeout: timeouts.navigation.initial }
  )
  await expect(tab(page).locator('nav[aria-label="UX sections"]')).toBeVisible({
    timeout: timeouts.ui.appearance,
  })
}

test.describe('ModTools SysAdmin UX tab: Experiments', () => {
  test('lists a running, a kept and a draft experiment, running first', async ({
    page,
    testEnv,
  }) => {
    await loginViaModTools(page, testEnv.mod.email)
    await serveExperiments(page)
    await open(page)

    await expect(rows(page)).toHaveCount(3, { timeout: timeouts.ui.appearance })
    // Running first, then not started, then finished.
    await expect(rows(page).locator('.title')).toHaveText([
      'Make skipping photos obvious',
      'Clearer labels on the settings page',
      'Shorter first screen',
    ])
    await expect(rows(page).locator('.status')).toHaveText([
      'Running',
      'Not started',
      'Kept',
    ])
    await expect(rows(page).first().locator('.line')).toContainText(
      'Looking better: 51% finished against 46% (+5.4 points).'
    )
    await expect(section(page).locator('.slots')).toHaveText(
      '1 of 5 experiment slots in use.'
    )
    // Nothing is open until one is chosen.
    await expect(section(page).locator('.ux-exp-detail')).toHaveCount(0)
  })

  test('opens a row from a linked address', async ({ page, testEnv }) => {
    await loginViaModTools(page, testEnv.mod.email)
    await serveExperiments(page)
    await open(page, 'ux_exp=3')

    const detail = section(page).locator('.ux-exp-detail')
    await expect(detail).toBeVisible({ timeout: timeouts.ui.appearance })
    await expect(detail).toHaveCount(1)
    await expect(detail).toContainText('A plain link above the camera button.')
    await expect(detail).toContainText('How it is going')
    await expect(detail).toContainText(
      'Started: 210 with the change, 200 without.'
    )
    await expect(detail).toContainText('Chance the change is better: 91%.')
    await expect(rows(page).first()).toHaveAttribute('aria-expanded', 'true')
    await expect(rows(page).nth(1)).toHaveAttribute('aria-expanded', 'false')

    // The full read is behind a link, and the code change is a link that opens apart.
    await expect(detail.locator('.full-read')).toHaveCount(0)
    await detail.getByText('Show the full read').click()
    await expect(detail.locator('.full-read')).toContainText(
      'After 6.5 hours: 210 people started "Offering something"'
    )
    const code = detail.getByRole('link', { name: 'The code change' })
    await expect(code).toHaveAttribute(
      'href',
      'https://github.com/Freegle/Iznik/pull/1800'
    )
    await expect(code).toHaveAttribute('rel', /noopener/)

    // Pressing the open row closes it, and the address follows.
    await rows(page).first().click()
    await expect(section(page).locator('.ux-exp-detail')).toHaveCount(0)
    expect(new URL(page.url()).searchParams.get('ux_exp')).toBeNull()
  })

  test('creates and starts an experiment from the form', async ({
    page,
    testEnv,
  }) => {
    await loginViaModTools(page, testEnv.mod.email)
    const served = await serveExperiments(page)
    await open(page)

    await section(page).getByRole('button', { name: 'New experiment' }).click()
    const form = section(page).locator('.ux-exp-form')
    await expect(form).toBeVisible({ timeout: timeouts.ui.appearance })
    expect(new URL(page.url()).searchParams.get('ux_exp')).toBe('new')

    await form.locator('.f-flow').selectOption('ask')
    await form.locator('.f-name').fill('ask-shorter-form')
    await expect(form.locator('.hint-missing')).toContainText(
      'Not found in the code yet. Everyone will see the old version until it is.'
    )
    await form.locator('.f-title').fill('A shorter form for asking')
    await form.locator('.f-hypothesis').fill('Asking takes too many steps.')
    await form.locator('.f-change').fill('The form has fewer fields.')
    await form.locator('.f-expected').fill('More people finish asking.')
    await form.locator('.f-points').fill('4')
    await form.getByLabel('Replying to a post').check()
    await form.getByRole('button', { name: 'Save and start' }).click()

    // The draft is made first, then the batch is asked to start it, in that order.
    await expect
      .poll(() => served.calls.length, { timeout: timeouts.ui.appearance })
      .toBe(2)
    expect(served.calls.map((c) => c.method)).toEqual(['POST', 'PATCH'])
    expect(served.calls[0].body).toMatchObject({
      flow: 'ask',
      name: 'ask-shorter-form',
      title: 'A shorter form for asking',
      hypothesis: 'Asking takes too many steps.',
      change: 'The form has fewer fields.',
      expected: 'More people finish asking.',
      design: { touches: ['ask', 'reply', 'login'], expected_points: 4 },
    })
    expect(served.calls[0].body).not.toHaveProperty('pr_url')
    expect(served.calls[1].body).toMatchObject({ action: 'start' })
    expect(served.calls[1].url).toMatch(/\/modtools\/ux\/experiments\/11(\?|$)/)

    // The form closes on the new draft, which says the start was requested.
    await expect(form).toHaveCount(0)
    await expect(section(page).locator('.ux-exp-detail')).toContainText(
      'Start requested',
      {
        timeout: timeouts.ui.appearance,
      }
    )
    expect(new URL(page.url()).searchParams.get('ux_exp')).toBe('11')
  })

  test('asks before it stops and then sends the stop', async ({
    page,
    testEnv,
  }) => {
    await loginViaModTools(page, testEnv.mod.email)
    const served = await serveExperiments(page)
    await open(page, 'ux_exp=3')

    const detail = section(page).locator('.ux-exp-detail')
    await detail.getByRole('button', { name: 'Stop now' }).click()
    await expect(detail).toContainText(
      'Stop "Make skipping photos obvious" now? Everyone goes back to the old version.'
    )
    expect(served.calls).toHaveLength(0)

    await detail.getByRole('button', { name: 'Keep it running' }).click()
    await expect(detail.getByRole('button', { name: 'Stop now' })).toBeVisible()
    expect(served.calls).toHaveLength(0)

    await detail.getByRole('button', { name: 'Stop now' }).click()
    await detail.getByRole('button', { name: 'Yes, stop it' }).click()
    await expect(detail).toContainText('Stop requested', {
      timeout: timeouts.ui.appearance,
    })
    await expect(detail).toContainText(
      'The batch applies it within a few minutes; this card changes when it has.'
    )
    expect(served.calls).toHaveLength(1)
    expect(served.calls[0].method).toBe('PATCH')
    expect(served.calls[0].body).toMatchObject({ action: 'stop' })
    expect(served.calls[0].url).toMatch(/\/modtools\/ux\/experiments\/3(\?|$)/)
  })

  test('says not switched on when the route is missing', async ({
    page,
    testEnv,
  }) => {
    await loginViaModTools(page, testEnv.mod.email)
    await serveExperiments(page, { missing: true })
    const errors = []
    page.on('pageerror', (error) => errors.push(error.message))
    await open(page)

    await expect(section(page)).toContainText(
      'This part is not switched on yet.',
      {
        timeout: timeouts.ui.appearance,
      }
    )
    await expect(rows(page)).toHaveCount(0)
    // The other sections still work, and the nav stays quiet.
    await expect(navLink(page, 'Experiments').locator('.badge')).toHaveCount(0)
    await navLink(page, 'Pages and features').click()
    await expect(tab(page).getByText('Friction', { exact: true })).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    expect(errors).toHaveLength(0)
  })

  test('shows the badge on the nav', async ({ page, testEnv }) => {
    await loginViaModTools(page, testEnv.mod.email)
    await serveExperiments(page)
    await open(page)

    const badge = navLink(page, 'Experiments').locator('.badge')
    await expect(badge).toHaveText('1 running', {
      timeout: timeouts.ui.appearance,
    })

    // The nav belongs to every section, so the count follows the person to another one.
    await navLink(page, 'Pages and features').click()
    await expect(navLink(page, 'Experiments').locator('.badge')).toHaveText(
      '1 running'
    )
  })

  test('shows an overdue cleanup on the nav instead of the count', async ({
    page,
    testEnv,
  }) => {
    await loginViaModTools(page, testEnv.mod.email)
    await serveExperiments(page, {
      list: [RUNNING, { ...KEPT, cleanup_overdue: true }, DRAFT],
    })
    await open(page)
    await expect(navLink(page, 'Experiments').locator('.badge')).toHaveText(
      'Cleanup overdue',
      { timeout: timeouts.ui.appearance }
    )
    await expect(rows(page).last().locator('.overdue')).toHaveText(
      'Clean up the code'
    )
  })

  test('draws a row on three lines at 390 wide, with nothing wider than the section', async ({
    page,
    testEnv,
  }) => {
    await page.setViewportSize({ width: 390, height: 844 })
    await loginViaModTools(page, testEnv.mod.email)
    await serveExperiments(page)
    await open(page, 'ux_exp=3')

    await expect(rows(page)).toHaveCount(3, { timeout: timeouts.ui.appearance })
    const row = rows(page).first()
    const box = async (sel) =>
      (await row.locator(sel).boundingBox()) || { y: -1, height: 0 }
    const status = await box('.status')
    const title = await box('.title')
    const line = await box('.line')
    // The status and funnel, then the title, then the result: each below the one before.
    expect(title.y).toBeGreaterThan(status.y + status.height - 2)
    expect(line.y).toBeGreaterThan(title.y + title.height - 2)

    // The ModTools menu takes room at this width, so the page itself is wider than the screen in
    // every section of the tab, Overview and Pages included. What matters here is the section: nothing
    // in it, the open row's details too, reaches past the room it was given.
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
    await expect(section(page).locator('.ux-exp-detail')).toBeVisible()
    expect(await fit()).toEqual({ past: [], overflow: 0 })

    // The form is the widest thing in the section.
    await section(page).getByRole('button', { name: 'New experiment' }).click()
    await expect(section(page).locator('.ux-exp-form')).toBeVisible()
    expect(await fit()).toEqual({ past: [], overflow: 0 })
  })

  test('says why an experiment was taken off, in its row and when it is opened', async ({
    page,
    testEnv,
  }) => {
    await loginViaModTools(page, testEnv.mod.email)
    await serveExperiments(page, { list: [RUNNING, TAKEN_OFF, DRAFT] })
    await open(page, 'ux_exp=4')

    // The figures say the change was better, so the batch's reason has to be there to read.
    const detail = section(page).locator('.ux-exp-detail')
    await expect(detail).toBeVisible({ timeout: timeouts.ui.appearance })
    await expect(detail.locator('.decision')).toContainText(GUARDRAIL_REASON)
    await expect(detail).toContainText('Chance the change is better: 97%.')
    await expect(detail.locator('.others-label')).toHaveText('Also checked')

    const row = rows(page).filter({ hasText: 'Cheaper first screen' })
    await expect(row.locator('.status')).toHaveText('Taken off')
    await expect(row.locator('.line-text')).toHaveText(
      `Taken off: 53% finished against 46% (+7.1 points). ${GUARDRAIL_REASON}`
    )
  })

  test('says in words, beside the button, why Start is off', async ({
    page,
    testEnv,
  }) => {
    // A disabled button takes no pointer, so its title is never shown. The reason has to be on the page.
    await loginViaModTools(page, testEnv.mod.email)
    const second = {
      ...DRAFT,
      id: 6,
      flow: 'give',
      name: 'give-second-change',
      title: 'A second change to offering',
    }
    await serveExperiments(page, { list: [RUNNING, second] })
    await open(page, 'ux_exp=6')

    const detail = section(page).locator('.ux-exp-detail')
    const start = detail.getByRole('button', { name: 'Start' })
    await expect(start).toBeDisabled({ timeout: timeouts.ui.appearance })
    await expect(detail.locator('.start-reason')).toBeVisible()
    await expect(detail.locator('.start-reason')).toHaveText(
      'Another change is running on this funnel.'
    )
    await expect(start).toHaveAttribute(
      'aria-describedby',
      await detail.locator('.start-reason').getAttribute('id')
    )
  })

  test('draws the word for a kept experiment dark enough to read', async ({
    page,
    testEnv,
  }) => {
    // ModTools forces its own light green on .bg-success, so the chip must not use it.
    await loginViaModTools(page, testEnv.mod.email)
    await serveExperiments(page)
    await open(page, 'ux_exp=3')

    const kept = rows(page).filter({ hasText: 'Shorter first screen' })
    await expect(kept.locator('.status')).toHaveText('Kept', {
      timeout: timeouts.ui.appearance,
    })
    const colours = await kept.locator('.status').evaluate((el) => {
      const s = getComputedStyle(el)
      return { text: s.color, back: s.backgroundColor }
    })
    expect(colours.back).toBe('rgb(42, 122, 10)')
    expect(contrast(colours.text, colours.back)).toBeGreaterThanOrEqual(4.5)

    // The same dark green is on the chance chip of a running experiment that is ahead.
    const ahead = section(page).locator('.ux-exp-detail .verdict')
    await expect(ahead).toHaveText('Change is ahead')
    const verdict = await ahead.evaluate((el) => {
      const s = getComputedStyle(el)
      return { text: s.color, back: s.backgroundColor }
    })
    expect(contrast(verdict.text, verdict.back)).toBeGreaterThanOrEqual(4.5)
  })

  test('opens an old experiment from a linked address, even though it is further down than 25', async ({
    page,
    testEnv,
  }) => {
    await loginViaModTools(page, testEnv.mod.email)
    const old = Array.from({ length: 30 }, (_, i) => OLD(i))
    await serveExperiments(page, { list: old })
    // The oldest of the 30 would be left out by the first page of 25.
    await open(page, 'ux_exp=129')

    const detail = section(page).locator('.ux-exp-detail')
    await expect(detail).toBeVisible({ timeout: timeouts.ui.appearance })
    await expect(detail).toContainText('Short name: old-29')
    await expect(rows(page).last().locator('.title')).toHaveText(
      'Old experiment 29'
    )
    await expect(rows(page)).toHaveCount(30)
    await expect(rows(page).last()).toHaveAttribute('aria-expanded', 'true')
    await expect(section(page).locator('button.more')).toHaveCount(0)
  })

  test('shows an experiment decided on the server: its channel, the text of each version, how long members are reached and how many were counted', async ({
    page,
    testEnv,
  }) => {
    await loginViaModTools(page, testEnv.mod.email)
    await serveExperiments(page, { list: [RUNNING, EMAIL, PUSH, DRAFT] })
    await open(page, 'ux_exp=21')

    await expect(rows(page)).toHaveCount(4, { timeout: timeouts.ui.appearance })
    // The funnel column names the channel and then the funnel for a change decided on the server, and the funnel
    // alone otherwise. The Funnel filter chooses by funnel, so the column says which funnel each one feeds.
    const funnels = await rows(page).locator('.funnel').allTextContents()
    expect([...funnels].sort()).toEqual([
      'Email, Replying',
      'Offering',
      'Push, Replying',
      'Settings',
    ])
    await expect(
      rows(page)
        .filter({ hasText: 'A plainer digest subject' })
        .locator('.line')
    ).toContainText(
      'Looking better: 18.0% clicked within a day against 10.0% (+8.0 points).'
    )

    // The mail experiment, open: the text of each version, how long members are reached, and the counts.
    const detail = section(page).locator('.ux-exp-detail')
    await expect(detail).toBeVisible({ timeout: timeouts.ui.appearance })
    await expect(detail).toHaveCount(1)
    const channel = detail.locator('.ux-exp-channel')
    await expect(channel).toContainText('Email experiment')
    await expect(channel).toContainText('Funnel: Replying to a post.')
    await expect(channel).toContainText('Control: Your daily digest')
    await expect(channel).toContainText(
      'Items: Sofa, bike and 14 more near you'
    )
    await expect(channel).toContainText(
      'Served until 21 Oct 2026, 10:00 UTC. Decision on 22 Oct 2026, 10:00 UTC.'
    )
    // In the words and the order of the read-out: the change first, then the old version.
    await expect(channel.locator('.exposed')).toHaveText(
      'Exposed: 12,512 with the change, 12,500 without.'
    )
    await expect(channel.locator('.counted')).toHaveText(
      'Counted: 12,402 with the change, 12,389 without.'
    )
    // The read-out uses the nouns of the source, and says how it counts.
    await expect(detail).toContainText(
      'Counted: 12,402 with the change, 12,389 without.'
    )
    await expect(detail).toContainText(
      'Clicked within a day: 18.0% with the change, 10.0% without. That is 8 points more.'
    )
    await expect(detail.locator('.note')).toContainText(
      'Each member is counted once, for the first email they were sent'
    )
    // Another funnel cannot be split by a server arm, so none is listed as watched.
    await expect(detail).not.toContainText('Other funnels we watched')

    // The push experiment has its own text.
    await rows(page)
      .filter({ hasText: 'A shorter title on the daily posts push' })
      .click()
    const push = section(page).locator('.ux-exp-detail .ux-exp-channel')
    await expect(push).toContainText('Push experiment', {
      timeout: timeouts.ui.appearance,
    })
    await expect(push).toContainText('Items: 12 new posts, including a sofa')
    await expect(section(page).locator('.ux-exp-detail')).toContainText(
      'Active within a day: 18.0% with the change, 10.0% without.'
    )

    // A change to the site has no channel block.
    await rows(page).filter({ hasText: 'Make skipping photos obvious' }).click()
    await expect(section(page).locator('.ux-exp-detail')).toContainText(
      'Started: 210 with the change, 200 without.',
      { timeout: timeouts.ui.appearance }
    )
    await expect(section(page).locator('.ux-exp-channel')).toHaveCount(0)
  })

  test('draws an experiment decided on the server at 390 wide, with nothing wider than the section', async ({
    page,
    testEnv,
  }) => {
    await page.setViewportSize({ width: 390, height: 844 })
    await loginViaModTools(page, testEnv.mod.email)
    await serveExperiments(page, { list: [EMAIL, PUSH] })
    await open(page, 'ux_exp=21')

    await expect(rows(page)).toHaveCount(2, { timeout: timeouts.ui.appearance })
    await expect(section(page).locator('.ux-exp-channel')).toBeVisible()
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
  })
})
