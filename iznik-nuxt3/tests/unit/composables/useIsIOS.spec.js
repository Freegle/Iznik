import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import { isIOS } from '~/composables/useIsIOS'

function setNavigator(props) {
  for (const [key, value] of Object.entries(props)) {
    Object.defineProperty(window.navigator, key, {
      value,
      configurable: true,
    })
  }
}

describe('useIsIOS', () => {
  let originalUserAgent
  let originalPlatform
  let originalMaxTouchPoints

  beforeEach(() => {
    process.client = true
    originalUserAgent = navigator.userAgent
    originalPlatform = navigator.platform
    originalMaxTouchPoints = navigator.maxTouchPoints
  })

  afterEach(() => {
    setNavigator({
      userAgent: originalUserAgent,
      platform: originalPlatform,
      maxTouchPoints: originalMaxTouchPoints,
    })
    delete process.client
  })

  it('detects iPhone', () => {
    setNavigator({
      userAgent:
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15',
      platform: 'iPhone',
      maxTouchPoints: 5,
    })
    expect(isIOS()).toBe(true)
  })

  it('detects iPad reporting as iPad', () => {
    setNavigator({
      userAgent:
        'Mozilla/5.0 (iPad; CPU OS 16_6 like Mac OS X) AppleWebKit/605.1.15',
      platform: 'iPad',
      maxTouchPoints: 5,
    })
    expect(isIOS()).toBe(true)
  })

  it('detects iPadOS masquerading as MacIntel via touch points', () => {
    setNavigator({
      userAgent:
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15',
      platform: 'MacIntel',
      maxTouchPoints: 5,
    })
    expect(isIOS()).toBe(true)
  })

  it('does not flag desktop Mac Safari', () => {
    setNavigator({
      userAgent:
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15',
      platform: 'MacIntel',
      maxTouchPoints: 0,
    })
    expect(isIOS()).toBe(false)
  })

  it('does not flag Android', () => {
    setNavigator({
      userAgent:
        'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/126.0 Mobile',
      platform: 'Linux armv81',
      maxTouchPoints: 5,
    })
    expect(isIOS()).toBe(false)
  })
})
