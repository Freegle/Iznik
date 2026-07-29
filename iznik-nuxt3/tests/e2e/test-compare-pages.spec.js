/**
 * Tests for the /compare/* pages (pages/compare/index.vue and
 * pages/compare/[competitor].vue). These are static, unauthenticated
 * informational pages with no prior e2e coverage: a listing page that links
 * to one entry per competitor, and a dynamic per-competitor page that either
 * renders the comparison content or 404s for an unknown slug.
 */

const { test, expect } = require('./fixtures')
const { timeouts } = require('./config')

// Kept in sync with constants/comparisons.js — one row per competitor entry.
const COMPARISONS = [
  { slug: 'trash-nothing', title: 'Freegle vs Trash Nothing' },
  { slug: 'olio', title: 'Freegle vs Olio' },
  { slug: 'freecycle', title: 'Freegle vs Freecycle' },
  { slug: 'lovejunk', title: 'Freegle vs LoveJunk' },
  { slug: 'facebook-marketplace', title: 'Freegle vs Facebook Marketplace' },
  { slug: 'gumtree', title: 'Freegle vs Gumtree' },
  { slug: 'nextdoor', title: 'Freegle vs Nextdoor' },
]

test.describe('Compare pages', () => {
  test('/compare index lists every comparison with a working link', async ({
    page,
    waitForNuxtPageLoad,
  }) => {
    await page.gotoAndVerify('/compare')
    await waitForNuxtPageLoad({ timeout: timeouts.navigation.default })

    await expect(page.locator('.compare__title')).toHaveText(
      'Freegle and the alternatives',
      { timeout: timeouts.ui.appearance }
    )

    for (const { slug } of COMPARISONS) {
      await expect(
        page.locator(`a.compare__list-link[href="/compare/${slug}"]`)
      ).toBeVisible()
    }

    // Link back to help section is present (the mobile nav also has one,
    // hidden at this desktop viewport, so filter to the visible match).
    await expect(
      page.locator('a[href="/help"]').filter({ visible: true }).first()
    ).toBeVisible()
  })

  for (const { slug, title } of COMPARISONS) {
    test(`/compare/${slug} renders the comparison content`, async ({
      page,
      waitForNuxtPageLoad,
    }) => {
      await page.gotoAndVerify(`/compare/${slug}`)
      await waitForNuxtPageLoad({ timeout: timeouts.navigation.default })

      await expect(page.locator('.compare__title')).toHaveText(title, {
        timeout: timeouts.ui.appearance,
      })

      // At-a-glance table, FAQ section and both CTAs should all render —
      // these are the branches that are only reachable when `entry` resolves.
      await expect(page.locator('.compare__table')).toBeVisible()
      await expect(page.locator('.compare__faq-q').first()).toBeVisible()
      await expect(page.locator('a.compare__cta-btn[href="/"]')).toBeVisible()
      await expect(
        page.locator('a.compare__cta-link[href="/compare"]')
      ).toBeVisible()
    })
  }

  test('/compare/<unknown slug> shows the 404 page', async ({ page }) => {
    // gotoAndVerify treats any 404 render as a hard failure (it exists to
    // catch *accidental* 404s on pages that should have loaded) so it can't
    // be used here — this test is deliberately exercising the 404 branch
    // that pages/compare/[competitor].vue throws via createError() when the
    // slug isn't in constants/comparisons.js.
    //
    // A real HTTP 404 response for the navigation itself logs a browser
    // console error, and Sentry captures the resulting "Page not found"
    // exception — both expected here, so allow them for this deliberate
    // negative-path test rather than letting the global console-error
    // listener fail the test.
    page.addAllowedErrorPattern(
      /Failed to load resource: the server responded with a status of 404.*compare\/not-a-real-competitor/
    )
    page.addAllowedErrorPattern(
      /\[Exc?eption for Sentry\]:.*Error: Page not found/
    )

    await page.goto('/compare/not-a-real-competitor', {
      waitUntil: 'domcontentloaded',
      timeout: timeouts.navigation.default,
    })

    await expect(page.locator('body')).toContainText(
      "Oh no! That page doesn't seem to exist",
      { timeout: timeouts.ui.appearance }
    )
  })
})
