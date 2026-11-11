/**
 * Chat rows in the left-hand list are real links (#115): ctrl-click, middle-click
 * and 'open in new tab' work, while a plain click still selects the chat in place.
 *
 * The harness mirrors chat-list-scroll-on-select.spec.js.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref, computed, defineComponent, h, Suspense } from 'vue'

import ChatsPage from '~/pages/chats/[[id]].vue'

vi.mock('dayjs', () => {
  const mockDayjs = () => ({
    diff: vi.fn().mockReturnValue(0),
    format: vi.fn().mockReturnValue(''),
    isSameOrBefore: vi.fn().mockReturnValue(false),
    isToday: vi.fn().mockReturnValue(false),
    fromNow: vi.fn().mockReturnValue('just now'),
  })
  mockDayjs.extend = vi.fn()
  return { default: mockDayjs }
})

vi.mock('dayjs/plugin/advancedFormat', () => ({ default: {} }))
vi.mock('dayjs/plugin/relativeTime', () => ({ default: {} }))
vi.mock('dayjs/plugin/isToday', () => ({ default: {} }))
vi.mock('dayjs/plugin/isSameOrBefore', () => ({ default: {} }))

vi.mock('~/components/VisibleWhen', () => ({
  default: { template: '<div><slot /></div>', props: ['at'] },
}))
vi.mock('~/components/InfiniteLoading', () => ({
  default: {
    template: '<div class="infinite-loading" />',
    props: ['identifier', 'forceUseInfiniteWrapper', 'distance'],
    emits: ['infinite'],
  },
}))
vi.mock('~/components/SidebarRight', () => ({
  default: { template: '<div />' },
}))
vi.mock('~/components/ChatMobileNavbar.vue', () => ({
  default: { template: '<div />' },
}))
vi.mock('~/components/ExternalDa.vue', () => ({
  default: { template: '<div />' },
}))
vi.mock('~/components/ChatListEntry.vue', () => ({
  default: { template: '<div />', props: ['id'] },
}))

const mockChatStore = {
  list: [],
  listMT: [],
  listByChatId: {},
  showContactDetailsAskModal: ref(false),
  fetchChats: vi.fn().mockResolvedValue([]),
  fetchChat: vi.fn().mockResolvedValue({}),
  markRead: vi.fn().mockResolvedValue({}),
  markAllRead: vi.fn().mockResolvedValue(),
  byChatId: vi.fn().mockReturnValue(null),
  clear: vi.fn(),
  unseenCount: 0,
  showClosed: false,
  searchSince: null,
}

vi.mock('~/stores/chat', () => ({
  useChatStore: () => mockChatStore,
}))

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({
    get: vi.fn(),
    set: vi.fn(),
    breakpoint: 'lg',
    stickyAdRendered: false,
  }),
}))

const mockMe = ref({ id: 1, displayname: 'Test User', settings: {} })

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    me: mockMe,
    myid: computed(() => mockMe.value?.id || null),
    myGroups: ref([]),
  }),
}))

vi.mock('~/composables/useBuildHead', () => ({
  buildHead: () => ({}),
}))

// The page gates its setup fetch and its onMounted body on authStore.user, so it has to
// be logged in for any of this to run.
const mockAuthUser = { id: 1, displayname: 'Test User', settings: {} }

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({
    user: mockAuthUser,
    groups: [],
  }),
}))

vi.mock('pinia', async () => {
  const actual = await vi.importActual('pinia')
  return {
    ...actual,
    storeToRefs: (store) => ({
      showContactDetailsAskModal: mockChatStore.showContactDetailsAskModal,
      list: ref(store?.list || []),
    }),
  }
})

// Route params are REACTIVE here (id.spec.js snapshots them). The page now watches
// route.params.id, and a snapshot would never trigger that watcher.
// NB no vi.resetModules() here (id.spec.js has one): it splits the test file's `vue` from
// the component's, so a ref written here would not wake a watcher over there — which is
// exactly what these tests exercise.
const mockRouteParams = ref({})

const routeStub = {
  get params() {
    return mockRouteParams.value
  },
  query: {},
  path: '/chats',
  name: 'chats',
  fullPath: '/chats',
  matched: [],
  redirectedFrom: undefined,
  meta: {},
}

const mockPush = vi.fn()
const mockReplace = vi.fn()
const routerStub = {
  push: mockPush,
  replace: mockReplace,
  currentRoute: { value: { path: '/chats' } },
}

vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return {
    ...actual,
    useRoute: () => routeStub,
    useRouter: () => routerStub,
  }
})

globalThis.__testUseRoute = () => routeStub
globalThis.__testUseRouter = () => routerStub

globalThis.definePageMeta = vi.fn()
globalThis.useHead = vi.fn()
globalThis.useRuntimeConfig = () => ({ public: { BUILD_DATE: '2026-01-01' } })
globalThis.defineAsyncComponent = () => ({ template: '<div />' })

describe('chats/[[id]].vue - every chat row is a real link', () => {
  // A row that only has a click handler cannot be opened in a new tab: there is
  // nothing for ctrl-click, middle-click or "open in new tab" to act on (#115).
  // Each row is an anchor to its chat; a plain click still selects in place.
  function mountComponent() {
    const Wrapper = defineComponent({
      setup() {
        return () => h(Suspense, null, { default: () => h(ChatsPage) })
      },
    })
    return mount(Wrapper, {
      global: {
        plugins: [createPinia()],
        stubs: {
          'client-only': { template: '<div><slot /></div>' },
          'b-container': { template: '<div><slot /></div>' },
          'b-row': { template: '<div><slot /></div>' },
          'b-col': { template: '<div><slot /></div>' },
          'b-card': { template: '<div><slot /></div>' },
          'b-card-body': { template: '<div><slot /></div>' },
          'b-form-input': { template: '<input />' },
          'b-button': { template: '<button><slot /></button>' },
          'b-badge': { template: '<span><slot /></span>' },
          'v-icon': { template: '<i />' },
          ChatPane: { template: '<div />' },
          GlobalMessage: { template: '<div />' },
          ExpectedRepliesWarning: { template: '<div />' },
        },
      },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    mockMe.value = { id: 1, displayname: 'Test User', settings: {} }
    mockChatStore.list = [
      {
        id: 123,
        status: 'Active',
        latestmessage: 5,
        lastmsg: 5,
        lastdate: '2026-01-01',
        name: 'Alice',
        unseen: 0,
      },
    ]
    mockChatStore.searchSince = null
    mockChatStore.fetchChats = vi.fn().mockResolvedValue([])
    mockChatStore.fetchChat = vi.fn().mockResolvedValue({})
    mockChatStore.byChatId = vi.fn().mockReturnValue(null)
    mockRouteParams.value = {}
  })

  it('renders each chat row as a link to that chat', async () => {
    const wrapper = mountComponent()
    await flushPromises()

    const link = wrapper.find('a[href="/chats/123"]')
    expect(link.exists()).toBe(true)
  })

  // A real DOM event, so the test can see whether the handler claimed it
  // (defaultPrevented) or left it to the browser. jsdom never navigates, so
  // that flag is the only evidence of what a real browser would do.
  function clickRow(wrapper, href, init = {}) {
    const ev = new MouseEvent('click', {
      bubbles: true,
      cancelable: true,
      ...init,
    })
    wrapper.find(`a[href="${href}"]`).element.dispatchEvent(ev)
    return ev
  }

  it('a plain click still selects the chat in place', async () => {
    const wrapper = mountComponent()
    await flushPromises()

    const ev = clickRow(wrapper, '/chats/123')
    await flushPromises()

    expect(ev.defaultPrevented).toBe(true)
    expect(mockPush).toHaveBeenCalledWith('/chats/123')
  })

  it('leaves a modified click to the browser so it opens a new tab or window', async () => {
    const wrapper = mountComponent()
    await flushPromises()

    for (const init of [
      { ctrlKey: true },
      { metaKey: true },
      { shiftKey: true },
      { altKey: true },
      { button: 1 },
    ]) {
      const ev = clickRow(wrapper, '/chats/123', init)
      expect(ev.defaultPrevented, JSON.stringify(init)).toBe(false)
    }
    await flushPromises()

    expect(mockPush).not.toHaveBeenCalled()
    expect(mockReplace).not.toHaveBeenCalled()
  })

  it('carries the search term on the link, encoded', async () => {
    // The page picks its search term up from the route on load, and the list
    // only shows chats whose name matches it.
    mockChatStore.list[0].name = 'A&B Reuse'
    routeStub.query = { search: 'a&b' }
    const wrapper = mountComponent()
    await flushPromises()
    routeStub.query = {}

    expect(wrapper.find('a[href="/chats/123?search=a%26b"]').exists()).toBe(
      true
    )
  })

  it('marks the open chat as the current row', async () => {
    mockRouteParams.value = { id: '123' }
    const wrapper = mountComponent()
    await flushPromises()

    expect(
      wrapper.find('a[href="/chats/123"]').attributes('aria-current')
    ).toBe('page')
  })

  it('links the rows of the closed list too', async () => {
    mockChatStore.showClosed = true
    mockChatStore.list = [
      {
        id: 456,
        status: 'Closed',
        latestmessage: 5,
        lastmsg: 5,
        lastdate: '2026-01-01',
        name: 'Bob',
        unseen: 0,
      },
    ]
    const wrapper = mountComponent()
    await flushPromises()

    expect(wrapper.find('a[href="/chats/456"]').exists()).toBe(true)
    mockChatStore.showClosed = false
  })
})
