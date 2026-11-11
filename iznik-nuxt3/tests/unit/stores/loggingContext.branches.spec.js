import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { nextTick } from 'vue'
import { useLoggingContextStore } from '~/stores/loggingContext'
import { getSessionId } from '~/composables/useTrace'

vi.mock('~/composables/useTrace', () => ({
  getSessionId: vi.fn(() => 'sess-1'),
}))

describe('loggingContext store branches', () => {
  let store

  beforeEach(() => {
    vi.clearAllMocks()
    getSessionId.mockReturnValue('sess-1')
    setActivePinia(createPinia())
    store = useLoggingContextStore()
  })

  it('has empty initial state', () => {
    expect(store.$state).toEqual({
      pageId: null,
      pageUrl: null,
      pageTitle: null,
      modalStack: [],
      site: null,
    })
  })

  // The server early-return is substituted away by the vitest pre-transform
  // (import.meta.server -> false), so only the client branch is reachable.
  describe('init', () => {
    it.each([
      ['site from config', { public: { SITE: 'MT' } }, 'MT'],
      ['missing SITE', { public: {} }, 'FD'],
      ['missing public', {}, 'FD'],
      ['undefined config', undefined, 'FD'],
      ['empty SITE', { public: { SITE: '' } }, 'FD'],
    ])('%s', (_n, cfg, expected) => {
      store.init(cfg)
      expect(store.site).toBe(expected)
    })
  })

  describe('startPage', () => {
    it('sets page id, url, resets modals and captures title', async () => {
      store.pushModal('x')
      document.title = 'My Title'
      store.startPage({ path: '/browse' })
      expect(store.pageId).toMatch(/^page_[a-z0-9]+$/)
      expect(store.pageUrl).toBe('/browse')
      expect(store.modalStack).toEqual([])
      await nextTick()
      expect(store.pageTitle).toBe('My Title')
    })

    it('generates a new page id each time', () => {
      store.startPage({ path: '/a' })
      const first = store.pageId
      store.startPage({ path: '/b' })
      expect(store.pageId).not.toBe(first)
      expect(store.pageUrl).toBe('/b')
    })

    it('skips the title when document is undefined', () => {
      vi.stubGlobal('document', undefined)
      try {
        store.startPage({ path: '/nodoc' })
        expect(store.pageUrl).toBe('/nodoc')
        expect(store.pageTitle).toBeNull()
      } finally {
        vi.unstubAllGlobals()
      }
    })
  })

  describe('modals', () => {
    it('pushModal returns a prefixed id and records it', () => {
      const id = store.pushModal('Login')
      expect(id).toMatch(/^modal_[a-z0-9]+$/)
      expect(store.modalStack).toEqual([{ id, name: 'Login' }])
    })

    it('popModal returns the last id, then undefined when empty', () => {
      const a = store.pushModal('A')
      const b = store.pushModal('B')
      expect(store.popModal()).toBe(b)
      expect(store.popModal()).toBe(a)
      expect(store.popModal()).toBeUndefined()
      expect(store.modalStack).toEqual([])
    })
  })

  describe('getHeaders', () => {
    it('is all empty strings when nothing set and no session', () => {
      getSessionId.mockReturnValue(null)
      expect(store.getHeaders()).toEqual({
        'X-Freegle-Session': '',
        'X-Freegle-Page': '',
        'X-Freegle-Modal': '',
        'X-Freegle-Site': '',
      })
    })

    it.each([[undefined], ['']])('session %p falls back to empty', (s) => {
      getSessionId.mockReturnValue(s)
      expect(store.getHeaders()['X-Freegle-Session']).toBe('')
    })

    it('builds all headers with comma-joined modal ids', () => {
      store.init({ public: { SITE: 'MT' } })
      store.startPage({ path: '/p' })
      const a = store.pushModal('A')
      const b = store.pushModal('B')
      expect(store.getHeaders()).toEqual({
        'X-Freegle-Session': 'sess-1',
        'X-Freegle-Page': store.pageId,
        'X-Freegle-Modal': `${a},${b}`,
        'X-Freegle-Site': 'MT',
      })
    })
  })

  describe('getContext', () => {
    it('returns defaults when nothing set', () => {
      getSessionId.mockReturnValue(null)
      expect(store.getContext()).toEqual({
        session_id: null,
        page_id: null,
        page_url: null,
        page_title: null,
        modal_stack: [],
        modal_names: [],
        site: null,
      })
    })

    it('returns the full context with modal ids and names', async () => {
      document.title = 'T'
      store.init({})
      store.startPage({ path: '/p' })
      await nextTick()
      const a = store.pushModal('A')
      const b = store.pushModal('B')
      expect(store.getContext()).toEqual({
        session_id: 'sess-1',
        page_id: store.pageId,
        page_url: '/p',
        page_title: 'T',
        modal_stack: [a, b],
        modal_names: ['A', 'B'],
        site: 'FD',
      })
    })
  })
})
