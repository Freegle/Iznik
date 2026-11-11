import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

const {
  HOSTING_AD_EXPERIMENT,
  HOSTING_AD_LINK,
  HOSTING_AD_ASK_GAP,
  hostingAdOpen,
  askedRecently,
  recordAsked,
  anotherModalOpen,
  whenNoOtherModal,
  considerHostingAd,
  closeHostingAd,
  resetHostingAd,
} = await import('~/composables/useHostingAd')

function ctx(over = {}) {
  return {
    loggedIn: true,
    path: '/browse',
    query: {},
    native: false,
    modtools: false,
    fetchHostingAd: vi.fn().mockResolvedValue(true),
    ...over,
  }
}

describe('useHostingAd', () => {
  beforeEach(() => {
    localStorage.clear()
    sessionStorage.clear()
    document.body.classList.remove('modal-open')
    resetHostingAd()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('names the experiment and the shortlink', () => {
    expect(HOSTING_AD_EXPERIMENT).toBe('krystal-hosting-ad')
    expect(HOSTING_AD_LINK).toBe('/shortlink/krystalhosting')
  })

  describe('askedRecently and recordAsked', () => {
    it('is false until recorded, then true for the gap', () => {
      expect(askedRecently()).toBe(false)
      recordAsked(1000)
      expect(askedRecently(1000 + HOSTING_AD_ASK_GAP - 1)).toBe(true)
      expect(askedRecently(1000 + HOSTING_AD_ASK_GAP)).toBe(false)
    })

    it('reads a time kept in localStorage from an earlier page view', () => {
      localStorage.setItem('freegle_hosting_ad_asked', String(5000))
      expect(askedRecently(5000 + 10)).toBe(true)
    })

    it('falls back to sessionStorage when localStorage is blocked', () => {
      const original = Object.getOwnPropertyDescriptor(window, 'localStorage')
      Object.defineProperty(window, 'localStorage', {
        configurable: true,
        value: {
          getItem: () => null,
          setItem: () => {
            throw new Error('blocked')
          },
        },
      })
      try {
        recordAsked(7000)
        expect(sessionStorage.getItem('freegle_hosting_ad_asked')).toBe('7000')
        resetHostingAd()
        expect(askedRecently(7000 + 10)).toBe(true)
      } finally {
        Object.defineProperty(window, 'localStorage', original)
      }
    })
  })

  describe('anotherModalOpen and whenNoOtherModal', () => {
    it('reads the body class Bootstrap sets', () => {
      expect(anotherModalOpen()).toBe(false)
      document.body.classList.add('modal-open')
      expect(anotherModalOpen()).toBe(true)
    })

    it('resolves at once when nothing is open', async () => {
      await expect(whenNoOtherModal()).resolves.toBe(true)
    })

    it('waits for the other modal to close', async () => {
      vi.useFakeTimers()
      document.body.classList.add('modal-open')
      const p = whenNoOtherModal({ wait: 5000, step: 100 })
      await vi.advanceTimersByTimeAsync(250)
      document.body.classList.remove('modal-open')
      await vi.advanceTimersByTimeAsync(100)
      await expect(p).resolves.toBe(true)
    })

    it('gives up after the wait', async () => {
      vi.useFakeTimers()
      document.body.classList.add('modal-open')
      const p = whenNoOtherModal({ wait: 1000, step: 100 })
      await vi.advanceTimersByTimeAsync(1100)
      await expect(p).resolves.toBe(false)
    })
  })

  describe('considerHostingAd', () => {
    it('asks the server once and opens the modal when it says so', async () => {
      const c = ctx()
      expect(await considerHostingAd(c)).toBe(true)
      expect(c.fetchHostingAd).toHaveBeenCalledTimes(1)
      expect(hostingAdOpen.value).toBe(true)
      expect(askedRecently()).toBe(true)

      expect(await considerHostingAd(ctx())).toBe(null)
    })

    it('does not open when the server says no, and remembers having asked', async () => {
      const c = ctx({ fetchHostingAd: vi.fn().mockResolvedValue(false) })
      expect(await considerHostingAd(c)).toBe(false)
      expect(hostingAdOpen.value).toBe(false)
      expect(askedRecently()).toBe(true)
    })

    it.each([
      ['signed out', { loggedIn: false }],
      ['in the app', { native: true }],
      ['in ModTools', { modtools: true }],
      ['mid-flow', { path: '/give' }],
      ['on a link from an email', { query: { k: 'abc' } }],
    ])('asks nothing %s', async (_, over) => {
      const c = ctx(over)
      expect(await considerHostingAd(c)).toBe(null)
      expect(c.fetchHostingAd).not.toHaveBeenCalled()
      expect(askedRecently()).toBe(false)
    })

    it('a signed-out arrival does not use up the look, so signing in asks', async () => {
      expect(await considerHostingAd(ctx({ loggedIn: false }))).toBe(null)
      const c = ctx()
      expect(await considerHostingAd(c)).toBe(true)
      expect(c.fetchHostingAd).toHaveBeenCalledTimes(1)
    })

    it('asks nothing when asked in the last day on this browser', async () => {
      recordAsked()
      const c = ctx()
      expect(await considerHostingAd(c)).toBe(null)
      expect(c.fetchHostingAd).not.toHaveBeenCalled()
    })

    it('asks nothing while another modal is open', async () => {
      document.body.classList.add('modal-open')
      const c = ctx()
      expect(await considerHostingAd(c)).toBe(null)
      expect(c.fetchHostingAd).not.toHaveBeenCalled()
      expect(askedRecently()).toBe(false)
    })

    it('treats a failed call as nothing to show', async () => {
      const c = ctx({
        fetchHostingAd: vi.fn().mockRejectedValue(new Error('404')),
      })
      expect(await considerHostingAd(c)).toBe(null)
      expect(hostingAdOpen.value).toBe(false)
    })

    it('waits for a modal that opened during the call, then shows', async () => {
      vi.useFakeTimers()
      let resolve
      const c = ctx({
        fetchHostingAd: vi.fn(
          () =>
            new Promise((r) => {
              resolve = r
            })
        ),
      })
      const p = considerHostingAd(c)
      document.body.classList.add('modal-open')
      resolve(true)
      await vi.advanceTimersByTimeAsync(600)
      expect(hostingAdOpen.value).toBe(false)
      document.body.classList.remove('modal-open')
      await vi.advanceTimersByTimeAsync(600)
      await expect(p).resolves.toBe(true)
      expect(hostingAdOpen.value).toBe(true)
    })
  })

  it('closeHostingAd and resetHostingAd clear the state', async () => {
    await considerHostingAd(ctx())
    expect(hostingAdOpen.value).toBe(true)
    closeHostingAd()
    expect(hostingAdOpen.value).toBe(false)
    resetHostingAd({ keepConsidered: true })
    expect(await considerHostingAd(ctx())).toBe(null)
    resetHostingAd()
    localStorage.clear()
    expect(await considerHostingAd(ctx())).toBe(true)
  })
})
