// Guard Leaflet's Tooltip._updatePosition (and related overlay methods) against
// a null _map. Leaflet's overlay classes (Tooltip, Popup, DivOverlay) call
// `this._map.latLngToLayerPoint(...)` inside _updatePosition without checking
// that _map is still bound. During Vue navigation / unmount, debounced or
// rAF-scheduled update ticks can fire after map.remove() has nulled out _map,
// producing "Cannot read properties of null (reading 'latLngToLayerPoint')"
// (Sentry NUXT3-D7B, duplicate 7375663927). Rather than hiding the error in
// Sentry alone, we prevent it at the source with a null-check shim.
import { defineNuxtPlugin } from '#app'

const GUARD_MARKER = '__freegleNullMapGuarded'

function wrapWithMapGuard(proto, methodName) {
  if (!proto || typeof proto[methodName] !== 'function') return false
  if (proto[methodName][GUARD_MARKER]) return false

  const original = proto[methodName]
  const guarded = function guardedUpdatePosition() {
    if (!this || !this._map) return
    return original.apply(this, arguments)
  }
  guarded[GUARD_MARKER] = true
  proto[methodName] = guarded
  return true
}

export function applyLeafletNullMapGuards(L) {
  if (!L) return 0
  let patched = 0
  if (wrapWithMapGuard(L.Tooltip?.prototype, '_updatePosition')) patched++
  if (wrapWithMapGuard(L.Popup?.prototype, '_updatePosition')) patched++
  if (wrapWithMapGuard(L.DivOverlay?.prototype, '_updatePosition')) patched++
  return patched
}

export default defineNuxtPlugin(async () => {
  try {
    const leaflet = await import('leaflet/dist/leaflet-src.esm')
    applyLeafletNullMapGuards(leaflet.default || leaflet)
  } catch (e) {
    console.log('leafletTooltipGuard: failed to apply guards', e)
  }
})
