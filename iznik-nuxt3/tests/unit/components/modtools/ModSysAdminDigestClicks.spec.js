import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

import ModSysAdminDigestClicks from '~/modtools/components/ModSysAdminDigestClicks.vue'

// Mock email tracking store (only the bits this component touches).
const mockEmailTrackingStore = {
  digestPositions: [],
  digestPositionsLoading: false,
  digestPositionsError: null,
  hasDigestPositions: false,
  setFilters: vi.fn(),
  fetchDigestPositions: vi.fn().mockResolvedValue({}),
}

vi.mock('~/modtools/stores/emailtracking', () => ({
  useEmailTrackingStore: () => mockEmailTrackingStore,
}))

vi.mock('#app', () => ({
  useRuntimeConfig: () => ({ public: { apiUrl: 'http://test' } }),
}))

vi.mock('vue-google-charts', () => ({
  GChart: {
    template: '<div class="gchart" :data-type="type" />',
    props: ['type', 'data', 'options'],
  },
}))

describe('ModSysAdminDigestClicks', () => {
  function mountComponent() {
    return mount(ModSysAdminDigestClicks, {
      global: {
        stubs: {
          NoticeMessage: {
            template:
              '<div class="notice-message" :class="variant"><slot /></div>',
            props: ['variant'],
          },
          'b-spinner': { template: '<span class="spinner" />' },
          GChart: {
            template: '<div class="gchart" :data-type="type" />',
            props: ['type', 'data', 'options'],
          },
          ModEmailDateFilter: {
            template: '<div class="date-filter" />',
            props: ['loading', 'fetchLabel', 'defaultPreset'],
          },
        },
      },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    mockEmailTrackingStore.digestPositions = []
    mockEmailTrackingStore.digestPositionsLoading = false
    mockEmailTrackingStore.digestPositionsError = null
    mockEmailTrackingStore.hasDigestPositions = false
  })

  describe('rendering', () => {
    it('explains the click-through metric and its denominator', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('click-through rate')
      expect(wrapper.text().toLowerCase()).toContain(
        'digests that actually showed a post'
      )
    })

    it('renders the date filter', () => {
      const wrapper = mountComponent()
      expect(wrapper.find('.date-filter').exists()).toBe(true)
    })

    it('shows the empty message when there is no data', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('No daily-digest click data available')
    })

    it('shows a single-series CTR chart when data is present', async () => {
      mockEmailTrackingStore.hasDigestPositions = true
      mockEmailTrackingStore.digestPositions = [
        {
          position: 0,
          shown: 200000,
          emails_clicked: 1000,
          clicks: 1200,
          ctr: 0.5,
        },
        {
          position: 1,
          shown: 100000,
          emails_clicked: 300,
          clicks: 350,
          ctr: 0.3,
        },
      ]
      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()
      expect(wrapper.find('.gchart').exists()).toBe(true)
      expect(wrapper.find('.gchart').attributes('data-type')).toBe('ComboChart')
      // Two columns only - no "Emails shown" series.
      expect(wrapper.vm.chartData[0]).toEqual([
        'Position',
        'Click-through rate (%)',
      ])
      expect(wrapper.vm.chartData[1]).toEqual([1, 0.5])
    })

    it('shows an error message when the store has an error', async () => {
      mockEmailTrackingStore.digestPositionsError = 'boom'
      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()
      expect(wrapper.text()).toContain('boom')
    })
  })

  describe('digest type', () => {
    it('is pinned to the daily digest (immediate is always position 1)', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.digestType).toBe('UnifiedDigestDaily')
    })
  })

  describe('sample-size cutoff', () => {
    it('drops positions whose sample is too small to trust', () => {
      mockEmailTrackingStore.digestPositions = [
        { position: 0, shown: 200000, emails_clicked: 1000, ctr: 0.5 },
        { position: 1, shown: 100000, emails_clicked: 300, ctr: 0.3 },
        // Deep tail: a single click off 31 digests reads as 3.2% - noise.
        { position: 329, shown: 31, emails_clicked: 1, ctr: 3.2 },
      ]
      const wrapper = mountComponent()
      expect(wrapper.vm.significantPositions.map((p) => p.position)).toEqual([
        0, 1,
      ])
      // The 3.2% spike is not plotted.
      expect(wrapper.vm.chartData.some((r) => r[1] === 3.2)).toBe(false)
    })
  })

  describe('insight', () => {
    it('is null with fewer than two well-sampled positions', () => {
      mockEmailTrackingStore.digestPositions = [
        { position: 0, shown: 200000, emails_clicked: 1000, ctr: 0.5 },
        { position: 329, shown: 31, emails_clicked: 1, ctr: 3.2 },
      ]
      const wrapper = mountComponent()
      expect(wrapper.vm.insight).toBeNull()
    })

    it('describes the decline using well-sampled positions, not the noisy tail', () => {
      mockEmailTrackingStore.digestPositions = [
        { position: 0, shown: 200000, emails_clicked: 1160, ctr: 0.58 },
        { position: 4, shown: 150000, emails_clicked: 345, ctr: 0.23 },
        { position: 29, shown: 100000, emails_clicked: 70, ctr: 0.07 },
        { position: 329, shown: 31, emails_clicked: 1, ctr: 3.2 },
      ]
      const wrapper = mountComponent()
      const text = wrapper.vm.insight
      expect(text).toContain('0.58%')
      expect(text).toContain('position 5')
      expect(text).toContain('0.07%')
      expect(text).toContain('position 30')
      expect(text).not.toContain('3.2') // the noisy tail spike is not used
      expect(text.toLowerCase()).toContain('declin')
    })

    it('is null when clicks do not decline', () => {
      mockEmailTrackingStore.digestPositions = [
        { position: 0, shown: 200000, emails_clicked: 200, ctr: 0.1 },
        { position: 1, shown: 150000, emails_clicked: 450, ctr: 0.3 },
      ]
      const wrapper = mountComponent()
      expect(wrapper.vm.insight).toBeNull()
    })
  })

  describe('summary', () => {
    it('reports the total digests and the cutoff position', () => {
      mockEmailTrackingStore.digestPositions = [
        { position: 0, shown: 200000, emails_clicked: 1000, ctr: 0.5 },
        { position: 1, shown: 100000, emails_clicked: 300, ctr: 0.3 },
      ]
      const wrapper = mountComponent()
      expect(wrapper.vm.summary).toContain('200,000')
      expect(wrapper.vm.summary).toContain('position 2')
    })
  })

  describe('chart options', () => {
    it('uses a single click-through-rate axis (no emails-shown axis)', () => {
      const wrapper = mountComponent()
      const options = wrapper.vm.getPositionChartOptions()
      expect(options.vAxis.title).toBe('Click-through rate (%)')
      expect(options.vAxes).toBeUndefined()
      expect(options.series[0].type).toBe('line')
      expect(options.series[1]).toBeUndefined()
    })
  })

  describe('fetching', () => {
    it('onFilterFetch records the dates and fetches via the store', () => {
      const wrapper = mountComponent()
      wrapper.vm.onFilterFetch({ start: '2026-01-01', end: '2026-01-31' })

      expect(mockEmailTrackingStore.setFilters).toHaveBeenCalledWith({
        type: 'UnifiedDigestDaily',
        start: '2026-01-01',
        end: '2026-01-31',
        cohort: '',
      })
      expect(mockEmailTrackingStore.fetchDigestPositions).toHaveBeenCalled()
    })
  })
})
