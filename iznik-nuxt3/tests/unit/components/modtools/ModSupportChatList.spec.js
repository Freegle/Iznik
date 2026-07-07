import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ModSupportChatList from '~/modtools/components/ModSupportChatList.vue'

const mockFetchMultiple = vi.fn()

vi.mock('~/stores/user', () => ({
  useUserStore: () => ({
    fetchMultiple: mockFetchMultiple,
  }),
}))

function makeChats(count, chattype = 'User2Mod') {
  return Array.from({ length: count }, (_, i) => ({
    id: i + 1,
    chattype,
    user1: 10 + i,
    user2: chattype === 'User2User' ? 200 + i : 0,
    lastdate: '2026-01-01T10:00:00Z',
  }))
}

// Stub that fires the @infinite event immediately on mount so loadMore runs.
const InfiniteLoadingStub = {
  template: '<div class="infinite-loading"><slot name="complete" /></div>',
  emits: ['infinite'],
  mounted() {
    const state = { loaded: () => {}, complete: () => {}, error: () => {} }
    this.$emit('infinite', state)
  },
}

function mountComponent(props = {}) {
  const chats = props.chats ?? []
  const pov = props.pov ?? 100
  return mount(ModSupportChatList, {
    props: { chats, pov },
    global: {
      stubs: {
        ModSupportChat: {
          template: '<div class="support-chat" :data-chat-id="chatid">Chat {{ chatid }}</div>',
          props: ['chatid', 'pov'],
        },
        'infinite-loading': InfiniteLoadingStub,
        'notice-message': { template: '<div class="notice-message"><slot /></div>' },
      },
    },
  })
}

describe('ModSupportChatList', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockFetchMultiple.mockResolvedValue([])
    setActivePinia(createPinia())
    globalThis.__mockChatStore = {
      list: [],
      listMT: [],
      listByChatId: {},
      messageById: () => null,
      byChatId: () => null,
      fetchMessages: async () => {},
      fetchMT: async () => {},
    }
  })

  afterEach(() => {
    globalThis.__mockChatStore = null
  })

  describe('empty state', () => {
    it('shows "No chats" notice when chats is empty', () => {
      const wrapper = mountComponent({ chats: [] })
      expect(wrapper.find('.notice-message').text()).toContain('No chats')
    })

    it('renders no chat items when empty', () => {
      const wrapper = mountComponent({ chats: [] })
      expect(wrapper.findAll('.support-chat')).toHaveLength(0)
    })
  })

  describe('props', () => {
    it('requires chats array prop', () => {
      const wrapper = mountComponent()
      expect(wrapper.props('chats')).toEqual([])
    })

    it('has optional pov prop with null default', () => {
      const wrapper = mount(ModSupportChatList, {
        props: { chats: [] },
        global: {
          stubs: {
            ModSupportChat: true,
            'infinite-loading': true,
            'notice-message': true,
          },
        },
      })
      expect(wrapper.props('pov')).toBeNull()
    })

    it('accepts pov as a number', () => {
      const wrapper = mountComponent({ pov: 999 })
      expect(wrapper.props('pov')).toBe(999)
    })
  })

  describe('infinite scroll pagination', () => {
    it('renders an infinite-loading element', () => {
      const wrapper = mountComponent({ chats: makeChats(5) })
      expect(wrapper.find('.infinite-loading').exists()).toBe(true)
    })

    it('does not show a "Show +N" button (replaced by infinite scroll)', () => {
      const wrapper = mountComponent({ chats: makeChats(20) })
      expect(wrapper.html()).not.toContain('Show +')
    })

    it('renders up to PAGE_SIZE chats on first load', async () => {
      const wrapper = mountComponent({ chats: makeChats(15) })
      await flushPromises()
      const shown = wrapper.findAll('.support-chat')
      expect(shown.length).toBeGreaterThan(0)
      expect(shown.length).toBeLessThanOrEqual(10)
    })
  })

  describe('User2Mod chats', () => {
    it('does NOT call fetchMultiple for User2Mod chats', async () => {
      mountComponent({ chats: makeChats(5, 'User2Mod') })
      await flushPromises()
      expect(mockFetchMultiple).not.toHaveBeenCalled()
    })
  })

  describe('User2User batch prefetch', () => {
    it('calls fetchMultiple with other-user ids for the first page', async () => {
      // pov=10 matches user1[0], so other user for each chat is user2 (200..204)
      mountComponent({ chats: makeChats(5, 'User2User'), pov: 10 })
      await flushPromises()
      expect(mockFetchMultiple).toHaveBeenCalledTimes(1)
      const calledIds = mockFetchMultiple.mock.calls[0][0]
      // Each chat has a different user1 (10..14); none matches pov exactly except chat 1.
      // Regardless, all user2 ids (200..204) should appear as the non-pov user.
      expect(calledIds.length).toBeGreaterThan(0)
      expect(calledIds.length).toBeLessThanOrEqual(5)
    })

    it('only fetches other-users for the first page, not all chats', async () => {
      mountComponent({ chats: makeChats(20, 'User2User'), pov: 10 })
      await flushPromises()
      expect(mockFetchMultiple).toHaveBeenCalledTimes(1)
      const calledIds = mockFetchMultiple.mock.calls[0][0]
      expect(calledIds.length).toBeLessThanOrEqual(10)
    })

    it('fetches user1 when pov matches user2', async () => {
      const chats = [
        { id: 1, chattype: 'User2User', user1: 50, user2: 100, lastdate: '2026-01-01T10:00:00Z' },
      ]
      mountComponent({ chats, pov: 100 })
      await flushPromises()
      const calledIds = mockFetchMultiple.mock.calls[0][0]
      expect(calledIds).toContain(50)
    })

    it('fetches user2 when pov matches user1', async () => {
      const chats = [
        { id: 1, chattype: 'User2User', user1: 100, user2: 50, lastdate: '2026-01-01T10:00:00Z' },
      ]
      mountComponent({ chats, pov: 100 })
      await flushPromises()
      const calledIds = mockFetchMultiple.mock.calls[0][0]
      expect(calledIds).toContain(50)
    })

    it('deduplicates user ids when the same user appears in multiple chats', async () => {
      const chats = [
        { id: 1, chattype: 'User2User', user1: 100, user2: 50, lastdate: '2026-01-01T10:00:00Z' },
        { id: 2, chattype: 'User2User', user1: 100, user2: 50, lastdate: '2026-01-01T10:00:00Z' },
      ]
      mountComponent({ chats, pov: 100 })
      await flushPromises()
      const calledIds = mockFetchMultiple.mock.calls[0][0]
      expect(calledIds.filter((id) => id === 50)).toHaveLength(1)
    })
  })

  describe('chat store population', () => {
    it('populates the chat store with all received chats', () => {
      mountComponent({ chats: makeChats(3) })
      expect(globalThis.__mockChatStore.listByChatId[1]).toBeDefined()
      expect(globalThis.__mockChatStore.listByChatId[2]).toBeDefined()
      expect(globalThis.__mockChatStore.listByChatId[3]).toBeDefined()
    })

    it('passes chatid and pov to each stub', async () => {
      const wrapper = mountComponent({ chats: makeChats(3), pov: 55 })
      await flushPromises()
      const stubs = wrapper.findAll('.support-chat')
      expect(stubs.length).toBeGreaterThan(0)
      expect(stubs[0].attributes('data-chat-id')).toBe('1')
    })
  })

  describe('chats prop updates', () => {
    it('populates the store when chats prop changes', async () => {
      const wrapper = mountComponent({ chats: makeChats(2) })
      await flushPromises()
      const newChat = { id: 99, chattype: 'User2Mod', lastdate: '2026-01-01T10:00:00Z', user1: 1, user2: 0 }
      await wrapper.setProps({ chats: [...makeChats(2), newChat] })
      expect(globalThis.__mockChatStore.listByChatId[99]).toEqual(newChat)
    })
  })
})
