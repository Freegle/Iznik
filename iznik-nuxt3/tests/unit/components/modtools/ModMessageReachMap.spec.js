import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import ModMessageReachMap from '~/modtools/components/ModMessageReachMap.vue'

const mockShow = vi.fn()
const mockHide = vi.fn()
const mockFetchReach = vi.fn(() => Promise.resolve({ rippling: false }))

vi.mock('~/composables/useOurModal', () => ({
  useOurModal: () => ({
    modal: ref(null),
    show: mockShow,
    hide: mockHide,
  }),
}))

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({ jwt: 'jwt-token' }),
}))

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({ fetchReach: mockFetchReach }),
}))

vi.mock('#imports', () => ({
  useRuntimeConfig: () => ({
    public: { SPATIAL_SERVER_URL: 'http://spatial.test' },
  }),
}))

const ExplorerStub = {
  name: 'RipplingExplorer',
  props: {
    minimal: { type: Boolean, default: false },
    initialLat: { default: null },
    initialLng: { default: null },
    initialView: { default: null },
    initialElapsedHours: { default: null },
    actualElapsedHours: { default: null },
    actualReach: { default: null },
    spatialUrl: { default: null },
    jwt: { default: null },
  },
  template:
    '<div class="explorer-stub" :data-lat="initialLat" :data-lng="initialLng" :data-view="initialView" :data-minimal="minimal" :data-spatial="spatialUrl" :data-actual="actualElapsedHours" :data-reach="actualReach" />',
}

// arrival N hours ago, as an ISO string.
function hoursAgo(n) {
  return new Date(Date.now() - n * 3600 * 1000).toISOString()
}

function mountComponent(props = { lat: 51.5, lng: -0.1 }) {
  return mount(ModMessageReachMap, {
    props,
    global: {
      stubs: {
        'b-modal': {
          template: '<div class="b-modal"><slot name="default" /></div>',
          props: ['title', 'fullscreen'],
        },
        RipplingExplorer: ExplorerStub,
      },
    },
  })
}

describe('ModMessageReachMap', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('exposes show and hide', () => {
    const wrapper = mountComponent()
    expect(typeof wrapper.vm.show).toBe('function')
    expect(typeof wrapper.vm.hide).toBe('function')
  })

  it('does not render the explorer until shown', () => {
    const wrapper = mountComponent()
    expect(wrapper.find('.explorer-stub').exists()).toBe(false)
  })

  it('renders the explorer seeded at the post point when shown', async () => {
    const wrapper = mountComponent({ lat: 55.95, lng: -3.19 })
    await wrapper.vm.show()
    await flushPromises()

    expect(mockShow).toHaveBeenCalled()
    const explorer = wrapper.find('.explorer-stub')
    expect(explorer.exists()).toBe(true)
    expect(explorer.attributes('data-lat')).toBe('55.95')
    expect(explorer.attributes('data-lng')).toBe('-3.19')
    expect(explorer.attributes('data-view')).toBe('outbound')
    // minimal mode: no panel / tunable controls, just map + scrubber.
    expect(explorer.attributes('data-minimal')).toBeTruthy()
    expect(explorer.attributes('data-spatial')).toBe('http://spatial.test')
  })

  it('passes a "now" (actual) point only when the engine is behind expected', async () => {
    // Post ~14h old -> expected tick 4 (12h band). Actual tick 2 (3h) = behind.
    mockFetchReach.mockResolvedValueOnce({ rippling: true, tick: 2, totalticks: 9 })
    const wrapper = mountComponent({
      messageid: 42,
      lat: 55.95,
      lng: -3.19,
      arrival: hoursAgo(14),
    })
    await wrapper.vm.show()
    await flushPromises()
    expect(mockFetchReach).toHaveBeenCalledWith(42, false)
    // actual = HAZARD_HOURS[tick-1] = HAZARD_HOURS[1] = 3
    expect(wrapper.find('.explorer-stub').attributes('data-actual')).toBe('3')
  })

  it('does NOT pass a "now" point when the engine is up to date', async () => {
    // Post ~14h old -> expected tick 4. Actual tick 4 = caught up.
    mockFetchReach.mockResolvedValueOnce({ rippling: true, tick: 4, totalticks: 9 })
    const wrapper = mountComponent({
      messageid: 42,
      lat: 55.95,
      lng: -3.19,
      arrival: hoursAgo(14),
    })
    await wrapper.vm.show()
    await flushPromises()
    const a = wrapper.find('.explorer-stub').attributes('data-actual')
    expect(a === undefined || a === '').toBe(true)
  })

  it('does NOT pass a "now" point when the post is not rippling (dark)', async () => {
    mockFetchReach.mockResolvedValueOnce({ rippling: false, reason: 'disabled' })
    const wrapper = mountComponent({
      messageid: 42,
      lat: 55.95,
      lng: -3.19,
      arrival: hoursAgo(14),
    })
    await wrapper.vm.show()
    await flushPromises()
    const a = wrapper.find('.explorer-stub').attributes('data-actual')
    expect(a === undefined || a === '').toBe(true)
  })

  // The mod-only reach endpoint now returns the ACTUAL stored outline as GeoJSON; the modal
  // hands it to the explorer to overlay against the projection, and explains the overlay.
  it('passes the actual stored reach outline through to the explorer', async () => {
    const POLY =
      '{"type":"Polygon","coordinates":[[[-0.2,51.4],[0,51.4],[0,51.6],[-0.2,51.4]]]}'
    mockFetchReach.mockResolvedValueOnce({
      rippling: true,
      tick: 4,
      totalticks: 9,
      status: 'expanding',
      polygon: POLY,
    })
    const wrapper = mountComponent({
      messageid: 42,
      lat: 55.95,
      lng: -3.19,
      arrival: hoursAgo(14),
    })
    await wrapper.vm.show()
    await flushPromises()
    expect(wrapper.find('.explorer-stub').attributes('data-reach')).toBe(POLY)
    expect(wrapper.text()).toContain('actual reach right now')
  })

  // A held reach is frozen, so the projection overstates it - the modal must say so
  // rather than letting the animated schedule read as reality.
  it('flags a held (frozen) reach above the map', async () => {
    mockFetchReach.mockResolvedValueOnce({
      rippling: true,
      tick: 3,
      totalticks: 9,
      status: 'held',
      polygon:
        '{"type":"Polygon","coordinates":[[[-0.2,51.4],[0,51.4],[0,51.6],[-0.2,51.4]]]}',
    })
    const wrapper = mountComponent({
      messageid: 42,
      lat: 55.95,
      lng: -3.19,
      arrival: hoursAgo(14),
    })
    await wrapper.vm.show()
    await flushPromises()
    expect(wrapper.text()).toContain('frozen (held)')
  })

  it('shows a no-location message instead of the map when the post has no location', async () => {
    const wrapper = mountComponent({ lat: null, lng: null })
    await wrapper.vm.show()
    await flushPromises()

    expect(wrapper.text()).toContain("no location")
    expect(wrapper.find('.explorer-stub').exists()).toBe(false)
  })
})
