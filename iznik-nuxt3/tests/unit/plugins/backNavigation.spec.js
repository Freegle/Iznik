import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

const mocks = vi.hoisted(() => ({ action: vi.fn() }))

vi.mock('~/composables/useClientLog', () => ({ action: mocks.action }))

const KEY = 'freegle_nav_history'

describe('back-navigation plugin', () => {
  let router
  let afterEachCb
  let popstateHandler
  let addSpy

  const setReferrer = (value) =>
    Object.defineProperty(document, 'referrer', {
      value,
      configurable: true,
    })

  const readHistory = () => JSON.parse(sessionStorage.getItem(KEY))

  // Load the plugin at the given current path with the given referrer.
  async function load({
    path = '/browse',
    referrer = '',
    history,
    preseed,
  } = {}) {
    if (preseed) sessionStorage.setItem(KEY, JSON.stringify(preseed))
    window.history.replaceState({}, '', path)
    setReferrer(referrer)

    router = {
      afterEach: vi.fn((cb) => {
        afterEachCb = cb
      }),
      replace: vi.fn(),
    }
    vi.stubGlobal('useRouter', () => router)
    vi.stubGlobal('defineNuxtPlugin', (fn) => fn)

    addSpy = vi.spyOn(window, 'addEventListener')
    const mod = await import('~/plugins/back-navigation.client.js')
    mod.default()
    popstateHandler = addSpy.mock.calls.find((c) => c[0] === 'popstate')[1]
    // Seed after init: an external landing resets history to the landing path.
    if (history) sessionStorage.setItem(KEY, JSON.stringify(history))
  }

  // Simulate the browser moving to a path and firing popstate.
  function popTo(path) {
    window.history.replaceState({}, '', path)
    popstateHandler()
  }

  beforeEach(() => {
    vi.resetModules()
    mocks.action.mockClear()
    sessionStorage.clear()
  })

  afterEach(() => {
    addSpy?.mockRestore()
    vi.unstubAllGlobals()
    setReferrer('')
  })

  describe('initialisation', () => {
    it.each([
      ['no referrer', ''],
      ['a different origin', 'https://example.com/page'],
    ])('seeds history with the landing path for %s', async (_n, referrer) => {
      await load({ path: '/give', referrer })
      expect(readHistory()).toEqual(['/give'])
    })

    it('leaves existing history alone for a same-origin referrer', async () => {
      await load({
        path: '/give',
        referrer: `${window.location.origin}/browse`,
        preseed: ['/browse', '/give'],
      })
      expect(readHistory()).toEqual(['/browse', '/give'])
    })

    it('registers a router hook and a popstate listener', async () => {
      await load()
      expect(router.afterEach).toHaveBeenCalledTimes(1)
      expect(typeof popstateHandler).toBe('function')
    })
  })

  describe('forward navigation tracking', () => {
    it('appends new paths', async () => {
      await load({ path: '/browse' })
      afterEachCb({ path: '/give' })
      afterEachCb({ path: '/message/5' })
      expect(readHistory()).toEqual(['/browse', '/give', '/message/5'])
    })

    it('does not duplicate the current last path', async () => {
      await load({ path: '/browse' })
      afterEachCb({ path: '/browse' })
      expect(readHistory()).toEqual(['/browse'])
    })

    it('treats corrupt stored history as empty', async () => {
      await load({
        path: '/browse',
        referrer: `${window.location.origin}/x`,
      })
      sessionStorage.setItem(KEY, '{not json')
      afterEachCb({ path: '/give' })
      expect(readHistory()).toEqual(['/give'])
    })

    it('treats missing stored history as empty', async () => {
      await load({
        path: '/browse',
        referrer: `${window.location.origin}/x`,
      })
      afterEachCb({ path: '/give' })
      expect(readHistory()).toEqual(['/give'])
    })
  })

  describe('popstate logging', () => {
    it.each([
      ['no referrer, single entry', '', ['/browse'], '/browse', true, ''],
      [
        'other-origin referrer, single entry',
        'https://example.com/p',
        ['/browse'],
        '/browse',
        true,
        false,
      ],
      [
        'same-origin entry, single entry',
        'SAME',
        ['/browse'],
        '/browse',
        false,
        true,
      ],
      [
        'no referrer, longer history',
        '',
        ['/browse', '/give'],
        '/give',
        false,
        '',
      ],
    ])(
      'logs back_nav_popstate: %s',
      async (_n, ref, history, current, willHome, same) => {
        const referrer = ref === 'SAME' ? `${window.location.origin}/p` : ref
        await load({ path: current, referrer, history })
        popTo(current)
        expect(mocks.action).toHaveBeenCalledWith('back_nav_popstate', {
          current_path: current,
          history_length: history.length,
          same_origin: same,
          is_message_page: false,
          will_navigate_home: willHome,
        })
      }
    )

    it('flags message pages', async () => {
      await load({ path: '/browse', history: ['/browse', '/message/9'] })
      popTo('/message/9')
      expect(mocks.action.mock.calls[0][1].is_message_page).toBe(true)
    })
  })

  describe('popstate routing', () => {
    it('pops history back to the current path without redirecting', async () => {
      await load({ history: ['/browse', '/give', '/myposts'] })
      popTo('/give')
      expect(readHistory()).toEqual(['/browse', '/give'])
      expect(router.replace).not.toHaveBeenCalled()
    })

    it('keeps one entry at minimum while syncing', async () => {
      await load({
        referrer: `${window.location.origin}/p`,
        history: ['/a', '/b'],
      })
      popTo('/zzz')
      expect(readHistory()).toEqual(['/a'])
    })

    it('skips back over message pages to the last other page', async () => {
      await load({
        history: ['/browse', '/give', '/message/1', '/message/2'],
      })
      popTo('/message/2')
      expect(router.replace).toHaveBeenCalledWith('/give')
      expect(readHistory()).toEqual(['/browse', '/give'])
    })

    it('goes home when only message pages are tracked', async () => {
      await load({ history: ['/message/1', '/message/2'] })
      popTo('/message/2')
      expect(router.replace).toHaveBeenCalledWith('/')
      expect(readHistory()).toEqual(['/'])
    })

    it('goes home when external history is exhausted', async () => {
      await load({ history: ['/browse'] })
      popTo('/browse')
      expect(router.replace).toHaveBeenCalledWith('/')
      expect(readHistory()).toEqual(['/'])
    })

    it('does not redirect when the entry was same-origin', async () => {
      await load({
        referrer: `${window.location.origin}/p`,
        history: ['/browse'],
      })
      popTo('/browse')
      expect(router.replace).not.toHaveBeenCalled()
      expect(readHistory()).toEqual(['/browse'])
    })
  })
})
