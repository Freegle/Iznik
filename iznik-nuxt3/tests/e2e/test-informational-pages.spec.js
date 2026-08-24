/**
 * e2e coverage for a handful of static, unauthenticated informational pages
 * that had no Playwright coverage at all: /together, /maintenance, /charity
 * (including its signup form's conditional branches, without ever actually
 * submitting it — that hits a real API and would create data), and the
 * generic 404 error page (error.vue) reached via an unknown route.
 */

const { test, expect } = require('./fixtures')
const { timeouts } = require('./config')

test.describe('Together (charity/community landing) page', () => {
  test('loads with hero, all five feature cards and the video/testimonial sections', async ({
    page,
    waitForNuxtPageLoad,
  }) => {
    await page.gotoAndVerify('/together')
    await waitForNuxtPageLoad({ timeout: timeouts.navigation.default })

    await expect(page.locator('.together__hero h1')).toContainText(
      'Freegle supports charities',
      { timeout: timeouts.ui.appearance }
    )

    // Hero action links — the mobile nav also has /ask and /give links,
    // hidden at this desktop viewport, so filter to the visible match.
    await expect(
      page.locator('a[href="/charity"]').filter({ visible: true }).first()
    ).toBeVisible()
    await expect(
      page.locator('a[href="/ask"]').filter({ visible: true }).first()
    ).toBeVisible()
    await expect(
      page.locator('a[href="/give"]').filter({ visible: true }).first()
    ).toBeVisible()

    // All five "what Freegle can do" cards render (v-for over `cards`),
    // plus the static "Coming soon" card which shares the same class.
    await expect(page.locator('.together__card')).toHaveCount(6)
    await expect(
      page.locator('.together__card--charity[href="/charity"]')
    ).toBeVisible()
    await expect(
      page.locator('.together__card--volunteer[href="/volunteerings"]')
    ).toBeVisible()

    // Video placeholder section (showVideo branch) with its chapter list.
    await expect(page.locator('.together__video')).toBeVisible()
    await expect(page.locator('.together__chapter')).toHaveCount(4)

    // Testimonial + logos section (showTestimonial branch).
    await expect(page.locator('.together__quote')).toBeVisible()
    await expect(page.locator('.together__logo')).toHaveCount(5)

    // "Coming soon" card and contact mailto link.
    await expect(page.locator('.together__card--soon')).toBeVisible()
    await expect(
      page.locator('a[href^="mailto:partnerships@ilovefreegle.org"]')
    ).toHaveCount(2)
  })
})

test.describe('Maintenance page', () => {
  test('loads with the maintenance message and a link back home', async ({
    page,
    waitForNuxtPageLoad,
  }) => {
    await page.gotoAndVerify('/maintenance')
    await waitForNuxtPageLoad({ timeout: timeouts.navigation.default })

    await expect(page.locator('.maintenance__container h1')).toContainText(
      "Sorry - we're doing some maintenance",
      { timeout: timeouts.ui.appearance }
    )
    await expect(
      page.locator('.maintenance__container a[href="/"]')
    ).toBeVisible()
    await expect(
      page.locator('a[href*="paypal.com/gb/fundraiser/charity"]')
    ).toBeVisible()
  })
})

test.describe('Charity partner signup page', () => {
  test('loads with hero and preview content, and scrolling to the form works', async ({
    page,
    waitForNuxtPageLoad,
  }) => {
    await page.gotoAndVerify('/charity')
    await waitForNuxtPageLoad({ timeout: timeouts.navigation.default })

    await expect(page.locator('.hero-title')).toHaveText(
      'Partner with Freegle',
      { timeout: timeouts.ui.appearance }
    )

    // Post preview mockup (always rendered — no conditional here, but it
    // pulls in CharityBadge, which otherwise has no e2e coverage).
    await expect(page.locator('.phone-mockup')).toBeVisible()
    await expect(page.locator('.browse-card--charity').first()).toBeVisible()

    // Clicking the hero CTA scrolls the signup form into view.
    await page.locator('.hero-cta-btn').click()
    await expect(page.locator('#signup')).toBeInViewport({
      timeout: timeouts.ui.appearance,
    })
  })

  test('submit is disabled until required fields are filled, and org type toggles the right conditional field', async ({
    page,
    waitForNuxtPageLoad,
  }) => {
    await page.gotoAndVerify('/charity')
    await waitForNuxtPageLoad({ timeout: timeouts.navigation.default })

    const submitBtn = page.locator('.submit-btn')
    await expect(submitBtn).toBeVisible({ timeout: timeouts.ui.appearance })

    // Nothing filled in yet — submit must be disabled (canSubmit === false).
    await expect(submitBtn).toBeDisabled()

    // Neither conditional field is shown until an org type is picked.
    await expect(page.locator('#charity-number')).toHaveCount(0)
    await expect(page.locator('#org-details')).toHaveCount(0)

    await page.locator('#org-name').fill('Wandsworth Community Aid')
    await page.locator('#contact-email').fill('info@example-charity.org.uk')

    // Still disabled — no org type selected yet.
    await expect(submitBtn).toBeDisabled()

    // Picking "Registered charity" reveals the charity-number field only.
    await page.getByLabel('Registered charity').check()
    await expect(page.locator('#charity-number')).toBeVisible()
    await expect(page.locator('#org-details')).toHaveCount(0)
    await expect(submitBtn).toBeEnabled()

    // Switching to "Other community organisation" swaps which field shows.
    await page
      .getByLabel('Other community organisation (CIC, trust, etc.)')
      .check()
    await expect(page.locator('#org-details')).toBeVisible()
    await expect(page.locator('#charity-number')).toHaveCount(0)
    await expect(submitBtn).toBeEnabled()
  })

  test('has a link to sign up as an individual instead', async ({
    page,
    waitForNuxtPageLoad,
  }) => {
    await page.gotoAndVerify('/charity')
    await waitForNuxtPageLoad({ timeout: timeouts.navigation.default })

    await expect(page.locator('a[href="/explore"]')).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
  })
})

test.describe('404 page', () => {
  test('an unknown route shows the "page not found" message', async ({
    page,
  }) => {
    // gotoAndVerify treats any 404 render as a hard failure (it exists to
    // catch *accidental* 404s on pages that should have loaded), so it can't
    // be used to test the 404 page itself — use page.goto() directly.
    //
    // A real HTTP 404 response for the navigation itself logs a browser
    // console error, and Sentry captures the resulting "Page not found"
    // exception — both expected here, so allow them for this deliberate
    // negative-path test rather than letting the global console-error
    // listener fail the test.
    page.addAllowedErrorPattern(
      /Failed to load resource: the server responded with a status of 404.*this-route-does-not-exist-e2e-check/
    )
    page.addAllowedErrorPattern(
      /\[Exc?eption for Sentry\]:.*Error: Page not found/
    )

    await page.goto('/this-route-does-not-exist-e2e-check', {
      waitUntil: 'domcontentloaded',
      timeout: timeouts.navigation.default,
    })

    await expect(page.locator('body')).toContainText(
      "Oh no! That page doesn't seem to exist",
      { timeout: timeouts.ui.appearance }
    )
    await expect(page.locator('a[href="/"]')).toBeVisible()
  })
})
