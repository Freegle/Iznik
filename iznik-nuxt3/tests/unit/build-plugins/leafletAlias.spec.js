import { describe, it, expect } from 'vitest'
import { leafletAlias } from '../../../build-plugins/leafletAlias'

// What Vite does with an alias entry whose `find` is a RegExp: replace the matched part.
function resolve(id) {
  return id.replace(leafletAlias.find, leafletAlias.replacement)
}

describe('leafletAlias', () => {
  it('maps a bare leaflet import to the ES module build', () => {
    expect(resolve('leaflet')).toBe('leaflet/dist/leaflet-src.esm.js')
  })

  it.each([
    'leaflet/dist/leaflet.css',
    'leaflet/dist/leaflet-src.esm',
    'leaflet/dist/leaflet-src.esm.js',
    'leaflet-control-geocoder',
    'vue-leaflet',
    '@vue-leaflet/vue-leaflet',
    'wicket/wicket-leaflet',
  ])('leaves %s alone', (id) => {
    expect(resolve(id)).toBe(id)
  })
})
