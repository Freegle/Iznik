import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, it, expect } from 'vitest'

// Addresses from the old site that members and search engines still follow. Each was a 404 in
// Sentry; a redirect rule in nuxt.config.ts answers it.
const config = readFileSync(
  join(__dirname, '..', '..', '..', 'nuxt.config.ts'),
  'utf8'
)

describe('legacy address redirects', () => {
  it.each([
    ['/groups', '/explore'],
    ['/groups/**', '/explore'],
    ['/mygroups', '/browse'],
    ['/mygroups/**', '/browse'],
    ['/search', '/browse'],
    ['/ask/search', '/browse'],
    ['/offer', '/give'],
    ['/main.php', '/'],
    ['/m.php', '/'],
    ['/index.php', '/'],
  ])('%s goes to %s with a 301', (from, to) => {
    expect(config).toContain(
      `'${from}': { redirect: { to: '${to}', statusCode: 301 } }`
    )
  })
})
