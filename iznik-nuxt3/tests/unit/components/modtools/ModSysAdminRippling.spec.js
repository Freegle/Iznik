import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import ModSysAdminRippling from '~/modtools/components/ModSysAdminRippling.vue'

const mockFetchMetrics = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    rippling: {
      fetchMetrics: mockFetchMetrics,
    },
  }),
}))

vi.mock('#app', () => ({
  useRuntimeConfig: () => ({ public: { apiUrl: 'http://test' } }),
}))

function mountComponent() {
  return mount(ModSysAdminRippling, {
    global: {
      stubs: {
        'b-table-simple': { template: '<table><slot /></table>' },
        'b-thead': { template: '<thead><slot /></thead>' },
        'b-tbody': { template: '<tbody><slot /></tbody>' },
        'b-tr': { template: '<tr><slot /></tr>' },
        'b-th': { template: '<th><slot /></th>' },
        'b-td': { template: '<td><slot /></td>' },
        'b-badge': { template: '<span class="badge"><slot /></span>' },
        'b-spinner': { template: '<div class="spinner" />' },
        'b-form-group': { template: '<div><slot /></div>' },
        'b-form-select': {
          template: '<select><slot /></select>',
          props: ['modelValue'],
        },
        // Drive the initial fetch deterministically: the real filter fires
        // 'fetch' on mount, so the stub mirrors that with a fixed range.
        ModEmailDateFilter: {
          template: '<div class="date-filter" />',
          emits: ['fetch'],
          mounted() {
            this.$emit('fetch', { start: '2026-05-24', end: '2026-06-23' })
          },
        },
        GChart: {
          template: '<div class="gchart" />',
          props: ['type', 'data', 'options'],
        },
      },
    },
  })
}

describe('ModSysAdminRippling', () => {
  beforeEach(() => {
    mockFetchMetrics.mockReset()
  })

  it('passes the selected date range to the metrics API', async () => {
    mockFetchMetrics.mockResolvedValue({})

    const wrapper = mountComponent()
    await flushPromises()

    expect(mockFetchMetrics).toHaveBeenCalledWith(0, '2026-05-24', '2026-06-23')
    wrapper.unmount()
  })

  it('renders the headline KPI questions and a chart when there is series data', async () => {
    mockFetchMetrics.mockResolvedValue({
      reply_rate_36h: [{ day: '2026-06-18', reply_pct: 35 }],
      taken_rate: [{ day: '2026-06-18', taken_pct: 58 }],
    })

    const wrapper = mountComponent()
    await flushPromises()

    const html = wrapper.html()
    // Plain-English, decision-oriented headings replace the old jargon.
    expect(html).toContain('Are more offers getting a reply?')
    expect(html).toContain('Is more stuff actually being reused?')
    // Charts render once their series has data.
    expect(wrapper.findAll('.gchart').length).toBeGreaterThanOrEqual(1)
  })

  it('renders geographic hotspots as an action surface', async () => {
    mockFetchMetrics.mockResolvedValue({
      hotspots: [
        {
          area_id: 99,
          area_name: 'Anomaly Town',
          metric: 'secondary_reject_rate',
          value: 0.9,
          baseline: 0.1,
          deviation: 12.3,
          direction: 'high',
          severity: 'alert',
        },
      ],
    })

    const wrapper = mountComponent()
    await flushPromises()

    const html = wrapper.html()
    expect(html).toContain('Where to look')
    expect(html).toContain('Anomaly Town')
    expect(html).toContain('secondary_reject_rate')
    expect(html).toContain('alert')
  })

  it('shows an empty state when there are no hotspots', async () => {
    mockFetchMetrics.mockResolvedValue({ hotspots: [] })

    const wrapper = mountComponent()
    await flushPromises()

    expect(wrapper.html()).toContain('No hotspots flagged')
  })

  describe('held_reply_summary section', () => {
    it('renders the reply-friction heading', async () => {
      mockFetchMetrics.mockResolvedValue({})
      const wrapper = mountComponent()
      await flushPromises()
      expect(wrapper.html()).toContain('Reply friction')
    })

    it('shows "No held replies recorded yet" when the list is empty', async () => {
      mockFetchMetrics.mockResolvedValue({ held_reply_summary: [] })
      const wrapper = mountComponent()
      await flushPromises()
      expect(wrapper.html()).toContain('No held replies recorded yet')
    })

    it('renders held/released rows with counts from the API', async () => {
      mockFetchMetrics.mockResolvedValue({
        held_reply_summary: [
          { status: 'held', count: 3, median_hold_hours: 0 },
          { status: 'released', count: 1, median_hold_hours: 4.2 },
        ],
      })
      const wrapper = mountComponent()
      await flushPromises()
      const html = wrapper.html()
      expect(html).toContain('held')
      expect(html).toContain('released')
      expect(html).toContain('4.2')
    })
  })

  it('no longer renders the dropped absolute-number sections', async () => {
    mockFetchMetrics.mockResolvedValue({
      recent: [{ day: '2026-06-18', event: 'reply_blocked', count: 7 }],
      live_metrics: [
        { period_start: '2026-06-16', metric: 'volume_posts_p50', value: 42.5 },
      ],
      cross_group_summary: { rippled_in: 57, total: 300, cross_group_pct: 19 },
      capture_summary: { week_start: '2026-06-09', curve: 'front-heavy' },
      proposed_params: [{ ons_category: 'urban_major', max_minutes: 25 }],
    })

    const wrapper = mountComponent()
    await flushPromises()

    const html = wrapper.html()
    expect(html).not.toContain('Last 30 days')
    expect(html).not.toContain('Cross-group reach')
    expect(html).not.toContain('Volume')
    expect(html).not.toContain('simulator')
    expect(html).not.toContain('Proposed parameter changes')
  })
})
