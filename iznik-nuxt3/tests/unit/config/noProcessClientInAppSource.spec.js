import { describe, it, expect } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'

/**
 * Nuxt 3's vite builder defined process.client/process.server; Nuxt 4's defines
 * neither, so a surviving guard reads undefined and its "client-only" code
 * silently never runs. The Nuxt 4 upgrade converted about a hundred of these
 * and missed two, which would have shipped the app's Android back button and
 * iOS detection quietly dead. The eslint rule nuxt/prefer-import-meta errors on
 * the pattern, but eslint only gates changed files at commit time via a local
 * hook; this spec makes the whole suite fail wherever it runs, CI included.
 *
 * Unit tests can never catch the runtime symptom themselves: the vitest
 * transform substitutes import.meta.client to a literal, so the only reliable
 * ratchet is asserting the pattern is absent from the source.
 */

const APP_DIRS = [
  'components',
  'composables',
  'stores',
  'pages',
  'plugins',
  'layouts',
  'utils',
  'api',
  'server',
  'modtools/components',
  'modtools/composables',
  'modtools/stores',
  'modtools/pages',
  'modtools/plugins',
  'modtools/layouts',
]

const SOURCE_EXT = /\.(vue|js|mjs|ts)$/

function* walk(dir) {
  for (const entry of readdirSync(dir)) {
    if (entry === 'node_modules' || entry.startsWith('.')) continue
    const p = join(dir, entry)
    if (statSync(p).isDirectory()) yield* walk(p)
    else if (SOURCE_EXT.test(entry)) yield p
  }
}

describe('app source does not read the removed process.client/server flags', () => {
  it('no app source file mentions process.client or process.server', () => {
    const offenders = []
    for (const dir of APP_DIRS) {
      const abs = join(process.cwd(), dir)
      let entries
      try {
        entries = [...walk(abs)]
      } catch {
        continue // directory absent in this layout
      }
      for (const file of entries) {
        const src = readFileSync(file, 'utf8')
        if (/process\.(client|server)\b/.test(src)) {
          offenders.push(file.replace(process.cwd() + '/', ''))
        }
      }
    }
    expect(
      offenders,
      `these files read process.client/server, which Nuxt 4 never defines - use import.meta.client/server: ${offenders.join(', ')}`
    ).toEqual([])
  })
})
