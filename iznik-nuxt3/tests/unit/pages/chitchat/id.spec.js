import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'

import ChitchatPage from '~/pages/chitchat/[[id]].vue'

// Mock all component imports BEFORE the page loads to prevent Nuxt internal imports.
vi.mock('~/components/NewsCommunityEventVolunteerSummary', () => ({
  default: { template: '<div />' },
}))
vi.mock('~/components/VisibleWhen', () => ({
  default: { template: '<div><slot /></div>', props: ['at'] },
}))
vi.mock('~/components/GlobalMessage', () => ({
  default: { template: '<div />' },
}))
vi.mock('~/components/NoticeMessage', () => ({
  default: {
    template: '<div><slot /></div>',
    props: ['variant'],
  },
}))
vi.mock('~/components/AutoHeightTextarea', () => ({
  default: { template: '<textarea />', props: ['id', 'modelValue'] },
}))
vi.mock('~/components/InfiniteLoading', () => ({
  default: {
    template: '<div class="infinite-loading" />',
    props: ['identifier', 'forceUseInfiniteWrapper', 'distance'],
    emits: ['infinite'],
  },
}))
vi.mock('~/components/NewsThread.vue', () => ({
  default: {
    name: 'NewsThread',
    template: '<div class="news-thread" />',
    props: ['id', 'scrollTo', 'duplicateCount', 'context'],
  },
}))
vi.mock('~/components/MessageListUpToDate.vue', () => ({
  default: { template: '<div />' },
}))

// Mock stores
const mockNewsfeedStore = {
  feed: [],
  count: 0,
  maxSeen: null,
  seenBeforeVisit: null,
  delayedSeenMode: false,
  delayedSeenTimer: null,
  fetchFeed: vi.fn().mockResolvedValue([]),
  fetch: vi.fn().mockResolvedValue({}),
  fetchCount: vi.fn().mockResolvedValue(0),
  reset: vi.fn(),
  send: vi.fn(),
  byId: vi.fn(),
  snapshotSeenBeforeVisit: vi.fn(),
  startDelayedSeen: vi.fn(),
  markAllSeen: vi.fn(),
}

vi.mock('~/stores/newsfeed', () => ({
  useNewsfeedStore: () => mockNewsfeedStore,
}))

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({
    get: vi.fn(),
    set: vi.fn(),
  }),
}))

vi.mock('~/stores/location', () => ({
  useLocationStore: () => ({
    fetchv2: vi.fn().mockResolvedValue({ name: 'Test Area' }),
  }),
}))

// Mock useMe
const mockMe = ref({ id: 1, displayname: 'Test User', settings: {} })

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    me: mockMe,
    myGroups: ref([]),
  }),
}))

const routeState = vi.hoisted(() => {
  vi.resetModules()
  return { params: {} }
})

vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return {
    ...actual,
    useRoute: () => ({
      params: routeState.params,
      query: {},
      path: '/',
      name: 'chitchat',
      fullPath: '/',
      matched: [],
      redirectedFrom: undefined,
      meta: {},
    }),
  }
})

globalThis.__testUseRoute = () => ({
  params: {},
  query: {},
  path: '/',
  name: 'chitchat',
  fullPath: '/',
  matched: [],
  redirectedFrom: undefined,
  meta: {},
})

vi.mock('~/composables/useBuildHead', () => ({
  buildHead: () => ({}),
}))

vi.mock('~/composables/useTwem', () => ({
  untwem: (msg) => msg,
}))

// Nuxt macros
globalThis.definePageMeta = vi.fn()
globalThis.useHead = vi.fn()
globalThis.useRuntimeConfig = () => ({ public: { BUILD_DATE: '2026-01-01' } })
globalThis.defineAsyncComponent = (fn) => ({ template: '<div />' })

describe('chitchat/[[id]].vue loadMore', () => {
  let wrapper

  function mountComponent() {
    wrapper = mount(ChitchatPage, {
      global: {
        plugins: [createPinia()],
        stubs: {
          'client-only': { template: '<div><slot /></div>' },
          'b-container': { template: '<div><slot /></div>' },
          'b-row': { template: '<div><slot /></div>' },
          'b-col': { template: '<div><slot /></div>' },
          'b-form-select': {
            template: '<select />',
            props: ['modelValue', 'options'],
          },
          'b-spinner': { template: '<span />' },
          'v-icon': { template: '<i />' },
          OurUploader: { template: '<div />' },
          OurUploadedImage: { template: '<div />' },
          NuxtPicture: { template: '<div />' },
          SidebarLeft: { template: '<div />' },
          SidebarRight: { template: '<div />' },
          ExpectedRepliesWarning: { template: '<div />' },
          Suspense: { template: '<div><slot /></div>' },
        },
      },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    mockMe.value = { id: 1, displayname: 'Test User', settings: {} }
    mockNewsfeedStore.feed = []
    routeState.params = {}
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
  })

  it('loadMore calls loaded (not complete) when auth not hydrated', () => {
    mockMe.value = null
    mountComponent()
    const mockState = { loaded: vi.fn(), complete: vi.fn() }

    wrapper.vm.loadMore(mockState)

    expect(mockState.loaded).toHaveBeenCalled()
    expect(mockState.complete).not.toHaveBeenCalled()
  })

  it('loadMore calls loaded (not complete) when feed is empty', () => {
    mockNewsfeedStore.feed = []
    mountComponent()
    const mockState = { loaded: vi.fn(), complete: vi.fn() }

    wrapper.vm.loadMore(mockState)

    expect(mockState.loaded).toHaveBeenCalled()
    expect(mockState.complete).not.toHaveBeenCalled()
  })

  it('loadMore increments show when more items available', () => {
    mockNewsfeedStore.feed = [
      { id: 1, userid: 1 },
      { id: 2, userid: 2 },
    ]
    mountComponent()
    wrapper.vm.show = 0
    const mockState = { loaded: vi.fn(), complete: vi.fn() }

    wrapper.vm.loadMore(mockState)

    expect(wrapper.vm.show).toBe(1)
  })

  it('loadMore calls complete when all items shown', () => {
    mockNewsfeedStore.feed = [{ id: 1, userid: 1 }]
    mountComponent()
    wrapper.vm.show = 1
    const mockState = { loaded: vi.fn(), complete: vi.fn() }

    wrapper.vm.loadMore(mockState)

    expect(mockState.complete).toHaveBeenCalled()
  })

  it('does not refetch feed when selectedArea changes because of logout', async () => {
    // Regression: logout resets the auth store, nulling me.  For users with a
    // newsfeedarea set that flips selectedArea to 0, and the watcher refetched
    // the feed without a JWT → 401 → fatal "Oh dear" error page.
    mockMe.value = {
      id: 1,
      displayname: 'Test User',
      settings: { newsfeedarea: 12345 },
    }
    mountComponent()
    await flushPromises()
    vi.clearAllMocks()

    mockMe.value = null
    await flushPromises()

    expect(mockNewsfeedStore.reset).not.toHaveBeenCalled()
    expect(mockNewsfeedStore.fetchFeed).not.toHaveBeenCalled()
  })

  it('refetches feed when a logged-in user changes area', async () => {
    mockMe.value = {
      id: 1,
      displayname: 'Test User',
      settings: { newsfeedarea: 0 },
    }
    mountComponent()
    await flushPromises()
    vi.clearAllMocks()

    mockMe.value.settings.newsfeedarea = 12345
    await flushPromises()

    expect(mockNewsfeedStore.reset).toHaveBeenCalled()
    expect(mockNewsfeedStore.fetchFeed).toHaveBeenCalledWith(12345)
  })

  it('does not hide consecutive posts from same user when message field is missing', () => {
    // Feed summary objects from the API only have id/userid/hidden — no message field.
    // Regression: undefined === undefined was true, so all consecutive same-user posts
    // were wrongly grouped as "duplicates" and hidden.
    mockNewsfeedStore.feed = [
      { id: 3, userid: 100 },
      { id: 2, userid: 100 },
      { id: 1, userid: 100 },
    ]
    mountComponent()
    wrapper.vm.show = 10

    // All 3 posts should be visible in newsfeedToShow
    const shown = wrapper.vm.newsfeedToShow
    expect(shown).toHaveLength(3)
    expect(shown.map((s) => s.id)).toEqual([3, 2, 1])
  })

  it('hides actual duplicate posts with identical message text', () => {
    // When message IS populated and identical, duplicates should be grouped.
    mockNewsfeedStore.feed = [
      { id: 3, userid: 100, message: 'Hello world' },
      { id: 2, userid: 100, message: 'Hello world' },
      { id: 1, userid: 200, message: 'Different user' },
    ]
    mountComponent()
    wrapper.vm.show = 10

    const shown = wrapper.vm.newsfeedToShow
    // Post 2 is a true duplicate of post 3 (same user, same message)
    expect(shown).toHaveLength(2)
    expect(shown.map((s) => s.id)).toEqual([3, 1])
  })

  it('does not hide posts from same user with different messages', () => {
    mockNewsfeedStore.feed = [
      { id: 3, userid: 100, message: 'First post' },
      { id: 2, userid: 100, message: 'Second post' },
      { id: 1, userid: 100, message: 'Third post' },
    ]
    mountComponent()
    wrapper.vm.show = 10

    const shown = wrapper.vm.newsfeedToShow
    expect(shown).toHaveLength(3)
    expect(shown.map((s) => s.id)).toEqual([3, 2, 1])
  })

  describe('thread rendering context', () => {
    it('renders feed cards in feed context', async () => {
      mockNewsfeedStore.feed = [{ id: 7, userid: 100, message: 'Hi' }]
      mountComponent()
      wrapper.vm.show = 1
      await flushPromises()

      const thread = wrapper.findComponent('.news-thread')
      expect(thread.exists()).toBe(true)
      expect(thread.props('context')).toBe('feed')
    })

    it('renders a deep-linked thread in thread context', async () => {
      routeState.params = { id: '456' }
      mockNewsfeedStore.fetch.mockResolvedValue({ id: 456, threadhead: 456 })
      mockNewsfeedStore.byId.mockReturnValue({ id: 456, userid: 100 })
      mountComponent()
      await flushPromises()

      const thread = wrapper.findComponent('.news-thread')
      expect(thread.exists()).toBe(true)
      expect(thread.props('context')).toBe('thread')
    })
  })

  describe('seen baseline', () => {
    it('snapshots the baseline before dispatching the thread fetch on a deep link', async () => {
      routeState.params = { id: '456' }
      mountComponent()
      await flushPromises()

      expect(mockNewsfeedStore.snapshotSeenBeforeVisit).toHaveBeenCalled()
      expect(mockNewsfeedStore.fetch).toHaveBeenCalled()
      // Order matters: delayedSeenMode must be on before the fetch's addItems
      // could fire an instant Seen POST for everything in the thread.
      expect(
        mockNewsfeedStore.snapshotSeenBeforeVisit.mock.invocationCallOrder[0]
      ).toBeLessThan(mockNewsfeedStore.fetch.mock.invocationCallOrder[0])
    })

    it('starts the delayed seen timer on a thread deep link', async () => {
      routeState.params = { id: '456' }
      mountComponent()
      await flushPromises()

      expect(mockNewsfeedStore.startDelayedSeen).toHaveBeenCalledWith(30000)
    })

    it('still snapshots and delays on the feed view', async () => {
      mountComponent()
      await flushPromises()

      expect(mockNewsfeedStore.snapshotSeenBeforeVisit).toHaveBeenCalled()
      expect(mockNewsfeedStore.startDelayedSeen).toHaveBeenCalledWith(30000)
    })
  })
})
