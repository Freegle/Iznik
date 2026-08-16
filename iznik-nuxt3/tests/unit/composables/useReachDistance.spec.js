import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref } from 'vue'
import {
  BROWSE_DISTANCE_UNLIMITED,
  BROWSE_MINUTES_MAX,
  BROWSE_MINUTES_FALLBACK_MAX,
} from '~/constants'

// The slider persists BOTH settings.browseMaxMinutes (source of truth) and
// settings.browseMaxDistance (the derived radius the fast feed/digest filters
// read). The pair must never diverge: a stale radius silently filters to a cap
// the slider no longer shows (seen live: slider at 25 minutes, feed capped at
// a 1-mile radius left over from an earlier position).

const mockSaveAndGet = vi.fn().mockResolvedValue(undefined)
vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({ saveAndGet: mockSaveAndGet }),
}))

const mockMe = ref(null)
vi.mock('~/composables/useMe', () => ({
  useMe: () => ({ me: mockMe }),
}))

const mockFetchNear = vi.fn()
vi.mock('~/api', () => ({
  default: () => ({
    town: {
      fetchNear: (...args) => mockFetchNear(...args),
    },
  }),
}))

vi.stubGlobal('useRuntimeConfig', () => ({ public: {} }))

describe('useReachDistance onSliderChange', () => {
  let useReachDistance

  beforeEach(async () => {
    vi.clearAllMocks()
    mockMe.value = {
      lat: 53.4,
      lng: -1.3,
      settings: { browseMaxMinutes: 5, browseMaxDistance: 1 },
    }
    const mod = await import('~/composables/useReachDistance')
    useReachDistance = mod.useReachDistance
  })

  it('persists the derived radius when the routing lookup succeeds', async () => {
    mockFetchNear.mockResolvedValue({ reach_radius_miles: 8.5 })
    const { onSliderChange } = useReachDistance()

    await onSliderChange(25)

    const saved = mockSaveAndGet.mock.calls[0][0].settings
    expect(saved.browseMaxMinutes).toBe(25)
    expect(saved.browseMaxDistance).toBe(8.5)
  })

  it('clears a stale cap instead of keeping it when the routing lookup fails', async () => {
    mockFetchNear.mockRejectedValue(new Error('routing down'))
    const { onSliderChange } = useReachDistance()

    await onSliderChange(25)

    const saved = mockSaveAndGet.mock.calls[0][0].settings
    expect(saved.browseMaxMinutes).toBe(25)
    // The old 1-mile cap must NOT survive a failed derivation - fail open.
    expect(saved.browseMaxDistance).toBe(BROWSE_DISTANCE_UNLIMITED)
  })

  it('clears a stale cap when the member has no known location', async () => {
    mockMe.value.lat = null
    mockMe.value.lng = null
    const { onSliderChange } = useReachDistance()

    await onSliderChange(15)

    const saved = mockSaveAndGet.mock.calls[0][0].settings
    expect(saved.browseMaxMinutes).toBe(15)
    expect(saved.browseMaxDistance).toBe(BROWSE_DISTANCE_UNLIMITED)
    expect(mockFetchNear).not.toHaveBeenCalled()
  })

  // Before the server has answered, the member is on the flat cap - which is BELOW the
  // ceiling the ripple grows to, so the top stop has to be a real radius. Storing the
  // sentinel here would hand an unmeasured member the widest band's reach.
  it('derives a radius at the far-right stop while still on the flat cap', async () => {
    mockFetchNear.mockResolvedValue({ reach_radius_miles: 11.3 })
    const { onSliderChange } = useReachDistance()

    await onSliderChange(BROWSE_MINUTES_FALLBACK_MAX)

    const saved = mockSaveAndGet.mock.calls[0][0].settings
    expect(saved.browseMaxMinutes).toBe(BROWSE_MINUTES_FALLBACK_MAX)
    expect(saved.browseMaxDistance).toBe(11.3)
  })
})

// The reach engine sizes a post's travel-time budget from local freegler density
// (20 dense / 30 medium / 45 sparse), so the top of the slider has to follow the
// member's own band. A fixed top left rural members unable to ask for the 45
// minutes they now actually receive, and told them "Max 10-12 miles by road"
// while the reach engine was already carrying posts further.
describe('useReachDistance density-aware maximum', () => {
  let useReachDistance

  beforeEach(async () => {
    vi.clearAllMocks()
    mockMe.value = {
      lat: 53.4,
      lng: -1.3,
      settings: { browseMaxMinutes: 15, browseMaxDistance: 6 },
    }
    const mod = await import('~/composables/useReachDistance')
    useReachDistance = mod.useReachDistance
  })

  it('uses the flat cap until the server answers', () => {
    const { maxMinutes } = useReachDistance()

    expect(maxMinutes.value).toBe(BROWSE_MINUTES_FALLBACK_MAX)
  })

  it('opens up to the sparse cap for a rural member', async () => {
    mockFetchNear.mockResolvedValue({ cap_minutes: 45, density_band: 'sparse' })
    const { maxMinutes, loadCap } = useReachDistance()

    await loadCap()

    expect(maxMinutes.value).toBe(45)
  })

  it('closes down to the dense cap for a city member', async () => {
    mockFetchNear.mockResolvedValue({ cap_minutes: 20, density_band: 'dense' })
    const { maxMinutes, loadCap } = useReachDistance()

    await loadCap()

    expect(maxMinutes.value).toBe(20)
  })

  it('treats the cap - not a fixed 30 - as the no-limit stop', async () => {
    mockFetchNear.mockResolvedValue({ cap_minutes: 45, density_band: 'sparse' })
    const { onSliderChange, loadCap } = useReachDistance()
    await loadCap()
    mockFetchNear.mockClear()

    await onSliderChange(45)

    const saved = mockSaveAndGet.mock.calls[0][0].settings
    expect(saved.browseMaxMinutes).toBe(45)
    expect(saved.browseMaxDistance).toBe(BROWSE_DISTANCE_UNLIMITED)
    expect(mockFetchNear).not.toHaveBeenCalled()
  })

  // The server grows every post's reach to the widest band's budget, so the
  // unlimited sentinel means "the widest band's worth", not "mine". Only a member
  // whose own band earns the ceiling can safely store it: below the ceiling the
  // top stop still needs a real radius, or a city member would silently inherit
  // the countryside's reach (on live, a 33-mile radius around Peterborough).
  it('stores a real radius at the top stop when the band is below the ceiling', async () => {
    mockFetchNear.mockResolvedValue({
      cap_minutes: 20,
      density_band: 'dense',
      reach_radius_miles: 7.4,
    })
    const { onSliderChange, loadCap } = useReachDistance()
    await loadCap()

    await onSliderChange(20)

    const saved =
      mockSaveAndGet.mock.calls[mockSaveAndGet.mock.calls.length - 1][0]
        .settings
    expect(saved.browseMaxMinutes).toBe(20)
    expect(saved.browseMaxDistance).toBe(7.4)
  })

  it('clamps a drag past the top stop back onto the cap', async () => {
    mockFetchNear.mockResolvedValue({
      cap_minutes: 20,
      density_band: 'dense',
      reach_radius_miles: 7.4,
    })
    const { sliderValue, onSliderChange, loadCap } = useReachDistance()
    await loadCap()

    await onSliderChange(30)

    const saved =
      mockSaveAndGet.mock.calls[mockSaveAndGet.mock.calls.length - 1][0]
        .settings
    expect(saved.browseMaxMinutes).toBe(20)
    expect(sliderValue.value).toBe(20)
  })

  it('still derives a radius below the cap', async () => {
    mockFetchNear.mockResolvedValue({
      cap_minutes: 45,
      density_band: 'sparse',
      reach_radius_miles: 16.2,
    })
    const { onSliderChange, loadCap } = useReachDistance()
    await loadCap()

    await onSliderChange(35)

    const saved = mockSaveAndGet.mock.calls[0][0].settings
    expect(saved.browseMaxMinutes).toBe(35)
    expect(saved.browseMaxDistance).toBe(16.2)
  })

  it('never offers more than the ceiling, whatever the server says', async () => {
    mockFetchNear.mockResolvedValue({
      cap_minutes: 120,
      density_band: 'sparse',
    })
    const { maxMinutes, loadCap } = useReachDistance()

    await loadCap()

    expect(maxMinutes.value).toBe(BROWSE_MINUTES_MAX)
  })

  it('keeps the flat cap when the server sends no cap at all', async () => {
    mockFetchNear.mockResolvedValue({ reach_radius_miles: 8 })
    const { maxMinutes, loadCap } = useReachDistance()

    await loadCap()

    expect(maxMinutes.value).toBe(BROWSE_MINUTES_FALLBACK_MAX)
  })

  it('pulls a saved position above the new cap down onto the slider, and saves the correction', async () => {
    // A city member who chose 25 back when the slider went to 30. Their band now
    // caps at 20, so the reach engine already ignores the last stops - but their
    // stored radius still filters at 25 minutes' worth. Showing the slider at the
    // top while a narrower cap kept filtering is exactly the divergence that made
    // members say "I only see old posts", so the correction is persisted.
    mockMe.value.settings = { browseMaxMinutes: 25, browseMaxDistance: 9 }
    mockFetchNear.mockResolvedValue({
      cap_minutes: 20,
      density_band: 'dense',
      reach_radius_miles: 7.4,
    })
    const { sliderValue, loadCap } = useReachDistance()

    await loadCap()

    expect(sliderValue.value).toBe(20)
    const saved = mockSaveAndGet.mock.calls[0][0].settings
    expect(saved.browseMaxMinutes).toBe(20)
    // Below the ceiling the top stop is a real radius, not the sentinel.
    expect(saved.browseMaxDistance).toBe(7.4)
  })

  it('leaves a saved position inside the new cap alone', async () => {
    mockMe.value.settings = { browseMaxMinutes: 15, browseMaxDistance: 6 }
    mockFetchNear.mockResolvedValue({ cap_minutes: 45, density_band: 'sparse' })
    const { sliderValue, loadCap } = useReachDistance()

    await loadCap()

    expect(sliderValue.value).toBe(15)
    expect(mockSaveAndGet).not.toHaveBeenCalled()
  })

  it('does not ask for a cap when the member has no known location', async () => {
    mockMe.value.lat = null
    mockMe.value.lng = null
    const { maxMinutes, loadCap } = useReachDistance()

    await loadCap()

    expect(mockFetchNear).not.toHaveBeenCalled()
    expect(maxMinutes.value).toBe(BROWSE_MINUTES_FALLBACK_MAX)
  })
})

// The browse map shades the member's real drive-time reach. Its shape comes from the very
// same /town/near call this composable already makes for the radius, so the map never routes
// the reach a second time - but only when the caller asks, so the Feed settings slider (which
// draws no map) keeps paying nothing for it.
describe('useReachDistance reach overlay', () => {
  let useReachDistance
  let useReachOverlay

  const FEATURE = {
    type: 'Feature',
    geometry: {
      type: 'Polygon',
      coordinates: [
        [
          [0, 0],
          [1, 0],
          [1, 1],
          [0, 0],
        ],
      ],
    },
  }

  beforeEach(async () => {
    vi.clearAllMocks()
    mockMe.value = {
      lat: 53.4,
      lng: -1.3,
      settings: { browseMaxMinutes: 15, browseMaxDistance: 6 },
    }
    clearNuxtState()
    ;({ useReachDistance } = await import('~/composables/useReachDistance'))
    ;({ useReachOverlay } = await import('~/composables/useReachOverlay'))
  })

  it('does not ask for the polygon by default', async () => {
    mockFetchNear.mockResolvedValue({ reach_radius_miles: 8.5 })
    const { onSliderChange } = useReachDistance()

    await onSliderChange(25)

    expect(mockFetchNear).toHaveBeenCalledWith(53.4, -1.3, 25, false)
    expect(useReachOverlay().reachGeoJSON.value).toBeNull()
  })

  it('asks for the polygon and publishes it when the caller wants a map', async () => {
    mockFetchNear.mockResolvedValue({
      reach_radius_miles: 8.5,
      reach_polygon: FEATURE,
    })
    const { onSliderChange } = useReachDistance(null, { withPolygon: true })

    await onSliderChange(25)

    expect(mockFetchNear).toHaveBeenCalledWith(53.4, -1.3, 25, true)
    expect(useReachOverlay().reachGeoJSON.value).toEqual(FEATURE)
  })

  // Dragging to the far-right stop takes the "no limit" shortcut, which skips the radius
  // derivation. Without an explicit refresh the map would keep shading the travel time the
  // member just dragged away from.
  it('refreshes the shape at the no-limit stop, where no radius is derived', async () => {
    mockFetchNear.mockResolvedValue({
      cap_minutes: BROWSE_MINUTES_MAX,
      density_band: 'sparse',
      reach_polygon: FEATURE,
    })
    const { onSliderChange, loadCap } = useReachDistance(null, {
      withPolygon: true,
    })
    await loadCap()
    mockFetchNear.mockClear()

    await onSliderChange(BROWSE_MINUTES_MAX)

    expect(mockFetchNear).toHaveBeenCalledWith(
      53.4,
      -1.3,
      BROWSE_MINUTES_MAX,
      true
    )
    expect(useReachOverlay().reachGeoJSON.value).toEqual(FEATURE)
  })

  it('clears the shape when the routing lookup fails, rather than leaving a stale one', async () => {
    mockFetchNear.mockResolvedValue({
      reach_radius_miles: 8.5,
      reach_polygon: FEATURE,
    })
    const { onSliderChange } = useReachDistance(null, { withPolygon: true })
    await onSliderChange(25)
    expect(useReachOverlay().reachGeoJSON.value).toEqual(FEATURE)

    mockFetchNear.mockRejectedValue(new Error('routing down'))
    await onSliderChange(10)

    expect(useReachOverlay().reachGeoJSON.value).toBeNull()
  })

  it('clears the shape when there is no location to draw a reach from', async () => {
    mockFetchNear.mockResolvedValue({
      reach_radius_miles: 8.5,
      reach_polygon: FEATURE,
    })
    const { onSliderChange } = useReachDistance(null, { withPolygon: true })
    await onSliderChange(25)
    expect(useReachOverlay().reachGeoJSON.value).toEqual(FEATURE)

    mockMe.value.lat = null
    mockMe.value.lng = null
    await onSliderChange(10)

    expect(useReachOverlay().reachGeoJSON.value).toBeNull()
  })

  // A response with no shape (routing answered, but the reach traced nothing drawable) must
  // leave nothing behind either.
  it('clears the shape when the answer carries no polygon', async () => {
    mockFetchNear.mockResolvedValue({
      reach_radius_miles: 8.5,
      reach_polygon: FEATURE,
    })
    const { onSliderChange } = useReachDistance(null, { withPolygon: true })
    await onSliderChange(25)

    mockFetchNear.mockResolvedValue({ reach_radius_miles: 4 })
    await onSliderChange(10)

    expect(useReachOverlay().reachGeoJSON.value).toBeNull()
  })
})

// A member who has never touched the slider sits at the top stop, but the top stop is their
// density band's cap and the client does not know it until the server says so. The first
// call therefore has to guess (the flat fallback), and the shape it brings back describes
// the wrong travel time for anyone whose band is not the fallback.
describe('useReachDistance reach overlay for an unset slider', () => {
  let useReachDistance
  let useReachOverlay

  beforeEach(async () => {
    vi.clearAllMocks()
    // No browseMaxMinutes: the slider sits at whatever the cap turns out to be.
    mockMe.value = { lat: 53.4, lng: -1.3, settings: {} }
    clearNuxtState()
    ;({ useReachDistance } = await import('~/composables/useReachDistance'))
    ;({ useReachOverlay } = await import('~/composables/useReachOverlay'))
  })

  function polygonFor(minutes) {
    return {
      type: 'Feature',
      minutes,
      geometry: { type: 'Polygon', coordinates: [] },
    }
  }

  it('redraws the shape for the real cap once the server reports it', async () => {
    mockFetchNear.mockImplementation((lat, lng, minutes) =>
      Promise.resolve({
        cap_minutes: 45,
        density_band: 'sparse',
        reach_polygon: polygonFor(minutes),
      })
    )
    const { loadCap } = useReachDistance(null, { withPolygon: true })

    await loadCap()

    // First asked for the flat fallback, then again for the 45 the server reported.
    expect(mockFetchNear).toHaveBeenCalledWith(
      53.4,
      -1.3,
      BROWSE_MINUTES_FALLBACK_MAX,
      true
    )
    expect(mockFetchNear).toHaveBeenLastCalledWith(53.4, -1.3, 45, true)
    expect(useReachOverlay().reachGeoJSON.value.minutes).toBe(45)
  })

  it('does not redraw when the cap turns out to be the one we guessed', async () => {
    mockFetchNear.mockResolvedValue({
      cap_minutes: BROWSE_MINUTES_FALLBACK_MAX,
      density_band: 'medium',
      reach_polygon: polygonFor(BROWSE_MINUTES_FALLBACK_MAX),
    })
    const { loadCap } = useReachDistance(null, { withPolygon: true })

    await loadCap()

    expect(mockFetchNear).toHaveBeenCalledTimes(1)
  })

  // The extra call is for the map alone, so a caller that draws no map must not make it.
  it('does not redraw for a caller that did not ask for a polygon', async () => {
    mockFetchNear.mockResolvedValue({ cap_minutes: 45, density_band: 'sparse' })
    const { loadCap } = useReachDistance()

    await loadCap()

    expect(mockFetchNear).toHaveBeenCalledTimes(1)
  })
})
