import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import ModMessageReachMap from '~/modtools/components/ModMessageReachMap.vue'

const mockShow = vi.fn()
const mockHide = vi.fn()

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
    spatialUrl: { default: null },
    jwt: { default: null },
  },
  template:
    '<div class="explorer-stub" :data-lat="initialLat" :data-lng="initialLng" :data-view="initialView" :data-minimal="minimal" :data-spatial="spatialUrl" />',
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

  it('shows a no-location message instead of the map when the post has no location', async () => {
    const wrapper = mountComponent({ lat: null, lng: null })
    await wrapper.vm.show()
    await flushPromises()

    expect(wrapper.text()).toContain("no location")
    expect(wrapper.find('.explorer-stub').exists()).toBe(false)
  })
})
