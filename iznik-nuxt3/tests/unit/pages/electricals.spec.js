import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, Suspense, h, ref } from 'vue'

// ============================================================
// pages/electricals.vue
//
// The page publishes accuracy claims, so the cases worth pinning are the
// honesty ones: the accuracy wording must downgrade itself when the stored
// figures were measured on a model that is no longer the one running, the
// window label must follow the payload rather than hard-coding "12 months",
// and a null percentage must drop its column rather than render "null%".
// ============================================================

let statsPayload

vi.mock('~/api', () => ({
  default: () => ({
    electricals: { stats: () => Promise.resolve(statsPayload) },
  }),
}))

vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return {
    ...actual,
    useRoute: () => ({
      query: {},
      path: '/electricals',
      fullPath: '/electricals',
    }),
    useRuntimeConfig: () => ({ public: { APIv2: 'https://api.test' } }),
    useHead: vi.fn(),
    useAsyncData: async (key, handler) => ({
      data: ref(await handler()),
      pending: ref(false),
      error: ref(null),
    }),
  }
})

globalThis.useHead = vi.fn()

function payload({ measuredForCurrent = true, months = 12, pct = 42.5 } = {}) {
  return {
    window: { months },
    counts: { classified: 1000, electrical: 425, electrical_pct: pct },
    impact: {
      tonnes: 1.2,
      tonnes_co2e: 1.9,
      carbon_value_gbp: 100,
      carbon_proxy_gbp_per_tonne: 273,
      mean_item_kg: 2.8,
      items_taken: 425,
    },
    success: {
      electrical: { posts: 100, taken: 50, taken_pct: 50.0 },
      other: { posts: 100, taken: 60, taken_pct: 60.0 },
    },
    condition: {
      reusable: { count: 300, taken: 150, taken_pct: 50.0 },
      damaged: { count: 50, taken: 20, taken_pct: 40.0 },
    },
    popular: [{ name: 'Kettle', count: 30 }],
    unusual: { items: [], guard: {} },
    monthly_trend: [],
    accuracy: {
      measured_on: 'gemini-2.0-flash-lite',
      current_model: measuredForCurrent
        ? 'gemini-2.0-flash-lite'
        : 'gemini-3.5-flash-lite',
      measured_for_current_model: measuredForCurrent,
      is_electrical: { pct: 96, publish: true },
      condition: { pct: 93, publish: true },
    },
  }
}

const passthrough = { template: '<div><slot /></div>' }

async function mountPage() {
  const ElectricalsPage = (await import('~/pages/electricals.vue')).default
  const Wrapper = defineComponent({
    setup() {
      return () => h(Suspense, null, { default: () => h(ElectricalsPage) })
    },
  })
  const wrapper = mount(Wrapper, {
    global: {
      stubs: {
        'b-row': passthrough,
        'b-col': passthrough,
        'b-img': { template: '<img />' },
        'b-alert': passthrough,
        'b-badge': passthrough,
        'b-card': passthrough,
        'b-card-body': passthrough,
        'b-card-text': passthrough,
        'b-list-group': passthrough,
        'b-list-group-item': passthrough,
        'b-table-simple': passthrough,
        'b-thead': passthrough,
        'b-tbody': passthrough,
        'b-tr': passthrough,
        'b-td': passthrough,
        'b-th': passthrough,
        'v-icon': { template: '<i />' },
        Spinner: { template: '<div class="spinner-stub" />' },
        ExternalLink: { template: '<a><slot /></a>', props: ['href'] },
      },
    },
  })
  await flushPromises()
  return wrapper
}

describe('pages/electricals.vue', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    statsPayload = payload()
  })

  it('presents accuracy as current when measured on the running model', async () => {
    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('we get this right 96% of the time')
    expect(wrapper.text()).not.toContain('earlier version of the software')
  })

  it('downgrades accuracy wording when the measured model is no longer the one running', async () => {
    statsPayload = payload({ measuredForCurrent: false })

    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('earlier version of the software')
    expect(wrapper.text()).toContain('has not yet been repeated')
    expect(wrapper.text()).not.toContain('we get this right 96% of the time')
  })

  it('takes the window length from the payload, not a hard-coded 12', async () => {
    statsPayload = payload({ months: 6 })

    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('in the last 6 months')
    expect(wrapper.text()).not.toContain('in the last 12 months')
  })

  it('drops the percentage figure rather than rendering null%', async () => {
    statsPayload = payload({ pct: null })

    const wrapper = await mountPage()

    expect(wrapper.text()).not.toContain('null%')
  })
})
