import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import Feedback from '~/modtools/pages/members/feedback.vue'

// Mock $api
const mockDashboardFetch = vi.fn().mockResolvedValue({})

// Mock useNuxtApp
vi.mock('#app', () => ({
  useNuxtApp: () => ({
    $api: {
      dashboard: {
        fetch: mockDashboardFetch,
      },
    },
  }),
}))

// Mock member store
const mockMemberStore = {
  list: {},
  ratings: [],
  clear: vi.fn().mockResolvedValue({}),
  fetchMembers: vi.fn().mockResolvedValue([]),
  happinessReviewed: vi.fn().mockResolvedValue({}),
}

// Mock user store
const mockUserStore = {
  ratingReviewed: vi.fn().mockResolvedValue({}),
}

// Mock modMembers composable return values
const mockGroupid = ref(0)
const mockBusy = ref(false)
const mockContext = ref(null)
const mockShow = ref(0)
const mockCollection = ref('')
const mockDistance = ref(100)
const mockSummary = ref(false)
const mockMembers = ref([])
const mockFilter = ref('')
const mockLoadMore = vi.fn()

vi.mock('~/stores/member', () => ({
  useMemberStore: () => mockMemberStore,
}))

vi.mock('~/stores/user', () => ({
  useUserStore: () => mockUserStore,
}))

vi.mock('~/composables/useModMembers', () => ({
  setupModMembers: () => ({
    busy: mockBusy,
    context: mockContext,
    groupid: mockGroupid,
    limit: ref(1000),
    show: mockShow,
    collection: mockCollection,
    distance: mockDistance,
    summary: mockSummary,
    members: mockMembers,
    filter: mockFilter,
    loadMore: mockLoadMore,
  }),
}))

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    fetchMe: vi.fn().mockResolvedValue({}),
  }),
}))

describe('Feedback Page', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockMemberStore.list = {}
    mockMemberStore.ratings = []
    mockGroupid.value = 0
    mockBusy.value = false
    mockContext.value = null
    mockShow.value = 0
    mockCollection.value = ''
    mockMembers.value = []
    mockFilter.value = ''
    mockDashboardFetch.mockResolvedValue({})
  })

  function mountComponent() {
    return mount(Feedback, {
      global: {
        stubs: {
          'client-only': { template: '<div><slot /></div>' },
          ScrollToTop: { template: '<div class="scroll-to-top" />' },
          ModHelpFeedback: { template: '<div class="mod-help-feedback" />' },
          ModGroupSelect: {
            template: '<select class="mod-group-select"><slot /></select>',
            props: ['modelValue', 'modonly', 'all', 'remember'],
          },
          'b-tabs': {
            template: '<div class="b-tabs"><slot /></div>',
            props: ['modelValue', 'contentClass', 'card'],
          },
          'b-tab': {
            template: '<div class="b-tab"><slot /><slot name="title" /></div>',
            props: ['active'],
          },
          'b-form-select': {
            template: '<select class="b-form-select"><slot /></select>',
            props: ['modelValue'],
          },
          'b-form-checkbox': {
            template:
              '<label class="b-form-checkbox"><input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)" /><slot /></label>',
            props: ['modelValue'],
            emits: ['update:modelValue'],
          },
          'b-button': {
            template:
              '<button class="b-button" @click="$emit(\'click\')"><slot /></button>',
            props: ['variant'],
          },
          'b-card': {
            template:
              '<div class="b-card"><slot /><slot name="default" /></div>',
            props: ['variant'],
          },
          'b-card-text': {
            template: '<div class="b-card-text"><slot /></div>',
          },
          GChart: {
            template: '<div class="gchart" />',
            props: ['type', 'data', 'options'],
          },
          NoticeMessage: {
            template: '<div class="notice-message"><slot /></div>',
          },
          ModMemberHappiness: {
            template: '<div class="mod-member-happiness" :data-id="id" />',
            props: ['id'],
          },
          ModMemberRating: {
            template:
              '<div class="mod-member-rating" :data-rating-id="ratingid" />',
            props: ['ratingid'],
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
          'b-img': { template: '<img />' },
        },
        mocks: {
          $api: {
            dashboard: {
              fetch: mockDashboardFetch,
            },
          },
          $nextTick: (fn) => Promise.resolve().then(fn),
        },
      },
    })
  }

  describe('rendering', () => {

    it('shows empty message when no members and not busy', async () => {
      mockMembers.value = []
      mockBusy.value = false
      const wrapper = mountComponent()
      await flushPromises()

      expect(wrapper.find('.notice-message').exists()).toBe(true)
    })

  })

  describe('getHappiness method', () => {

    it('populates happinessData from response', async () => {
      mockDashboardFetch.mockResolvedValue({
        Happiness: [
          { happiness: 'Happy', count: 10 },
          { happiness: 'Unhappy', count: 3 },
        ],
      })

      const wrapper = mountComponent()
      await flushPromises()

      expect(wrapper.vm.happinessData).toEqual([
        ['Feedback', 'Count'],
        ['Happy', 10],
        ['Unhappy', 3],
      ])
    })
  })

  describe('data properties', () => {

    it('initializes happinessData as empty array', () => {
      const wrapper = mountComponent()
      // After mount, it gets populated from getHappiness
      expect(Array.isArray(wrapper.vm.happinessData)).toBe(true)
    })

  })
})
