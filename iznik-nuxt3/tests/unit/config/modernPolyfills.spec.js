import { describe, it, expect } from 'vitest'
import { readFileSync } from 'fs'
import { resolve } from 'path'

/**
 * Verifies that critical polyfills are listed in nuxt.config.ts modernPolyfills.
 *
 * These polyfills are injected into the "modern" Vite bundle on Netlify so
 * that browsers which support ES modules (and therefore receive the modern
 * chunk) but are missing newer APIs still work correctly.
 *
 * If a Sentry error reports "<method> is not a function" on a browser that
 * predates that method's introduction, add the corresponding core-js feature
 * flag here AND in the modernPolyfills array in nuxt.config.ts.
 */
describe('nuxt.config.ts modernPolyfills', () => {
  const configPath = resolve(__dirname, '../../../nuxt.config.ts')
  const config = readFileSync(configPath, 'utf-8')

  // Helper: true if the feature string appears inside the modernPolyfills array.
  function hasPolyfill(feature) {
    return config.includes(`'${feature}'`) || config.includes(`"${feature}"`)
  }

  it('polyfills es.array.at for Chrome <92 / WebView <92', () => {
    // Array.prototype.at() — Chrome 92, Safari 15.4, Android WebView 92.
    // Sentry issue 7493124034: Chrome WebView 90 on Android 11.
    expect(hasPolyfill('es.array.at')).toBe(true)
  })

  it('polyfills es.global-this', () => {
    expect(hasPolyfill('es.global-this')).toBe(true)
  })

  it('polyfills es.string.replace-all', () => {
    expect(hasPolyfill('es.string.replace-all')).toBe(true)
  })
})
