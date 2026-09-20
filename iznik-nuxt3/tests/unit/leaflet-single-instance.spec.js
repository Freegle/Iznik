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
const BARE = /(?:from\s*|import\s*\(\s*|require\s*\(\s*)['"]leaflet['"]/

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

describe('single Leaflet instance', () => {
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
})
