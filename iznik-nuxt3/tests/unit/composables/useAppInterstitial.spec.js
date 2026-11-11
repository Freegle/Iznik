import { describe, it, expect, vi, beforeEach } from 'vitest'

const experiment = vi.hoisted(() => ({ arm: null, calls: [] }))
vi.mock('~/composables/useExperiment', () => ({
  useExperiment: (name) => {
    experiment.calls.push(name)
    return experiment.arm
  },
}))

const {
  APP_INTERSTITIAL_EXPERIMENT,
  APP_INTERSTITIAL_GAP,
  appInterstitialOpen,
  appInterstitialPlatform,
  platformOf,
  isBot,
  excludedRoute,
  seenRecently,
  recordSeen,
  considerAppInterstitial,
  resetAppInterstitial,
} = await import('~/composables/useAppInterstitial')

const ANDROID =
  'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36'
const ANDROID_TABLET =
  'Mozilla/5.0 (Linux; Android 14; SM-X900) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36'
const IPHONE =
  'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1'
const IPAD =
  'Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1'
const DESKTOP =
  'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36'
const GOOGLEBOT =
  'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'

function ctx(over = {}) {
  return {
    ua: ANDROID,
    width: 390,
    path: '/browse',
    query: {},
    native: false,
    modtools: false,
    ...over,
  }
}

describe('useAppInterstitial', () => {
  beforeEach(() => {
    localStorage.clear()
    sessionStorage.clear()
    resetAppInterstitial()
    experiment.arm = 'b'
    experiment.calls = []
  })

  describe('platformOf', () => {
    it('knows phones', () => {
      expect(platformOf(ANDROID)).toBe('android')
      expect(platformOf(IPHONE)).toBe('ios')
    })

    it('does not count tablets, desktops or bots as phones', () => {
      expect(platformOf(ANDROID_TABLET)).toBe(null)
      expect(platformOf(IPAD)).toBe(null)
      expect(platformOf(DESKTOP)).toBe(null)
      expect(platformOf('')).toBe(null)
    })
  })

  it('spots crawlers', () => {
    expect(isBot(GOOGLEBOT)).toBe(true)
    expect(isBot('Mozilla/5.0 (compatible; bingbot/2.0)')).toBe(true)
    expect(isBot('Lighthouse')).toBe(true)
    expect(isBot(ANDROID)).toBe(false)
    expect(isBot(IPHONE)).toBe(false)
  })

  describe('excludedRoute', () => {
    it.each([
      '/unsubscribe',
      '/unsubscribe/123',
      '/forgot',
      '/privacy',
      '/terms',
      '/disclaimer',
      '/chats/55',
      '/chats',
      '/give',
      '/give/mobile/photos',
      '/ask/mobile/details',
      '/donate',
      '/donated',
      '/merge',
      '/marketing-optout',
      '/mobile',
      '/modtools/messages',
    ])('leaves %s alone', (path) => {
      expect(excludedRoute(path, {})).toBe(true)
    })

    it.each(['/', '/browse', '/message/123', '/explore', '/myposts', '/stats'])(
      'allows %s',
      (path) => {
        expect(excludedRoute(path, {})).toBe(false)
      }
    )

    it('leaves a login link from an email alone', () => {
      expect(excludedRoute('/browse', { u: '1', k: 'abc' })).toBe(true)
      expect(excludedRoute('/', { k: 'abc' })).toBe(true)
    })
  })

  describe('the 30 day gap', () => {
    it('is remembered for 30 days', () => {
      const now = Date.now()
      expect(seenRecently(now)).toBe(false)
      recordSeen(now)
      expect(seenRecently(now + APP_INTERSTITIAL_GAP - 1000)).toBe(true)
      expect(seenRecently(now + APP_INTERSTITIAL_GAP + 1000)).toBe(false)
    })

    it('falls back to the session when local storage is blocked', () => {
      const real = Object.getOwnPropertyDescriptor(window, 'localStorage')
      Object.defineProperty(window, 'localStorage', {
        configurable: true,
        get() {
          throw new Error('blocked')
        },
      })
      try {
        recordSeen()
        expect(seenRecently()).toBe(true)
        expect(
          sessionStorage.getItem('freegle_app_interstitial_seen')
        ).toBeTruthy()
      } finally {
        if (real) Object.defineProperty(window, 'localStorage', real)
      }
    })

    it('does not throw when all storage is blocked, and still holds for the page view', () => {
      const get = vi
        .spyOn(Storage.prototype, 'getItem')
        .mockImplementation(() => {
          throw new Error('blocked')
        })
      const set = vi
        .spyOn(Storage.prototype, 'setItem')
        .mockImplementation(() => {
          throw new Error('blocked')
        })
      expect(() => recordSeen()).not.toThrow()
      expect(seenRecently()).toBe(true)
      get.mockRestore()
      set.mockRestore()
    })
  })

  describe('considerAppInterstitial', () => {
    it('opens it for the variant on a phone', () => {
      expect(considerAppInterstitial(ctx())).toBe('b')
      expect(experiment.calls).toEqual([APP_INTERSTITIAL_EXPERIMENT])
      expect(appInterstitialOpen.value).toBe(true)
      expect(appInterstitialPlatform.value).toBe('android')
    })

    it('uses the App Store on an iPhone', () => {
      considerAppInterstitial(ctx({ ua: IPHONE }))
      expect(appInterstitialPlatform.value).toBe('ios')
    })

    it('shows control nothing, but still reads the arm and records the visit', () => {
      experiment.arm = 'control'
      expect(considerAppInterstitial(ctx())).toBe('control')
      expect(appInterstitialOpen.value).toBe(false)
      expect(experiment.calls).toHaveLength(1)
      expect(seenRecently()).toBe(true)
    })

    it('treats no experiment running as control', () => {
      experiment.arm = null
      considerAppInterstitial(ctx())
      expect(appInterstitialOpen.value).toBe(false)
    })

    it('considers a page view once', () => {
      considerAppInterstitial(ctx())
      localStorage.clear()
      resetAppInterstitial({ keepConsidered: true })
      expect(considerAppInterstitial(ctx())).toBe(null)
      expect(experiment.calls).toHaveLength(1)
    })

    it.each([
      ['a tablet', { ua: ANDROID_TABLET }],
      ['an iPad', { ua: IPAD }],
      ['a desktop', { ua: DESKTOP, width: 1400 }],
      ['a wide window on a phone', { width: 900 }],
      ['the app', { native: true }],
      ['ModTools', { modtools: true }],
      ['a crawler', { ua: GOOGLEBOT }],
      ['an unsubscribe page', { path: '/unsubscribe/5' }],
      ['an email login link', { query: { u: '1', k: 'x' } }],
    ])('never reads the arm on %s', (_name, over) => {
      expect(considerAppInterstitial(ctx(over))).toBe(null)
      expect(experiment.calls).toHaveLength(0)
      expect(appInterstitialOpen.value).toBe(false)
    })

    it('does not read the arm again within 30 days', () => {
      recordSeen()
      expect(considerAppInterstitial(ctx())).toBe(null)
      expect(experiment.calls).toHaveLength(0)
    })
  })
})
