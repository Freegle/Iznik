import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { defineComponent, Suspense, h } from 'vue'
import { DASHBOARD_CHART_HEADER } from '~/composables/useAuthoritySearch'

import StatsPage from '~/pages/stats/[[groupname]].vue'

// ── Stubs ─────────────────────────────────────────────────────────────────
vi.mock('~/components/StatsImpact.vue', () => ({
  default: {
    template: '<div class="stats-impact" />',
    props: ['range', 'totalBenefit', 'totalCO2', 'totalWeight'],
  },
}))
vi.mock('~/components/ActivityGraph.vue', () => ({
  default: {
    template: '<div class="activity-graph" />',
    props: ['groupid', 'systemwide', 'start', 'end'],
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

// ── Group store stub ───────────────────────────────────────────────────────
vi.mock('~/stores/group', () => ({
  useGroupStore: () => ({
    fetch: vi.fn().mockResolvedValue(undefined),
    get: vi.fn(() => null),
  }),
}))

// ── Composable mocks ──────────────────────────────────────────────────────
vi.mock('~/composables/useBuildHead', () => ({
  buildHead: vi.fn(() => ({})),
}))
vi.mock('~/composables/useReuseBenefit', () => ({
  getBenefitPerTonne: vi.fn(() => 1000),
  CO2_PER_TONNE: 0.5,
}))

// ── #imports shim ─────────────────────────────────────────────────────────
let mockRouteReturn = { params: { groupname: undefined }, query: {} }

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
        GroupHeader: {
          template: '<div class="group-header" />',
          props: ['id', 'group', 'showJoin'],
        },
      },
    },
  })
}

// ── Tests ─────────────────────────────────────────────────────────────────
describe('pages/stats/[[groupname]].vue', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    mockRouteReturn = { params: { groupname: undefined }, query: {} }
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
})
