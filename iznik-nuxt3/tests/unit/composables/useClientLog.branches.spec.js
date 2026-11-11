/**
 * Extra branch coverage for composables/useClientLog.js: DOM event listeners, error
 * guards and small state helpers that the main spec does not reach.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

const capacitor = vi.hoisted(() => ({ platform: 'web', throws: false }))
vi.mock('@capacitor/core', () => ({
  Capacitor: {
    getPlatform: () => {
      if (capacitor.throws) throw new Error('no capacitor')
      return capacitor.platform
    },
  },
}))

describe('useClientLog branches', () => {
  let mod

  function sent() {
    mod.flush(true)
    return JSON.parse(global.fetch.mock.calls.at(-1)[1].body).logs
  }

  function setSize(w, h) {
    Object.defineProperty(window, 'innerWidth', {
      value: w,
      configurable: true,
      writable: true,
    })
    Object.defineProperty(window, 'innerHeight', {
      value: h,
      configurable: true,
      writable: true,
    })
  }

  beforeEach(async () => {
    vi.useFakeTimers()
    vi.resetModules()
    capacitor.platform = 'web'
    capacitor.throws = false
    global.fetch = vi.fn().mockResolvedValue({ ok: true })
    // Re-registers the module's window/document listeners on the fresh module.
    mod = await import('~/composables/useClientLog')
    vi.stubGlobal('useRuntimeConfig', () => ({
      public: { APIv2: 'http://api', BUILD_DATE: '2026-01-01' },
    }))
    mod.useClientLog()
    setSize(1024, 768)
  })

  afterEach(() => {
    vi.runAllTimers()
    vi.useRealTimers()
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
    document.body.innerHTML = ''
  })

  describe('setAppVersion', () => {
    it.each([
      ['1.2.3', '1.2.3'],
      ['', null],
      [undefined, null],
      [null, null],
    ])('setAppVersion(%j) gives app_version %j', (v, expected) => {
      mod.setAppVersion(v)
      mod.sessionStart()
      expect(sent()[0].app_version).toBe(expected)
    })

    it('session start carries build date from runtime config', () => {
      mod.sessionStart({ extra: 1 })
      const e = sent()[0]
      expect(e.build_date).toBe('2026-01-01')
      expect(e.extra).toBe(1)
    })
  })

  describe('platform guards', () => {
    it('falls back to the viewport when Capacitor throws', () => {
      capacitor.throws = true
      setSize(1400, 800)
      expect(mod.getDeviceClass()).toBe('desktop')
      mod.info('x')
      expect(sent()[0].is_app).toBe(false)
    })

    it('treats an unknown Capacitor platform as the web', () => {
      capacitor.platform = 'electron'
      setSize(1400, 800)
      expect(mod.getDeviceClass()).toBe('desktop')
    })
  })

  describe('route name provider', () => {
    it('stringifies a symbol-like route name', () => {
      mod.setRouteNameProvider(() => 12)
      mod.info('x')
      expect(sent()[0].page_route).toBe('12')
    })

    it('is null when the provider returns an empty value', () => {
      mod.setRouteNameProvider(() => '')
      mod.info('x')
      expect(sent()[0].page_route).toBeNull()
    })
  })

  describe('api request timing window', () => {
    it('drops request starts older than a minute', () => {
      const t0 = Date.now()
      mod.noteApiRequestStarted()
      vi.advanceTimersByTime(61000)
      mod.noteApiRequestStarted()
      expect(mod.apiRequestStartedBetween(t0, t0 + 1000)).toBe(false)
      expect(mod.apiRequestStartedBetween(Date.now() - 10, Date.now())).toBe(
        true
      )
      expect(mod.getApiRequestCount()).toBe(2)
    })
  })

  describe('click component', () => {
    it('expires after the max age and stores null for a missing component', () => {
      mod.noteClickComponent(undefined)
      expect(mod.getRecentClickComponent(1000)).toBeNull()
      mod.noteClickComponent('Nav')
      vi.advanceTimersByTime(2000)
      expect(mod.getRecentClickComponent(1000)).toBeNull()
    })
  })

  describe('session sampling stored values', () => {
    it.each([
      ['1', true],
      ['0', false],
    ])('reuses a stored "%s"', (stored, expected) => {
      sessionStorage.setItem('freegle_ux_sampled', stored)
      expect(mod.sessionSampled()).toBe(expected)
      sessionStorage.removeItem('freegle_ux_sampled')
    })

    it('ignores a garbage stored value and decides afresh', () => {
      sessionStorage.setItem('freegle_ux_sampled', 'banana')
      vi.spyOn(Math, 'random').mockReturnValue(0.01)
      expect(mod.sessionSampled()).toBe(true)
      expect(sessionStorage.getItem('freegle_ux_sampled')).toBe('1')
      sessionStorage.removeItem('freegle_ux_sampled')
    })
  })

  describe('auth store helpers', () => {
    it('isSignedIn is false without a store and true with a user', () => {
      expect(mod.isSignedIn()).toBe(false)
      mod.setAuthStore({ user: { id: 5 }, loginStateKnown: true })
      expect(mod.isSignedIn()).toBe(true)
    })

    it('survives a store whose getters throw', () => {
      mod.setAuthStore({
        get user() {
          throw new Error('x')
        },
        get loginStateKnown() {
          throw new Error('y')
        },
      })
      expect(mod.isSignedIn()).toBe(false)
      mod.info('x')
      mod.flush()
      // Not ready, so it retries rather than sending.
      expect(global.fetch).not.toHaveBeenCalled()
      mod.flush(true)
      expect(global.fetch).toHaveBeenCalled()
    })

    it('does not overwrite an existing user_id when flushing', () => {
      mod.setAuthStore({ user: { id: 9 }, loginStateKnown: true })
      mod.info('x', { user_id: 3 })
      expect(sent()[0].user_id).toBe(3)
    })
  })

  describe('sendLogs guards', () => {
    it('swallows a synchronous fetch failure', () => {
      global.fetch = vi.fn(() => {
        throw new Error('boom')
      })
      mod.info('x')
      expect(() => mod.flush(true)).not.toThrow()
    })

    it('swallows a rejected fetch', async () => {
      global.fetch = vi.fn().mockRejectedValue(new Error('net'))
      mod.info('x')
      mod.flush(true)
      await Promise.resolve()
    })
  })

  describe('page load phase', () => {
    it('loading clears a pending idle timer', () => {
      mod.setPageLoadPhase('loading')
      mod.markPageInteractive()
      mod.setPageLoadPhase('loading')
      vi.advanceTimersByTime(5000)
      expect(mod.getPageLoadPhase()).toBe('loading')
    })

    it('markUserAction replaces an existing idle timer', () => {
      mod.setPageLoadPhase('loading')
      mod.markPageInteractive()
      mod.setPageLoadPhase('idle')
      mod.markUserAction()
      expect(mod.getPageLoadPhase()).toBe('user_action')
      vi.advanceTimersByTime(600)
      expect(mod.getPageLoadPhase()).toBe('idle')
    })

    it('apiRequest records ms since page load and logs errors at error level', () => {
      mod.setPageLoadPhase('loading')
      vi.advanceTimersByTime(100)
      mod.apiRequest('GET', '/x', 5, 500)
      const e = sent()[0]
      expect(e.level).toBe('error')
      expect(e.ms_since_page_load).toBe(100)
      expect(e.page_load_phase).toBe('loading')
    })
  })

  describe('pageView logging context', () => {
    it('tolerates a provider that throws', () => {
      mod.setLoggingContextProvider(() => {
        throw new Error('x')
      })
      mod.pageView('home')
      expect(sent()[0].page_name).toBe('home')
    })

    it('tolerates a provider returning null', () => {
      mod.setLoggingContextProvider(() => null)
      mod.pageView('home')
      expect(sent()[0].page_id).toBeUndefined()
    })
  })

  describe('window and document listeners', () => {
    it('flushes on beforeunload without waiting for auth', () => {
      mod.info('x')
      window.dispatchEvent(new Event('beforeunload'))
      expect(global.fetch).toHaveBeenCalledTimes(1)
    })

    it.each([
      ['hidden', 1],
      ['visible', 0],
    ])('visibilitychange to %s flushes %i times', (state, calls) => {
      vi.spyOn(document, 'visibilityState', 'get').mockReturnValue(state)
      mod.info('x')
      document.dispatchEvent(new Event('visibilitychange'))
      expect(global.fetch).toHaveBeenCalledTimes(calls)
    })

    it('logs a first resize, ignores small changes and logs large ones', () => {
      setSize(1000, 700)
      window.dispatchEvent(new Event('resize'))
      vi.advanceTimersByTime(1100)
      setSize(1030, 720)
      window.dispatchEvent(new Event('resize'))
      vi.advanceTimersByTime(1100)
      setSize(500, 700)
      window.dispatchEvent(new Event('resize'))
      window.dispatchEvent(new Event('resize'))
      vi.advanceTimersByTime(1100)
      const logs = sent().filter((l) => l.event_type === 'viewport_resize')
      expect(logs).toHaveLength(2)
      expect(logs[0].previous_width).toBeNull()
      expect(logs[1].previous_width).toBe(1000)
      expect(logs[1].viewport_width).toBe(500)
    })

    it('logs a large change in height alone', () => {
      setSize(1000, 700)
      window.dispatchEvent(new Event('resize'))
      vi.advanceTimersByTime(1100)
      setSize(1000, 300)
      window.dispatchEvent(new Event('resize'))
      vi.advanceTimersByTime(1100)
      expect(
        sent().filter((l) => l.event_type === 'viewport_resize')
      ).toHaveLength(2)
    })

    it('logs an orientation change', () => {
      // jsdom has no screen.orientation, so the module uses the window fallback.
      window.dispatchEvent(new Event('orientationchange'))
      const e = sent().find((l) => l.event_type === 'orientation_change')
      expect(e).toBeTruthy()
      expect(e.orientation).toBe('unknown')
    })
  })

  describe('modal events', () => {
    function modal(html) {
      document.body.innerHTML = html
      return document.body.firstElementChild
    }

    function fire(type, el) {
      el.dispatchEvent(new Event(type, { bubbles: true }))
    }

    it.each([
      ['an id', '<div id="m1"></div>', 'm1'],
      [
        'aria-labelledby',
        '<div aria-labelledby="t"><h5 id="t">  Hello title  </h5></div>',
        'Hello title',
      ],
      [
        'a .modal-title',
        '<div><span class="modal-title"> Inner </span></div>',
        'Inner',
      ],
      ['nothing usable', '<div aria-labelledby="gone"></div>', 'unknown'],
      [
        'a long title',
        `<div><span class="modal-title">${'x'.repeat(80)}</span></div>`,
        'x'.repeat(50),
      ],
    ])('identifies a modal by %s', (_n, html, expected) => {
      const el = modal(html)
      fire('show.bs.modal', el)
      fire('hidden.bs.modal', el)
      const logs = sent()
      expect(logs[0]).toMatchObject({
        event_type: 'modal_open',
        modal_id: expected,
      })
      expect(logs[1]).toMatchObject({
        event_type: 'modal_close',
        modal_id: expected,
      })
    })
  })
})
