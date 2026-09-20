// @ts-check
/**
 * Head/meta smoke tests against the real built app.
 *
 * The unit suite mocks useHead() entirely, so a regression in unhead (bumped
 * to v3 in Nuxt 4.5) or in our hid:->key: migration is structurally invisible
 * to vitest. These tests assert the actually-rendered <head> output: titles,
 * meta descriptions, OG tags, deduplication (exactly one of each keyed tag)
 * and the route.meta.layout contract (navbar hidden on no-navbar pages).
 */

const { test, expect } = require('./fixtures')
const { timeouts } = require('./config')

test.describe('Rendered head/meta smoke tests', () => {
  test('homepage renders title, description and deduplicated OG tags', async ({
    page,
  }) => {
    await page.gotoAndVerify('/', { timeout: timeouts.navigation.initial })

    const title = await page.title()
    expect(title).toContain('give it away')

    // Exactly one of each keyed meta tag: duplicates would mean unhead
    // deduplication regressed in the hid:->key: migration.
    const descriptions = page.locator('head meta[name="description"]')
    await expect(descriptions).toHaveCount(1)
    const description = await descriptions.getAttribute('content')
    expect(description?.length).toBeGreaterThan(20)

    const ogTitles = page.locator('head meta[property="og:title"]')
    await expect(ogTitles).toHaveCount(1)
    expect(await ogTitles.getAttribute('content')).toBeTruthy()

    const ogImages = page.locator('head meta[property="og:image"]')
    await expect(ogImages).toHaveCount(1)

    const ogDescriptions = page.locator('head meta[property="og:description"]')
    await expect(ogDescriptions).toHaveCount(1)

    // The navbar should be present on a default-layout page.
    await expect(page.locator('header .navbar').first()).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
  })

  test('explore page renders its own title and canonical link', async ({
    page,
  }) => {
    await page.gotoAndVerify('/explore', {
      timeout: timeouts.navigation.initial,
    })

    const title = await page.title()
    expect(title.length).toBeGreaterThan(5)

    const canonicals = page.locator('head link[rel="canonical"]')
    await expect(canonicals).toHaveCount(1)
    const canonical = await canonicals.getAttribute('href')
    expect(canonical).toContain('/explore')

    const descriptions = page.locator('head meta[name="description"]')
    await expect(descriptions).toHaveCount(1)
  })

  test('no-navbar layout page hides the navbar (route.meta.layout contract)', async ({
    page,
  }) => {
    // NationalReuseDay declares definePageMeta({ layout: 'no-navbar' }).
    // If route.meta.layout stopped being populated (a Nuxt 4 route-metadata
    // change), the navbar would wrongly appear here — app.vue's
    // useNavbarVisibility() would fall back to 'default'.
    await page.gotoAndVerify('/NationalReuseDay', {
      timeout: timeouts.navigation.initial,
    })

    // Wait until the page has real content (it has no h1), then check that
    // the navbar never rendered.
    await expect(page.locator('#__nuxt')).toContainText('Reuse', {
      timeout: timeouts.ui.appearance,
    })
    await expect(page.locator('header .navbar')).toHaveCount(0)
  })
})
