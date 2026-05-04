// @ts-check
/**
 * Tests for pages that exercise v2 API endpoints.
 *
 * These tests verify that pages backed by the Go v2 API load correctly
 * and display expected content. Each test navigates to a page whose data
 * comes from a v2 endpoint, confirming the endpoint returns valid data
 * and the UI renders it without errors.
 *
 * V2 endpoints exercised:
 *   GET /api/v2/story           - stories list
 *   GET /api/v2/communityevent  - community events list
 *   GET /api/v2/volunteering    - volunteering opportunities list
 *   GET /api/v2/job             - job listings
 *   GET /api/v2/donations       - donation target/raised
 *   GET /api/v2/group           - group list (used on many pages)
 */
const { test, expect } = require('./fixtures')
const { timeouts } = require('./config')

test.describe('V2 API Page Tests', () => {
  test('Stories page loads and exercises GET /api/v2/story', async ({
    page,
    waitForNuxtPageLoad,
  }) => {
    await page.gotoAndVerify('/stories')
    await waitForNuxtPageLoad({ timeout: timeouts.navigation.default })

    // Page title should indicate stories
    const title = await page.title()
    expect(title.toLowerCase()).toContain('stories')

    // The page should render without error - check for main content area
    await expect(page.locator('body')).toBeVisible()

    // Should not show an error page
    await expect(page.locator('text=Something went wrong')).not.toBeVisible()
  })

  test('Community events page loads and exercises GET /api/v2/communityevent', async ({
    page,
    waitForNuxtPageLoad,
  }) => {
    await page.gotoAndVerify('/communityevents')
    await waitForNuxtPageLoad({ timeout: timeouts.navigation.default })

    // Page title should indicate community events
    const title = await page.title()
    expect(title.toLowerCase()).toContain('community event')

    // The page should render without error
    await expect(page.locator('body')).toBeVisible()
    await expect(page.locator('text=Something went wrong')).not.toBeVisible()
  })

  test('Volunteering page loads and exercises GET /api/v2/volunteering', async ({
    page,
    waitForNuxtPageLoad,
  }) => {
    await page.gotoAndVerify('/volunteerings')
    await waitForNuxtPageLoad({ timeout: timeouts.navigation.default })

    // Page title should indicate volunteering
    const title = await page.title()
    expect(title.toLowerCase()).toContain('volunteer')

    // The page should render without error
    await expect(page.locator('body')).toBeVisible()
    await expect(page.locator('text=Something went wrong')).not.toBeVisible()
  })

  test('Jobs page loads and exercises GET /api/v2/job', async ({
    page,
    waitForNuxtPageLoad,
  }) => {
    await page.gotoAndVerify('/jobs')
    await waitForNuxtPageLoad({ timeout: timeouts.navigation.default })

    // Page title should indicate jobs
    const title = await page.title()
    expect(title.toLowerCase()).toContain('job')

    // The page should render without error
    await expect(page.locator('body')).toBeVisible()
    await expect(page.locator('text=Something went wrong')).not.toBeVisible()
  })

  test('Jobs page client-only content renders and job search works', async ({
    page,
    waitForNuxtPageLoad,
  }) => {
    await page.gotoAndVerify('/jobs')
    await waitForNuxtPageLoad({ timeout: timeouts.navigation.default })

    // The jobs page uses <client-only>. Wait for its content to render.
    const jobsIntro = page.locator('.jobs-page-intro')
    await jobsIntro.waitFor({ state: 'visible', timeout: timeouts.ui.appearance })

    // Location filter and category select should be present
    await expect(
      page.locator('.jobs-location-input')
    ).toBeVisible({ timeout: timeouts.ui.appearance })
    await expect(
      page.locator('.jobs-category-select')
    ).toBeVisible({ timeout: timeouts.ui.appearance })

    // Type a location to trigger job search (exercises search() and doSearch())
    const locationInput = page
      .locator('.jobs-location-input input')
      .first()
    await locationInput.waitFor({ state: 'visible', timeout: timeouts.ui.appearance })
    await locationInput.fill('Edinburgh')

    // Wait for autocomplete suggestion and select it.
    // Wrap only the waiting/clicking (not assertions) in try-catch to handle
    // cases where the Place API is unavailable in CI.
    let locationSelected = false
    const suggestion = page.locator('.test-place-suggestion-item').first()
    try {
      await suggestion.waitFor({ state: 'visible', timeout: timeouts.ui.autocomplete })
      await suggestion.click()
      locationSelected = true
    } catch {
      // Place API not available in this CI environment — skip search assertions
      console.log('Place autocomplete not available — skipping job search test')
    }

    if (locationSelected) {
      // After selecting, spinner may appear briefly then results render
      // (exercises busy.value toggle in doSearch())
      const resultsArea = page.locator('.jobs-results')
      await resultsArea.waitFor({ state: 'visible', timeout: timeouts.ui.appearance })

      // One of these states must render: job cards, adblock warning, or no-jobs message
      const hasJobs = await page.locator('.job-item').count()
      const hasNoJobsMessage = await page.locator('.jobs-empty').count()
      const hasAdblockWarning = await page
        .locator('text=AdBlocker')
        .count()
      expect(hasJobs + hasNoJobsMessage + hasAdblockWarning).toBeGreaterThan(0)

      if (hasJobs > 0) {
        // Job cards rendered — verify JobOne component structure.
        // AI-generated images are suppressed in email digests (this PR);
        // the briefcase icon is the reliable fallback in both email and UI.
        const jobItem = page.locator('.job-item').first()
        await expect(jobItem).toBeVisible()
        await expect(jobItem.locator('.job-link')).toBeVisible()
      }
    }

    // Disclaimer is always visible once client-only content renders
    await expect(
      page.locator('.jobs-disclaimer')
    ).toBeVisible({ timeout: timeouts.ui.appearance })
  })

  test('Donate page loads and exercises GET /api/v2/donations', async ({
    page,
    waitForNuxtPageLoad,
  }) => {
    // Donate page loads external Stripe/PayPal resources that may be slow in CI.
    // Use domcontentloaded to avoid hanging on external resource loading.
    await page.gotoAndVerify('/donate', { waitUntil: 'domcontentloaded' })
    await waitForNuxtPageLoad({ timeout: timeouts.navigation.default })

    // Page title should indicate donations
    const title = await page.title()
    expect(title.toLowerCase()).toContain('donate')

    // The page should render without error
    await expect(page.locator('body')).toBeVisible()
    await expect(page.locator('text=Something went wrong')).not.toBeVisible()
  })

  test('Explore page for specific group exercises GET /api/v2/group/{id}', async ({
    page,
    waitForNuxtPageLoad,
    testEnv,
  }) => {
    await page.gotoAndVerify(`/explore/${testEnv.group.name}`)
    await waitForNuxtPageLoad({ timeout: timeouts.navigation.default })

    // Should display the group name
    await expect(
      page.locator(`h4:has-text("${testEnv.group.name}")`).first()
    ).toBeVisible({ timeout: timeouts.ui.appearance })

    // Should not show an error page
    await expect(page.locator('text=Something went wrong')).not.toBeVisible()
  })
})
