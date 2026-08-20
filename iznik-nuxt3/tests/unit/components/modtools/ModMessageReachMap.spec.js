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
    hideProjection: { type: Boolean, default: false },
    initialLat: { default: null },
    initialLng: { default: null },
    initialView: { default: null },
    initialElapsedHours: { default: null },
    actualReach: { default: null },
    spatialUrl: { default: null },
    jwt: { default: null },
  },
  template:
    '<div class="explorer-stub" :data-lat="initialLat" :data-lng="initialLng" :data-view="initialView" :data-minimal="minimal" :data-spatial="spatialUrl" :data-hideprojection="hideProjection" :data-reach="actualReach" />',
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

  // Once the endpoint can tell us where a post REALLY got to, drawing a schedule-modelled
  // projection beside it just puts two contradictory outlines on one map, so the modal
  // suppresses the projection and the actual reach is the only thing drawn.
  it('tells the explorer to suppress the projected reach', async () => {
    const wrapper = mountComponent({
      messageid: 42,
      lat: 55.95,
      lng: -3.19,
      arrival: hoursAgo(14),
    })
    await wrapper.vm.show()
    await flushPromises()
    expect(
      wrapper.find('.explorer-stub').attributes('data-hideprojection')
    ).toBeTruthy()
  })

  // The mod-only reach endpoint returns the ACTUAL stored outline as GeoJSON; the modal
  // hands it to the explorer to draw, and says what the shape means.
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
    expect(mockFetchReach).toHaveBeenCalledWith(42, false)
    expect(wrapper.find('.explorer-stub').attributes('data-reach')).toBe(POLY)
    expect(wrapper.text()).toContain('actually rippled out')
  })

  // With no projection to fall back on, a post that hasn't rippled would otherwise show a
  // bare map with no explanation of why nothing is drawn.
  it('explains when the post has not rippled out', async () => {
    mockFetchReach.mockResolvedValueOnce({ rippling: false, reason: 'pending' })
    const wrapper = mountComponent({
      messageid: 42,
      lat: 55.95,
      lng: -3.19,
      arrival: hoursAgo(14),
    })
    await wrapper.vm.show()
    await flushPromises()
    expect(wrapper.text()).toContain("hasn't rippled out yet")
  })

  // A held reach is frozen short of where the post is still listed - the modal must say so.
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

    expect(wrapper.text()).toContain('no location')
    expect(wrapper.find('.explorer-stub').exists()).toBe(false)
  })
})
