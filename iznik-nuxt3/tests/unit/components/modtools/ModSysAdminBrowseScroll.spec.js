import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import ModSysAdminBrowseScroll from '~/modtools/components/ModSysAdminBrowseScroll.vue'

const mockFetchScrollDepth = vi
  .fn()
  .mockResolvedValue({ total: 0, positions: [] })

vi.mock('~/api', () => ({
  default: () => ({ browse: { fetchScrollDepth: mockFetchScrollDepth } }),
}))

vi.mock('#app', () => ({
  useRuntimeConfig: () => ({ public: { APIv2: 'http://test/apiv2' } }),
}))

vi.mock('vue-google-charts', () => ({
  GChart: {
    template: '<div class="gchart" :data-type="type" />',
    props: ['type', 'data', 'options'],
  },
}))

describe('ModSysAdminBrowseScroll', () => {
  function mountComponent() {
    return mount(ModSysAdminBrowseScroll, {
      global: {
        stubs: {
          NoticeMessage: {
            template:
              '<div class="notice-message" :class="variant"><slot /></div>',
            props: ['variant'],
          },
          'b-spinner': { template: '<span class="spinner" />' },
          'b-table': {
            template: '<table class="table" />',
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
    mockFetchScrollDepth.mockResolvedValue({ total: 0, positions: [] })
  })

  // Drive a fetch with a given curve, then return the mounted wrapper.
  async function withPositions(positions, total = 1000) {
    mockFetchScrollDepth.mockResolvedValue({ total, positions })
    const wrapper = mountComponent()
    wrapper.vm.onFilterFetch({ start: '2026-01-01', end: '2026-01-31' })
    await flushPromises()
    return wrapper
  }

  describe('rendering', () => {
    it('explains the browse-feed scroll metric', () => {
      expect(mountComponent().text().toLowerCase()).toContain('browse feed')
    })

    it('renders the date filter', () => {
      expect(mountComponent().find('.date-filter').exists()).toBe(true)
    })

    it('shows the empty message before any data is fetched', () => {
      expect(mountComponent().text()).toContain('No browse scroll data')
    })

    it('shows the chart once data is present', async () => {
      const wrapper = await withPositions([
        { position: 0, sessions_reaching: 10, pct: 100 },
      ])
      expect(wrapper.find('.gchart').exists()).toBe(true)
      expect(wrapper.find('.gchart').attributes('data-type')).toBe('LineChart')
    })
  })

  describe('tableRows', () => {
    it('formats position, sessions reaching, and percentage', async () => {
      const wrapper = await withPositions([
        { position: 0, sessions_reaching: 1000, pct: 100 },
        { position: 5, sessions_reaching: 620, pct: 62 },
      ])
      const rows = wrapper.vm.tableRows
      expect(rows[0]).toMatchObject({
        position: 0,
        sessions_reaching: '1,000',
        pct: '100.0%',
      })
      expect(rows[1]).toMatchObject({
        position: 5,
        sessions_reaching: '620',
        pct: '62.0%',
      })
    })
  })

  describe('chartData', () => {
    it('maps each position to its percentage reaching', async () => {
      const wrapper = await withPositions([
        { position: 0, sessions_reaching: 1000, pct: 100 },
        { position: 1, sessions_reaching: 900, pct: 90 },
      ])
      expect(wrapper.vm.chartData).toEqual([
        ['Feed position', '% of sessions reaching'],
        [0, 100],
        [1, 90],
      ])
    })
  })

  describe('insight', () => {
    it('names the position where reach first falls below half', async () => {
      const wrapper = await withPositions([
        { position: 0, sessions_reaching: 1000, pct: 100 },
        { position: 7, sessions_reaching: 480, pct: 48 },
      ])
      expect(wrapper.vm.insight).toContain('position 7')
    })

    it('is null when reach never drops below half', async () => {
      const wrapper = await withPositions([
        { position: 0, sessions_reaching: 1000, pct: 100 },
        { position: 1, sessions_reaching: 900, pct: 90 },
      ])
      expect(wrapper.vm.insight).toBeNull()
    })
  })

  describe('fetching', () => {
    it('onFilterFetch fetches via api.browse.fetchScrollDepth with the dates', async () => {
      const wrapper = mountComponent()
      wrapper.vm.onFilterFetch({ start: '2026-01-01', end: '2026-01-31' })
      await flushPromises()
      expect(mockFetchScrollDepth).toHaveBeenCalledWith({
        start: '2026-01-01',
        end: '2026-01-31',
      })
    })
  })
})
