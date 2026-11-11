/**
 * e2e coverage for the /electricals statistics page. The page renders a lot of
 * conditional sections driven by the stats payload (estimated vs firm figures,
 * current vs stale accuracy, success comparison variants, condition table,
 * unusual items). The browser-side stats request is intercepted with a table of
 * payloads so every branch renders; the page retries the fetch on mount, which
 * is what lets the intercepted payload take effect after server rendering.
 */

const { test, expect } = require('./fixtures')
const { timeouts } = require('./config')

const BASE = {
  window: { months: 12 },
  generated_at: '2026-10-01T12:00:00Z',
  counts: { classified: 12000, electrical: 1500, electrical_pct: 12.5 },
  accuracy: {
    measured_for_current_model: true,
    is_electrical: { pct: 94 },
    condition: { pct: 81 },
  },
  impact: {
    tonnes: 42.5,
    tonnes_co2e: 120.3,
    carbon_value_gbp: 9000,
    carbon_proxy_gbp_per_tonne: 75,
    mean_item_kg: 3.2,
  },
  success: {
    electrical: { taken_pct: 70 },
    other: { taken_pct: 65 },
  },
  condition: {
    reusable: { count: 900, taken_pct: 80 },
    damaged: { count: 400, taken_pct: 55 },
    unsure: { count: 200, taken_pct: 40 },
  },
  popular: [
    { name: 'kettle', count: 321 },
    { name: 'toaster', count: 123 },
  ],
  unusual: { items: [{ name: 'fish tank pump', count: 7 }] },
}

const clone = (o) => JSON.parse(JSON.stringify(o))

const CASES = [
  {
    name: 'measured figures with current accuracy',
    payload: () => BASE,
    visible: [
      'OF ALL ITEMS',
      'TONNES CO2e',
      'Checked against items sorted by hand, we get this right 94%',
      'Weights',
      '70% of the electricals people offer get taken, against 65%',
      'Even broken ones get taken',
      'matches what our volunteers said 81% of the time',
      'fish tank pump',
      'kettle',
      'Covers the last 12 months',
      'in the last 12 months',
    ],
    hidden: ['estimates: we', 'an estimated', 'Nothing qualifies yet'],
  },
  {
    name: 'estimated figures with stale accuracy',
    payload: () => {
      const p = clone(BASE)
      p.estimates = {
        firm: false,
        electrical_offers: 4000,
        tonnes: 99,
        tonnes_co2e: 250,
        carbon_value_gbp: 18000,
      }
      p.coverage = { pct: 37 }
      p.accuracy.measured_for_current_model = false
      p.window.months = 6
      return p
    },
    visible: [
      'estimates: we',
      "we've analysed 37% of posts",
      'an estimated',
      'an earlier version of the software',
      'Covers the last 6 months',
    ],
    hidden: ['Checked against items sorted by hand'],
  },
  {
    name: 'firm estimates carry no estimate caveat',
    payload: () => {
      const p = clone(BASE)
      p.estimates = {
        firm: true,
        electrical_offers: 1500,
        tonnes: 42.5,
        tonnes_co2e: 120.3,
        carbon_value_gbp: 9000,
      }
      p.coverage = { pct: 100 }
      return p
    },
    visible: ['Weights'],
    hidden: ['estimates: we', 'an estimated'],
  },
  {
    name: 'sparse payload hides optional sections',
    payload: () => {
      const p = clone(BASE)
      p.counts.electrical_pct = null
      p.impact = { tonnes: null, tonnes_co2e: null, carbon_value_gbp: null }
      p.success = { electrical: null, other: null }
      p.condition = {}
      p.unusual = { items: [] }
      delete p.window
      return p
    },
    visible: ['Nothing qualifies yet', 'Covers the last 12 months'],
    hidden: [
      'OF ALL ITEMS',
      'TONNES CO2e',
      'Weights',
      'Do electricals find a new home?',
      'Even broken ones get taken',
    ],
  },
  {
    name: 'only electrical success is quoted without a comparison',
    payload: () => {
      const p = clone(BASE)
      p.success = { electrical: { taken_pct: 70 }, other: null }
      p.condition = { damaged: { count: 5, taken_pct: 20 } }
      return p
    },
    visible: [
      '70% of the electricals people offer get taken.',
      'Damaged or for spares',
    ],
    hidden: ['of everything else', 'Not clear from the photo'],
  },
]

async function openWithStats(page, waitForNuxtPageLoad, payload) {
  await page.route('**/electricals/stats*', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(payload),
    })
  )
  await page.gotoAndVerify('/electricals')
  await waitForNuxtPageLoad({ timeout: timeouts.navigation.default })
  await expect(page.locator('h2')).toContainText('Electricals on Freegle', {
    timeout: timeouts.ui.appearance,
  })
}

test.describe('Electricals statistics page', () => {
  for (const c of CASES) {
    test(`renders ${c.name}`, async ({ page, waitForNuxtPageLoad }) => {
      await openWithStats(page, waitForNuxtPageLoad, c.payload())

      const body = page.locator('body')
      for (const text of c.visible) {
        await expect(body).toContainText(text.replace(/ +/g, ' '), {
          timeout: timeouts.ui.appearance,
        })
      }
      for (const text of c.hidden) {
        await expect(body).not.toContainText(text)
      }
    })
  }

  test('shows the unavailable notice when the stats request fails', async ({
    page,
    waitForNuxtPageLoad,
  }) => {
    await page.route('**/electricals/stats*', (route) =>
      route.fulfill({
        status: 503,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'boom' }),
      })
    )
    await page.gotoAndVerify('/electricals')
    await waitForNuxtPageLoad({ timeout: timeouts.navigation.default })
    await expect(page.locator('h2')).toContainText('Electricals on Freegle')
    await expect(page.locator('body')).toContainText(
      "These figures aren't available at the moment",
      { timeout: timeouts.ui.appearance }
    )
  })
})
