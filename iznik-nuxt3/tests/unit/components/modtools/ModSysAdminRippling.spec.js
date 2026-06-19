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
})
