import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
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

describe('Feedback Page', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockDashboardFetch.mockResolvedValue({})
  })

  function mountComponent() {
    return mount(Feedback, {
      global: {
        stubs: {
          'client-only': { template: '<div><slot /></div>' },
          ScrollToTop: { template: '<div class="scroll-to-top" />' },
          ModHelpFeedback: { template: '<div class="mod-help-feedback" />' },
          'b-card': {
            template: '<div class="b-card"><slot /></div>',
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
        },
        mocks: {
          $api: {
            dashboard: {
              fetch: mockDashboardFetch,
            },
          },
        },
      },
    })
  }

  describe('rendering', () => {
    it('renders ModHelpFeedback component', () => {
      const wrapper = mountComponent()
      expect(wrapper.find('.mod-help-feedback').exists()).toBe(true)
    })

    it('shows the empty notice when there is no happiness data', async () => {
      mockDashboardFetch.mockResolvedValue({})
      const wrapper = mountComponent()
      await flushPromises()

      expect(wrapper.find('.notice-message').exists()).toBe(true)
      expect(wrapper.find('.b-card').exists()).toBe(false)
    })

    it('shows the happiness chart card when data is present', async () => {
      mockDashboardFetch.mockResolvedValue({
        Happiness: [{ happiness: 'Happy', count: 5 }],
      })
      const wrapper = mountComponent()
      await flushPromises()

      expect(wrapper.find('.b-card').exists()).toBe(true)
      expect(wrapper.find('.notice-message').exists()).toBe(false)
      expect(wrapper.findAll('.gchart')).toHaveLength(2)
    })

    it('mentions Freegle-wide feedback rather than a single community', async () => {
      mockDashboardFetch.mockResolvedValue({
        Happiness: [{ happiness: 'Happy', count: 5 }],
      })
      const wrapper = mountComponent()
      await flushPromises()

      expect(wrapper.text()).toContain('across all of Freegle')
    })
  })

  describe('getHappiness method', () => {
    it('calls getHappiness on mount', async () => {
      mountComponent()
      await flushPromises()

      expect(mockDashboardFetch).toHaveBeenCalled()
    })

    it('always fetches systemwide, with no group scoping', async () => {
      mountComponent()
      await flushPromises()

      expect(mockDashboardFetch).toHaveBeenCalledWith(
        expect.objectContaining({
          components: ['Happiness'],
          systemwide: true,
        })
      )

      const callArgs = mockDashboardFetch.mock.calls[0][0]
      expect(callArgs).not.toHaveProperty('groupid')
      expect(callArgs).not.toHaveProperty('allgroups')
      expect(callArgs).not.toHaveProperty('group')
    })

    it('populates happinessData from the response', async () => {
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
    it('initializes happinessData as an array', () => {
      const wrapper = mountComponent()
      expect(Array.isArray(wrapper.vm.happinessData)).toBe(true)
    })

    it('initializes happinessOptions with chart configuration', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.happinessOptions).toHaveProperty('chartArea')
      expect(wrapper.vm.happinessOptions.colors).toEqual([
        'green',
        '#f8f9fa',
        'orange',
      ])
    })
  })
})
