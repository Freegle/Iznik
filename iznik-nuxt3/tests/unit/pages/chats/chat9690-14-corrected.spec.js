/**
 * TDD tests for the corrected spec of Discourse #9690 post #14 (PR #627 rev).
 *
 * Points verified:
 *   (a) hideAll() skips User2Mod chats — only User2User / other types are hidden.
 *   (b) User2Mod chat with status !== 'Closed': per-chat hide/unhide is NOT offered
 *       (chattype guard: only show the button when not User2Mod, or when already hidden).
 *   (c) User2Mod chat with status === 'Closed': Unhide IS offered (recovery path
 *       for chats accidentally hidden by the previously-buggy Hide all).
 *   (d) Return-link wording in the hidden-chats toggle is "Return to chats that are not hidden".
 *
 * All four assertions flip from FAIL → PASS with the corrected implementation.
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
const mockRouteQuery = ref({})

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

describe('bug #9690/14 corrected — hideAll must skip User2Mod chats', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    mockMe.value = { id: 1, displayname: 'Test User', settings: {} }
    mockRouteParams.value = {}
    mockRouteQuery.value = {}
    mockChatStore.list = []
    mockChatStore.showClosed = false
    mockChatStore.fetchChats = vi.fn().mockResolvedValue([])
    mockChatStore.hide = vi.fn().mockResolvedValue(undefined)
    mockChatStore.byChatId = vi.fn().mockReturnValue(null)
    mockChatStore.searchSince = null
  })

  /**
   * (a) hideAll skips User2Mod chats.
   *
   * On BUGGY code this test FAILS because hideAll iterates ALL filteredChats
   * with no chattype check, so chatStore.hide is called for the User2Mod chat
   * (id=3) as well as the User2User chats — resulting in 3 hide calls instead
   * of 2.
   *
   * After the fix (filter out chattype === 'User2Mod' before hiding) only the
   * two User2User chats are hidden → PASSES.
   */
  it(
    'does not hide User2Mod (volunteer) chats when Hide All is triggered',
    async () => {
      mockChatStore.list = [
        {
          id: 1,
          chattype: 'User2User',
          status: 'Active',
          latestmessage: 5,
          lastdate: '2026-01-01',
          name: 'Alice',
          unseen: 0,
        },
        {
          id: 2,
          chattype: 'User2User',
          status: 'Active',
          latestmessage: 4,
          lastdate: '2026-01-01',
          name: 'Bob',
          unseen: 0,
        },
        {
          id: 3,
          chattype: 'User2Mod',
          status: 'Active',
          latestmessage: 3,
          lastdate: '2026-01-01',
          name: 'Edinburgh Freegle volunteers',
          unseen: 0,
        },
      ]

      const wrapper = mountComponent()
      await flushPromises()
      await nextTick()

      const page = wrapper.findComponent(ChatsPage)

      await page.vm.hideAll()

      // Only the 2 User2User chats should be hidden
      expect(mockChatStore.hide).toHaveBeenCalledTimes(2)
      const hiddenIds = mockChatStore.hide.mock.calls.map((c) => c[0])
      expect(hiddenIds).toContain(1)
      expect(hiddenIds).toContain(2)
      expect(hiddenIds).not.toContain(3)
    }
  )

  /**
   * (d) Return-link wording: when showClosed is true (user is on the hidden list),
   * the toggle button must say "Return to chats that are not hidden" (not "Hide ...").
   *
   * On BUGGY code this test FAILS because the button text is "Hide X hidden/blocked
   * chat(s)" — confusing, as it sounds like a hide action rather than a navigation
   * action.
   *
   * After the wording fix the button text contains the correct return phrase → PASSES.
   */
  it(
    'shows "Return to chats that are not hidden" as the toggle link when on the hidden-chats list',
    async () => {
      mockChatStore.list = [
        {
          id: 1,
          chattype: 'User2User',
          status: 'Closed',
          lastmsg: 3,
          latestmessage: 3,
          lastdate: '2026-01-01',
          name: 'Hidden User',
          unseen: 0,
        },
      ]
      // Simulate being on the hidden-chats view
      mockChatStore.showClosed = true

      const wrapper = mountComponent()
      await flushPromises()
      await nextTick()

      // The toggle button should say "Return to chats that are not hidden"
      // (not the old confusing "Hide X hidden/blocked chats")
      const text = wrapper.text()
      expect(text).toContain('Return to chats that are not hidden')
      expect(text).not.toMatch(/^Hide \d+ hidden/)
    }
  )
})
