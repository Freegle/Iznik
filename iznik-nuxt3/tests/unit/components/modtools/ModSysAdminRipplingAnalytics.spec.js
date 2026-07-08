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
        'b-spinner': { template: '<div class="spinner" />' },
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
    replied_36h: 4940,
    replied_36h_pct: 51.2,
    replied_ever: 5407,
    replied_ever_pct: 56.1,
    taken: 2857,
    taken_pct: 29.6,
    mean_replies: 1.28,
    mean_freeglers_reached: 3261,
    held_replies: 1344,
    held_replies_pct: 10.8,
    reply_drive_min: {
      mean_min: 17.2,
      ci_half_min: 0.85,
      n_replies: 525,
      available: true,
    },
  },
  section2: {
    kpis: [
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
    drive_time: [
      { day: '2026-06-24', mean_min: 16.1, n: 12 },
      { day: '2026-06-25', mean_min: 18.4, n: 9 },
    ],
  },
  section3: {
    replies: 9781,
    rippled_replies: 1727,
    rippled_replies_pct: 17.7,
    takers: 2290,
    rippled_takers: 292,
    rippled_takers_pct: 12.8,
    rescued_takes: 140,
    rescued_takes_pct: 6.1,
    contribution_low_pct: 6.1,
    contribution_high_pct: 12.8,
    home_conv_pct: 22.5,
    rippled_conv_pct: 11.0,
    client_instrumented_pct: 0,
    ripple_drive_min: {
      mean_min: 24.9,
      ci_half_min: 3.0,
      n_replies: 55,
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
    expect(html).toContain('51.2%') // reply within 36h (headline)
    expect(html).toContain('56.1%') // eventual total (smaller)
    expect(html).toContain('29.6%') // taken
    expect(html).toContain('1.28') // mean replies
    expect(html).toContain('3,261') // freeglers
    expect(html).toContain('17.2') // reply drive-time
    expect(html).toContain('10.8%') // held-reply friction KPI
    expect(html).toContain('of replies held')
    wrapper.unmount()
  })

  it('uses one consistent green across every pie (positive slice first)', async () => {
    mockFetchAnalytics.mockResolvedValue(FULL)
    const wrapper = mountComponent()
    await flushPromises()
    const pies = wrapper
      .findAllComponents({ name: 'GChart' })
      .filter((c) => c.props('type') === 'PieChart')
    expect(pies.length).toBe(4) // replied, taken, rippled replies, rippled takers
    for (const p of pies) {
      expect(p.props('options').colors[0]).toBe('#28a745')
    }
    wrapper.unmount()
  })

  it('renders a separate trend chart per metric (incl. freeglers + drive-time)', async () => {
    mockFetchAnalytics.mockResolvedValue(FULL)
    const wrapper = mountComponent()
    await flushPromises()
    expect(wrapper.html()).toContain('Trends')
    const areas = wrapper
      .findAllComponents({ name: 'GChart' })
      .filter((c) => c.props('type') === 'AreaChart')
    // 5 trend metrics: reply rate, taken rate, mean replies, freeglers, drive-time
    expect(areas.length).toBe(5)
    expect(wrapper.html()).toContain('Active freeglers reached')
    expect(wrapper.html()).toContain('Mean reply travel (min)')
    wrapper.unmount()
  })

  it('answers "is rippling helping?" as a contribution range with a rescue floor', async () => {
    mockFetchAnalytics.mockResolvedValue(FULL)
    const wrapper = mountComponent()
    await flushPromises()
    const html = wrapper.html()
    expect(html).toContain('Is rippling out helping?')
    expect(html).toContain('6.1%') // contribution floor
    expect(html).toContain('12.8%') // contribution ceiling
    expect(html).toContain('140') // rescued takes
    expect(html).toContain('rescued from silence')
    // home vs rippled reply->take comparison
    expect(html).toContain('22.5%')
    expect(html).toContain('11.0%')
    wrapper.unmount()
  })

  it('highlights the active density and refetches on change (defaults to All)', async () => {
    mockFetchAnalytics.mockResolvedValue(FULL)
    const wrapper = mountComponent()
    await flushPromises()

    // "All" is active by default.
    const buttons = wrapper.findAll('.seg-btn')
    expect(buttons.length).toBe(4)
    expect(buttons[0].classes()).toContain('active')
    expect(buttons[1].classes()).not.toContain('active')

    mockFetchAnalytics.mockClear()
    await buttons[1].trigger('click') // Rural
    await flushPromises()
    expect(mockFetchAnalytics).toHaveBeenCalledWith(
      'rural',
      '2026-06-24',
      '2026-07-08'
    )
    expect(wrapper.findAll('.seg-btn')[1].classes()).toContain('active')
    wrapper.unmount()
  })
})
