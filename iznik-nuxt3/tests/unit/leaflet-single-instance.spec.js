import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'
import { describe, it, expect } from 'vitest'

// The app runs ONE Leaflet: plugins/vue-leaflet.client.js and composables/useMap.js pin
// window.L to 'leaflet/dist/leaflet-src.esm' before any vue-leaflet map is created.
// Leaflet's package entry ('leaflet' bare) is the UMD build, which assigns window.L to
// ITSELF when it loads. One bare import anywhere therefore swaps every later map onto a
// second instance, and a LatLngBounds built with the first instance fails Leaflet's
// instanceof check: fitBounds() throws "Bounds are not valid." and the page falls over
// to the error view. Seen on ModTools when the rippling explorer loaded (September 2026).
const ROOT = join(__dirname, '..', '..')
const SCAN = [
  'components',
  'composables',
  'layouts',
  'modtools',
  'pages',
  'plugins',
  'stores',
]
const SKIP = new Set(['node_modules', '.nuxt', '.output', 'dist', 'tests'])
// Includes the side-effect form, `import 'leaflet'`, which has no `from`.
const BARE =
  /(?:from\s*|import\s*\(\s*|require\s*\(\s*|^\s*import\s+)['"]leaflet['"]/m

// Old-style scripts that read the global L while they load. window.L is only set by
// loadLeaflet() and the vue-leaflet plugin, both at run time, so one of these as a static import
// runs before either and throws "L is not defined" ("Can't find variable: L" in Safari), which
// takes the whole page to the error view. That broke the ModTools map in November 2026.
const NEEDS_GLOBAL_L =
  /['"](@geoman-io\/leaflet-geoman-free|wicket\/wicket-leaflet|leaflet-draw|leaflet-gesture-handling)['"]/
const STATIC_IMPORT = new RegExp(
  '^\\s*import\\s+(?:[^\'"()]*\\sfrom\\s*)?' + NEEDS_GLOBAL_L.source,
  'm'
)
const DYNAMIC_IMPORT = new RegExp(
  'import\\s*\\(\\s*' + NEEDS_GLOBAL_L.source + '\\s*\\)'
)

function sources(dir) {
  const out = []
  for (const name of readdirSync(dir)) {
    if (SKIP.has(name)) continue
    const full = join(dir, name)
    if (statSync(full).isDirectory()) {
      out.push(...sources(full))
    } else if (/\.(vue|js|ts|mjs)$/.test(name)) {
      out.push(full)
    }
  }
  return out
}

// A library in node_modules can import the bare package too: leaflet-control-geocoder does, which
// is how a second copy reached the bundle in August 2026 (Sentry NUXT3-DS1 and the rest of that
// family). The app source scan below cannot see it, so the build maps the bare specifier itself.
describe('single Leaflet instance', () => {
  it('the build sends bare leaflet imports from libraries to the ES module build', () => {
    // nuxt.config.ts is too heavy to load in a unit test, so check it installs the alias module
    // in vite.resolve.alias; the alias itself is exercised in build-plugins/leafletAlias.spec.js.
    const config = readFileSync(join(ROOT, 'nuxt.config.ts'), 'utf8')
    expect(config).toMatch(/resolve:\s*\{[^}]*alias:\s*\[leafletAlias\]/)
    expect(config).not.toContain('leafletSingleInstance')
  })

  it('no app source imports the bare leaflet package', () => {
    const offenders = []
    for (const dir of SCAN) {
      for (const file of sources(join(ROOT, dir))) {
        if (BARE.test(readFileSync(file, 'utf8'))) {
          offenders.push(relative(ROOT, file))
        }
      }
    }
    expect(offenders).toEqual([])
  })

  it('the bare-import check sees every form of the import', () => {
    expect(BARE.test("import L from 'leaflet'")).toBe(true)
    expect(BARE.test("const L = await import('leaflet')")).toBe(true)
    expect(
      BARE.test("import { nextTick } from 'vue'\n\nimport 'leaflet'\n")
    ).toBe(true)
    expect(BARE.test("import 'leaflet/dist/leaflet.css'")).toBe(false)
    expect(BARE.test("import('leaflet/dist/leaflet-src.esm')")).toBe(false)
  })

  it('no app source statically imports a script that reads the global L as it loads', () => {
    expect(STATIC_IMPORT.test("import '@geoman-io/leaflet-geoman-free'")).toBe(
      true
    )
    expect(
      STATIC_IMPORT.test(
        "import '@geoman-io/leaflet-geoman-free/dist/leaflet-geoman.css'"
      )
    ).toBe(false)
    expect(
      STATIC_IMPORT.test("  await import('@geoman-io/leaflet-geoman-free')")
    ).toBe(false)

    const offenders = []
    for (const dir of SCAN) {
      for (const file of sources(join(ROOT, dir))) {
        if (STATIC_IMPORT.test(readFileSync(file, 'utf8'))) {
          offenders.push(relative(ROOT, file))
        }
      }
    }
    expect(offenders).toEqual([])
  })

  it('a script that reads the global L is only loaded after the code that sets it', () => {
    // loadLeaflet() itself is where window.L is assigned, so its own imports are in order by
    // construction; every other file has to call it first.
    const offenders = []
    for (const dir of SCAN) {
      for (const file of sources(join(ROOT, dir))) {
        const name = relative(ROOT, file)
        if (name === join('composables', 'useMap.js')) continue
        const text = readFileSync(file, 'utf8')
        const load = text.match(DYNAMIC_IMPORT)
        if (!load) continue
        const pinned = text.indexOf('await loadLeaflet()')
        if (pinned === -1 || pinned > load.index) {
          offenders.push(name)
        }
      }
    }
    expect(offenders).toEqual([])
  })
})
