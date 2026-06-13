import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

import ModSysAdminDigestClicks from '~/modtools/components/ModSysAdminDigestClicks.vue'

// Mock email tracking store (only the bits this component touches).
const mockEmailTrackingStore = {
  digestPositions: [],
  digestPositionsLoading: false,
  digestPositionsError: null,
  hasDigestPositions: false,
  digestPositionsChartData: null,
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
          'b-form-select': {
            template:
              '<select :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value); $emit(\'change\', $event.target.value)"><slot /></select>',
            props: ['modelValue', 'options', 'size'],
          },
          'b-spinner': { template: '<span class="spinner" />' },
          'b-table': {
            template: '<table class="table"><slot /></table>',
            props: [
              'items',
              'fields',
              'striped',
              'hover',
              'responsive',
              'small',
            ],
          },
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
    mockEmailTrackingStore.digestPositionsChartData = null
  })

  describe('rendering', () => {
    it('explains the click-through metric', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('click-through rate')
    })

    it('renders the date filter', () => {
      const wrapper = mountComponent()
      expect(wrapper.find('.date-filter').exists()).toBe(true)
    })

    it('shows the empty message when there is no data', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('No digest click data available')
    })

    it('shows the chart when data is present', async () => {
      mockEmailTrackingStore.hasDigestPositions = true
      mockEmailTrackingStore.digestPositionsChartData = [
        ['Position', 'Click-through rate (%)', 'Emails shown'],
        ['1', 50, 100],
      ]
      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()
      expect(wrapper.find('.gchart').exists()).toBe(true)
      expect(wrapper.find('.gchart').attributes('data-type')).toBe('ComboChart')
    })

    it('shows an error message when the store has an error', async () => {
      mockEmailTrackingStore.digestPositionsError = 'boom'
      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()
      expect(wrapper.text()).toContain('boom')
    })
  })

  describe('digest type options', () => {
    it('offers All / Immediate / Daily', () => {
      const wrapper = mountComponent()
      const options = wrapper.vm.digestTypeOptions
      expect(options).toContainEqual({ text: 'All digests', value: '' })
      expect(options).toContainEqual({
        text: 'Immediate',
        value: 'UnifiedDigestImmediate',
      })
      expect(options).toContainEqual({
        text: 'Daily',
        value: 'UnifiedDigestDaily',
      })
    })
  })

  describe('tableRows', () => {
    it('formats positions as 1-based and CTR as a percentage', () => {
      mockEmailTrackingStore.digestPositions = [
        { position: 0, shown: 1000, clicks: 520, emails_clicked: 500, ctr: 50 },
        { position: 1, shown: 1000, clicks: 260, emails_clicked: 250, ctr: 25 },
      ]
      const wrapper = mountComponent()
      const rows = wrapper.vm.tableRows
      expect(rows[0].position).toBe(1)
      expect(rows[0].ctr).toBe('50.0%')
      expect(rows[0].shown).toBe('1,000')
      expect(rows[1].position).toBe(2)
      expect(rows[1].ctr).toBe('25.0%')
    })
  })

  describe('insight', () => {
    it('is null with fewer than two positions', () => {
      mockEmailTrackingStore.digestPositions = [
        { position: 0, shown: 10, clicks: 5, emails_clicked: 5, ctr: 50 },
      ]
      const wrapper = mountComponent()
      expect(wrapper.vm.insight).toBeNull()
    })

    it('describes the drop from the top position to the last', () => {
      mockEmailTrackingStore.digestPositions = [
        { position: 0, shown: 100, clicks: 80, emails_clicked: 80, ctr: 80 },
        { position: 2, shown: 100, clicks: 20, emails_clicked: 20, ctr: 20 },
      ]
      const wrapper = mountComponent()
      const text = wrapper.vm.insight
      expect(text).toContain('80.0%')
      expect(text).toContain('20.0%')
      expect(text).toContain('position 3')
      expect(text).toContain('75% lower')
    })

    it('is null when there is no drop (flat or rising)', () => {
      mockEmailTrackingStore.digestPositions = [
        { position: 0, shown: 100, clicks: 20, emails_clicked: 20, ctr: 20 },
        { position: 1, shown: 100, clicks: 30, emails_clicked: 30, ctr: 30 },
      ]
      const wrapper = mountComponent()
      expect(wrapper.vm.insight).toBeNull()
    })
  })

  describe('chart options', () => {
    it('returns a ComboChart configuration with two axes', () => {
      const wrapper = mountComponent()
      const options = wrapper.vm.getPositionChartOptions()
      expect(options.seriesType).toBe('bars')
      expect(options.vAxes[0].title).toBe('Click-through rate (%)')
      expect(options.vAxes[1].title).toBe('Emails shown')
      expect(options.series[1].type).toBe('line')
    })
  })

  describe('fetching', () => {
    it('onFilterFetch records the dates and fetches via the store', () => {
      const wrapper = mountComponent()
      wrapper.vm.onFilterFetch({ start: '2026-01-01', end: '2026-01-31' })

      expect(mockEmailTrackingStore.setFilters).toHaveBeenCalledWith({
        type: '',
        start: '2026-01-01',
        end: '2026-01-31',
      })
      expect(mockEmailTrackingStore.fetchDigestPositions).toHaveBeenCalled()
    })

    it('onTypeChange refetches only once dates are known', () => {
      const wrapper = mountComponent()

      // No dates yet → should not fetch.
      wrapper.vm.onTypeChange()
      expect(mockEmailTrackingStore.fetchDigestPositions).not.toHaveBeenCalled()

      // After a fetch sets the dates → type change refetches.
      wrapper.vm.onFilterFetch({ start: '2026-01-01', end: '2026-01-31' })
      mockEmailTrackingStore.fetchDigestPositions.mockClear()
      wrapper.vm.digestType = 'UnifiedDigestDaily'
      wrapper.vm.onTypeChange()
      expect(mockEmailTrackingStore.fetchDigestPositions).toHaveBeenCalled()
    })
  })
})
