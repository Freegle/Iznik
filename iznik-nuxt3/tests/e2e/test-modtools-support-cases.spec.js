// @ts-check
/**
 * Support Tools > Cases: the cases moderators referred to Support, the red count of those waiting
 * for Support, and the first pass's draft put in the reply box as a suggested reply.
 *
 * The page's own API calls are answered here with page.route, so no case, first pass or helper
 * needs to exist in the test database. The session is the real one with the waiting count set.
 */

const { test, expect } = require('./fixtures')
const { timeouts, environment } = require('./config')
const { loginViaModTools } = require('./utils/user')

const MODTOOLS_URL = environment.modtoolsBaseUrl
const SUPPORT_EMAIL = 'testmod@test.com' // seeded with systemrole Admin

const DRAFT =
  'Hi, thanks for sending this over. The post was marked as taken before the member replied, so replies to it were closed.'
const SUGGESTED =
  'Suggested reply from the AI helper. Check it, change what you need, then send.'

function caseRow(id, extra = {}) {
  return {
    id,
    created: '2026-11-09T09:00:00Z',
    status: 'Open',
    chatid: 900 + id,
    member: { id: 3, displayname: 'Morgan Member' },
    referrer: { id: 2, displayname: 'Rae Referrer' },
    classification: 'Usage',
    confidence: 'High',
    summary: `Question for case ${id}`,
    firstpass_status: 'Done',
    messages: 0,
    lastactivity: '2026-11-09T09:00:00Z',
    awaiting: false,
    ...extra,
  }
}

const CASE = {
  viewer: 'Support',
  id: 41,
  created: '2026-11-09T09:00:00Z',
  updated: '2026-11-09T09:00:00Z',
  status: 'Open',
  source: 'Referral',
  chatid: null,
  reason: 'The member says they cannot reply to a post. Can you see why?',
  member: { id: 3, displayname: 'Morgan Member' },
  referrer: { id: 2, displayname: 'Rae Referrer' },
  firstpass_status: 'Done',
  firstpass_error: null,
  firstpass_runid: null,
  brief: {
    version: 2,
    question: 'Why can this member not reply to the post?',
    answer: 'The post was marked **taken** before they replied.',
    classification: 'Usage',
    confidence: {
      level: 'High',
      reason: 'The post history and the chat agree.',
    },
    checks: ['Looked at the chat', 'Checked the post and its outcome'],
    open_questions: [],
    related_cases: [40],
    draft: {
      to: 'referrer',
      text: DRAFT,
      link_warnings: ['example.invalid is not a Freegle page'],
    },
    pool: { fiveHour: 12, sevenDay: 30 },
  },
  handledby: null,
  handled_at: null,
  messages: [],
}

test.describe('ModTools Support cases', () => {
  test('Cases tab: the waiting count, the intro, and the draft as a suggested reply', async ({
    page,
  }) => {
    const listRequests = []
    const posted = []

    // The waiting count comes from the real session, set to 2.
    await page.route('**/api/session**', async (route) => {
      if (route.request().method() !== 'GET') return route.fallback()
      const response = await route.fetch()
      if (!(response.headers()['content-type'] || '').includes('json')) {
        return route.fulfill({ response })
      }
      const body = await response.json()
      if (body && body.me) {
        body.work = { ...(body.work || {}), supportcases: 2 }
        body.work.total = (body.work.total || 0) + 2
      }
      return route.fulfill({ response, json: body })
    })

    await page.route(/\/api\/supportcases(\?|$)/, (route) => {
      listRequests.push(
        new URL(route.request().url()).searchParams.get('status')
      )
      return route.fulfill({
        json: [caseRow(41, { awaiting: true }), caseRow(40, { messages: 1 })],
      })
    })
    await page.route(/\/api\/supportcases\/41(\?|$)/, (route) =>
      route.fulfill({ json: CASE })
    )
    await page.route(/\/api\/supportcases\/41\/messages/, (route) => {
      posted.push(route.request().postDataJSON())
      return route.fulfill({ json: { ret: 0, status: 'Success', id: 5 } })
    })

    await loginViaModTools(page, SUPPORT_EMAIL)
    await page.goto(`${MODTOOLS_URL}/support`, {
      timeout: timeouts.navigation.initial,
    })

    // The red count is on the Support menu item and on the Cases tab, and the page opens there.
    const tabCount = page.locator('[data-testid="cases-tab-count"]')
    await expect(tabCount).toHaveText('2', { timeout: timeouts.ui.appearance })
    await expect(
      page.locator('a[href="/support"]').locator('xpath=..').locator('.badge')
    ).toHaveText('2')

    const intro = page.locator('[data-testid="case-intro"]')
    await expect(intro).toBeVisible({ timeout: timeouts.ui.appearance })
    await expect(intro).toContainText('Refer to Support')
    await expect(intro).toContainText('never to the member')

    const rows = page.locator('[data-testid="case-row"]')
    await expect(rows).toHaveCount(2, { timeout: timeouts.api.default })
    await expect(
      rows.nth(0).locator('[data-testid="case-awaiting"]')
    ).toBeVisible()
    await expect(
      rows.nth(1).locator('[data-testid="case-awaiting"]')
    ).toHaveCount(0)

    // The filter asks the server for only the cases waiting for Support.
    await page
      .locator('[data-testid="case-filter"]')
      .getByText('Waiting for Support')
      .click()
    await expect.poll(() => listRequests).toContain('Waiting')

    // A case whose first pass wrote a draft: the draft is already in the reply box.
    await rows.nth(0).click()
    const box = page.locator('[data-testid="case-reply"]')
    await expect(box).toHaveValue(DRAFT, { timeout: timeouts.ui.appearance })
    const label = page.locator('[data-testid="case-reply-suggested"]')
    await expect(label).toHaveText(SUGGESTED)
    await expect(
      page.locator('[data-testid="case-linkwarnings"]')
    ).toContainText('not a Freegle page')
    await expect(page.locator('[data-testid="draft-use"]')).toHaveCount(0)
    await expect(page.locator('[data-testid="draft-copy"]')).toHaveCount(0)
    expect(posted).toHaveLength(0)

    // Editing it takes the label away; sending is an ordinary reply, marked as an edited draft.
    await box.fill(`${DRAFT} Thanks for checking.`)
    await expect(label).toHaveCount(0)
    expect(posted).toHaveLength(0)
    await page.locator('[data-testid="case-send"]').click()
    await expect.poll(() => posted.length).toBe(1)
    expect(posted[0]).toMatchObject({
      body: `${DRAFT} Thanks for checking.`,
      draftused: 'Edited',
    })
  })
})
