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

  it('renders the event totals from the metrics endpoint', async () => {
    mockFetchMetrics.mockResolvedValue({
      totals: [
        { day: '', event: 'reply_blocked', count: 7 },
        { day: '', event: 'held', count: 3 },
      ],
      recent: [{ day: '2026-06-18', event: 'reply_blocked', count: 7 }],
    })

    const wrapper = mountComponent()
    await flushPromises()

    expect(mockFetchMetrics).toHaveBeenCalled()
    const html = wrapper.html()
    expect(html).toContain('reply_blocked')
    expect(html).toContain('held')
    expect(html).toContain('7')
  })

  it('shows an empty state when there are no events', async () => {
    mockFetchMetrics.mockResolvedValue({ totals: [], recent: [] })

    const wrapper = mountComponent()
    await flushPromises()

    expect(wrapper.html()).toContain('No rippling events recorded yet')
  })

  it('renders geographic hotspots and proposed parameter changes', async () => {
    mockFetchMetrics.mockResolvedValue({
      totals: [],
      recent: [],
      hotspots: [
        {
          period_start: '2026-06-11',
          area_type: 'group',
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
      proposed_params: [
        {
          ons_category: 'urban_major',
          max_minutes: 25,
          rationale: 'volume delta +80% outside band; propose to tighten reach',
          proposed_at: '2026-06-18 09:00',
        },
      ],
    })

    const wrapper = mountComponent()
    await flushPromises()

    const html = wrapper.html()
    expect(html).toContain('Anomaly Town')
    expect(html).toContain('secondary_reject_rate')
    expect(html).toContain('alert')
    expect(html).toContain('urban_major')
    expect(html).toContain('tighten reach')
  })

  it('shows empty states for hotspots and proposals when there are none', async () => {
    mockFetchMetrics.mockResolvedValue({
      totals: [],
      recent: [],
      hotspots: [],
      proposed_params: [],
    })

    const wrapper = mountComponent()
    await flushPromises()

    expect(wrapper.html()).toContain('No hotspots flagged')
    expect(wrapper.html()).toContain('No proposals')
  })

  // §16.1/§16.2 — volume & reach weekly rollup
  describe('live_metrics section', () => {
    it('shows "No rollup data yet" when live_metrics is empty or absent', async () => {
      mockFetchMetrics.mockResolvedValue({ totals: [], recent: [] })
      const wrapper = mountComponent()
      await flushPromises()
      expect(wrapper.html()).toContain('No rollup data yet')
    })

    it('renders live metric rows returned by the API', async () => {
      mockFetchMetrics.mockResolvedValue({
        totals: [],
        recent: [],
        live_metrics: [
          {
            period_start: '2026-06-16',
            metric: 'volume_posts_p50',
            value: 42.5,
            sample_size: 80,
          },
        ],
      })
      const wrapper = mountComponent()
      await flushPromises()
      const html = wrapper.html()
      expect(html).toContain('volume_posts_p50')
      expect(html).toContain('42.5')
      expect(html).toContain('2026-06-16')
    })
  })

  // §16.3 — cross-group reach summary
  describe('cross_group_summary section', () => {
    it('renders the cross-group reach heading', async () => {
      mockFetchMetrics.mockResolvedValue({ totals: [], recent: [] })
      const wrapper = mountComponent()
      await flushPromises()
      expect(wrapper.html()).toContain('Cross-group reach')
    })

    it('shows the rippled-in count and cross_group_pct from the API', async () => {
      mockFetchMetrics.mockResolvedValue({
        totals: [],
        recent: [],
        cross_group_summary: {
          period_days: 30,
          rippled_in: 57,
          total: 300,
          cross_group_pct: 19.0,
          approval_rate: 72.0,
        },
      })
      const wrapper = mountComponent()
      await flushPromises()
      const html = wrapper.html()
      expect(html).toContain('57')
      expect(html).toContain('19.0%')
    })

    it('shows — for approval_rate when rippled_in is zero', async () => {
      mockFetchMetrics.mockResolvedValue({
        totals: [],
        recent: [],
        cross_group_summary: {
          period_days: 30,
          rippled_in: 0,
          total: 100,
          cross_group_pct: 0,
          approval_rate: 0,
        },
      })
      const wrapper = mountComponent()
      await flushPromises()
      expect(wrapper.html()).toContain('—')
    })
  })

  // §16.4 — timing / capture from offline simulator
  describe('capture_summary section', () => {
    it('shows "No simulator data yet" when week_start is empty', async () => {
      mockFetchMetrics.mockResolvedValue({ totals: [], recent: [] })
      const wrapper = mountComponent()
      await flushPromises()
      expect(wrapper.html()).toContain('No simulator data yet')
    })

    it('renders capture rate and curve when data is present', async () => {
      mockFetchMetrics.mockResolvedValue({
        totals: [],
        recent: [],
        capture_summary: {
          week_start: '2026-06-09',
          curve: 'front-heavy',
          pairs_total: 200,
          pairs_in_time: 150,
          pairs_late: 40,
          capture_rate: 75.0,
          reply_p50_hours: 2.5,
          reply_p75_hours: 6.0,
        },
      })
      const wrapper = mountComponent()
      await flushPromises()
      const html = wrapper.html()
      expect(html).toContain('front-heavy')
      expect(html).toContain('75.0%')
      expect(html).toContain('2.5h')
      expect(html).toContain('2026-06-09')
    })
  })

  // §15/§16.5 — held-reply friction summary
  describe('held_reply_summary section', () => {
    it('renders the held external replies heading', async () => {
      mockFetchMetrics.mockResolvedValue({ totals: [], recent: [] })
      const wrapper = mountComponent()
      await flushPromises()
      expect(wrapper.html()).toContain('Held external replies')
    })

    it('shows "No held replies recorded yet" when the list is empty', async () => {
      mockFetchMetrics.mockResolvedValue({
        totals: [],
        recent: [],
        held_reply_summary: [],
      })
      const wrapper = mountComponent()
      await flushPromises()
      expect(wrapper.html()).toContain('No held replies recorded yet')
    })

    it('renders held/released rows with counts from the API', async () => {
      mockFetchMetrics.mockResolvedValue({
        totals: [],
        recent: [],
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
})
