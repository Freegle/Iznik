import { describe, it, expect, vi } from 'vitest'
import { updateActualReachLayer } from '~/modtools/composables/rippling/actualreach.js'

const POLY = JSON.stringify({
  type: 'Polygon',
  coordinates: [
    [
      [-0.2, 51.4],
      [0, 51.4],
      [0, 51.6],
      [-0.2, 51.4],
    ],
  ],
})

// A stub Leaflet: L.polygon(...).bindTooltip(...).addTo(map) returns a sentinel layer.
function stubLeaflet() {
  const layer = { addTo: vi.fn(() => layer), bindTooltip: vi.fn(() => layer) }
  const L = { polygon: vi.fn(() => layer) }
  return { L, layer }
}

function stubMap(hasExisting = false) {
  return {
    hasLayer: vi.fn(() => hasExisting),
    removeLayer: vi.fn(),
  }
}

describe('updateActualReachLayer', () => {
  it('fills the reach when the projection is suppressed (it is the subject)', () => {
    const { L, layer } = stubLeaflet()
    const map = stubMap()
    const out = updateActualReachLayer(L, map, null, POLY, true)
    expect(out).toBe(layer)
    const [latlngs, style] = L.polygon.mock.calls[0]
    expect(style).toMatchObject({ fill: true, fillColor: '#0055cc' })
    expect(style.dashArray).toBeUndefined()
    // GeoJSON [lng, lat] flipped to Leaflet [lat, lng].
    expect(latlngs[0][0][0]).toEqual([51.4, -0.2])
    expect(layer.bindTooltip).toHaveBeenCalled()
    expect(layer.addTo).toHaveBeenCalledWith(map)
  })

  it('stays a dashed outline alongside a projection (annotation, not subject)', () => {
    const { L } = stubLeaflet()
    updateActualReachLayer(L, stubMap(), null, POLY, false)
    const [, style] = L.polygon.mock.calls[0]
    expect(style).toMatchObject({ dashArray: '6 4', fill: false })
  })

  it('removes the previous layer before drawing the replacement', () => {
    const { L } = stubLeaflet()
    const map = stubMap(true)
    const existing = { existing: true }
    updateActualReachLayer(L, map, existing, POLY, true)
    expect(map.removeLayer).toHaveBeenCalledWith(existing)
  })

  it('removes the previous layer and returns null when the reach clears', () => {
    const { L } = stubLeaflet()
    const map = stubMap(true)
    const existing = { existing: true }
    expect(updateActualReachLayer(L, map, existing, null, true)).toBeNull()
    expect(map.removeLayer).toHaveBeenCalledWith(existing)
    expect(L.polygon).not.toHaveBeenCalled()
  })

  it('draws nothing for unusable GeoJSON or a missing map', () => {
    const { L } = stubLeaflet()
    expect(
      updateActualReachLayer(L, stubMap(), null, 'not json', true)
    ).toBeNull()
    expect(updateActualReachLayer(L, null, null, POLY, true)).toBeNull()
    expect(L.polygon).not.toHaveBeenCalled()
  })
})
