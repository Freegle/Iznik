import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, Suspense, h, reactive } from 'vue'

// ============================================================
// calendar.client.vue — unit tests (TDD)
//
// The bug: the page used a plain try/catch in <script setup>
// that set error.value once, permanently. Netlify's trailing-slash
// 301 (/calendar?data → /calendar/?data) plus Nuxt's no-slash
// canonical causes a transient bare-/calendar render (route.query
// stripped → error cached) followed immediately by the real render
// with ?data= on the same route instance. Since setup never re-runs
// the cached error stayed on screen.
//
// The fix: derive everything from a `computed` so when route.query
// changes the error clears automatically.
// ============================================================

// ---- mock add-to-calendar-button (web component, not needed in jsdom)
vi.mock('add-to-calendar-button', () => ({}))

// ---- reactive mock route so computed properties respond to query changes
// (Nuxt's real useRoute() returns a reactive proxy; we replicate that here)
const mockRoute = reactive({
  params: {},
  query: {},
  path: '/calendar',
  name: 'calendar',
  fullPath: '/calendar',
})

vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return {
    ...actual,
    useRoute: () => mockRoute,
  }
})

globalThis.__testUseRoute = () => mockRoute
globalThis.useHead = vi.fn()
globalThis.definePageMeta = vi.fn()

// ---- helpers -------------------------------------------------

function b64encode(obj) {
  return btoa(JSON.stringify(obj))
}

function b64urlEncode(obj) {
  const s = btoa(JSON.stringify(obj))
  return s.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

const validEvent = {
  name: 'Handover: Alice and Bob',
  description: 'Please add to your calendar.',
  startDate: '2026-07-01',
  startTime: '14:00',
  endTime: '14:15',
  timeZone: 'Europe/London',
  location: '',
}

async function mountCalendarPage() {
  const CalendarPage = (await import('~/pages/calendar.client.vue')).default
  const Wrapper = defineComponent({
    setup() {
      return () => h(Suspense, null, { default: () => h(CalendarPage) })
    },
  })
  return mount(Wrapper, {
    global: {
      stubs: {
        'client-only': { template: '<div><slot /></div>' },
        'b-row': { template: '<div><slot /></div>' },
        'b-col': { template: '<div><slot /></div>' },
        'add-to-calendar-button': { template: '<div class="atcb"><slot /></div>' },
      },
    },
  })
}

describe('pages/calendar.client.vue', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    // Reset to a bare /calendar with NO query data (simulates transient
    // Netlify trailing-slash render — route.query is stripped)
    mockRoute.query = {}
    mockRoute.path = '/calendar'
    mockRoute.name = 'calendar'
    mockRoute.fullPath = '/calendar'
    // Ensure the global hook also reflects the current route
    globalThis.__testUseRoute = () => mockRoute
    vi.resetModules()
  })

  // -----------------------------------------------------------
  // (a) No data → shows "No calendar event data provided"
  // -----------------------------------------------------------
  it('shows "No calendar event data provided" error when route.query.data is absent', async () => {
    const wrapper = await mountCalendarPage()
    await flushPromises()

    expect(wrapper.text()).toContain('No calendar event data provided')
  })

  // -----------------------------------------------------------
  // (b) THE REGRESSION TEST: when route.query.data becomes
  // available reactively, the error CLEARS and the event renders.
  //
  // This proves the cached-error bug is fixed: if error were a plain
  // ref set in setup, it would persist after the route updates.
  // With the computed-based fix, the error re-derives as null when
  // rawData becomes available.
  // -----------------------------------------------------------
  it('clears the error and renders event name when route.query.data arrives reactively', async () => {
    // Step 1: mount with no data (transient bare render)
    const wrapper = await mountCalendarPage()
    await flushPromises()

    // Confirm the error state is showing initially
    expect(wrapper.text()).toContain('No calendar event data provided')

    // Step 2: simulate query arriving (Nuxt reuses component; route object
    // is mutated in place — we do the same with our reactive mock)
    mockRoute.query = { data: b64encode(validEvent) }

    await flushPromises()
    await wrapper.vm.$nextTick()

    // The error should be gone and the event name visible
    expect(wrapper.text()).not.toContain('No calendar event data provided')
    expect(wrapper.text()).toContain(validEvent.name)
  })

  // -----------------------------------------------------------
  // (c) base64url payload (url-safe alphabet) decodes correctly
  // -----------------------------------------------------------
  it('decodes a base64url-encoded payload (url-safe -_ alphabet) correctly', async () => {
    // Start with data already present in the query
    mockRoute.query = { data: b64urlEncode(validEvent) }

    const wrapper = await mountCalendarPage()
    await flushPromises()

    expect(wrapper.text()).not.toContain('Error')
    expect(wrapper.text()).toContain(validEvent.name)
  })

  // -----------------------------------------------------------
  // (d) Missing required fields → correct error message
  // -----------------------------------------------------------
  it('shows "Missing required event information" when name/startDate/startTime absent', async () => {
    const incomplete = { description: 'No required fields here' }
    mockRoute.query = { data: b64encode(incomplete) }

    const wrapper = await mountCalendarPage()
    await flushPromises()

    expect(wrapper.text()).toContain('Missing required event information')
  })
})
