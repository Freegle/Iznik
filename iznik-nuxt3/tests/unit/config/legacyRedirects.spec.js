import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, it, expect } from 'vitest'

// Addresses from the old site that members and search engines still follow. Each was a 404 in
// Sentry. They are answered at the edge by public/_redirects (Netlify applies it before the app)
// and, for hosts that do not read that file, by routeRules in nuxt.config.ts.
const ROOT = join(__dirname, '..', '..', '..')
const config = readFileSync(join(ROOT, 'nuxt.config.ts'), 'utf8')
const redirectsFile = readFileSync(join(ROOT, 'public', '_redirects'), 'utf8')

// [address, where it goes]. /mygroups is in _redirects only.
const LEGACY = [
  ['/mygroups', '/browse'],
  ['/mygroups/*', '/browse'],
  ['/groups', '/explore'],
  ['/groups/*', '/explore'],
  ['/search', '/browse'],
  ['/ask/search', '/browse'],
  ['/offer', '/give'],
  ['/main.php', '/'],
  ['/m.php', '/'],
  ['/index.php', '/'],
]

const edgeRules = Object.fromEntries(
  redirectsFile
    .split('\n')
    .map((line) => line.trim().split(/\s+/))
    .filter((parts) => parts.length >= 3 && !parts[0].startsWith('#'))
    .map(([from, to, status]) => [from, { to, status }])
)

// Is there a page for this address? An optional param ([[x]]) page answers its folder too.
function isRealPage(route) {
  const path = route.replace(/\/\*$/, '').replace(/^\//, '')

  if (path === '') {
    return existsSync(join(ROOT, 'pages', 'index.vue'))
  }

  const base = join(ROOT, 'pages', path)

  return (
    existsSync(base + '.vue') ||
    existsSync(join(base, 'index.vue')) ||
    (existsSync(base) &&
      readdirSync(base).some((name) => /^\[\[.+\]\]\.vue$/.test(name)))
  )
}

describe('legacy address redirects', () => {
  it.each(LEGACY)('%s is answered at the edge with a 301 to %s', (from, to) => {
    const rule = edgeRules[from]

    expect(rule, `${from} has no line in public/_redirects`).toBeTruthy()
    expect(rule.status).toBe('301!')
    // The final address, with the slash Netlify adds to a prerendered page, so there is one hop.
    expect(rule.to.replace(/\/$/, '') || '/').toBe(to)
  })

  it.each(LEGACY.filter(([from]) => !from.startsWith('/mygroups')))(
    '%s is also a routeRule for hosts that do not read _redirects',
    (from, to) => {
      const key = from.endsWith('/*') ? from.replace(/\/\*$/, '/**') : from

      expect(config).toContain(
        `'${key}': { redirect: { to: '${to}', statusCode: 301 } }`
      )
    }
  )

  it.each(LEGACY)('%s is not a page that exists', (from) => {
    expect(isRealPage(from)).toBe(false)
  })

  it.each([...new Set(LEGACY.map(([, to]) => to))])(
    '%s, where they go, is a page that exists',
    (to) => {
      expect(isRealPage(to)).toBe(true)
    }
  )

  it('leaves no forced rewrite (200!) for an old address, which would keep it in the browser', () => {
    const rewrites = Object.entries(edgeRules)
      .filter(([, rule]) => rule.status.startsWith('200'))
      .map(([from]) => from)

    for (const [from] of LEGACY) {
      expect(rewrites).not.toContain(from)
      expect(rewrites).not.toContain(from.replace(/\/\*$/, '*'))
    }
  })
})
