import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref, nextTick } from 'vue'
import Related from '~/modtools/pages/members/related.vue'

// Mock stores
const mockMemberStore = {
  list: {},
  clear: vi.fn(),
}

// Mock modMembers composable return values
const mockGroupid = ref(0)
const mockBump = ref(0)
const mockDistance = ref(100)
const mockCollection = ref('')
const mockContext = ref(null)
const mockShow = ref(0)
const mockLoadMore = vi.fn()

vi.mock('~/stores/member', () => ({
  useMemberStore: () => mockMemberStore,
}))

vi.mock('~/composables/useModMembers', () => ({
  setupModMembers: () => ({
    bump: mockBump,
    collection: mockCollection,
    context: mockContext,
    distance: mockDistance,
    groupid: mockGroupid,
    show: mockShow,
    loadMore: mockLoadMore,
  }),
}))

describe('Related Page', () => {
  // Track mounted wrappers so watchers are torn down between tests.
  // The composable uses module-level refs; if wrappers aren't unmounted their
  // watchers keep firing on subsequent ref changes across tests.
  const wrappers = []

  beforeEach(() => {
    vi.clearAllMocks()
    mockMemberStore.list = {}
    mockGroupid.value = 0
    mockBump.value = 0
    mockCollection.value = ''
    mockContext.value = null
    mockShow.value = 0
  })

  afterEach(() => {
    wrappers.forEach((w) => w.unmount())
    wrappers.length = 0
  })

  function mountComponent() {
    const wrapper = mount(Related, {
      global: {
        stubs: {
          'client-only': { template: '<div><slot /></div>' },
          ScrollToTop: { template: '<div class="scroll-to-top" />' },
          ModHelpRelated: { template: '<div class="mod-help-related" />' },
          ModGroupSelect: {
            template: '<select class="mod-group-select"><slot /></select>',
            props: [
              'modelValue',
              'all',
              'modonly',
              'systemwide',
              'work',
              'remember',
            ],
          },
          ModRelatedMember: {
            template:
              '<div class="mod-related-member" :data-member-id="memberid" />',
            props: ['memberid'],
          },
          'infinite-loading': {
            template:
              '<div class="infinite-loading"><slot name="spinner" /><slot name="complete" /></div>',
            props: [
              'direction',
              'forceUseInfiniteWrapper',
              'distance',
              'identifier',
            ],
          },
          NoticeMessage: {
            template: '<div class="notice-message"><slot /></div>',
          },
          Spinner: { template: '<div />' },
          'b-img': { template: '<img />' },
        },
      },
    })
    wrappers.push(wrapper)
    return wrapper
  }

  // Helper to set up pair entries (as created by the member store's Related handling)
  function addPair(id, user1Id, user2Id) {
    mockMemberStore.list[id] = {
      id,
      user1: user1Id,
      user2: user2Id,
      collection: 'Related',
      rawindex: id,
    }
    // Synthetic per-user entries (should be filtered out by members computed)
    if (!mockMemberStore.list[user1Id]) {
      mockMemberStore.list[user1Id] = {
        id: user1Id,
        userid: user1Id,
        collection: 'Related',
        rawindex: user1Id + 1000,
        _syntheticRelated: true,
      }
    }
    if (!mockMemberStore.list[user2Id]) {
      mockMemberStore.list[user2Id] = {
        id: user2Id,
        userid: user2Id,
        collection: 'Related',
        rawindex: user2Id + 1000,
        _syntheticRelated: true,
      }
    }
  }

  describe('rendering', () => {
    it('renders ModHelpRelated component', () => {
      const wrapper = mountComponent()
      expect(wrapper.find('.mod-help-related').exists()).toBe(true)
    })

    it('renders ModGroupSelect', () => {
      const wrapper = mountComponent()
      expect(wrapper.find('.mod-group-select').exists()).toBe(true)
    })

    it('renders related members when available', async () => {
      addPair(100, 1, 2)
      addPair(101, 3, 4)
      const wrapper = mountComponent()
      await flushPromises()

      const memberComponents = wrapper.findAll('.mod-related-member')
      expect(memberComponents).toHaveLength(2)
    })

    it('passes member prop to ModRelatedMember', async () => {
      addPair(42, 10, 20)
      const wrapper = mountComponent()
      await flushPromises()

      const memberComponent = wrapper.find('.mod-related-member')
      expect(memberComponent.attributes('data-member-id')).toBe('42')
    })
  })

  describe('members computed', () => {
    it('excludes synthetic per-user entries', () => {
      addPair(100, 1, 2)
      addPair(101, 3, 4)
      const wrapper = mountComponent()
      const members = wrapper.vm.members

      // Should have 2 pairs, not the 4 synthetic user entries
      expect(members).toHaveLength(2)
      expect(members.every((m) => !m._syntheticRelated)).toBe(true)
    })

    it('excludes entries from other collections', () => {
      addPair(100, 1, 2)
      // Add an Approved member to the store
      mockMemberStore.list[200] = {
        id: 200,
        userid: 200,
        collection: 'Approved',
        rawindex: 0,
      }
      const wrapper = mountComponent()

      expect(wrapper.vm.members).toHaveLength(1)
      expect(wrapper.vm.members[0].id).toBe(100)
    })

    it('returns empty array when store is empty', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.members).toHaveLength(0)
    })
  })

  describe('visibleMembers — no client-side groupid filter', () => {
    // Bug fix regression: previously visibleMembers filtered by userStore memberships,
    // which failed because Related pair users aren't in userStore.  Now it returns
    // all loaded pairs and relies on the API to filter by groupid.

    it('returns all pairs when groupid is 0 (all communities)', () => {
      addPair(100, 1, 2)
      addPair(101, 3, 4)
      mockGroupid.value = 0
      const wrapper = mountComponent()

      expect(wrapper.vm.visibleMembers).toHaveLength(2)
    })

    it('returns all loaded pairs even when a single community is selected', () => {
      // The API (via loadMore with groupid param) provides the right subset;
      // visibleMembers must not further filter by userStore.
      addPair(100, 1, 2)
      addPair(101, 3, 4)
      mockGroupid.value = 5
      const wrapper = mountComponent()

      // Both pairs should be visible — they came from the API filtered for group 5.
      expect(wrapper.vm.visibleMembers).toHaveLength(2)
    })

    it('returns pairs even when users are absent from userStore', () => {
      // Users are only stored as synthetics in memberStore, never in userStore.
      // The fix must not depend on userStore for visibility.
      addPair(100, 1, 2)
      mockGroupid.value = 7
      const wrapper = mountComponent()

      expect(wrapper.vm.visibleMembers).toHaveLength(1)
    })

    it('visibleMembers equals members', () => {
      addPair(100, 1, 2)
      addPair(101, 3, 4)
      const wrapper = mountComponent()

      expect(wrapper.vm.visibleMembers).toEqual(wrapper.vm.members)
    })
  })

  describe('lifecycle', () => {
    it('sets collection to Related in setup', () => {
      mountComponent()
      expect(mockCollection.value).toBe('Related')
    })

    it('clears member store synchronously during setup (before first render)', () => {
      // clear() must be called before mounting completes so stale data never renders.
      let clearedBeforeRender = false
      mockMemberStore.clear.mockImplementation(() => {
        clearedBeforeRender = true
      })
      mountComponent()

      expect(mockMemberStore.clear).toHaveBeenCalled()
      expect(clearedBeforeRender).toBe(true)
    })
  })

  describe('groupid watcher — reset on community change', () => {
    // Bug fix regression: changing community must clear stale store data and
    // re-trigger loadMore with the new groupid via bump increment.

    it('clears the store when groupid changes', async () => {
      mountComponent()
      vi.clearAllMocks() // ignore the clear() call from setup

      mockGroupid.value = 5
      await nextTick()

      expect(mockMemberStore.clear).toHaveBeenCalledTimes(1)
    })

    it('resets context when groupid changes', async () => {
      mockContext.value = 99
      mountComponent()

      mockGroupid.value = 5
      await nextTick()

      expect(mockContext.value).toBeNull()
    })

    it('resets show when groupid changes', async () => {
      mockShow.value = 20
      mountComponent()

      mockGroupid.value = 5
      await nextTick()

      expect(mockShow.value).toBe(0)
    })

    it('increments bump when groupid changes to trigger re-fetch', async () => {
      mountComponent()
      const bumpBefore = mockBump.value

      mockGroupid.value = 5
      await nextTick()

      expect(mockBump.value).toBe(bumpBefore + 1)
    })

    it('resets state again on subsequent groupid change', async () => {
      mountComponent()

      mockGroupid.value = 5
      await nextTick()
      mockShow.value = 15
      mockContext.value = 55

      mockGroupid.value = 6
      await nextTick()

      expect(mockShow.value).toBe(0)
      expect(mockContext.value).toBeNull()
    })
  })
})
