/**
 * AssertFlip test for bug #9690 post #9 — button says "Hide all chats" on the
 * hidden/blocked chats page, but the label should read "Delete all chats on
 * this page" in that context.
 *
 * When showClosed is true the user is viewing the hidden/blocked chat list.
 * The "Hide all chats" label is semantically wrong there (those chats are
 * already hidden). The fix makes the label context-aware: "Delete all chats on
 * this page" when showClosed is true, "Hide all chats" otherwise.
 *
 * AssertFlip protocol:
 *   STEP 1 — buggy behaviour: button says "Hide all chats" even on the
 *             hidden/blocked view. Verified PASSES on unpatched code.
 *   STEP 2 — inverted assertion committed here: button must say
 *             "Delete all chats on this page" when showClosed is true.
 *             FAILS on buggy code, PASSES after fix.
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
describe('bug #9690/9 — hide-all button label must reflect context (hidden vs regular chats)', () => {
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
   * On BUGGY code this FAILS because the button always says "Hide all chats"
   * regardless of whether we're viewing hidden/blocked chats (showClosed=true).
   *
   * After the fix (label becomes "Delete all chats on this page" when
   * showClosed is true) this test PASSES.
   */
  it(
    'shows "Delete all chats on this page" label when viewing hidden/blocked chats',
    async () => {
      // Simulate the hidden/blocked chats view.
      mockChatStore.showClosed = true
      mockChatStore.list = [
        { id: 1, status: 'Closed', latestmessage: 5, lastdate: '2026-01-01', name: 'Alice', unseen: 0 },
        { id: 2, status: 'Blocked', latestmessage: 4, lastdate: '2026-01-01', name: 'Bob', unseen: 0 },
      ]

      const wrapper = mountComponent()
      await flushPromises()
      await nextTick()

      const page = wrapper.findComponent(ChatsPage)

      // Force complete=true so the action button is rendered.
      page.vm.complete = true
      await nextTick()

      const button = wrapper.find('button.chat-action-btn')
      expect(button.exists()).toBe(true)
      expect(button.text()).toContain('Delete all chats on this page')
    }
  )

  it(
    'still shows "Hide all chats" label when viewing regular (non-hidden) chats',
    async () => {
      // Normal chats view (showClosed=false is the default).
      mockChatStore.showClosed = false
      mockChatStore.list = [
        { id: 3, status: 'Active', latestmessage: 5, lastdate: '2026-01-01', name: 'Carol', unseen: 0 },
        { id: 4, status: 'Active', latestmessage: 4, lastdate: '2026-01-01', name: 'Dave', unseen: 0 },
      ]

      const wrapper = mountComponent()
      await flushPromises()
      await nextTick()

      const page = wrapper.findComponent(ChatsPage)
      page.vm.complete = true
      await nextTick()

      const button = wrapper.find('button.chat-action-btn')
      expect(button.exists()).toBe(true)
      expect(button.text()).toContain('Hide all chats')
    }
  )
})
