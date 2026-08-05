import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref } from 'vue'
import { BROWSE_DISTANCE_UNLIMITED, BROWSE_MINUTES_MAX } from '~/constants'

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

  it('stores the unlimited sentinel at the far-right stop without a routing call', async () => {
    const { onSliderChange } = useReachDistance()

    await onSliderChange(BROWSE_MINUTES_MAX)

    const saved = mockSaveAndGet.mock.calls[0][0].settings
    expect(saved.browseMaxMinutes).toBe(BROWSE_MINUTES_MAX)
    expect(saved.browseMaxDistance).toBe(BROWSE_DISTANCE_UNLIMITED)
    expect(mockFetchNear).not.toHaveBeenCalled()
  })
})
