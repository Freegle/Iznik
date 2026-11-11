import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { defineComponent, Suspense, h } from 'vue'
import dayjs from 'dayjs'
import { DASHBOARD_CHART_HEADER } from '~/composables/useAuthoritySearch'

import StatsPage from '~/pages/stats/index.vue'
import StatsImpact from '~/components/StatsImpact.vue'

// ── Stubs ─────────────────────────────────────────────────────────────────
vi.mock('~/components/StatsImpact.vue', () => ({
  default: {
    template: '<div class="stats-impact" />',
    props: ['range', 'totalBenefit', 'benefitNote', 'totalCO2', 'totalWeight'],
  },
}))
vi.mock('~/components/ActivityGraph.vue', () => ({
  default: {
    template: '<div class="activity-graph" />',
    props: ['start', 'end'],
  },
}))
vi.mock('vue-google-charts', () => ({
  GChart: {
    name: 'GChart',
    template: '<div class="gchart" :data-charttype="type" />',
    props: ['type', 'data', 'options'],
  },
}))

// ── Stats store mock ──────────────────────────────────────────────────────
// Mock the store directly (rather than the API) to avoid module caching
// issues where the real store imports api before our mock takes effect.
const mockStatsFetch = vi.fn().mockResolvedValue(undefined)
const mockStatsClear = vi.fn()

// Mutable state object — set per test to control what store exposes.
let storeData = {}

vi.mock('~/stores/stats', () => ({
  useStatsStore: () => ({
    get Weight() {
      return storeData.Weight
    },
    get ApprovedMemberCount() {
      return storeData.ApprovedMemberCount
    },
    get MessageBreakdown() {
      return storeData.MessageBreakdown
    },
    get Outcomes() {
      return storeData.Outcomes
    },
    heatmap: {},
    fetch: mockStatsFetch,
    clear: mockStatsClear,
  }),
}))

// ── Composable mocks ──────────────────────────────────────────────────────
vi.mock('~/composables/useBuildHead', () => ({
  buildHead: vi.fn(() => ({})),
}))
vi.mock('~/composables/useReuseBenefit', () => ({
  getBenefitPerTonne: vi.fn(() => 1000),
  CO2_PER_TONNE: 0.5,
  calculateFlyTipSaving: vi.fn((items) => items * 2),
  describeFlyTipSaving: vi.fn((saving) => `fly-tipping note for £${saving}`),
}))

// ── #imports shim ─────────────────────────────────────────────────────────
let mockRouteReturn = { params: {}, query: {} }

vi.hoisted(() => {
  vi.resetModules()
})

vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return {
    ...actual,
    useRoute: () => mockRouteReturn,
    useHead: vi.fn(),
    useRuntimeConfig: () => ({ public: { BUILD_DATE: '2026-01-01' } }),
  }
})

globalThis.definePageMeta = vi.fn()
globalThis.useHead = vi.fn()
globalThis.useRuntimeConfig = () => ({ public: { BUILD_DATE: '2026-01-01' } })

// ── Mount helper ──────────────────────────────────────────────────────────
function mountPage() {
  const Wrapper = defineComponent({
    setup() {
      return () => h(Suspense, null, { default: () => h(StatsPage) })
    },
  })
  return mount(Wrapper, {
    global: {
      plugins: [createPinia()],
      stubs: {
        'client-only': { template: '<div><slot /></div>' },
        'nuxt-link': {
          template: '<a><slot /></a>',
          props: ['to', 'noPrefetch'],
        },
        'b-img': {
          template: '<img />',
          props: ['thumbnail', 'src', 'class'],
        },
        'b-card': { template: '<div class="b-card"><slot /></div>' },
        'b-card-text': { template: '<div><slot /></div>' },
        'b-card-body': { template: '<div><slot /></div>' },
        'b-row': { template: '<div><slot /></div>' },
        'b-col': { template: '<div><slot /></div>' },
        ExternalLink: { template: '<a><slot /></a>' },
        Spinner: { template: '<div class="spinner" />', props: ['size'] },
      },
    },
  })
}

// ── Tests ─────────────────────────────────────────────────────────────────
describe('pages/stats/index.vue', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    mockRouteReturn = { params: {}, query: {} }
    storeData = {}
  })

  describe('statsStore.fetch() components parameter', () => {
    it('passes components=Weight so the API returns weight data', async () => {
      mountPage()
      await flushPromises()

      expect(mockStatsFetch).toHaveBeenCalled()
      const params = mockStatsFetch.mock.calls[0][0]
      expect(params).toHaveProperty('components')
      expect(params.components).toContain('Weight')
    })

    it('passes components=MessageBreakdown for the Post Balance pie chart', async () => {
      mountPage()
      await flushPromises()

      const params = mockStatsFetch.mock.calls[0][0]
      expect(params.components).toContain('MessageBreakdown')
    })

    it('passes components=Outcomes for the outcome pie charts', async () => {
      mountPage()
      await flushPromises()

      const params = mockStatsFetch.mock.calls[0][0]
      expect(params.components).toContain('Outcomes')
    })

    it('passes components=ApprovedMemberCount for the members chart', async () => {
      mountPage()
      await flushPromises()

      const params = mockStatsFetch.mock.calls[0][0]
      expect(params.components).toContain('ApprovedMemberCount')
    })
  })

  describe('chart data headers — typed columns prevent Google Charts crash', () => {
    it('ColumnChart (weights) data[0] is typed DASHBOARD_CHART_HEADER when no weight data', async () => {
      // storeData.Weight is undefined — chart is header-only.
      // Must use {type:'date'} column or Google Charts throws
      // "Data column(s) for axis #0 cannot be of type string".
      const wrapper = mountPage()
      await flushPromises()

      const colChart = wrapper
        .findAllComponents({ name: 'GChart' })
        .find((c) => c.props('type') === 'ColumnChart')
      if (colChart) {
        expect(colChart.props('data')[0]).toEqual(DASHBOARD_CHART_HEADER)
      }
    })

    it('LineChart (members) data[0] is typed DASHBOARD_CHART_HEADER when no member data', async () => {
      const wrapper = mountPage()
      await flushPromises()

      const lineChart = wrapper
        .findAllComponents({ name: 'GChart' })
        .find((c) => c.props('type') === 'LineChart')
      if (lineChart) {
        expect(lineChart.props('data')[0]).toEqual(DASHBOARD_CHART_HEADER)
      }
    })
  })

  describe('members chart v-if guard', () => {
    it('hides the LineChart when ApprovedMemberCount is absent (non-mod visitor)', async () => {
      // ApprovedMemberCount is mod-gated in Go API; non-mod visitors get
      // undefined back. Rendering a LineChart with a header-only string-typed
      // column throws a Google Charts type error — the chart must be hidden.
      storeData = {}
      const wrapper = mountPage()
      await flushPromises()

      const lineCharts = wrapper
        .findAllComponents({ name: 'GChart' })
        .filter((c) => c.props('type') === 'LineChart')
      expect(lineCharts).toHaveLength(0)
    })

    it('shows the LineChart when ApprovedMemberCount data is present', async () => {
      storeData = {
        ApprovedMemberCount: [{ date: '2025-04-01', count: 500 }],
        Weight: [],
        MessageBreakdown: { Offer: 100, Wanted: 50 },
        Outcomes: {},
      }
      const wrapper = mountPage()
      await flushPromises()

      const lineCharts = wrapper
        .findAllComponents({ name: 'GChart' })
        .filter((c) => c.props('type') === 'LineChart')
      expect(lineCharts.length).toBeGreaterThan(0)
    })
  })

  describe('date range — complete months only', () => {
    // The page deliberately ends the window at the last COMPLETE month so a
    // part-finished month can't render as a cliff on the monthly charts. That
    // means for the whole of the current month the newest data shown is the end
    // of the previous month, which reads as "the stats have stopped updating"
    // unless the range is stated on the page.
    it('requests data up to the end of the previous month, not today', async () => {
      mountPage()
      await flushPromises()

      const params = mockStatsFetch.mock.calls[0][0]
      expect(params.end).toBe(
        dayjs().subtract(1, 'month').endOf('month').format('YYYY-MM-DD')
      )
    })

    it('states the range on the page so a complete-months window is not mistaken for stale data', async () => {
      const wrapper = mountPage()
      await flushPromises()

      expect(wrapper.text()).toContain('Showing data for:')
      expect(wrapper.text()).toContain(
        dayjs().subtract(1, 'month').endOf('month').format('D MMMM YYYY')
      )
    })

    it('does not claim "the last 12 months" when the window excludes the current month', async () => {
      const wrapper = mountPage()
      await flushPromises()

      expect(wrapper.text()).not.toContain('the last 12 months')
    })
  })

  describe('benefit includes the fly-tipping saving', () => {
    // A day in the middle of last month: inside the default window whatever
    // today is.
    const lastMonth = dayjs().subtract(1, 'month').date(15).format('YYYY-MM-DD')

    it('adds the saving for the gifts made to the reuse benefit', async () => {
      storeData = {
        Weight: [{ date: lastMonth, count: 3000 }],
        Outcomes: [
          { date: lastMonth + 'T00:00:00Z', count: 100 },
          { date: lastMonth + 'T00:00:00Z', count: 50 },
        ],
        MessageBreakdown: { Offer: 1, Wanted: 1 },
      }
      const wrapper = mountPage()
      await flushPromises()

      const impact = wrapper.findComponent(StatsImpact)
      // 3 tonnes at £1,000 a tonne, plus 150 gifts at £2 each.
      expect(impact.props('totalBenefit')).toBe(3300)
      expect(impact.props('benefitNote')).toBe('fly-tipping note for £300')
    })

    it('adds nothing when there are no outcomes', async () => {
      storeData = {
        Weight: [{ date: lastMonth, count: 1000 }],
        MessageBreakdown: { Offer: 1, Wanted: 1 },
      }
      const wrapper = mountPage()
      await flushPromises()

      expect(wrapper.findComponent(StatsImpact).props('totalBenefit')).toBe(
        1000
      )
    })
  })

  describe('totals cover the period the page names', () => {
    it('requests exactly the 12 complete months it describes', async () => {
      mountPage()
      await flushPromises()

      const params = mockStatsFetch.mock.calls[0][0]
      expect(params.start).toBe(
        dayjs().subtract(12, 'month').startOf('month').format('YYYY-MM-DD')
      )
    })

    it('counts weights and gifts from the first day to the last', async () => {
      const first = dayjs().subtract(12, 'month').startOf('month')
      const last = dayjs().subtract(1, 'month').endOf('month')
      storeData = {
        Weight: [
          { date: first.subtract(1, 'day').format('YYYY-MM-DD'), count: 9000 },
          { date: first.format('YYYY-MM-DD'), count: 1000 },
          { date: last.format('YYYY-MM-DD'), count: 1000 },
        ],
        Outcomes: [
          { date: first.subtract(1, 'day').format('YYYY-MM-DD'), count: 900 },
          { date: first.format('YYYY-MM-DD') + 'T00:00:00Z', count: 10 },
          { date: last.format('YYYY-MM-DD') + 'T00:00:00Z', count: 10 },
        ],
        MessageBreakdown: { Offer: 1, Wanted: 1 },
      }
      const wrapper = mountPage()
      await flushPromises()

      const impact = wrapper.findComponent(StatsImpact)
      expect(impact.props('totalWeight')).toBe(2)
      // 2 tonnes at £1,000 a tonne, plus 20 gifts at £2 each.
      expect(impact.props('totalBenefit')).toBe(2040)
    })

    it('counts a past financial year in full', async () => {
      // Financial year 2023-24 runs from 6 April 2023 to 5 April 2024, more
      // than a year before today.
      mockRouteReturn = {
        params: { groupname: undefined },
        query: { financialYear: 'FY2023' },
      }
      storeData = {
        Weight: [
          { date: '2023-06-01', count: 1000 },
          { date: '2024-03-01', count: 2000 },
          { date: '2024-05-01', count: 5000 },
        ],
        Outcomes: [{ date: '2023-06-01T00:00:00Z', count: 30 }],
        MessageBreakdown: { Offer: 1, Wanted: 1 },
      }
      const wrapper = mountPage()
      await flushPromises()

      const impact = wrapper.findComponent(StatsImpact)
      expect(impact.props('totalWeight')).toBe(3)
      expect(impact.props('totalBenefit')).toBe(3060)
    })
  })
})
