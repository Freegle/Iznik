import { describe, it, expect } from 'vitest'
import { readFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'

/**
 * The patch-package patches carry crash guards that production depends on.
 * Their failure mode is silent: a version bump regenerates the patch, a guard
 * gets lost in the rewrite, and nothing notices until Sentry does. That is not
 * hypothetical - the runtime-dom parentNode guard (added 2023 for crashes on
 * /browse) was dropped in an April 2024 patch rewrite and the same crash was
 * live on production /browse again in August 2026.
 *
 * These tests grep the INSTALLED dist files, so they prove the patches both
 * still exist and actually applied in the environment running the suite.
 */

const nm = (...p) => join(process.cwd(), 'node_modules', ...p)
const read = (...p) => readFileSync(nm(...p), 'utf8')

const RUNTIME_DOM_BUILDS = [
  'runtime-dom.cjs.js',
  'runtime-dom.cjs.prod.js',
  'runtime-dom.esm-browser.js',
  'runtime-dom.esm-bundler.js',
  'runtime-dom.global.js',
]

describe('patch-package guards are applied', () => {
  it('runtime-dom: nodeOps.parentNode tolerates a null node in every build', () => {
    for (const build of RUNTIME_DOM_BUILDS) {
      const src = read('@vue/runtime-dom/dist', build)
      expect(
        src.includes('parentNode: (node) => (node ? node.parentNode : null)'),
        `${build} is missing the parentNode null-guard`
      ).toBe(true)
      expect(
        src.includes('parentNode: (node) => node.parentNode,'),
        `${build} still contains the unguarded parentNode`
      ).toBe(false)
    }
  })

  it('runtime-dom: nodeOps.remove tolerates a null child in every build', () => {
    for (const build of RUNTIME_DOM_BUILDS) {
      const src = read('@vue/runtime-dom/dist', build)
      expect(
        /remove: \(child\) => \{\s*\n\s*if \(child\) \{/.test(src),
        `${build} is missing the remove null-guard`
      ).toBe(true)
    }
  })

  it('runtime-core: unmountComponent tolerates a null instance', () => {
    const src = read('@vue/runtime-core/dist/runtime-core.esm-bundler.js')
    const at = src.indexOf(
      'const unmountComponent = (instance, parentSuspense, doRemove) => {'
    )
    expect(
      at,
      'unmountComponent not found - vue internals moved'
    ).toBeGreaterThan(0)
    expect(
      src.slice(at, at + 200).includes('if (!instance) {'),
      'unmountComponent is missing the null-instance guard'
    ).toBe(true)
  })

  it('vue-leaflet: moveend and MutationObserver teardown guards are applied', () => {
    // The debounced moveend handler fires after map.remove() (leafletRef is
    // never nulled), and LIcon's observer can attach to a null ref when the
    // async setup resolves after unmount. Both guarded via the patch; the es
    // build is what vite serves, the cjs build is what nitro requires.
    for (const build of ['vue-leaflet.es.js', 'vue-leaflet.cjs.js']) {
      const src = read('@vue-leaflet/vue-leaflet/dist', build)
      const moveendAt = src.indexOf('moveend:')
      expect(moveendAt, `${build}: moveend handler not found`).toBeGreaterThan(
        0
      )
      expect(
        src.slice(moveendAt, moveendAt + 80).includes('try'),
        `${build}: moveend handler is missing its try/catch teardown guard`
      ).toBe(true)
      expect(
        /\.value\s?&&\s?new MutationObserver/.test(src),
        `${build}: MutationObserver.observe is missing its null-ref guard`
      ).toBe(true)
    }
  })

  it('every patch file matches the installed version of its package', () => {
    // patch-package silently skips a patch whose version does not match what is
    // installed, which un-applies every guard above on the next careless bump.
    for (const f of readdirSync(join(process.cwd(), 'patches'))) {
      const m = f.match(/^(.+)\+(\d[^+]*)\.patch$/)
      expect(m, `unparseable patch filename ${f}`).toBeTruthy()
      const pkg = m[1].replace(/\+/g, '/').replace(/^@/, '@')
      const version = JSON.parse(read(pkg, 'package.json')).version
      expect(version, `${f} does not match installed ${pkg}@${version}`).toBe(
        m[2]
      )
    }
  })
})
