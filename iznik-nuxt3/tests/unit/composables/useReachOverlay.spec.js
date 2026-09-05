import { describe, it, expect, beforeEach } from 'vitest'
import { useReachOverlay } from '~/composables/useReachOverlay'

// The browse map's shaded area is the member's real drive-time reach, handed over by the
// distance slider (which already routes it) rather than fetched again by the map. The state
// in between has one job that is easy to get wrong: dragging the slider fires overlapping
// /town/near calls, and the map must end up showing the travel time the slider settled on,
// not whichever response happened to land last.

const FEATURE_20 = {
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
const FEATURE_45 = {
  type: 'Feature',
  geometry: {
    type: 'Polygon',
    coordinates: [
      [
        [0, 0],
        [3, 0],
        [3, 3],
        [0, 0],
      ],
    ],
  },
}

describe('useReachOverlay', () => {
  beforeEach(() => {
    clearNuxtState()
  })

  it('starts with nothing to draw, so the map falls back to its hull', () => {
    const { reachGeoJSON } = useReachOverlay()
    expect(reachGeoJSON.value).toBeNull()
  })

  it('publishes a shape and the travel time it describes', () => {
    const { reachGeoJSON, nextReachSeq, publishReach } = useReachOverlay()

    publishReach(nextReachSeq(), FEATURE_20)

    expect(reachGeoJSON.value).toBe(FEATURE_20)
  })

  it('shares state between separate callers, so the map sees what the slider published', () => {
    const slider = useReachOverlay()
    const map = useReachOverlay()

    slider.publishReach(slider.nextReachSeq(), FEATURE_20)

    expect(map.reachGeoJSON.value).toBe(FEATURE_20)
  })

  it('drops a stale response that lands after a newer request was issued', () => {
    const { reachGeoJSON, nextReachSeq, publishReach } = useReachOverlay()

    // Slider dragged 20 -> 45: both calls issued, then the 45 answers first and the
    // slower 20 answers second.
    const seq20 = nextReachSeq()
    const seq45 = nextReachSeq()

    publishReach(seq45, FEATURE_45)
    publishReach(seq20, FEATURE_20)

    expect(reachGeoJSON.value).toBe(FEATURE_45)
  })

  it('lets the newest response overwrite an earlier one that already landed', () => {
    const { reachGeoJSON, nextReachSeq, publishReach } = useReachOverlay()

    const seq20 = nextReachSeq()
    publishReach(seq20, FEATURE_20)
    const seq45 = nextReachSeq()
    publishReach(seq45, FEATURE_45)

    expect(reachGeoJSON.value).toBe(FEATURE_45)
  })

  // A failed lookup must not leave the previous shape shaded: it would claim a travel time
  // the slider no longer shows.
  it('clears the shape when the newest response has none', () => {
    const { reachGeoJSON, nextReachSeq, publishReach } = useReachOverlay()

    publishReach(nextReachSeq(), FEATURE_20)
    publishReach(nextReachSeq(), null)

    expect(reachGeoJSON.value).toBeNull()
  })

  it('clearReach empties the overlay without resetting the staleness guard', () => {
    const { reachGeoJSON, nextReachSeq, publishReach, clearReach } =
      useReachOverlay()

    const inFlight = nextReachSeq()
    clearReach()
    // The response that was already in flight when we cleared is not newer than the
    // clear, so it must not repopulate the map.
    publishReach(inFlight, FEATURE_20)

    expect(reachGeoJSON.value).toBeNull()
  })
})
