/**
 * The WANTED flow lived at /find until Aug 2026 and is now /ask. Old emails,
 * app home-screen shortcuts and bookmarks keep pointing at /find for years, so
 * the redirect is permanent rather than a migration shim - and it has to
 * survive both a cold request (Netlify _redirects / Nitro route rules) and an
 * in-app navigation that never reaches a server (middleware/ask.global.js).
 *
 * There was no test for any redirect in this repo before this one, which is how
 * server/middleware/councils.js was able to quietly become dead code.
 */

const { test, expect } = require('./fixtures')
const { timeouts } = require('./config')

const PATHS = [
  ['/find', '/ask'],
  ['/find/whereami', '/ask/whereami'],
  ['/find/whoami', '/ask/whoami'],
  ['/find/mobile/photos', '/ask/mobile/photos'],
  ['/find/mobile/details', '/ask/mobile/details'],
  ['/find/mobile/whereami', '/ask/mobile/whereami'],
]

test.describe('/find redirects to /ask', () => {
  for (const [from, to] of PATHS) {
    test(`${from} lands on ${to}`, async ({ page }) => {
      await page.goto(from, { timeout: timeouts.navigation.default })
      await expect
        .poll(() => new URL(page.url()).pathname, {
          timeout: timeouts.navigation.default,
        })
        .toBe(to)
    })
  }

  test('keeps the query string that emails use for source tracking', async ({
    page,
  }) => {
    await page.goto('/find?src=communitynews', {
      timeout: timeouts.navigation.default,
    })
    await expect
      .poll(() => new URL(page.url()).pathname, {
        timeout: timeouts.navigation.default,
      })
      .toBe('/ask')
    expect(new URL(page.url()).searchParams.get('src')).toBe('communitynews')
  })

  test('does not catch a path that merely starts with the same letters', async ({
    page,
  }) => {
    await page.goto('/findsomething', { timeout: timeouts.navigation.default })
    await expect
      .poll(() => new URL(page.url()).pathname, {
        timeout: timeouts.navigation.default,
      })
      .not.toBe('/ask')
  })
})
