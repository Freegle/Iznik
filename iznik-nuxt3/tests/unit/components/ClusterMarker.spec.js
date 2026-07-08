import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import ClusterMarker from '~/components/ClusterMarker.vue'

// Mock Supercluster
const mockGetClusters = vi.fn().mockReturnValue([])
const mockGetClusterExpansionZoom = vi.fn().mockReturnValue(10)
const mockLoad = vi.fn()

vi.mock('supercluster/dist/supercluster', () => {
  return {
    default: vi.fn().mockImplementation(() => ({
      load: mockLoad,
      getClusters: mockGetClusters,
      getClusterExpansionZoom: mockGetClusterExpansionZoom,
    })),
  }
})

// Mock constants
vi.mock('~/constants', () => ({
  MAX_MAP_ZOOM: 16,
}))

describe('ClusterMarker', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockGetClusters.mockReturnValue([])
  })

  const createMockMap = (options = {}) => ({
    getBounds: vi.fn().mockReturnValue({
      getNorthWest: () => ({ lat: 52.5, lng: -1.2 }),
      getSouthEast: () => ({ lat: 52.0, lng: -0.8 }),
    }),
    getZoom: vi.fn().mockReturnValue(10),
    flyTo: vi.fn(),
    on: vi.fn(),
    off: vi.fn(),
    ...options,
  })

  function createWrapper(props = {}) {
    const mockMap = createMockMap()
    return mount(ClusterMarker, {
      props: {
        markers: [],
        map: mockMap,
        ...props,
      },
      global: {
        stubs: {
          'l-marker': {
            template:
              '<div class="l-marker" @click="$emit(\'click\')"><slot /></div>',
            props: ['latLng', 'interactive', 'title', 'className'],
          },
          'l-icon': {
            template: '<div class="l-icon"><slot /></div>',
            props: ['className'],
          },
          ClusterIcon: {
            template:
              '<div class="cluster-icon" :data-count="count" :data-tag="tag" />',
            props: ['count', 'tag', 'className'],
          },
        },
      },
    })
  }

  describe('rendering', () => {
    it('does not render when clusters is empty', () => {
      mockGetClusters.mockReturnValue([])
      const wrapper = createWrapper()
      expect(wrapper.find('.l-marker').exists()).toBe(false)
    })

    it('renders markers for cluster points', () => {
      mockGetClusters.mockReturnValue([
        {
          id: 1,
          properties: { point_count: 5, cluster_id: 1 },
          geometry: { coordinates: [-1.0, 52.2] },
        },
      ])
      const wrapper = createWrapper({
        markers: [
          { id: 1, lat: 52.2, lng: -1.0 },
          { id: 2, lat: 52.3, lng: -1.1 },
        ],
      })
      expect(wrapper.find('.l-marker').exists()).toBe(true)
    })

    it('renders cluster icon with count', () => {
      mockGetClusters.mockReturnValue([
        {
          id: 1,
          properties: { point_count: 10, cluster_id: 1 },
          geometry: { coordinates: [-1.0, 52.2] },
        },
      ])
      const wrapper = createWrapper({
        markers: Array(15)
          .fill(null)
          .map((_, i) => ({ id: i, lat: 52.2 + i * 0.01, lng: -1.0 })),
      })
      const clusterIcon = wrapper.find('.cluster-icon')
      expect(clusterIcon.exists()).toBe(true)
      expect(clusterIcon.attributes('data-count')).toBe('10')
    })

    it('renders with custom tag', () => {
      mockGetClusters.mockReturnValue([
        {
          id: 1,
          properties: { point_count: 3, cluster_id: 1 },
          geometry: { coordinates: [-1.0, 52.2] },
        },
      ])
      const wrapper = createWrapper({
        markers: Array(15)
          .fill(null)
          .map((_, i) => ({ id: i, lat: 52.2, lng: -1.0 })),
        tag: 'custom-tag',
      })
      const clusterIcon = wrapper.find('.cluster-icon')
      expect(clusterIcon.attributes('data-tag')).toBe('custom-tag')
    })
  })

  describe('props', () => {
    it('requires markers array', () => {
      const wrapper = createWrapper({ markers: [{ id: 1, lat: 52, lng: -1 }] })
      expect(wrapper.props('markers')).toHaveLength(1)
    })

    it('requires map object', () => {
      const wrapper = createWrapper()
      expect(wrapper.props('map')).toBeDefined()
      expect(typeof wrapper.props('map').getBounds).toBe('function')
    })

    it('defaults radius to 60', () => {
      const wrapper = createWrapper()
      expect(wrapper.props('radius')).toBe(60)
    })

    it('defaults extent to 256', () => {
      const wrapper = createWrapper()
      expect(wrapper.props('extent')).toBe(256)
    })

    it('defaults maxZoom to MAX_MAP_ZOOM', () => {
      const wrapper = createWrapper()
      expect(wrapper.props('maxZoom')).toBe(16)
    })

    it('defaults minCluster to 10', () => {
      const wrapper = createWrapper()
      expect(wrapper.props('minCluster')).toBe(10)
    })

    it('defaults cssClass to empty string', () => {
      const wrapper = createWrapper()
      expect(wrapper.props('cssClass')).toBe('')
    })

    it('accepts custom radius', () => {
      const wrapper = createWrapper({ radius: 100 })
      expect(wrapper.props('radius')).toBe(100)
    })

    it('accepts custom cssClass', () => {
      const wrapper = createWrapper({ cssClass: 'custom-class' })
      expect(wrapper.props('cssClass')).toBe('custom-class')
    })
  })

  describe('emit events', () => {
    it('emits click on cluster click', async () => {
      mockGetClusters.mockReturnValue([
        {
          id: 1,
          properties: { point_count: 5, cluster_id: 1 },
          geometry: { coordinates: [-1.0, 52.2] },
        },
      ])
      const wrapper = createWrapper({
        markers: Array(15)
          .fill(null)
          .map((_, i) => ({ id: i, lat: 52.2, lng: -1.0 })),
      })
      const marker = wrapper.find('.l-marker')
      await marker.trigger('click')
      expect(wrapper.emitted('click')).toBeTruthy()
    })
  })

  // Bug class: the overlap-spreading `points` computed used to do
  // `marker.lat = Math.max(nelat, marker.lat + (already+1)*0.003)`, which (a) MUTATED
  // the shared marker objects, (b) ACCUMULATED the offset on every recompute, and
  // (c) snapped every duplicate to the map's north edge. These tests pin down the
  // fixed behaviour: markers are nudged into local render coordinates without ever
  // touching the input objects.
  //
  // `points` is only actually evaluated (and so fed into Supercluster's `load`) when
  // the `clusters` computed goes down the "many markers" path (markers.length >=
  // minCluster) - so these tests use `minCluster: 0` to force that path on every
  // render, and inspect what was passed to the mocked `load()` to see the computed
  // points without needing to reach into component internals.
  describe('points computation (marker overlap-spreading safety)', () => {
    function lastLoadedPoints() {
      const calls = mockLoad.mock.calls
      expect(calls.length).toBeGreaterThan(0)
      return calls[calls.length - 1][0]
    }

    it('never mutates the input marker objects', () => {
      const markers = [
        { id: 1, lat: 52.2, lng: -1.0 },
        { id: 2, lat: 52.2, lng: -1.0 },
        { id: 3, lat: 52.2, lng: -1.0 },
      ]
      const before = JSON.parse(JSON.stringify(markers))

      createWrapper({ markers, minCluster: 0 })

      expect(markers).toEqual(before)
      // Specifically the properties the old buggy code wrote to.
      markers.forEach((m, i) => {
        expect(m.lat).toBe(before[i].lat)
        expect(m.lng).toBe(before[i].lng)
      })
    })

    it('gives duplicate-location markers distinct render coordinates', () => {
      const markers = [
        { id: 1, lat: 52.2, lng: -1.0 },
        { id: 2, lat: 52.2, lng: -1.0 },
        { id: 3, lat: 52.2, lng: -1.0 },
      ]
      createWrapper({ markers, minCluster: 0 })

      const points = lastLoadedPoints()
      const coords = points.map((p) => p.geometry.coordinates.join(','))
      // All three should render at distinct positions.
      expect(new Set(coords).size).toBe(3)
    })

    it('keeps nudged duplicates NEAR the real location, not flung to a map edge', () => {
      const markers = [
        { id: 1, lat: 52.2, lng: -1.0 },
        { id: 2, lat: 52.2, lng: -1.0 },
        { id: 3, lat: 52.2, lng: -1.0 },
        { id: 4, lat: 52.2, lng: -1.0 },
      ]
      const mockMap = createMockMap()
      createWrapper({ markers, minCluster: 0, map: mockMap })

      const points = lastLoadedPoints()
      const bounds = mockMap.getBounds()
      const northEdge = bounds.getNorthWest().lat

      points.forEach((p) => {
        const [lng, lat] = p.geometry.coordinates
        // Small offset only - within a fraction of a degree of the real location.
        expect(Math.abs(lat - 52.2)).toBeLessThan(0.1)
        expect(Math.abs(lng - -1.0)).toBeLessThan(0.1)
        // Must not have been snapped to the map's north edge (the old bug).
        expect(lat).not.toBeCloseTo(northEdge, 5)
      })
    })

    it('leaves the first marker at a location unmoved and nudges subsequent ones by a small fixed step', () => {
      const markers = [
        { id: 1, lat: 52.2, lng: -1.0 },
        { id: 2, lat: 52.2, lng: -1.0 },
        { id: 3, lat: 52.2, lng: -1.0 },
      ]
      createWrapper({ markers, minCluster: 0 })

      const points = lastLoadedPoints()
      const byId = Object.fromEntries(points.map((p) => [p.id, p]))

      expect(byId[1].geometry.coordinates).toEqual([-1.0, 52.2])
      expect(byId[2].geometry.coordinates[0]).toBeCloseTo(-1.0 + 0.003, 10)
      expect(byId[2].geometry.coordinates[1]).toBeCloseTo(52.2 + 0.003, 10)
      expect(byId[3].geometry.coordinates[0]).toBeCloseTo(-1.0 + 0.006, 10)
      expect(byId[3].geometry.coordinates[1]).toBeCloseTo(52.2 + 0.006, 10)
    })

    it('does not offset markers which are at distinct locations', () => {
      const markers = [
        { id: 1, lat: 52.2, lng: -1.0 },
        { id: 2, lat: 52.3, lng: -1.1 },
      ]
      createWrapper({ markers, minCluster: 0 })

      const points = lastLoadedPoints()
      const byId = Object.fromEntries(points.map((p) => [p.id, p]))
      expect(byId[1].geometry.coordinates).toEqual([-1.0, 52.2])
      expect(byId[2].geometry.coordinates).toEqual([-1.1, 52.3])
    })

    it('is idempotent across repeated recomputes with the SAME marker objects (no drift)', async () => {
      // Mirrors how the parent (PostMap) rebuilds the markers array on every render
      // from the store: a NEW array wrapper around the SAME underlying marker
      // objects. The old bug accumulated an extra +0.003 offset every time this
      // recomputed because it wrote the nudge back onto marker.lat itself.
      const sharedMarkers = [
        { id: 1, lat: 52.2, lng: -1.0 },
        { id: 2, lat: 52.2, lng: -1.0 },
      ]

      const wrapper = createWrapper({ markers: sharedMarkers, minCluster: 0 })
      const firstPoints = lastLoadedPoints()
      const firstSecond = firstPoints.find((p) => p.id === 2).geometry
        .coordinates

      mockLoad.mockClear()

      // Force a recompute with a new array reference wrapping the same objects.
      await wrapper.setProps({ markers: [...sharedMarkers], radius: 61 })

      const secondPoints = lastLoadedPoints()
      const secondSecond = secondPoints.find((p) => p.id === 2).geometry
        .coordinates

      expect(secondSecond[0]).toBeCloseTo(firstSecond[0], 10)
      expect(secondSecond[1]).toBeCloseTo(firstSecond[1], 10)

      // And the underlying objects are still untouched.
      expect(sharedMarkers[0]).toEqual({ id: 1, lat: 52.2, lng: -1.0 })
      expect(sharedMarkers[1]).toEqual({ id: 2, lat: 52.2, lng: -1.0 })

      // Recompute a third time for good measure - still no drift.
      mockLoad.mockClear()
      await wrapper.setProps({ markers: [...sharedMarkers], radius: 62 })
      const thirdPoints = lastLoadedPoints()
      const thirdSecond = thirdPoints.find((p) => p.id === 2).geometry
        .coordinates
      expect(thirdSecond[0]).toBeCloseTo(firstSecond[0], 10)
      expect(thirdSecond[1]).toBeCloseTo(firstSecond[1], 10)
    })

    it('skips markers with neither lat nor lng set', () => {
      const markers = [
        { id: 1, lat: 0, lng: 0 },
        { id: 2, lat: 52.2, lng: -1.0 },
      ]
      createWrapper({ markers, minCluster: 0 })
      const points = lastLoadedPoints()
      // Marker 1 has lat=0 AND lng=0 (falsy/falsy), so it's excluded; only marker 2
      // produces a point.
      expect(points.length).toBe(1)
      expect(points[0].id).toBe(2)
    })
  })

  describe('minCluster behavior', () => {
    it('uses points directly (bypassing Supercluster) when markers count is below minCluster', () => {
      const markers = [
        { id: 1, lat: 52.2, lng: -1.0 },
        { id: 2, lat: 52.3, lng: -1.1 },
      ]
      createWrapper({
        markers,
        minCluster: 10, // markers.length < minCluster
      })
      // getClusters should never be reached down this path.
      expect(mockGetClusters).not.toHaveBeenCalled()
    })

    it('uses Supercluster getClusters when markers count is at/above minCluster', () => {
      const markers = [
        { id: 1, lat: 52.2, lng: -1.0 },
        { id: 2, lat: 52.3, lng: -1.1 },
      ]
      createWrapper({
        markers,
        minCluster: 2,
      })
      expect(mockGetClusters).toHaveBeenCalled()
    })
  })

  describe('bounds handling', () => {
    it('handles map without bounds', () => {
      const mockMap = createMockMap({
        getBounds: vi.fn().mockReturnValue(null),
      })
      const wrapper = createWrapper({
        map: mockMap,
        markers: [{ id: 1, lat: 52.2, lng: -1.0 }],
      })
      // Should not crash
      expect(wrapper.exists()).toBe(true)
    })
  })

  // Bug class: clusters used to be computed purely from getZoom()/getBounds() read
  // imperatively inside a `computed`, which Vue can't track - so without an explicit
  // reactive trigger, the clustering would freeze at whatever zoom/pan it was first
  // computed at. `mapEpoch` is bumped on the map's own 'zoomend moveend' events to
  // force a recompute.
  describe('cluster recompute on zoom/pan (mapEpoch)', () => {
    function getBoundHandler(mockMap) {
      const call = mockMap.on.mock.calls.find((c) => c[0] === 'zoomend moveend')
      expect(call).toBeTruthy()
      return call[1]
    }

    it('registers a zoomend/moveend listener on the map when mounted', () => {
      const mockMap = createMockMap()
      createWrapper({
        map: mockMap,
        markers: [{ id: 1, lat: 52.2, lng: -1.0 }],
        minCluster: 0,
      })
      expect(mockMap.on).toHaveBeenCalledWith(
        'zoomend moveend',
        expect.any(Function)
      )
    })

    it('recomputes clusters (re-reads getZoom) when the map fires zoomend/moveend', async () => {
      const mockMap = createMockMap()
      mockGetClusters.mockReturnValue([
        {
          id: 1,
          properties: { point_count: 1, cluster_id: 1 },
          geometry: { coordinates: [-1.0, 52.2] },
        },
      ])
      createWrapper({
        map: mockMap,
        markers: [{ id: 1, lat: 52.2, lng: -1.0 }],
        minCluster: 0,
      })
      await nextTick()

      const callsBefore = mockGetClusters.mock.calls.length
      expect(callsBefore).toBeGreaterThan(0)
      expect(mockGetClusters.mock.calls[callsBefore - 1][1]).toBe(10) // initial zoom

      // Simulate the map zooming from 10 to 14.
      mockMap.getZoom.mockReturnValue(14)
      const handler = getBoundHandler(mockMap)
      handler()
      await nextTick()

      const callsAfter = mockGetClusters.mock.calls.length
      expect(callsAfter).toBeGreaterThan(callsBefore)
      expect(mockGetClusters.mock.calls[callsAfter - 1][1]).toBe(14)
    })

    it('recomputes again on a second zoomend/moveend (not just once)', async () => {
      const mockMap = createMockMap()
      mockGetClusters.mockReturnValue([])
      createWrapper({
        map: mockMap,
        markers: [{ id: 1, lat: 52.2, lng: -1.0 }],
        minCluster: 0,
      })
      await nextTick()
      const handler = getBoundHandler(mockMap)

      mockMap.getZoom.mockReturnValue(11)
      handler()
      await nextTick()
      const callsAfterFirst = mockGetClusters.mock.calls.length

      mockMap.getZoom.mockReturnValue(12)
      handler()
      await nextTick()
      const callsAfterSecond = mockGetClusters.mock.calls.length

      expect(callsAfterSecond).toBeGreaterThan(callsAfterFirst)
      expect(mockGetClusters.mock.calls[callsAfterSecond - 1][1]).toBe(12)
    })

    it('removes the zoomend/moveend listener on unmount', () => {
      const mockMap = createMockMap()
      const wrapper = createWrapper({
        map: mockMap,
        markers: [{ id: 1, lat: 52.2, lng: -1.0 }],
        minCluster: 0,
      })
      const handler = getBoundHandler(mockMap)

      wrapper.unmount()

      expect(mockMap.off).toHaveBeenCalledWith('zoomend moveend', handler)
    })

    it('re-binds to a new map instance when the map prop changes', async () => {
      const mockMapA = createMockMap()
      const wrapper = createWrapper({
        map: mockMapA,
        markers: [{ id: 1, lat: 52.2, lng: -1.0 }],
        minCluster: 0,
      })
      const handlerA = getBoundHandler(mockMapA)

      const mockMapB = createMockMap()
      await wrapper.setProps({ map: mockMapB })

      // Old map's listener removed, new map's listener registered.
      expect(mockMapA.off).toHaveBeenCalledWith('zoomend moveend', handlerA)
      expect(mockMapB.on).toHaveBeenCalledWith(
        'zoomend moveend',
        expect.any(Function)
      )
    })
  })
})
