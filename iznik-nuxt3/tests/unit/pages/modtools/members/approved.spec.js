import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'
import ApprovedPage from '~/modtools/pages/members/approved/[[term]].vue'

// Self-moderating rework: this page used to be a per-community membership
// browser (ModGroupSelect + groupid, [[id]]/[[term]] route). It's now a
// national member-lookup tool: a numeric route term is a direct id lookup
// (the links other components use to jump to a member), anything else is a
// name/email search via the shared useModMembers composable's
// filter=search contract. These tests replace the groupid-based suite.
const mockBump = ref(0)
const mockSearch = ref('')
const mockFilter = ref('new')
const mockDistance = ref(10)
const mockMembers = ref([])
const mockLoadMore = vi.fn()

vi.mock('@/composables/useModMembers', () => ({
  setupModMembers: () => ({
    bump: mockBump,
    search: mockSearch,
    filter: mockFilter,
    distance: mockDistance,
    members: mockMembers,
    loadMore: mockLoadMore,
  }),
}))

const mockFetch = vi.fn()
const mockClear = vi.fn()

vi.mock('~/modtools/stores/member', () => ({
  useMemberStore: () => ({
    fetch: mockFetch,
    clear: mockClear,
    list: {},
  }),
}))

const mockRouteParams = ref({ term: undefined })
const mockRouterPush = vi.fn()

vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return {
    ...actual,
    useRoute: () => ({ params: mockRouteParams.value }),
    useRouter: () => ({
      push: mockRouterPush,
      currentRoute: {
        value: {
          path: mockRouteParams.value.term
            ? '/members/approved/' + mockRouteParams.value.term
            : '/members/approved/',
        },
      },
    }),
  }
})

describe('members/approved/[[term]].vue page', () => {
  function mountComponent() {
    return mount(ApprovedPage, {
      global: {
        plugins: [createPinia()],
        stubs: {
          'client-only': { template: '<div><slot /></div>' },
          ModMemberSearchbox: {
            template: '<div class="mod-member-searchbox" />',
            props: ['search'],
            emits: ['search'],
          },
          ModMember: {
            template: '<div class="mod-member" />',
            props: ['membershipid'],
          },
          ModMembers: { template: '<div class="mod-members" />' },
          NoticeMessage: {
            template: '<div class="notice-message"><slot /></div>',
            props: ['variant'],
          },
          Spinner: { template: '<div class="spinner" />', props: ['size'] },
          'infinite-loading': {
            template:
              '<div class="infinite-loading"><slot name="spinner" /><slot name="complete" /></div>',
            props: ['direction', 'distance', 'identifier'],
            emits: ['infinite'],
          },
        },
      },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    mockBump.value = 0
    mockSearch.value = ''
    mockFilter.value = 'new'
    mockMembers.value = []
    mockRouteParams.value = { term: undefined }
  })

  describe('rendering', () => {
    it('prompts to search when there is no term', async () => {
      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()
      expect(wrapper.text()).toContain('Search for a member')
      expect(wrapper.find('.mod-member').exists()).toBe(false)
      expect(wrapper.find('.mod-members').exists()).toBe(false)
    })

    it('shows the member card for a numeric route term', async () => {
      mockRouteParams.value = { term: '456' }
      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()
      expect(wrapper.find('.mod-member').exists()).toBe(true)
    })

    it('shows the member list for a text route term', async () => {
      mockRouteParams.value = { term: 'smith' }
      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()
      expect(wrapper.find('.mod-members').exists()).toBe(true)
    })
  })

  describe('mounted lifecycle', () => {
    it('sets filter to search', () => {
      mountComponent()
      expect(mockFilter.value).toBe('search')
    })

    it('fetches a single member directly for a numeric term', async () => {
      mockRouteParams.value = { term: '789' }
      mountComponent()
      await Promise.resolve()
      expect(mockFetch).toHaveBeenCalledWith(789)
      expect(mockSearch.value).toBe('')
    })

    it('sets search and bumps for a text term', async () => {
      mockRouteParams.value = { term: 'jones' }
      mountComponent()
      expect(mockSearch.value).toBe('jones')
      expect(mockClear).toHaveBeenCalled()
    })

    it('leaves search empty with no term', () => {
      mockRouteParams.value = { term: undefined }
      mountComponent()
      expect(mockSearch.value).toBe('')
    })
  })

  describe('startsearch', () => {
    it('navigates to the id route for a numeric search', async () => {
      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()
      mockRouterPush.mockClear()

      wrapper.vm.startsearch('123')

      expect(mockRouterPush).toHaveBeenCalledWith('/members/approved/123')
      expect(mockFetch).toHaveBeenCalledWith(123)
    })

    it('navigates to the term route for a text search', async () => {
      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()
      mockRouterPush.mockClear()

      wrapper.vm.startsearch('smith')

      expect(mockRouterPush).toHaveBeenCalledWith('/members/approved/smith')
      expect(mockSearch.value).toBe('smith')
    })

    it('strips a leading hash from the term', async () => {
      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()
      mockRouterPush.mockClear()

      wrapper.vm.startsearch('#smith')

      expect(mockSearch.value).toBe('smith')
      expect(mockRouterPush).toHaveBeenCalledWith('/members/approved/smith')
    })

    it('encodes a term containing URL punctuation', async () => {
      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()
      mockRouterPush.mockClear()

      wrapper.vm.startsearch('Derek/Bill Roberts')

      expect(mockSearch.value).toBe('Derek/Bill Roberts')
      expect(mockRouterPush).toHaveBeenCalledWith(
        '/members/approved/Derek%2FBill%20Roberts'
      )
    })

    it('keeps searching when the term is only a hash', async () => {
      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()
      mockRouterPush.mockClear()

      wrapper.vm.startsearch('#')

      expect(mockSearch.value).toBe('')
    })

    it('navigates to the bare page for an empty search', async () => {
      mockRouteParams.value = { term: 'smith' }
      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()
      mockRouterPush.mockClear()

      wrapper.vm.startsearch('')

      expect(mockRouterPush).toHaveBeenCalledWith('/members/approved/')
    })
  })
})
