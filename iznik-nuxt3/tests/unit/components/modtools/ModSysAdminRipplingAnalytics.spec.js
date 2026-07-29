import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import ModSysAdminRipplingAnalytics from '~/modtools/components/ModSysAdminRipplingAnalytics.vue'

const mockFetchAnalytics = vi.fn()
const mockFetchAnalyticsDriveTimes = vi.fn()
const mockScoreAnalyticsDriveTimes = vi.fn()
const mockAggregateAnalyticsDriveTimes = vi.fn()
const mockFetchMetrics = vi.fn().mockResolvedValue({})

vi.mock('~/api', () => ({
  default: () => ({
    rippling: {
      fetchAnalytics: mockFetchAnalytics,
      fetchAnalyticsDriveTimes: mockFetchAnalyticsDriveTimes,
      scoreAnalyticsDriveTimes: mockScoreAnalyticsDriveTimes,
      aggregateAnalyticsDriveTimes: mockAggregateAnalyticsDriveTimes,
      fetchMetrics: mockFetchMetrics,
    },
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
        'b-table-simple': { template: '<table><slot /></table>' },
        'b-thead': { template: '<thead><slot /></thead>' },
        'b-tbody': { template: '<tbody><slot /></tbody>' },
        'b-tr': { template: '<tr><slot /></tr>' },
        'b-th': { template: '<th><slot /></th>' },
        'b-td': { template: '<td><slot /></td>' },
        'b-badge': { template: '<span class="badge"><slot /></span>' },
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
  bullseye: [
    {
      min_lo: 0,
      min_hi: 10,
      n_replies: 120,
      n_takers: 36,
      conv_pct: 30.0,
      ci_half_pct: 8.2,
    },
    {
      min_lo: 10,
      min_hi: 15,
      n_replies: 140,
      n_takers: 39,
      conv_pct: 27.9,
      ci_half_pct: 7.4,
    },
    {
      min_lo: 15,
      min_hi: 20,
      n_replies: 90,
      n_takers: 22,
      conv_pct: 24.4,
      ci_half_pct: 8.9,
    },
    {
      min_lo: 20,
      min_hi: 25,
      n_replies: 60,
      n_takers: 13,
      conv_pct: 21.7,
      ci_half_pct: 10.4,
    },
    {
      min_lo: 25,
      min_hi: 30,
      n_replies: 40,
      n_takers: 7,
      conv_pct: 17.5,
      ci_half_pct: 11.8,
    },
    {
      min_lo: 30,
      min_hi: 45,
      n_replies: 0,
      n_takers: 0,
      conv_pct: 0,
      ci_half_pct: 0,
    },
  ],
}

// The endpoint is split: /rippling/analytics returns fast SQL KPIs (drive-time fields blank), and
// /rippling/analytics/drivetime returns the slow sampled routing pass. These helpers split the
// combined FULL fixture the same way, so the component is exercised through both requests.
const BLANK_DRIVE = {
  mean_min: 0,
  ci_half_min: 0,
  n_replies: 0,
  available: false,
}
function fastOf(full) {
  return {
    ...full,
    section1: { ...full.section1, reply_drive_min: { ...BLANK_DRIVE } },
    section2: { ...full.section2, drive_time: [] },
    section3: { ...full.section3, ripple_drive_min: { ...BLANK_DRIVE } },
    bullseye: [],
  }
}
function driveOf(full) {
  return {
    status: 'done',
    reply_drive_min: full.section1.reply_drive_min,
    ripple_drive_min: full.section3.ripple_drive_min,
    drive_time: full.section2.drive_time,
    bullseye: full.bullseye,
  }
}
// The drive-time pass is now three client calls: fetch the random SAMPLE, score it in chunks
// (serial), then aggregate. A one-post sample + one observation is enough to drive the loop; the
// rendered figures come from the mocked aggregate (driveOf(FULL)).
const DRIVE_SAMPLE_POST = {
  lat: 51.5,
  lng: -0.1,
  points: [[-0.1, 51.5]],
  rippled: [false],
  days: ['2026-07-01'],
  takers: [false],
}
const DRIVE_OBS = { min: 17.2, rippled: false, day: '2026-07-01', taker: false }

describe('ModSysAdminRipplingAnalytics', () => {
  beforeEach(() => {
    mockFetchAnalytics.mockReset()
    mockFetchAnalyticsDriveTimes.mockReset()
    mockScoreAnalyticsDriveTimes.mockReset()
    mockAggregateAnalyticsDriveTimes.mockReset()
    mockFetchMetrics.mockReset()
    mockFetchMetrics.mockResolvedValue({})
    mockFetchAnalyticsDriveTimes.mockResolvedValue({
      posts: [DRIVE_SAMPLE_POST],
      total: 1,
    })
    mockScoreAnalyticsDriveTimes.mockResolvedValue({ obs: [DRIVE_OBS] })
    mockAggregateAnalyticsDriveTimes.mockResolvedValue(driveOf(FULL))
  })

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
    mockFetchAnalytics.mockResolvedValue(fastOf(FULL))
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
    mockFetchAnalytics.mockResolvedValue(fastOf(FULL))
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
    mockFetchAnalytics.mockResolvedValue(fastOf(FULL))
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
    mockFetchAnalytics.mockResolvedValue(fastOf(FULL))
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

  it('folds in the attribution channels + geographic hotspots from the metrics call', async () => {
    mockFetchAnalytics.mockResolvedValue(fastOf(FULL))
    mockFetchMetrics.mockResolvedValueOnce({
      reply_source_split: [
        { day: '2026-06-24', home: 5, ripple_notified: 2, ripple_reach: 1 },
      ],
      hotspots: [
        {
          area_name: 'Anomaly Town',
          metric: 'secondary_reject_rate',
          value: 0.9,
          baseline: 0.1,
          deviation: 12.3,
          severity: 'alert',
        },
      ],
    })
    const wrapper = mountComponent()
    await flushPromises()
    const html = wrapper.html()
    expect(html).toContain('Where do replies come from?')
    expect(html).toContain('Where to look')
    expect(html).toContain('Anomaly Town')
    expect(html).toContain('secondary_reject_rate')
    expect(mockFetchMetrics).toHaveBeenCalled()
    wrapper.unmount()
  })

  // The metrics endpoint feeds only the attribution + hotspot panels. It used to be awaited in the
  // same Promise.all as the KPIs, so when it 504'd on production (its heavy KPI queries ran for
  // minutes, and a gateway timeout reads to the browser as a CORS failure) the whole tab showed
  // "Failed to load" and the drive-time pass never started.
  it('still renders the KPIs when the metrics request fails, and says so in its panels', async () => {
    mockFetchAnalytics.mockResolvedValue(fastOf(FULL))
    mockFetchMetrics.mockRejectedValue(new Error('Network Error'))
    const wrapper = mountComponent()
    await flushPromises()
    const html = wrapper.html()

    expect(html).toContain('51.2%') // Section 1 KPI still rendered
    expect(html).not.toContain('Failed to load:') // the tab-level error banner stays away
    // The panels that DID depend on it report the failure rather than reading as "no data".
    expect(html).toContain("Couldn't load reply attribution")
    expect(html).toContain("Couldn't load hotspots")
    // ...and the slow drive-time pass still ran.
    expect(mockFetchAnalyticsDriveTimes).toHaveBeenCalled()
    wrapper.unmount()
  })

  // The server names any section whose query hit its deadline, so an empty panel is not passed
  // off as "nothing to show" when it means "we gave up".
  it('distinguishes a section the server gave up on from one with no data', async () => {
    mockFetchAnalytics.mockResolvedValue(fastOf(FULL))
    mockFetchMetrics.mockResolvedValue({
      reply_source_split: [],
      hotspots: [],
      degraded: ['reply_source_split', 'hotspots'],
    })
    const wrapper = mountComponent()
    await flushPromises()
    const html = wrapper.html()
    expect(html).toContain('Reply attribution timed out on the server')
    expect(html).toContain('Hotspots timed out on the server')
    expect(html).not.toContain('No attribution data yet')
    expect(html).not.toContain('No hotspots flagged')
    wrapper.unmount()
  })

  it('renders the KPIs before the metrics request has resolved', async () => {
    mockFetchAnalytics.mockResolvedValue(fastOf(FULL))
    let resolveMetrics
    mockFetchMetrics.mockReturnValue(
      new Promise((resolve) => {
        resolveMetrics = resolve
      })
    )
    const wrapper = mountComponent()
    await flushPromises()

    // KPIs are on screen with the metrics call still in flight.
    expect(wrapper.html()).toContain('51.2%')
    expect(wrapper.html()).not.toContain('Anomaly Town')

    resolveMetrics({
      hotspots: [
        {
          area_name: 'Anomaly Town',
          metric: 'secondary_reject_rate',
          value: 0.9,
          baseline: 0.1,
          deviation: 12.3,
          severity: 'alert',
        },
      ],
    })
    await flushPromises()
    expect(wrapper.html()).toContain('Anomaly Town')
    wrapper.unmount()
  })

  it('draws the reliability bullseye: a ring per drive-time band, empty rings greyed', async () => {
    mockFetchAnalytics.mockResolvedValue(fastOf(FULL))
    const wrapper = mountComponent()
    await flushPromises()
    const html = wrapper.html()
    expect(html).toContain('How reliably does a reply convert')

    // One <circle> per band (7) + the 30-min reach-edge ring + the centre "post" dot.
    const circles = wrapper.findAll('svg.bullseye circle')
    expect(circles.length).toBe(FULL.bullseye.length + 2)

    // Populated bands are a green ramp (hsl); the empty 30-45 band is grey, not pale green,
    // so "no sampled replies" never reads as "0% conversion".
    const greenRings = circles.filter((c) =>
      c.attributes('fill')?.startsWith('hsl')
    )
    expect(greenRings.length).toBe(5) // five populated bands; the empty 30-45 band is grey
    expect(html).toContain('#eef0ec') // empty ring grey

    // The exact-values table carries the real numbers beside the shape.
    expect(html).toContain('30.0%') // 0-10 min conversion
    expect(html).toContain('0–10m')
    wrapper.unmount()
  })

  it('highlights the active density and refetches on change (defaults to All)', async () => {
    mockFetchAnalytics.mockResolvedValue(fastOf(FULL))
    const wrapper = mountComponent()
    await flushPromises()

    // "All" is active by default.
    const buttons = wrapper.findAll('.seg-btn')
    expect(buttons.length).toBe(4)
    expect(buttons[0].classes()).toContain('active')
    expect(buttons[1].classes()).not.toContain('active')

    mockFetchAnalytics.mockClear()
    mockFetchMetrics.mockClear()
    await buttons[1].trigger('click') // Rural
    await flushPromises()
    expect(mockFetchAnalytics).toHaveBeenCalledWith(
      'rural',
      '2026-06-24',
      '2026-07-08'
    )
    // The metrics sections vary by window, not density, so they are not refetched (and the
    // panels don't blank) just because the density changed.
    expect(mockFetchMetrics).not.toHaveBeenCalled()
    expect(wrapper.findAll('.seg-btn')[1].classes()).toContain('active')
    wrapper.unmount()
  })

  it('refetches the metrics sections when the date window changes', async () => {
    mockFetchAnalytics.mockResolvedValue(fastOf(FULL))
    const wrapper = mountComponent()
    await flushPromises()

    mockFetchMetrics.mockClear()
    wrapper.vm.onFilterFetch({ start: '2026-05-01', end: '2026-05-31' })
    await flushPromises()
    expect(mockFetchMetrics).toHaveBeenCalledWith(0, '2026-05-01', '2026-05-31')
    wrapper.unmount()
  })

  it('renders KPIs immediately and fills the drive-time panels from a separate request', async () => {
    mockFetchAnalytics.mockResolvedValue(fastOf(FULL))
    // Hold the slow routing pass open so we can assert the page is usable before it resolves.
    let resolveDrive
    mockFetchAnalyticsDriveTimes.mockReturnValue(
      new Promise((resolve) => {
        resolveDrive = resolve
      })
    )
    const wrapper = mountComponent()
    await flushPromises()

    // The SQL KPIs are on screen even though the routing pass has NOT resolved.
    expect(wrapper.html()).toContain('51.2%')
    // The drive-time value isn't shown yet — a sampling spinner is in its place.
    expect(wrapper.html()).not.toContain('17.2')
    expect(wrapper.html()).toContain('sampling drive-times')

    // Resolve the SAMPLE fetch; the client then scores it and aggregates (both mocked in
    // beforeEach), so the drive panels + bullseye fill in.
    resolveDrive({ posts: [DRIVE_SAMPLE_POST], total: 1 })
    await flushPromises()
    expect(wrapper.html()).toContain('17.2') // reply drive-time
    expect(wrapper.html()).toContain('How reliably does a reply convert') // bullseye
    expect(mockFetchAnalyticsDriveTimes).toHaveBeenCalledWith(
      'all',
      '2026-06-24',
      '2026-07-08'
    )
    wrapper.unmount()
  })
})
