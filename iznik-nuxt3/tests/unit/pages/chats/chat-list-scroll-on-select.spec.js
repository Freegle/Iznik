/**
 * The chat list jumped when you clicked a chat you could already see: the clicked chat
 * was yanked to the very top of the panel.
 *
 * That scroll is `revealChat()` — `scrollIntoView({ block: 'start' })` plus, above it, a
 * `fetchOlder()` that pulls the ENTIRE chat history to make room below the target. It is
 * meant for deep links (opening /chats/123 from an email or push notification, where the
 * chat may be far down the list or not loaded at all), and it lived in onMounted.
 *
 * It ran on ordinary clicks because Nuxt's default page key interpolates the route params
 * (generateRouteKey -> interpolatePath, nuxt/dist/pages/runtime/utils.js), so the key went
 * /chats/1 -> /chats/2 on every selection and the page remounted.
 *
 * The fix pins a constant `key: 'chats'` in definePageMeta and moves the selection onto a
 * route watcher, so ARRIVING reveals but SELECTING does not. Anything that was previously
 * refreshed only by the remount (`id`, `chat` for the mobile navbar) has to track the
 * selection instead.
 *
 * The harness mirrors id.spec.js, except route params are reactive — the watcher under
 * test cannot fire against a snapshot.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref, computed, defineComponent, h, Suspense, nextTick } from 'vue'

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

describe('chats/[[id]].vue - selecting a chat must not scroll the list', () => {
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
    mockChatStore.list = []
    mockChatStore.searchSince = null
    mockChatStore.fetchChats = vi.fn().mockResolvedValue([])
    mockChatStore.fetchChat = vi.fn().mockResolvedValue({})
    mockChatStore.byChatId = vi.fn().mockReturnValue(null)
    mockRouteParams.value = {}
  })

  // The constant `key: 'chats'` in definePageMeta is what stops the remount, but
  // definePageMeta is a compiler macro that is stripped at build time, so there is
  // nothing here to assert against. What IS observable is the behaviour it enables, and
  // that is what the rest of this file covers: the page keeping its state and tracking
  // the route itself instead of being rebuilt.

  // These drive gotoChat() — the click handler on every row of the list — rather than
  // pushing a new route param, because that is the path the report is about and it does
  // not depend on the harness faking router reactivity. Arrival via the route is covered
  // by the deep-link test at the end.

  it('does not pull the whole chat history when a chat is picked from the list', async () => {
    const wrapper = mountComponent()
    await flushPromises()
    const page = wrapper.findComponent(ChatsPage)

    mockChatStore.fetchChats.mockClear()
    mockChatStore.searchSince = null

    page.vm.gotoChat(123)
    await nextTick()
    await flushPromises()

    // revealChat() calls fetchOlder(), which sets searchSince and refetches every chat
    // ever, purely so there are entries below the target to scroll past — and then
    // scrollIntoView({ block: 'start' }) yanks it to the top. Doing either on a click is
    // the disruption being fixed. Asserting the fetch is how the scroll is observed:
    // jsdom has no layout, so scrollIntoView itself proves nothing.
    expect(mockChatStore.searchSince).toBeNull()
    expect(mockChatStore.fetchChats).not.toHaveBeenCalled()
    expect(mockPush).toHaveBeenCalledWith('/chats/123')
  })

  it('still reveals a deep-linked chat on arrival', async () => {
    // Arriving directly at /chats/123 - the chat may be far down the list or not loaded
    // at all, so this path must still fetch and scroll.
    mockRouteParams.value = { id: '123' }

    const wrapper = mountComponent()
    await flushPromises()
    const page = wrapper.findComponent(ChatsPage)

    expect(page.vm.selectedChatId).toBe(123)
    expect(mockChatStore.fetchChats).toHaveBeenCalled()
  })
})
