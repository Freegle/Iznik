/**
 * The WANTED flow lived at /find until Aug 2026 and is now /ask. Old emails,
 * app home-screen shortcuts and bookmarks keep pointing at /find for years, so
 * the redirect is permanent rather than a migration shim - and it has to
 * survive both a cold request (Netlify _redirects / Nitro route rules) and an
 * in-app navigation that never reaches a server (middleware/ask.global.js).
 *
 * There was no test for any redirect in this repo before this one, which is how
 * server/middleware/councils.js was able to quietly become dead code.
 *
 * The server-side cases assert on the HTTP response rather than driving a
 * browser: it is the actual contract (301 + Location), it does not depend on
 * the page rendering, and the negative case deliberately requests a path that
 * does not exist - which in a browser logs a console error, and this suite
 * fails a test for those.
 */

const { test, expect } = require('./fixtures')
const { timeouts } = require('./config')

const REDIRECTS = [
  ['/find', '/ask'],
  ['/find/whereami', '/ask/whereami'],
  ['/find/whoami', '/ask/whoami'],
  ['/find/mobile/photos', '/ask/mobile/photos'],
  ['/find/mobile/details', '/ask/mobile/details'],
  ['/find/mobile/whereami', '/ask/mobile/whereami'],
]

test.describe('/find redirects to /ask', () => {
  for (const [from, to] of REDIRECTS) {
    test(`${from} answers a permanent redirect to ${to}`, async ({
      request,
    }) => {
      const res = await request.get(from, { maxRedirects: 0 })
      expect(res.status()).toBe(301)
      expect(new URL(res.headers().location, 'http://x').pathname).toBe(to)
    })
  }

  test('keeps the query string that emails use for source tracking', async ({
    request,
  }) => {
    const res = await request.get('/find?src=communitynews', {
      maxRedirects: 0,
    })
    expect(res.status()).toBe(301)
    const location = new URL(res.headers().location, 'http://x')
    expect(location.pathname).toBe('/ask')
    expect(location.searchParams.get('src')).toBe('communitynews')
  })

  test('does not catch a path that merely starts with the same letters', async ({
    request,
  }) => {
    const res = await request.get('/findsomething', { maxRedirects: 0 })
    expect(res.status()).not.toBe(301)
  })

  test('a member following an old link lands on the ask page', async ({
    page,
  }) => {
    await page.gotoAndVerify('/find', {
      timeout: timeouts.navigation.default,
      maxRetries: 1,
    })
    await expect
      .poll(() => new URL(page.url()).pathname, {
        timeout: timeouts.navigation.default,
      })
      .toBe('/ask')
  })
})
