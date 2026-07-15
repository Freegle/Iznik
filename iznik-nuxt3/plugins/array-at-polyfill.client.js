// Array/String.prototype.at() polyfill for old Android WebViews.
//
// Chrome < 92 (e.g. the Android System WebView on some older devices) lacks the
// ES2022 .at() method. A bundled dependency calls `<route>.matched.at(...)`
// during route matching, which throws "matched.at is not a function" and breaks
// navigation for those users - seen in Sentry from url https://localhost/ (the
// Capacitor app) on Chrome/90 WebViews. Define a spec-correct .at() when it is
// missing; a no-op on every modern browser. Runs early, client-only - mirrors
// mutation-observer-polyfill.client.js.

export default defineNuxtPlugin(() => {
  if (!process.client) return

  function at(n) {
    const len = this.length
    n = Math.trunc(n) || 0
    if (n < 0) n += len
    if (n < 0 || n >= len) return undefined
    return this[n]
  }

  const protos = [Array.prototype, String.prototype]
  // %TypedArray%.prototype is shared by Int8Array etc., so typed arrays get .at too.
  const typedArrayProto =
    typeof Int8Array === 'function'
      ? Object.getPrototypeOf(Int8Array.prototype)
      : null
  if (typedArrayProto) protos.push(typedArrayProto)

  for (const proto of protos) {
    if (proto && typeof proto.at !== 'function') {
      Object.defineProperty(proto, 'at', {
        value: at,
        writable: true,
        enumerable: false,
        configurable: true,
      })
    }
  }
})
