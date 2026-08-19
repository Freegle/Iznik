import { readFileSync } from 'fs'
import { resolve } from 'path'
import { describe, it, expect, vi, beforeEach } from 'vitest'

// The middleware is written against Nuxt's auto-imports, so stub them the way
// the other auto-import-dependent specs in this suite do.
const navigateTo = vi.fn((to, opts) => ({ to, opts }))
vi.stubGlobal('navigateTo', navigateTo)
vi.stubGlobal('defineNuxtRouteMiddleware', (fn) => fn)

const middleware = (await import('~/middleware/ask.global.js')).default

function route(path, query = {}, hash = '') {
  return { path, query, hash }
}

describe('ask.global middleware', () => {
  beforeEach(() => navigateTo.mockClear())

  it('sends the old /find page to /ask', () => {
    middleware(route('/find'))
    expect(navigateTo).toHaveBeenCalledTimes(1)
    expect(navigateTo.mock.calls[0][0].path).toBe('/ask')
  })

  it.each([
    ['/find/whereami', '/ask/whereami'],
    ['/find/whoami', '/ask/whoami'],
    ['/find/mobile/photos', '/ask/mobile/photos'],
    ['/find/mobile/details', '/ask/mobile/details'],
    ['/find/mobile/whereami', '/ask/mobile/whereami'],
  ])('sends %s to %s', (from, to) => {
    middleware(route(from))
    expect(navigateTo.mock.calls[0][0].path).toBe(to)
  })

  it('keeps the query string, so email src tracking survives', () => {
    middleware(route('/find', { src: 'communitynews' }))
    expect(navigateTo.mock.calls[0][0].query).toEqual({ src: 'communitynews' })
  })

  it('keeps the hash', () => {
    middleware(route('/find/mobile/details', {}, '#photos'))
    expect(navigateTo.mock.calls[0][0].hash).toBe('#photos')
  })

  it('replaces rather than pushes, so Back does not bounce forward again', () => {
    middleware(route('/find'))
    expect(navigateTo.mock.calls[0][1]).toMatchObject({
      replace: true,
      redirectCode: 301,
    })
  })

  it.each(['/ask', '/give', '/findsomething', '/browse', '/'])(
    'leaves %s alone',
    (path) => {
      expect(middleware(route(path))).toBeUndefined()
      expect(navigateTo).not.toHaveBeenCalled()
    }
  )
})

describe('production redirect config', () => {
  const root = resolve(__dirname, '../../..')

  // Netlify serves the live site, so the middleware alone is not enough for a
  // cold hit on an old link - _redirects is what answers those.
  it('has forced Netlify redirects for /find', () => {
    const redirects = readFileSync(resolve(root, 'public/_redirects'), 'utf8')
    expect(redirects).toMatch(/^\/find\s+\/ask\s+301!$/m)
    expect(redirects).toMatch(/^\/find\/\*\s+\/ask\/:splat\s+301!$/m)
  })

  it('has Nitro route rules for /find, for non-Netlify hosting', () => {
    const config = readFileSync(resolve(root, 'nuxt.config.ts'), 'utf8')
    expect(config).toContain(
      "'/find': { redirect: { to: '/ask', statusCode: 301 } }"
    )
    expect(config).toContain(
      "'/find/**': { redirect: { to: '/ask/**', statusCode: 301 } }"
    )
  })
})
