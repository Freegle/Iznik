import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import ModSysAdminRipplingAnalytics from '~/modtools/components/ModSysAdminRipplingAnalytics.vue'

const mockFetchAnalytics = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    rippling: { fetchAnalytics: mockFetchAnalytics },
  }),
}))

vi.mock('#app', () => ({
  useRuntimeConfig: () => ({ public: { apiUrl: 'http://test' } }),
}))

function mountComponent() {
  return mount(ModSysAdminRipplingAnalytics, {
    global: {
      stubs: {
        'b-row': { template: '<div><slot /></div>' },
        'b-col': { template: '<div><slot /></div>' },
        'b-spinner': { template: '<div class="spinner" />' },
        'b-form-radio-group': {
          template: '<div><slot /></div>',
          props: ['modelValue'],
        },
        'b-form-radio': { template: '<label><slot /></label>' },
        ModEmailDateFilter: {
          template: '<div class="date-filter" />',
          emits: ['fetch'],
          mounted() {
            this.$emit('fetch', { start: '2026-06-24', end: '2026-07-08' })
          },
        },
        GChart: {
          name: 'GChart',
          template: '<div class="gchart" />',
          props: ['type', 'data', 'options'],
        },
      },
    },
  })
}

const FULL = {
  stratum: 'all',
  section1: {
    posts: 9645,
    replied: 5407,
    replied_pct: 56.1,
    taken: 2857,
    taken_pct: 29.6,
    mean_replies: 1.28,
    mean_freeglers_reached: 3261,
    reply_drive_min: {
      mean_min: 17.2,
      ci_half_min: 0.85,
      n_replies: 525,
      n_posts: 240,
      available: true,
    },
  },
  section2: [
    {
      day: '2026-06-24',
      posts: 225,
      replied_pct: 59.5,
      taken_pct: 45.3,
      mean_replies: 1.23,
      mean_freeglers: 2547,
    },
    {
      day: '2026-06-25',
      posts: 300,
      replied_pct: 55.0,
      taken_pct: 30.0,
      mean_replies: 1.3,
      mean_freeglers: 2600,
    },
  ],
  section3: {
    replies: 9781,
    rippled_replies: 1727,
    rippled_replies_pct: 17.7,
    takers: 2290,
    rippled_takers: 292,
    rippled_takers_pct: 12.8,
    client_instrumented_pct: 0.29,
    ripple_drive_min: {
      mean_min: 24.9,
      ci_half_min: 3.0,
      n_replies: 55,
      n_posts: 30,
      available: true,
    },
  },
}

describe('ModSysAdminRipplingAnalytics', () => {
  beforeEach(() => mockFetchAnalytics.mockReset())

  it('fetches with the default stratum + date range on mount', async () => {
    mockFetchAnalytics.mockResolvedValue({})
    const wrapper = mountComponent()
    await flushPromises()
    expect(mockFetchAnalytics).toHaveBeenCalledWith(
      'all',
      '2026-06-24',
      '2026-07-08'
    )
    wrapper.unmount()
  })

  it('renders Section 1 KPIs from the response', async () => {
    mockFetchAnalytics.mockResolvedValue(FULL)
    const wrapper = mountComponent()
    await flushPromises()
    const html = wrapper.html()
    expect(html).toContain('56.1%') // replied
    expect(html).toContain('29.6%') // taken
    expect(html).toContain('1.28') // mean replies
    expect(html).toContain('3,261') // freeglers reached
    expect(html).toContain('17.2') // drive-time mean
    expect(html).toContain('525') // drive-time sample size
    wrapper.unmount()
  })

  it('renders the Section 2 trend line chart with the day series', async () => {
    mockFetchAnalytics.mockResolvedValue(FULL)
    const wrapper = mountComponent()
    await flushPromises()
    expect(wrapper.html()).toContain('Trends')
    const charts = wrapper.findAllComponents({ name: 'GChart' })
    const line = charts.find((c) => c.props('type') === 'LineChart')
    expect(line).toBeTruthy()
    const header = line.props('data')[0]
    expect(header).toContain('Reply rate (%)')
    expect(header).toContain('Taken rate (%)')
    expect(line.props('data').length).toBe(3) // header + 2 days
    wrapper.unmount()
  })

  it('renders Section 3 rippling-out shares from the response', async () => {
    mockFetchAnalytics.mockResolvedValue(FULL)
    const wrapper = mountComponent()
    await flushPromises()
    const html = wrapper.html()
    expect(html).toContain('Rippling out specifically')
    expect(html).toContain('17.7%') // rippled replies pct
    expect(html).toContain('12.8%') // rippled takers pct
    expect(html).toContain('24.9') // rippled-out drive-time
    // client-instrumented pct > 0 → shows the cross-check figure (0.29 -> 0.3%)
    expect(html).toContain('Client-instrumented cross-check')
    expect(html).toContain('0.3%')
    wrapper.unmount()
  })

  it('shows the "fills in after deploy" note when no client-instrumented data', async () => {
    mockFetchAnalytics.mockResolvedValue({
      ...FULL,
      section3: { ...FULL.section3, client_instrumented_pct: 0 },
    })
    const wrapper = mountComponent()
    await flushPromises()
    expect(wrapper.html()).toContain('ships to')
    wrapper.unmount()
  })

  it('refetches with the selected stratum when the density changes', async () => {
    mockFetchAnalytics.mockResolvedValue(FULL)
    const wrapper = mountComponent()
    await flushPromises()
    mockFetchAnalytics.mockClear()

    wrapper.vm.stratum = 'rural'
    wrapper.vm.fetchAnalytics()
    await flushPromises()
    expect(mockFetchAnalytics).toHaveBeenCalledWith(
      'rural',
      '2026-06-24',
      '2026-07-08'
    )
    wrapper.unmount()
  })
})
