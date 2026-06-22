import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import ModMessageReachMap from '~/modtools/components/ModMessageReachMap.vue'

const mockShow = vi.fn()
const mockHide = vi.fn()
const mockFetchReach = vi.fn()

vi.mock('~/composables/useOurModal', () => ({
  useOurModal: () => ({
    modal: ref(null),
    show: mockShow,
    hide: mockHide,
  }),
}))

vi.mock('~/composables/useMap', () => ({
  attribution: () => 'attr',
  osmtile: () => 'tileurl',
}))

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    fetchReach: mockFetchReach,
  }),
}))

vi.mock('@vue-leaflet/vue-leaflet', () => ({
  LGeoJson: { template: '<div class="l-geo-json" />' },
}))

function mountComponent() {
  return mount(ModMessageReachMap, {
    props: { messageid: 42 },
    global: {
      stubs: {
        'b-modal': {
          template:
            '<div class="b-modal"><slot name="default" /><slot name="footer" /></div>',
          props: ['title', 'size'],
        },
        'b-button': {
          template: '<button @click="$emit(\'click\')"><slot /></button>',
        },
        'b-spinner': { template: '<span class="spinner" />' },
        NoticeMessage: {
          template: '<div class="notice"><slot /></div>',
          props: ['variant'],
        },
        'l-map': { template: '<div class="l-map"><slot /></div>' },
        'l-tile-layer': { template: '<div class="l-tile-layer" />' },
        'l-marker': { template: '<div class="l-marker" />' },
        'v-icon': { template: '<i />' },
      },
    },
  })
}

// Build a MySQL-style UTC timestamp string a given number of hours from now.
function utcTimestampInHours(hours) {
  const d = new Date(Date.now() + hours * 3600 * 1000)
  return d.toISOString().slice(0, 19).replace('T', ' ')
}

describe('ModMessageReachMap', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('shows a "not rippling yet" notice when the post has no reach', async () => {
    mockFetchReach.mockResolvedValue({ rippling: false, msgid: 42 })
    const wrapper = mountComponent()
    await wrapper.vm.show()
    await flushPromises()

    expect(mockFetchReach).toHaveBeenCalledWith(42)
    expect(mockShow).toHaveBeenCalled()
    expect(wrapper.find('.notice').exists()).toBe(true)
    expect(wrapper.find('.notice').text()).toContain("isn't rippling out yet")
    expect(wrapper.find('.l-map').exists()).toBe(false)
  })

  it('renders the reach map and details when the post is rippling', async () => {
    mockFetchReach.mockResolvedValue({
      rippling: true,
      msgid: 42,
      lat: 51.5,
      lng: -0.1,
      tick: 3,
      totalticks: 9,
      status: 'expanding',
      nextexpansionat: utcTimestampInHours(3),
      polygon: { type: 'Polygon', coordinates: [[[0, 0]]] },
    })
    const wrapper = mountComponent()
    await wrapper.vm.show()
    await flushPromises()

    expect(wrapper.find('.l-map').exists()).toBe(true)
    expect(wrapper.find('.l-geo-json').exists()).toBe(true)
    expect(wrapper.text()).toContain('Step 3 of 9')
    expect(wrapper.text()).toContain('Still rippling out')
  })

  it('surfaces when the next ripple-out is due, in hours', async () => {
    mockFetchReach.mockResolvedValue({
      rippling: true,
      msgid: 42,
      lat: 51.5,
      lng: -0.1,
      tick: 3,
      totalticks: 9,
      status: 'expanding',
      nextexpansionat: utcTimestampInHours(3),
      polygon: { type: 'Polygon', coordinates: [[[0, 0]]] },
    })
    const wrapper = mountComponent()
    await wrapper.vm.show()
    await flushPromises()

    expect(wrapper.text()).toContain('Due in about 3 hours')
  })

  it('says fully rippled out when expansion is done', async () => {
    mockFetchReach.mockResolvedValue({
      rippling: true,
      msgid: 42,
      lat: 51.5,
      lng: -0.1,
      tick: 9,
      totalticks: 9,
      status: 'done',
      nextexpansionat: null,
      polygon: { type: 'Polygon', coordinates: [[[0, 0]]] },
    })
    const wrapper = mountComponent()
    await wrapper.vm.show()
    await flushPromises()

    expect(wrapper.text()).toContain('Fully rippled out')
  })

  it('exposes show and hide', async () => {
    const wrapper = mountComponent()
    expect(typeof wrapper.vm.show).toBe('function')
    expect(typeof wrapper.vm.hide).toBe('function')
  })
})
