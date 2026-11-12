import { describe, it, expect } from 'vitest'
import {
  googleButtonFailureKind,
  inAppBrowser,
} from '~/composables/useGoogleButtonReport'

describe('googleButtonFailureKind', () => {
  it.each([
    [{ scriptLoaded: true, containerPresent: false }, 'container_missing'],
    [{ scriptLoaded: false, containerPresent: false }, 'container_missing'],
    [{ scriptLoaded: false, containerPresent: true }, 'script_not_loaded'],
    [{ scriptLoaded: true, containerPresent: true }, 'not_drawn'],
  ])('%j is %s', (facts, kind) => {
    expect(googleButtonFailureKind(facts)).toBe(kind)
  })
})

describe('inAppBrowser', () => {
  it('spots the Google app, Facebook and an Android WebView', () => {
    expect(inAppBrowser('Mozilla/5.0 (iPhone) GSA/446.3.1011462875')).toBe(true)
    expect(inAppBrowser('Mozilla/5.0 [FBAN/FBIOS;FBAV/450.0]')).toBe(true)
    expect(
      inAppBrowser('Mozilla/5.0 (Linux; Android 13; SM-A346B; wv) Chrome')
    ).toBe(true)
  })

  it('does not take an ordinary browser for one', () => {
    expect(
      inAppBrowser('Mozilla/5.0 (Windows NT 10.0) Chrome/157.0 Safari/537.36')
    ).toBe(false)
    expect(inAppBrowser(undefined)).toBe(false)
  })
})
