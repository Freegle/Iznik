import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import NotesPage from '~/modtools/pages/members/notes/[[id]].vue'

// Self-moderating rework: this page used to filter mod notes by a chosen
// community (ModGroupSelect + groupid), always showing flagged/own notes
// as an exception. Moderators are national now, so every note is visible
// to every moderator - there is no group filter left to test.

// Mock comment store
const mockCommentStore = {
  sortedList: [],
  context: null,
  fetch: vi.fn().mockResolvedValue({}),
  clear: vi.fn(),
}

vi.mock('~/stores/comment', () => ({
  useCommentStore: () => mockCommentStore,
}))

describe('members/notes/[[id]].vue page', () => {
  function mountComponent() {
    return mount(NotesPage, {
      global: {
        plugins: [createPinia()],
        stubs: {
          'client-only': {
            template: '<div><slot /></div>',
          },
          ScrollToTop: {
            template: '<div class="scroll-to-top" />',
          },
          ModHelpComments: {
            template: '<div class="mod-help-comments" />',
          },
          ModCommentUser: {
            template:
              '<div class="mod-comment-user" :data-comment-id="commentid" />',
            props: ['commentid'],
          },
          NoticeMessage: {
            template: '<div class="notice-message"><slot /></div>',
            props: ['variant'],
          },
          'b-img': {
            template: '<img />',
            props: ['src', 'alt', 'lazy'],
          },
          'infinite-loading': {
            template:
              '<div class="infinite-loading"><slot name="no-results" /><slot name="no-more" /><slot name="spinner" /></div>',
            props: ['forceUseInfiniteWrapper', 'distance', 'identifier'],
            emits: ['infinite'],
          },
        },
      },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    mockCommentStore.sortedList = []
    mockCommentStore.context = null
  })

  describe('rendering', () => {
    it('shows empty message when no comments and not busy', async () => {
      mockCommentStore.sortedList = []
      const wrapper = mountComponent()
      wrapper.vm.busy = false
      await wrapper.vm.$nextTick()
      expect(wrapper.text()).toContain('no comments to show')
    })

    it('renders comment components for visible comments', async () => {
      mockCommentStore.sortedList = [
        { id: 1, flag: false, byuserid: 2 },
        { id: 2, flag: false, byuserid: 3 },
      ]
      const wrapper = mountComponent()
      wrapper.vm.show = 10
      await wrapper.vm.$nextTick()
      expect(wrapper.findAll('.mod-comment-user')).toHaveLength(2)
    })

    it('has no community picker - moderators are national', () => {
      const wrapper = mountComponent()
      expect(wrapper.find('.mod-group-select').exists()).toBe(false)
    })
  })

  describe('initial state', () => {
    it('clears comment store on mount', async () => {
      mountComponent()
      await flushPromises()
      expect(mockCommentStore.clear).toHaveBeenCalled()
    })
  })

  describe('computed properties', () => {
    it('comments returns sortedList from store', () => {
      mockCommentStore.sortedList = [{ id: 1 }, { id: 2 }]
      const wrapper = mountComponent()
      expect(wrapper.vm.comments).toHaveLength(2)
    })

    it('visibleComments returns every comment, regardless of group', () => {
      mockCommentStore.sortedList = [
        { id: 1, groupid: 10 },
        { id: 2, groupid: 20 },
      ]
      const wrapper = mountComponent()
      wrapper.vm.show = 2
      expect(wrapper.vm.visibleComments).toHaveLength(2)
    })

    it('visibleComments limits to show value', () => {
      mockCommentStore.sortedList = [{ id: 1 }, { id: 2 }, { id: 3 }]
      const wrapper = mountComponent()
      wrapper.vm.show = 2
      expect(wrapper.vm.visibleComments).toHaveLength(2)
    })
  })

  describe('methods', () => {
    describe('loadMore', () => {
      it('increments show when more comments available', async () => {
        mockCommentStore.sortedList = [{ id: 1 }, { id: 2 }]
        const wrapper = mountComponent()
        wrapper.vm.show = 1
        const mockState = { loaded: vi.fn(), complete: vi.fn() }

        await wrapper.vm.loadMore(mockState)

        expect(wrapper.vm.show).toBe(2)
        expect(mockState.loaded).toHaveBeenCalled()
      })

      it('fetches more comments nationally when show equals comments length', async () => {
        mockCommentStore.sortedList = [{ id: 1 }]
        const wrapper = mountComponent()
        wrapper.vm.show = 1
        const mockState = { loaded: vi.fn(), complete: vi.fn() }

        await wrapper.vm.loadMore(mockState)

        expect(mockCommentStore.fetch).toHaveBeenCalledWith({
          context: null,
        })
      })

      it('completes when no new comments returned', async () => {
        mockCommentStore.sortedList = []
        const wrapper = mountComponent()
        wrapper.vm.show = 0
        const mockState = { loaded: vi.fn(), complete: vi.fn() }

        await wrapper.vm.loadMore(mockState)

        expect(mockState.complete).toHaveBeenCalled()
        expect(wrapper.vm.complete).toBe(true)
      })

      it('handles fetch errors gracefully', async () => {
        mockCommentStore.fetch.mockRejectedValueOnce(new Error('Network error'))
        const wrapper = mountComponent()
        wrapper.vm.show = 0
        const mockState = { loaded: vi.fn(), complete: vi.fn() }

        await wrapper.vm.loadMore(mockState)

        expect(mockState.complete).toHaveBeenCalled()
        expect(wrapper.vm.busy).toBe(false)
      })
    })
  })
})
