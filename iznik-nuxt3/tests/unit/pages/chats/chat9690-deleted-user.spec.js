/**
 * AssertFlip test for bug #9690:
 * Clicking "Show hidden/blocked chats" blanks the entire chat list when a
 * closed chat belongs to a deleted user whose `name` is null.
 *
 * Root cause: `scanChats` (pages/chats/[[id]].vue) calls
 *   `chat.name.toLowerCase()`            (line ~536)
 * without guarding against null. When a search is active this throws a
 * TypeError, the `closedChats` computed propagates the error, and Vue
 * renders the whole page as blank.
 *
 * AssertFlip protocol:
 *   STEP 1 — Assert BUGGY behaviour passes (verified, then inverted).
 *             With the current code the page blanks → `.chatlist` absent.
 *   STEP 2 — Inverted assertion committed here:
 *             `.chatlist` MUST be present → FAILS on buggy code,
 *             PASSES once `chat.name?.toLowerCase()` is guarded.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref, computed, defineComponent, h, Suspense, nextTick } from 'vue'

import ChatsPage from '~/pages/chats/[[id]].vue'

/* ── dayjs (needed by transitive imports) ── */
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

/* ── shallow component stubs ── */
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
vi.mock('~/components/SidebarRight', () => ({ default: { template: '<div />' } }))
vi.mock('~/components/ChatMobileNavbar.vue', () => ({ default: { template: '<div />' } }))
vi.mock('~/components/ExternalDa.vue', () => ({ default: { template: '<div />' } }))
vi.mock('~/components/ChatListEntry.vue', () => ({
  default: {
    template: '<div class="chat-list-entry" data-testid="chat-list-entry" />',
    props: ['id', 'active'],
  },
}))

/* ── store mocks ── */
const mockChatStore = {
  list: [],
  showContactDetailsAskModal: ref(false),
  fetchChats: vi.fn().mockResolvedValue([]),
  fetchChat: vi.fn().mockResolvedValue({}),
  markAllRead: vi.fn().mockResolvedValue(),
  markRead: vi.fn().mockResolvedValue({}),
  byChatId: vi.fn().mockReturnValue(null),
  clear: vi.fn(),
  unseenCount: 0,
  showClosed: false,
  searchSince: null,
}

vi.mock('~/stores/chat', () => ({ useChatStore: () => mockChatStore }))
vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({
    get: vi.fn(),
    set: vi.fn(),
    breakpoint: 'lg',
    stickyAdRendered: false,
  }),
}))
vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({ user: { id: 1 } }),
}))

const mockMe = ref({ id: 1, displayname: 'Test User', settings: {} })
vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    me: mockMe,
    myid: computed(() => mockMe.value?.id || null),
    myGroups: ref([]),
  }),
}))
vi.mock('~/composables/useBuildHead', () => ({ buildHead: () => ({}) }))

vi.mock('pinia', async () => {
  const actual = await vi.importActual('pinia')
  return {
    ...actual,
    storeToRefs: () => ({
      showContactDetailsAskModal: mockChatStore.showContactDetailsAskModal,
      list: ref(mockChatStore.list),
    }),
  }
})

/* ── router / route mocks ── */
const mockRouteQuery = ref({})
const mockRouteParams = ref({})

vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return {
    ...actual,
    useRoute: () => ({
      params: mockRouteParams.value,
      query: mockRouteQuery.value,
      path: '/chats',
      name: 'chats',
      fullPath: '/chats',
      matched: [],
      redirectedFrom: undefined,
      meta: {},
    }),
    useRouter: () => ({
      push: vi.fn(),
      replace: vi.fn(),
      currentRoute: { value: { path: '/chats' } },
    }),
  }
})

/* ── Nuxt global macros ── */
globalThis.definePageMeta = vi.fn()
globalThis.useHead = vi.fn()
globalThis.useRuntimeConfig = () => ({ public: { BUILD_DATE: '2026-01-01' } })
globalThis.defineAsyncComponent = () => ({ template: '<div />' })

/* ── mount helper ── */
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
        'b-row': { template: '<div><slot /></div>' },
        'b-col': { template: '<div><slot /></div>' },
        'b-form-input': { template: '<input />' },
        'b-button': {
          template: '<button @click="$emit(\'click\')"><slot /></button>',
          emits: ['click'],
        },
        'b-badge': { template: '<span><slot /></span>' },
        'v-icon': { template: '<i />' },
        ChatPane: { template: '<div />' },
      },
    },
  })
}

/* ── tests ── */
describe('bug #9690 — chat list blanks when closed chat has deleted user', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    mockMe.value = { id: 1, displayname: 'Test User', settings: {} }
    mockRouteParams.value = {}
    mockRouteQuery.value = {}
    mockChatStore.list = []
    mockChatStore.showClosed = false
    mockChatStore.fetchChats = vi.fn().mockResolvedValue([])
    mockChatStore.byChatId = vi.fn().mockReturnValue(null)
    mockChatStore.searchSince = null
  })

  /*
   * STEP 2 — INVERTED assertion (commits here).
   *
   * On BUGGY code this test FAILS because:
   *   scanChats calls `chat.name.toLowerCase()` at line ~536; when chat.name
   *   is null (deleted user) and search is active this throws TypeError,
   *   the closedChats computed propagates the error, and Vue blanks the page
   *   so `.chatlist` is absent from the rendered output.
   *
   * After the fix (`chat.name?.toLowerCase()` or equivalent guard) the page
   * renders without error and `.chatlist` is present → test PASSES.
   */
  it(
    'renders the chat list without blanking when a hidden chat has a deleted user (name null)',
    async () => {
      /* Simulate a search term being active — this is what triggers the null
       * dereference: scanChats executes `chat.name.toLowerCase()` inside the
       * search-filter branch. */
      mockRouteQuery.value = { search: 'hello' }

      /* Two chats: one normal Active chat and one Closed chat whose other
       * party is a deleted user (name: null, the field the server omits). */
      mockChatStore.list = [
        {
          id: 1,
          status: 'Active',
          lastmsg: 5,
          lastdate: '2026-01-01T00:00:00Z',
          name: 'Normal User',
          snippet: 'hello there',
          unseen: 0,
        },
        {
          id: 2,
          status: 'Closed',
          lastmsg: 3,
          lastdate: '2026-01-01T00:00:00Z',
          name: null, /* deleted user — server returns null */
          snippet: '',
          unseen: 0,
        },
      ]
      /* The user has clicked "Show hidden/blocked chats" */
      mockChatStore.showClosed = true

      /* Suppress console output so the test output is clean.
       * Vue's render-error path calls both console.error and console.warn
       * (the warn triggers the test-setup throw, creating an unhandled
       * rejection that obscures the assertion failure — mock it here). */
      const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
      const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {})

      const wrapper = mountComponent()
      await flushPromises()
      await nextTick()

      /* After the fix the page renders correctly — .chatlist is in the DOM
       * and no render error was logged. */
      expect(wrapper.find('.chatlist').exists()).toBe(true)
      expect(errorSpy).not.toHaveBeenCalled()

      errorSpy.mockRestore()
      warnSpy.mockRestore()
    }
  )
})
