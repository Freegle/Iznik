/**
 * Deterministic coverage for two small, stable, always-on-master components
 * whose Playwright coverage flips run-to-run because nothing explicitly
 * waits for them to finish rendering (the same "timing-dependent line"
 * disease previously found in SpinButton.vue / LoginModal.vue):
 *
 *  - components/ProxyImage.vue: the preloadValue/imgAttrsComputed branches
 *    (preload+fetchpriority vs neither) only both execute if a page renders
 *    both variants and something actually waits for them; the fullSrc
 *    branches (prepend USER_SITE, encode a '?' query string) only execute
 *    for a src that needs both transforms.
 *  - components/SupportLink.vue: wrapped in <client-only>, so it mounts on
 *    a post-hydration tick; the href computed also branches on logged-in
 *    vs logged-out.
 *
 * FreeglerPhotoGrid.vue (rendered on the logged-out homepage) passes image
 * 1 preload+fetchpriority="high" and image 3 neither, exercising both
 * ProxyImage branches in one render. NationalReuseDay.vue's banner
 * (src="/NRD/Banner.png?a=1", non-http, contains '?') exercises the
 * fullSrc branches, and its (logged-out) SupportLink covers that branch.
 * The logged-in SupportLink branch is exercised on /mobile instead:
 * NationalReuseDay uses the 'no-navbar' layout, which never calls
 * useBootSession()/fetchMe(), so authStore.user is never populated there
 * regardless of login - see the comment on that test below. /mobile uses
 * the default layout (which does boot the session).
 */
const { test, expect } = require('./fixtures')
const { timeouts } = require('./config')
const { loginViaHomepage } = require('./utils/user')

test.describe('ProxyImage and SupportLink branch coverage', () => {
  test('logged-out homepage photo grid: first image gets preload+fetchpriority, a later one gets neither', async ({
    page,
    waitForNuxtPageLoad,
  }) => {
    await page.gotoAndVerify('/')
    await waitForNuxtPageLoad({ timeout: timeouts.ui.appearance })

    const grid = page.locator('.photo-grid')
    await expect(grid).toBeVisible({ timeout: timeouts.ui.appearance })

    const firstImg = grid.locator('.photo-cell:nth-child(1) img')
    await expect(firstImg).toBeVisible({ timeout: timeouts.ui.appearance })
    await expect(firstImg).toHaveAttribute('fetchpriority', 'high')

    const thirdImg = grid.locator('.photo-cell:nth-child(3) img')
    await expect(thirdImg).toBeVisible({ timeout: timeouts.ui.appearance })
    expect(await thirdImg.getAttribute('fetchpriority')).toBeNull()

    // Both resolve through ProxyImage's fullSrc computed, which prepends
    // USER_SITE to the non-http /landingpage/... src before handing it to
    // the weserv provider as the proxied url= param.
    // @nuxt/image 2 URL-encodes the weserv url= param, so decode before
    // checking the proxied path.
    const firstSrc = await firstImg.getAttribute('src')
    expect(decodeURIComponent(firstSrc)).toContain('/landingpage/Freegler')
  })

  test('National Reuse Day: banner image resolves the non-http + query-string branches, and the logged-out support link renders', async ({
    page,
    waitForNuxtPageLoad,
  }) => {
    await page.gotoAndVerify('/NationalReuseDay')
    await waitForNuxtPageLoad({ timeout: timeouts.ui.appearance })

    const banner = page.locator('img[alt="National Reuse Day banner"]')
    await expect(banner).toBeVisible({ timeout: timeouts.ui.appearance })

    // fullSrc must have prepended the site origin (didn't start with http)
    // and passed the '?a=1' query through untouched.
    //
    // This used to assert 'a%3D1' - escaped - which is what the origin then
    // received. Harmless for a banner whose parameter nothing reads; not
    // harmless for Gravatar, which lost d=identicon that way and served its
    // own logo to every member without a Gravatar account. @nuxt/image encodes
    // the weserv url= param once, so one decode must show the query as the
    // origin will see it.
    const bannerSrc = await banner.getAttribute('src')
    expect(decodeURIComponent(bannerSrc)).toContain('/NRD/Banner.png?a=1')

    // SupportLink is <client-only>; wait for its mailto link explicitly
    // rather than relying on the page title, so hydration has actually
    // finished before the test (and coverage collection) ends.
    const supportLink = page.locator(
      'a[href^="mailto:partnerships@ilovefreegle.org"]'
    )
    await expect(supportLink).toBeVisible({ timeout: timeouts.ui.appearance })
    const loggedOutHref = await supportLink.getAttribute('href')
    expect(loggedOutHref).toContain('not logged in when contacting Support')
  })

  test('Mobile app page: logged-in support link href includes the user id note', async ({
    page,
    testEnv,
  }) => {
    // NationalReuseDay uses the 'no-navbar' layout, which never calls
    // useBootSession()/fetchMe() - so authStore.user is never populated
    // there regardless of login, and SupportLink's logged-in branch can't be
    // observed on that page. /mobile uses the default layout (which does
    // boot the session) and also renders a plain, unconditional
    // <SupportLink /> - see components/ProxyImage.vue coverage tests above
    // for why NationalReuseDay is still the right page for the other
    // branches.
    await loginViaHomepage(page, testEnv.user.email, 'freegle')

    await page.gotoAndVerify('/mobile')

    const supportLink = page.locator(
      'a[href^="mailto:support@ilovefreegle.org"]'
    )
    // Poll the attribute itself (rather than a single getAttribute snapshot
    // right after visibility) since the default layout's session boot and
    // this <client-only> component's own mount both happen asynchronously,
    // in no guaranteed order.
    await expect(supportLink).toHaveAttribute('href', /logged in as user id/, {
      timeout: timeouts.ui.appearance,
    })
  })
})
