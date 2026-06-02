/**
 * AssertFlip test for bug #9690 post #7 — "Hide all chats" only hides some.
 *
 * Root cause: hideAll() in pages/chats/[[id]].vue iterates visibleChats (a
 * paginated slice of filteredChats) instead of filteredChats itself. This
 * means chats beyond the current scroll position are never hidden. In
 * addition, the loop uses an index against the reactive computed, which
 * shrinks as each chat is hidden, causing every other remaining entry to be
 * skipped (mutate-while-iterating).
 *
 * Fix: capture filteredChats.value.map(c => c.id) into a plain array before
 * the loop so:
 *   (a) all filtered chats are included regardless of pagination, and
 *   (b) the array does not mutate during iteration.
 *
 * AssertFlip protocol:
 *   STEP 1 — buggy behaviour: hideAll hides only showChats entries (2),
 *             leaving the rest unhidden. Verified, then inverted.
 *   STEP 2 — inverted assertion committed here:
 *             hideAll must call chatStore.hide for every chat in
 *             filteredChats → FAILS on buggy code, PASSES after fix.
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
    template: '<div class="chat-list-entry" />',
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
  hide: vi.fn().mockResolvedValue(undefined),
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
const mockRouteParams = ref({})

vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return {
    ...actual,
    useRoute: () => ({
      params: mockRouteParams.value,
      query: {},
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
describe('bug #9690/7 — hideAll must hide every filtered chat, not just the visible page', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    mockMe.value = { id: 1, displayname: 'Test User', settings: {} }
    mockRouteParams.value = {}
    mockChatStore.list = []
    mockChatStore.showClosed = false
    mockChatStore.fetchChats = vi.fn().mockResolvedValue([])
    mockChatStore.hide = vi.fn().mockResolvedValue(undefined)
    mockChatStore.byChatId = vi.fn().mockReturnValue(null)
    mockChatStore.searchSince = null
  })

  /*
   * STEP 2 — INVERTED assertion (committed here).
   *
   * On BUGGY code this test FAILS because hideAll iterates visibleChats
   * (filteredChats.slice(0, showChats)), and showChats is set to 2, so
   * chatStore.hide is called only twice — leaving chats 3–5 unhidden.
   *
   * After the fix (iterate over filteredChats.value.map(c => c.id) captured
   * before the loop) all five chats are hidden in a single pass → PASSES.
   */
  it(
    'hides all filtered chats in one pass even when showChats < total chats',
    async () => {
      mockChatStore.list = [
        { id: 1, status: 'Active', latestmessage: 5, lastdate: '2026-01-01', name: 'Alice', unseen: 0 },
        { id: 2, status: 'Active', latestmessage: 4, lastdate: '2026-01-01', name: 'Bob', unseen: 0 },
        { id: 3, status: 'Active', latestmessage: 3, lastdate: '2026-01-01', name: 'Carol', unseen: 0 },
        { id: 4, status: 'Active', latestmessage: 2, lastdate: '2026-01-01', name: 'Dave', unseen: 0 },
        { id: 5, status: 'Active', latestmessage: 1, lastdate: '2026-01-01', name: 'Eve', unseen: 0 },
      ]

      const wrapper = mountComponent()
      await flushPromises()
      await nextTick()

      const page = wrapper.findComponent(ChatsPage)

      // Simulate a paginated view: only the first 2 chats are currently
      // rendered (user hasn't scrolled down to load more).
      page.vm.showChats = 2

      await page.vm.hideAll()

      // All 5 chats must be hidden — not just the 2 visible ones.
      expect(mockChatStore.hide).toHaveBeenCalledTimes(5)
      const hiddenIds = mockChatStore.hide.mock.calls.map((c) => c[0])
      expect(hiddenIds).toEqual(expect.arrayContaining([1, 2, 3, 4, 5]))
    }
  )
})
