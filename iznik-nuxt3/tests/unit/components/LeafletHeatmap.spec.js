import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent } from 'vue'
import LeafletHeatmap from '~/components/LeafletHeatmap.vue'

// The component builds its layer from L.Layer.extend and only needs setOptions
// and requestAnimFrame until the layer is attached to a real map, so a small
// stub is enough to drive the real component (the same approach PostMap.spec.js
// takes for leaflet-control-geocoder).
vi.mock('leaflet/dist/leaflet-src.esm', () => ({
  Layer: {
    extend: (def) =>
      function HeatLayerStub(latlngs, options) {
        Object.assign(this, def)
        this.initialize(latlngs, options)
      },
  },
  setOptions: (target, options) => {
    target.options = options
  },
  Util: { requestAnimFrame: vi.fn() },
}))

// The component top-level-awaits the leaflet import, so it must mount inside
// a Suspense boundary. The wrapper also plays the part of the LMap parent:
// findRealParent() walks up looking for `leafletObject` in a parent's
// setupState, which is where a setup() return value lands.
function mountHeatmap({ latLngs = [], leafletObject } = {}) {
  const Host = defineComponent({
    components: { LeafletHeatmap },
    props: { latLngs: { type: Array, default: () => [] } },
    setup() {
      return { leafletObject }
    },
    template:
      '<Suspense><LeafletHeatmap ref="heat" :lat-lngs="latLngs" /></Suspense>',
  })
  return mount(Host, { props: { latLngs } })
}

describe('LeafletHeatmap', () => {
  let map

  beforeEach(() => {
    vi.useFakeTimers()
    map = { addLayer: vi.fn(), removeLayer: vi.fn() }
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  async function settle(wrapper) {
    await flushPromises()
    vi.advanceTimersByTime(100)
    await flushPromises()
    return wrapper
  }

  it('attaches a heat layer carrying the points to the parent map', async () => {
    const wrapper = await settle(
      mountHeatmap({ latLngs: [[53.9, -2.5, 0.5]], leafletObject: map })
    )

    expect(map.addLayer).toHaveBeenCalledTimes(1)
    const layer = map.addLayer.mock.calls[0][0]
    expect(layer._latlngs).toEqual([[53.9, -2.5, 0.5]])
    expect(layer.options.max).toBe(1.0)
    expect(layer.options.radius).toBe(25)
    wrapper.unmount()
  })

  it('survives mounting with no surrounding map', async () => {
    // Regression pin: the legacy tail of onMounted dereferenced a null
    // `options` ~100ms after every mount, throwing inside the setTimeout
    // whether or not a map was present.
    const wrapper = await settle(mountHeatmap({ latLngs: [[53.9, -2.5, 1]] }))
    expect(map.addLayer).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('exposes addLatLng, which feeds the live layer', async () => {
    const wrapper = await settle(
      mountHeatmap({ latLngs: [[53.9, -2.5, 0.5]], leafletObject: map })
    )

    wrapper.findComponent(LeafletHeatmap).vm.addLatLng([54.0, -2.0, 1])
    const layer = map.addLayer.mock.calls[0][0]
    expect(layer._latlngs).toHaveLength(2)
    wrapper.unmount()
  })

  it('replacing the latLngs prop replaces the layer data', async () => {
    const wrapper = await settle(
      mountHeatmap({ latLngs: [[53.9, -2.5, 0.5]], leafletObject: map })
    )

    await wrapper.setProps({ latLngs: [[51.5, -0.1, 0.9]] })
    const layer = map.addLayer.mock.calls[0][0]
    expect(layer._latlngs).toEqual([[51.5, -0.1, 0.9]])
    wrapper.unmount()
  })

  it('removes its layer from the map on unmount', async () => {
    const wrapper = await settle(
      mountHeatmap({ latLngs: [[53.9, -2.5, 0.5]], leafletObject: map })
    )

    const layer = map.addLayer.mock.calls[0][0]
    wrapper.unmount()
    expect(map.removeLayer).toHaveBeenCalledWith(layer)
  })
})
