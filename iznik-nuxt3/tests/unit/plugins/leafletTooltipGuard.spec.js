import { describe, it, expect } from 'vitest'
import { applyLeafletNullMapGuards } from '~/plugins/leafletTooltipGuard.client.js'

function makeLeafletLike() {
  const calls = { tooltip: 0, popup: 0, divoverlay: 0 }
  return {
    calls,
    Tooltip: {
      prototype: {
        _updatePosition() {
          calls.tooltip++
          return this._map.latLngToLayerPoint(this._latlng)
        },
      },
    },
    Popup: {
      prototype: {
        _updatePosition() {
          calls.popup++
          return this._map.latLngToLayerPoint(this._latlng)
        },
      },
    },
    DivOverlay: {
      prototype: {
        _updatePosition() {
          calls.divoverlay++
          return this._map.latLngToLayerPoint(this._latlng)
        },
      },
    },
  }
}

describe('applyLeafletNullMapGuards', () => {
  it('returns early without throwing when _map is null', () => {
    const L = makeLeafletLike()
    expect(applyLeafletNullMapGuards(L)).toBe(3)

    const tooltip = { _map: null, _latlng: [0, 0] }
    expect(() => L.Tooltip.prototype._updatePosition.call(tooltip)).not.toThrow()
    expect(L.calls.tooltip).toBe(0)
  })

  it('returns early without throwing when _map is undefined', () => {
    const L = makeLeafletLike()
    applyLeafletNullMapGuards(L)

    const tooltip = { _latlng: [0, 0] }
    expect(() => L.Tooltip.prototype._updatePosition.call(tooltip)).not.toThrow()
    expect(L.calls.tooltip).toBe(0)
  })

  it('calls the original method when _map is present', () => {
    const L = makeLeafletLike()
    applyLeafletNullMapGuards(L)

    const map = {
      latLngToLayerPoint: (ll) => ({ x: ll[0], y: ll[1] }),
    }
    const tooltip = { _map: map, _latlng: [3, 4] }

    const result = L.Tooltip.prototype._updatePosition.call(tooltip)
    expect(result).toEqual({ x: 3, y: 4 })
    expect(L.calls.tooltip).toBe(1)
  })

  it('guards Popup and DivOverlay prototypes too', () => {
    const L = makeLeafletLike()
    applyLeafletNullMapGuards(L)

    expect(() => L.Popup.prototype._updatePosition.call({ _map: null })).not.toThrow()
    expect(() => L.DivOverlay.prototype._updatePosition.call({ _map: null })).not.toThrow()
    expect(L.calls.popup).toBe(0)
    expect(L.calls.divoverlay).toBe(0)
  })

  it('is idempotent — applying twice does not double-wrap', () => {
    const L = makeLeafletLike()
    expect(applyLeafletNullMapGuards(L)).toBe(3)
    expect(applyLeafletNullMapGuards(L)).toBe(0)

    const map = { latLngToLayerPoint: () => ({ x: 0, y: 0 }) }
    L.Tooltip.prototype._updatePosition.call({ _map: map, _latlng: [0, 0] })
    expect(L.calls.tooltip).toBe(1)
  })

  it('handles missing prototypes gracefully', () => {
    expect(applyLeafletNullMapGuards(null)).toBe(0)
    expect(applyLeafletNullMapGuards({})).toBe(0)
    expect(applyLeafletNullMapGuards({ Tooltip: {} })).toBe(0)
    expect(applyLeafletNullMapGuards({ Tooltip: { prototype: {} } })).toBe(0)
  })

  it('returns early when called with null `this` (e.g. detached tooltip)', () => {
    const L = makeLeafletLike()
    applyLeafletNullMapGuards(L)

    expect(() => L.Tooltip.prototype._updatePosition.call(null)).not.toThrow()
    expect(L.calls.tooltip).toBe(0)
  })
})
