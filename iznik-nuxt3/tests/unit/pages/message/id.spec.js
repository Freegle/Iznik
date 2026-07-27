import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { defineComponent, Suspense, h } from 'vue'

import MessagePage from '~/pages/message/[id].vue'

// Mock component imports to avoid deep Nuxt chains
vi.mock('~/components/MyMessage', () => ({
  default: { template: '<div />', props: ['id', 'showOld', 'expand'] },
}))
vi.mock('~/components/OurMessage', () => ({
  default: {
    template: '<div class="our-message" />',
    props: ['id', 'startExpanded', 'hideClose', 'recordView'],
    emits: ['not-found'],
  },
}))
vi.mock('~/components/GlobalMessage', () => ({
  default: { template: '<div />' },
}))
vi.mock('~/components/VisibleWhen', () => ({
  default: { template: '<div><slot /></div>', props: ['not'] },
}))
vi.mock('~/components/ExternalDa', () => ({
  default: { template: '<div />' },
}))
vi.mock('~/components/MicroVolunteering', () => ({
  default: { template: '<div />' },
}))
// Renders once a message is present, and pulls in the observe-visibility directive.
vi.mock('~/components/SimilarPosts', () => ({
  default: { template: '<div />', props: ['msgid'] },
}))

// Mock composables. buildHead is spied on so tests can assert what SEO options the
// page asks for; seoDescription keeps its real behaviour because the point of the
// description tests is what it actually produces.
const mockBuildHead = vi.fn().mockReturnValue({})
vi.mock('~/composables/useBuildHead', () => ({
  buildHead: (...args) => mockBuildHead(...args),
  seoDescription: (text, max = 160) => {
    if (!text) return ''
    const s = String(text).replace(/\s+/g, ' ').trim()
    return s.length <= max ? s : s.slice(0, max).trimEnd() + '...'
  },
}))
vi.mock('~/composables/useTwem', () => ({
  twem: vi.fn((s) => s),
}))
vi.mock('~/composables/useTimeFormat', () => ({
  dateonlyNoYear: vi.fn().mockReturnValue('1 Jan'),
}))

// Mock stores
const mockFetch = vi.fn().mockResolvedValue({})
const mockById = vi.fn().mockReturnValue(null)

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    fetch: mockFetch,
    byId: mockById,
  }),
}))

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({
    user: null,
  }),
}))

// Mutable route so each test can set its own params
let mockRouteReturn = { params: { id: '123' }, query: {} }

vi.hoisted(() => {
  vi.resetModules()
})

// The page imports useHead from #imports, so spy on it there rather than on
// globalThis - that's where the call actually lands.
const mockHead = vi.fn()

vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return {
    ...actual,
    useRoute: () => mockRouteReturn,
    useHead: (...args) => mockHead(...args),
    ref: actual.ref,
    computed: actual.computed,
    onMounted: actual.onMounted,
  }
})

// Nuxt auto-imports
const mockStatus = vi.fn()
globalThis.__testUseRoute = () => mockRouteReturn
globalThis.__testSetResponseStatus = (...args) => mockStatus(...args)
globalThis.definePageMeta = vi.fn()
globalThis.useHead = vi.fn()
globalThis.useRuntimeConfig = () => ({
  public: {
    BUILD_DATE: '2026-01-01',
    USER_SITE: 'https://www.ilovefreegle.org',
  },
})

// A live post, and the various ways one stops being live.
const LIVE = {
  id: 123,
  type: 'Offer',
  subject: 'OFFER: Dining chairs (Moulton NN3)',
  textbody: 'Four solid oak dining chairs. Good condition.',
  groups: [{ collection: 'Approved' }],
  attachments: [],
}

describe('pages/message/[id].vue', () => {
  // Wrap in Suspense since message page has top-level await in setup
  function mountPage() {
    const Wrapper = defineComponent({
      setup() {
        return () => h(Suspense, null, { default: () => h(MessagePage) })
      },
    })
    return mount(Wrapper, {
      global: {
        plugins: [createPinia()],
        stubs: {
          'client-only': { template: '<div><slot /></div>' },
          'b-col': { template: '<div><slot /></div>' },
          'b-row': { template: '<div><slot /></div>' },
          'b-button': { template: '<button><slot /></button>' },
          'v-icon': { template: '<i />' },
        },
      },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    mockRouteReturn = { params: { id: '123' }, query: {} }
    mockFetch.mockResolvedValue({})
    mockById.mockReturnValue(null)
    mockBuildHead.mockReturnValue({})
    mockStatus.mockClear()
  })

  it('mounts without error when route is undefined (SSR hydration race)', async () => {
    mockRouteReturn = undefined

    let mountError = null
    try {
      mountPage()
      await flushPromises()
    } catch (e) {
      mountError = e
    }

    expect(mountError).toBeNull()
  })

  it('mounts without error when route.params has no id', async () => {
    mockRouteReturn = { params: {}, query: {} }

    let mountError = null
    try {
      mountPage()
      await flushPromises()
    } catch (e) {
      mountError = e
    }

    expect(mountError).toBeNull()
  })

  it('calls fetch with parsed integer id on setup', async () => {
    mockRouteReturn = { params: { id: '98765' }, query: {} }

    mountPage()
    await flushPromises()

    expect(mockFetch).toHaveBeenCalledWith(98765)
  })

  it('mounts without error when fetch throws (failed state set gracefully)', async () => {
    mockRouteReturn = { params: { id: '123' }, query: {} }
    mockFetch.mockRejectedValueOnce(new Error('network error'))

    let mountError = null
    try {
      mountPage()
      await flushPromises()
    } catch (e) {
      mountError = e
    }

    expect(mountError).toBeNull()
  })

  it('mounts without error when fetch returns null (failed state set)', async () => {
    // Return null on first call (setup), allow second call (onMounted) to succeed
    mockFetch.mockResolvedValueOnce(null).mockResolvedValue({})

    let mountError = null
    try {
      mountPage()
      await flushPromises()
    } catch (e) {
      mountError = e
    }

    expect(mountError).toBeNull()
  })

  it('initial fetch is called without force flag', async () => {
    mockRouteReturn = { params: { id: '42' }, query: {} }

    mountPage()
    await flushPromises()

    expect(mockFetch.mock.calls[0]).toEqual([42])
  })

  it('calls fetch a second time on mount with force=true', async () => {
    mockRouteReturn = { params: { id: '42' }, query: {} }

    mountPage()
    await flushPromises()

    expect(mockFetch).toHaveBeenCalledWith(42, true)
  })

  it('fetch is called exactly twice per mount (setup + onMounted refetch)', async () => {
    mockRouteReturn = { params: { id: '42' }, query: {} }

    mountPage()
    await flushPromises()

    expect(mockFetch).toHaveBeenCalledTimes(2)
  })

  it('onMounted fetch also completes without error when it throws', async () => {
    mockRouteReturn = { params: { id: '42' }, query: {} }
    // First call (setup) resolves, second call (onMounted) rejects
    mockFetch
      .mockResolvedValueOnce({})
      .mockRejectedValueOnce(new Error('reload failed'))

    let mountError = null
    try {
      mountPage()
      await flushPromises()
    } catch (e) {
      mountError = e
    }

    expect(mountError).toBeNull()
  })

  /* There are roughly 8.3 million finished posts against ~42,000 live ones. They
  used to answer 200 with the item subject as the title, which reads to a crawler as
  millions of near-identical thin pages. */
  describe('finished posts', () => {
    const gone = [
      ['taken', { ...LIVE, outcomes: [{ outcome: 'Taken' }] }],
      ['received', { ...LIVE, outcomes: [{ outcome: 'Received' }] }],
      ['withdrawn', { ...LIVE, outcomes: [{ outcome: 'Withdrawn' }] }],
      ['deleted', { ...LIVE, deleted: '2026-07-01' }],
      [
        'rejected everywhere',
        { ...LIVE, groups: [{ collection: 'Rejected' }] },
      ],
    ]

    for (const [label, message] of gone) {
      it(`answers 410 for a ${label} post`, async () => {
        mockById.mockReturnValue(message)

        mountPage()
        await flushPromises()

        expect(mockStatus).toHaveBeenCalledWith(410)
      })

      it(`asks for noindex on a ${label} post`, async () => {
        mockById.mockReturnValue(message)

        mountPage()
        await flushPromises()

        expect(mockBuildHead.mock.calls[0][6].noindex).toBe(true)
      })
    }

    it('answers 410 when the post does not exist at all', async () => {
      mockFetch.mockResolvedValueOnce(null).mockResolvedValue({})
      mockById.mockReturnValue(null)

      mountPage()
      await flushPromises()

      expect(mockStatus).toHaveBeenCalledWith(410)
    })

    it('leaves a live post alone', async () => {
      mockById.mockReturnValue(LIVE)

      mountPage()
      await flushPromises()

      expect(mockStatus).not.toHaveBeenCalled()
      expect(mockBuildHead.mock.calls[0][6].noindex).toBe(false)
    })

    it('honours ?showtaken, which deliberately shows a finished post', async () => {
      mockRouteReturn = { params: { id: '123' }, query: { showtaken: 1 } }
      mockById.mockReturnValue({ ...LIVE, outcomes: [{ outcome: 'Taken' }] })

      mountPage()
      await flushPromises()

      expect(mockStatus).not.toHaveBeenCalled()
    })
  })

  describe('SEO head', () => {
    it('describes the post from its body rather than the old "Click for more details"', async () => {
      mockById.mockReturnValue(LIVE)

      mountPage()
      await flushPromises()

      expect(mockBuildHead.mock.calls[0][3]).toBe(
        'Four solid oak dining chairs. Good condition.'
      )
    })

    it('prefers a snippet when the API ever starts sending one', async () => {
      mockById.mockReturnValue({ ...LIVE, snippet: 'Short version' })

      mountPage()
      await flushPromises()

      expect(mockBuildHead.mock.calls[0][3]).toBe('Short version...')
    })

    it('falls back to the generic line only when there is no body at all', async () => {
      mockById.mockReturnValue({ ...LIVE, textbody: '' })

      mountPage()
      await flushPromises()

      expect(mockBuildHead.mock.calls[0][3]).toBe('Click for more details')
    })

    it('canonicalises to the bare post URL, dropping any ?src tracking', async () => {
      mockRouteReturn = { params: { id: '123' }, query: { src: 'digest' } }
      mockById.mockReturnValue(LIVE)

      mountPage()
      await flushPromises()

      expect(mockBuildHead.mock.calls[0][6].canonical).toBe('/message/123')
    })

    it('marks a live post as og:type product', async () => {
      mockById.mockReturnValue(LIVE)

      mountPage()
      await flushPromises()

      expect(mockBuildHead.mock.calls[0][6].ogType).toBe('product')
    })

    it('does not claim a finished post is a product', async () => {
      mockById.mockReturnValue({ ...LIVE, outcomes: [{ outcome: 'Taken' }] })

      mountPage()
      await flushPromises()

      expect(mockBuildHead.mock.calls[0][6].ogType).toBe('website')
    })

    it('attaches JSON-LD for a live offer', async () => {
      mockById.mockReturnValue(LIVE)

      mountPage()
      await flushPromises()

      const head = mockHead.mock.calls[0][0]
      const ld = JSON.parse(head.script[0].innerHTML)
      expect(ld['@type']).toBe('Product')
      expect(ld.name).toBe('Dining chairs (Moulton NN3)')
    })

    it('attaches no JSON-LD for a wanted', async () => {
      mockById.mockReturnValue({ ...LIVE, type: 'Wanted' })

      mountPage()
      await flushPromises()

      const head = mockHead.mock.calls[0][0]
      expect(head.script).toBeUndefined()
    })
  })
})
