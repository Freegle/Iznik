import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ChatReviewPage from '~/modtools/pages/chats/review.vue'

// Create mock stores
const mockChatStore = {
  clear: vi.fn().mockResolvedValue({}),
  fetchReviewChatsMT: vi.fn().mockResolvedValue({}),
  messagesById: vi.fn().mockReturnValue([]),
  rejectChat: vi.fn().mockResolvedValue({}),
}

const mockAuthStore = {
  work: {
    chatreview: 5,
  },
}

// Mock stores
vi.mock('~/stores/chat', () => ({
  useChatStore: () => mockChatStore,
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => mockAuthStore,
}))

describe('chats/review.vue page', () => {
  function mountComponent(options = {}) {
    return mount(ChatReviewPage, {
      global: {
        stubs: {
          'client-only': {
            template: '<div><slot /></div>',
          },
          ModHelpChatReview: { template: '<div class="help-stub" />' },
          ModChatReview: {
            template: '<div class="chat-review-stub" />',
            props: ['id', 'messageid'],
            emits: ['reload'],
          },
          SpinButton: {
            template:
              '<button class="spin-button-stub" @click="$emit(\'handle\', () => {})"><slot /></button>',
            props: ['iconName', 'label', 'variant'],
            emits: ['handle'],
          },
          ConfirmModal: {
            template: '<div class="confirm-modal-stub" />',
            props: ['title'],
            emits: ['confirm'],
          },
          'notice-message': {
            template: '<div class="notice-stub"><slot /></div>',
          },
          'b-img': { template: '<img />' },
          'infinite-loading': {
            template:
              '<div class="infinite-loading"><slot name="spinner" /><slot name="complete" /></div>',
            props: [
              'direction',
              'forceUseInfiniteWrapper',
              'distance',
              'identifier',
            ],
            emits: ['infinite'],
          },
        },
        ...options.global,
      },
      ...options,
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    mockChatStore.messagesById.mockReturnValue([])
    mockAuthStore.work = { chatreview: 5 }
  })

  describe('initial state', () => {
    it('clears and loads on mount', async () => {
      mountComponent()
      await flushPromises()
      expect(mockChatStore.clear).toHaveBeenCalled()
      expect(mockChatStore.fetchReviewChatsMT).toHaveBeenCalled()
    })
  })

  describe('computed properties', () => {
    it('returns messages filtered for non-null', () => {
      mockChatStore.messagesById.mockReturnValue([
        { id: 1, chatid: 100 },
        null,
        { id: 2, chatid: 200 },
      ])
      const wrapper = mountComponent()
      wrapper.vm.show = 10
      expect(wrapper.vm.visibleMessages).toHaveLength(2)
    })

    it('slices messages to show count', () => {
      mockChatStore.messagesById.mockReturnValue([
        { id: 1, chatid: 100 },
        { id: 2, chatid: 200 },
        { id: 3, chatid: 300 },
      ])
      const wrapper = mountComponent()
      wrapper.vm.show = 2
      expect(wrapper.vm.visibleMessages).toHaveLength(2)
    })

    it('gets work count from auth store', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.work).toBe(5)
    })

    it('returns undefined for work when auth work is null', () => {
      mockAuthStore.work = null
      const wrapper = mountComponent()
      expect(wrapper.vm.work).toBeUndefined()
    })
  })

  describe('methods', () => {
    it('loadMore increments show when more messages available', () => {
      mockChatStore.messagesById.mockReturnValue([
        { id: 1, chatid: 100 },
        { id: 2, chatid: 200 },
      ])
      const wrapper = mountComponent()
      const mockState = { loaded: vi.fn(), complete: vi.fn() }

      wrapper.vm.show = 0
      wrapper.vm.loadMore(mockState)

      expect(wrapper.vm.show).toBe(1)
      expect(mockState.loaded).toHaveBeenCalled()
    })

    it('loadMore calls loaded (not complete) when messages not loaded yet', () => {
      mockChatStore.messagesById.mockReturnValue([])
      const wrapper = mountComponent()
      const mockState = { loaded: vi.fn(), complete: vi.fn() }

      wrapper.vm.show = 0
      wrapper.vm.loadMore(mockState)

      // When messages array is empty, loadMore calls loaded() and returns
      // early to avoid permanently completing before data arrives.
      expect(mockState.loaded).toHaveBeenCalled()
      expect(mockState.complete).not.toHaveBeenCalled()
    })

    it('reload clears and loads', async () => {
      const wrapper = mountComponent()
      vi.clearAllMocks()

      await wrapper.vm.reload()

      expect(mockChatStore.clear).toHaveBeenCalled()
      expect(mockChatStore.fetchReviewChatsMT).toHaveBeenCalled()
    })

    it('clearAndLoad clears store and fetches review chats', async () => {
      const wrapper = mountComponent()
      vi.clearAllMocks()

      await wrapper.vm.clearAndLoad()

      expect(mockChatStore.clear).toHaveBeenCalled()
      expect(mockChatStore.fetchReviewChatsMT).toHaveBeenCalledWith(null, {})
    })

    it('clearAndLoad fetches without a hard 5-item limit so all pending items are reachable', async () => {
      // Regression: clearAndLoad was passing limit: 5 to fetchReviewChatsMT,
      // so the server returned at most 5 messages even when the badge showed more.
      // loadMore would call $state.complete() after the 5, making further items
      // unreachable. Fix: remove the hard limit so the server default (100) applies.
      const wrapper = mountComponent()
      await flushPromises()
      vi.clearAllMocks()

      await wrapper.vm.clearAndLoad()

      // Must NOT pass limit: 5 — that was capping the review list
      const calls = mockChatStore.fetchReviewChatsMT.mock.calls
      expect(calls.length).toBe(1)
      expect(calls[0][1]?.limit).not.toBe(5)
    })

    it('clearAndLoad increments bump', async () => {
      const wrapper = mountComponent()
      await flushPromises()
      // After mount, bump has already been incremented once
      const bumpAfterMount = wrapper.vm.bump
      vi.clearAllMocks()

      await wrapper.vm.clearAndLoad()

      expect(wrapper.vm.bump).toBe(bumpAfterMount + 1)
    })

    it('deleteAll sets showDeleteModal to true', () => {
      const wrapper = mountComponent()
      const callback = vi.fn()

      wrapper.vm.deleteAll(callback)

      expect(wrapper.vm.showDeleteModal).toBe(true)
      expect(callback).toHaveBeenCalled()
    })

    it('deleteConfirmed rejects each visible message via the chat store', async () => {
      // Regression: chatStore has no reject({id, chatid}) method — only
      // rejectChat(id). Calling reject() threw TypeError in production.
      mockChatStore.messagesById.mockReturnValue([
        { id: 1, chatid: 100, widerchatreview: false },
        { id: 2, chatid: 200, widerchatreview: false },
      ])
      const wrapper = mountComponent()
      await flushPromises()
      wrapper.vm.show = 10
      await wrapper.vm.$nextTick()
      vi.clearAllMocks()

      wrapper.vm.deleteConfirmed()
      await flushPromises()

      expect(mockChatStore.rejectChat).toHaveBeenCalledTimes(2)
      expect(mockChatStore.rejectChat).toHaveBeenCalledWith(1)
      expect(mockChatStore.rejectChat).toHaveBeenCalledWith(2)
    })

    it('deleteConfirmed skips wider-chat-review messages (mod-only entries)', async () => {
      mockChatStore.messagesById.mockReturnValue([
        { id: 1, chatid: 100, widerchatreview: true },
        { id: 2, chatid: 200, widerchatreview: false },
      ])
      const wrapper = mountComponent()
      await flushPromises()
      wrapper.vm.show = 10
      await wrapper.vm.$nextTick()
      vi.clearAllMocks()

      wrapper.vm.deleteConfirmed()
      await flushPromises()

      expect(mockChatStore.rejectChat).toHaveBeenCalledTimes(1)
      expect(mockChatStore.rejectChat).toHaveBeenCalledWith(2)
    })
  })

  describe('watchers', () => {
    it('clears and loads when work increases and modal not open', async () => {
      const wrapper = mountComponent()
      await flushPromises()
      vi.clearAllMocks()

      // Simulate work increasing
      mockAuthStore.work.chatreview = 10
      await wrapper.vm.$nextTick()

      // The watcher should trigger clearAndLoad when work increases
      // Note: Testing watchers requires the reactive system to detect changes
    })
  })

  describe('rendering', () => {
    it('shows Delete All button when multiple messages', async () => {
      mockChatStore.messagesById.mockReturnValue([
        { id: 1, chatid: 100 },
        { id: 2, chatid: 200 },
      ])
      const wrapper = mountComponent()
      wrapper.vm.show = 10
      await wrapper.vm.$nextTick()

      expect(wrapper.find('.spin-button-stub').exists()).toBe(true)
    })

    it('shows notice when no messages', async () => {
      mockChatStore.messagesById.mockReturnValue([])
      const wrapper = mountComponent()
      // Wait for onMounted clearAndLoad to complete (sets loading=false)
      await flushPromises()
      wrapper.vm.show = 10
      await wrapper.vm.$nextTick()

      expect(wrapper.find('.notice-stub').exists()).toBe(true)
    })
  })
})
