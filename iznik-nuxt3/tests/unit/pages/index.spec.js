/**
 * pages/index.vue boot behaviour.
 *
 * The landing page's data cascade (group list → message inbounds → message
 * details) exists as an SSR prefetch for the web build. In the Capacitor app
 * build (ssr: false, runtimeConfig.public.ISAPP) it used to run client-side as
 * top-level awaits, blocking first paint on ~3 sequential API round trips even
 * for logged-in users who are immediately redirected to /browse.
 *
 * Contract:
 * - App build + stored session: no landing data fetches at all.
 * - App build + logged out: fetches happen after mount, without blocking the
 *   page from rendering.
 * - Web build (client): blocking prefetch preserved (unchanged behaviour).
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, Suspense, h, nextTick } from 'vue'

import IndexPage from '~/pages/index.vue'

vi.mock('~/composables/useBuildHead', () => ({
  buildHead: vi.fn(() => ({})),
}))

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({ get: vi.fn() }),
}))

vi.mock('@/stores/mobile', () => ({
  useMobileStore: () => ({ isApp: false }),
}))

const mockFetchInBounds = vi.fn()
const mockMessageFetch = vi.fn()
vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    fetchInBounds: mockFetchInBounds,
    fetch: mockMessageFetch,
  }),
}))

const mockGroupFetch = vi.fn()
vi.mock('~/stores/group', () => ({
  useGroupStore: () => ({ fetch: mockGroupFetch }),
}))

vi.mock('~/api', () => ({
  default: () => ({ bandit: { chosen: vi.fn() } }),
}))

vi.mock('~/components/FreeglerPhotoGrid.vue', () => ({
  default: { template: '<div class="mock-photo-grid" />' },
}))
vi.mock('~/components/ProxyImage.vue', () => ({
  default: { template: '<img />', props: ['src', 'alt', 'width', 'height'] },
}))
vi.mock('~/components/PlaceAutocomplete.vue', () => ({
  default: { template: '<div />' },
}))
vi.mock('~/components/ExternalLink.vue', () => ({
  default: { template: '<a><slot /></a>', props: ['href'] },
}))

const globalStubs = {
  LazyBreakpointFettler: true,
  LazyMobileVisualiseList: true,
  LazyMainFooter: true,
  'v-icon': true,
  NuxtLink: { template: '<a><slot /></a>', props: ['to'] },
}

function mountPage() {
  const Wrapper = defineComponent({
    setup() {
      return () => h(Suspense, null, { default: () => h(IndexPage) })
    },
  })

  return mount(Wrapper, { global: { stubs: globalStubs } })
}

function setAuth(overrides = {}) {
  globalThis.__mockAuthStore = {
    groups: [],
    user: null,
    auth: { jwt: null, persistent: null },
    loginStateKnown: false,
    ...overrides,
  }
}

function setAppBuild(isApp) {
  globalThis.__testRuntimeConfig = () => ({ public: { ISAPP: isApp } })
}

describe('pages/index boot data cascade', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockGroupFetch.mockResolvedValue(undefined)
    mockFetchInBounds.mockResolvedValue([])
    mockMessageFetch.mockResolvedValue({})
    setAuth()
    setAppBuild(false)
  })

  afterEach(() => {
    delete globalThis.__mockAuthStore
    delete globalThis.__testRuntimeConfig
  })

  it('app build with a resolved login skips the landing data fetches entirely', async () => {
    setAppBuild(true)
    // By mount time the layout's session fetch has resolved the user.
    setAuth({
      user: { id: 1 },
      auth: { jwt: 'token', persistent: null },
      loginStateKnown: true,
    })

    mountPage()
    await flushPromises()

    expect(mockGroupFetch).not.toHaveBeenCalled()
    expect(mockFetchInBounds).not.toHaveBeenCalled()
  })

  it('app build logged out renders immediately and fetches after mount', async () => {
    setAppBuild(true)
    // Landing fetches hang forever - the page must still render.
    mockGroupFetch.mockReturnValue(new Promise(() => {}))

    const wrapper = mountPage()
    await flushPromises()
    await nextTick()

    // Page rendered despite the pending fetch → fetch is not blocking paint.
    expect(wrapper.find('.landing-page').exists()).toBe(true)

    // And the fetch was kicked off (after mount).
    expect(mockGroupFetch).toHaveBeenCalledTimes(1)
  })

  it('web build (client) keeps the blocking prefetch', async () => {
    setAppBuild(false)
    // While the group fetch hangs, Suspense must NOT resolve - this is the
    // existing web behaviour, preserved.
    mockGroupFetch.mockReturnValue(new Promise(() => {}))

    const wrapper = mountPage()
    await flushPromises()
    await nextTick()

    expect(mockGroupFetch).toHaveBeenCalledTimes(1)
    expect(wrapper.find('.landing-page').exists()).toBe(false)
  })

  it('web build completes the full cascade in order', async () => {
    setAppBuild(false)
    mockFetchInBounds.mockResolvedValue([
      { id: 1, type: 'Offer' },
      { id: 2, type: 'Wanted' },
    ])

    const wrapper = mountPage()
    await flushPromises()

    expect(mockGroupFetch).toHaveBeenCalledTimes(1)
    expect(mockFetchInBounds).toHaveBeenCalledTimes(1)
    // Only offers get detail-preloaded.
    expect(mockMessageFetch).toHaveBeenCalledTimes(1)
    expect(mockMessageFetch).toHaveBeenCalledWith(1)
    expect(wrapper.find('.landing-page').exists()).toBe(true)
  })
})
