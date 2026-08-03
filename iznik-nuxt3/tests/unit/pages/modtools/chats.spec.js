import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'
import ChatsPage from '~/modtools/pages/chats/[[id]].vue'

// Mock dayjs
vi.mock('dayjs', () => {
  const mockDayjs = (date) => ({
    diff: vi.fn().mockReturnValue(0),
  })
  return { default: mockDayjs }
})

// Mock chat store
const mockChatStore = {
  list: [],
  searchSince: null,
  listChatsMT: vi.fn().mockResolvedValue([]),
  markRead: vi.fn().mockResolvedValue({}),
  markAllReadMT: vi.fn().mockResolvedValue({}),
  clear: vi.fn(),
}

vi.mock('~/stores/chat', () => ({
  useChatStore: () => mockChatStore,
}))

// Mock auth store
const mockAuthStore = {
  user: { id: 1, lat: 0, lng: 0 },
  fetchUser: vi.fn(),
}

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => mockAuthStore,
}))

// Mock route params
const mockRouteParams = ref({ id: undefined })
const mockRouterPush = vi.fn()

vi.hoisted(() => {
  vi.resetModules()
})

vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return {
    ...actual,
    useRoute: () => ({
      params: mockRouteParams.value,
      query: {},
      path: '/',
      name: 'modtools-chats',
      fullPath: '/',
      matched: [],
      redirectedFrom: undefined,
      meta: {},
    }),
    useRouter: () => ({
      push: mockRouterPush,
    }),
  }
})

globalThis.__testUseRoute = () => ({
  params: mockRouteParams.value,
  query: {},
  path: '/',
  name: 'modtools-chats',
  fullPath: '/',
  matched: [],
  redirectedFrom: undefined,
  meta: {},
})
globalThis.__testUseRouter = () => ({
  push: mockRouterPush,
})

describe('chats/[[id]].vue page', () => {
  function mountComponent() {
    return mount(ChatsPage, {
      global: {
        plugins: [createPinia()],
        stubs: {
          'client-only': {
            template: '<div><slot /></div>',
          },
          'b-row': {
            template: '<div><slot /></div>',
            props: ['class'],
          },
          'b-col': {
            template: '<div><slot /></div>',
            props: ['cols', 'md', 'class'],
          },
          'b-card': {
            template: '<div><slot /></div>',
            props: ['class'],
          },
          'b-card-body': {
            template: '<div><slot /></div>',
            props: ['class'],
          },
          'b-form-input': {
            template: '<input />',
            props: ['modelValue', 'placeholder', 'class'],
          },
          'b-button': {
            template: '<button @click="$emit(\'click\')"><slot /></button>',
            props: ['variant', 'class'],
          },
          ChatListEntry: {
            template: '<div class="chat-list-entry" />',
            props: ['id', 'class'],
          },
          ModChatPane: {
            template: '<div class="mod-chat-pane" />',
            props: ['id'],
          },
          'v-icon': {
            template: '<i />',
            props: ['icon', 'class'],
          },
          'infinite-loading': {
            template:
              '<div class="infinite-loading"><slot name="no-results" /><slot name="no-more" /></div>',
            props: ['identifier', 'forceUseInfiniteWrapper', 'distance'],
            emits: ['infinite'],
          },
        },
      },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    mockRouteParams.value = { id: undefined }
    mockChatStore.list = []
    mockChatStore.searchSince = null
    mockAuthStore.user = { id: 1, lat: 0, lng: 0 }
    mockAuthStore.fetchUser = vi.fn().mockResolvedValue(mockAuthStore.user)
  })

  describe('initial state', () => {
    it('sets selectedChatId from route param', () => {
      mockRouteParams.value = { id: '123' }
      const wrapper = mountComponent()
      expect(wrapper.vm.selectedChatId).toBe(123)
    })

    it('calls listChats on mount', async () => {
      mountComponent()
      await flushPromises()
      expect(mockChatStore.listChatsMT).toHaveBeenCalled()
    })
  })

  describe('computed properties', () => {
    it('chats returns store list', () => {
      mockChatStore.list = [{ id: 1 }, { id: 2 }]
      const wrapper = mountComponent()
      expect(wrapper.vm.chats).toHaveLength(2)
    })

    it('visibleChats slices to showChats limit', () => {
      mockChatStore.list = [
        { id: 1, status: 'Active' },
        { id: 2, status: 'Active' },
        { id: 3, status: 'Active' },
      ]
      const wrapper = mountComponent()
      wrapper.vm.showChats = 2
      wrapper.vm.bump = Date.now()
      expect(wrapper.vm.visibleChats.length).toBeLessThanOrEqual(2)
    })
  })

  describe('methods', () => {
    it('gotoChat navigates to chat page', () => {
      const wrapper = mountComponent()
      wrapper.vm.gotoChat(456)
      expect(mockRouterPush).toHaveBeenCalledWith('/chats/456')
    })

    it('loadMore increments showChats', () => {
      mockChatStore.list = [
        { id: 1, status: 'Active' },
        { id: 2, status: 'Active' },
      ]
      const wrapper = mountComponent()
      wrapper.vm.showChats = 0
      const mockState = { loaded: vi.fn(), complete: vi.fn() }
      wrapper.vm.loadMore(mockState)
      expect(wrapper.vm.showChats).toBe(1)
      expect(mockState.loaded).toHaveBeenCalled()
    })

    it('loadMore always calls loaded (never complete) when all chats shown', () => {
      mockChatStore.list = [{ id: 1, status: 'Active' }]
      const wrapper = mountComponent()
      wrapper.vm.showChats = 1
      const mockState = { loaded: vi.fn(), complete: vi.fn() }
      wrapper.vm.loadMore(mockState)
      // Chat list loadMore never calls complete() — the list can grow asynchronously
      expect(mockState.loaded).toHaveBeenCalled()
    })

    it('markAllRead clears store and reloads', async () => {
      mockChatStore.list = [{ id: 1, unseen: 5 }]
      const wrapper = mountComponent()
      await wrapper.vm.markAllRead()
      expect(mockChatStore.markAllReadMT).toHaveBeenCalled()
      expect(mockChatStore.clear).toHaveBeenCalled()
    })

    it('scanChats filters by search', () => {
      const wrapper = mountComponent()
      wrapper.vm.searching = true
      wrapper.vm.search = 'test'
      const chats = [
        { id: 1, name: 'Test Chat', status: 'Active' },
        { id: 2, name: 'Other Chat', status: 'Active' },
      ]
      const filtered = wrapper.vm.scanChats(false, chats)
      expect(filtered).toHaveLength(1)
      expect(filtered[0].name).toBe('Test Chat')
    })
  })

  describe('session expiry on chat load (Discourse 9881)', () => {
    // Mount first so the automatic onMounted() -> listChats() call consumes
    // the default (resolving) mock, then queue the scenario for the explicit
    // wrapper.vm.listChats() call made by each test below.
    async function mountAndSettle() {
      const wrapper = mountComponent()
      await flushPromises()
      return wrapper
    }

    it('redirects home instead of throwing a raw API error when the session has genuinely expired', async () => {
      const wrapper = await mountAndSettle()

      // Simulate a 401 from the chat list endpoint, as happens when the
      // moderator's session has expired.
      mockChatStore.listChatsMT.mockRejectedValueOnce({
        response: { status: 401 },
        message: 'API Error GET /chat?chattypes=... -> status: 401',
      })

      // The authoritative /session check (authStore.fetchUser) confirms the
      // session really is dead by clearing the user.
      mockAuthStore.fetchUser = vi.fn().mockImplementation(() => {
        mockAuthStore.user = null
        return Promise.resolve(null)
      })

      // Should not reject / bubble up as an unhandled API error.
      await expect(wrapper.vm.listChats()).resolves.toBeUndefined()

      expect(mockAuthStore.fetchUser).toHaveBeenCalled()
      expect(mockRouterPush).toHaveBeenCalledWith('/')
    })

    it('does not redirect when a 401 turns out to be transient (session still valid)', async () => {
      const wrapper = await mountAndSettle()

      mockChatStore.listChatsMT.mockRejectedValueOnce({
        response: { status: 401 },
        message: 'API Error GET /chat?chattypes=... -> status: 401',
      })

      // The authoritative /session check says we're still logged in - a
      // background 401 elsewhere shouldn't force a redirect (Discourse #9893).
      mockAuthStore.fetchUser = vi.fn().mockResolvedValue(mockAuthStore.user)

      await expect(wrapper.vm.listChats()).resolves.toBeUndefined()

      expect(mockAuthStore.fetchUser).toHaveBeenCalled()
      expect(mockRouterPush).not.toHaveBeenCalledWith('/')
    })

    it('still throws (and does not redirect) for non-401 errors', async () => {
      const wrapper = await mountAndSettle()

      mockChatStore.listChatsMT.mockRejectedValueOnce(
        new Error('Network error')
      )

      await expect(wrapper.vm.listChats()).rejects.toThrow('Network error')
      expect(mockAuthStore.fetchUser).not.toHaveBeenCalled()
      expect(mockRouterPush).not.toHaveBeenCalledWith('/')
    })
  })

  describe('watchers', () => {
    it('bumps when search changes', async () => {
      const wrapper = mountComponent()
      const initialBump = wrapper.vm.bump
      wrapper.vm.search = 'test'
      await wrapper.vm.$nextTick()
      expect(wrapper.vm.bump).not.toBe(initialBump)
    })
  })
})
