/**
 * Extra branch coverage for composables/useReachDistance.js: the myPosts and chitchat axes,
 * legacy metres adoption, the unlimited-sentinel heal, and clamping of the slider position.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref } from 'vue'
import {
  BROWSE_DISTANCE_UNLIMITED,
  BROWSE_MINUTES_MIN,
  BROWSE_MINUTES_MAX,
  BROWSE_MINUTES_FALLBACK_MAX,
} from '~/constants'

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
    town: { fetchNear: (...args) => mockFetchNear(...args) },
  }),
}))

vi.stubGlobal('useRuntimeConfig', () => ({ public: {} }))

describe('useReachDistance branches', () => {
  let useReachDistance

  function setMe(settings, extra = {}) {
    mockMe.value = { lat: 53.4, lng: -1.3, settings, ...extra }
  }

  beforeEach(async () => {
    vi.clearAllMocks()
    mockSaveAndGet.mockResolvedValue(undefined)
    mockFetchNear.mockResolvedValue({})
    setMe({})
    ;({ useReachDistance } = await import('~/composables/useReachDistance'))
  })

  describe('slider position', () => {
    it.each([
      [undefined, BROWSE_MINUTES_FALLBACK_MAX],
      [2, BROWSE_MINUTES_MIN],
      [BROWSE_MINUTES_MIN, BROWSE_MINUTES_MIN],
      [17, 17],
      [BROWSE_MINUTES_FALLBACK_MAX, BROWSE_MINUTES_FALLBACK_MAX],
      [60, BROWSE_MINUTES_FALLBACK_MAX],
    ])('browse saved %s starts at %s', (saved, expected) => {
      setMe(saved === undefined ? {} : { browseMaxMinutes: saved })
      expect(useReachDistance().sliderValue.value).toBe(expected)
    })

    it('has no member at all: sits at the top stop', () => {
      mockMe.value = null
      expect(useReachDistance().sliderValue.value).toBe(
        BROWSE_MINUTES_FALLBACK_MAX
      )
    })

    it.each([
      ['own minutes win', { myPostsMaxMinutes: 12, browseMaxDistance: 3 }, 12],
      [
        'inbound choice carries over',
        { browseMaxDistance: 3, browseMaxMinutes: 20 },
        20,
      ],
      [
        'legacy miles-only shows the top stop',
        { browseMaxDistance: 3 },
        BROWSE_MINUTES_MAX,
      ],
      [
        'no choice shows the top stop',
        { browseMaxMinutes: 20 },
        BROWSE_MINUTES_MAX,
      ],
    ])('myPosts: %s', (_n, settings, expected) => {
      setMe(settings)
      const { sliderValue, maxMinutes } = useReachDistance(null, {
        axis: 'myPosts',
      })
      expect(maxMinutes.value).toBe(BROWSE_MINUTES_MAX)
      expect(sliderValue.value).toBe(expected)
    })
  })

  describe('fetchNear through loadCap', () => {
    it('does not ask the server when there is no location, and clears the overlay', async () => {
      setMe({ browseMaxMinutes: 10 }, { lat: 0, lng: 0 })
      await useReachDistance(null, { withPolygon: true }).loadCap()
      expect(mockFetchNear).not.toHaveBeenCalled()
    })

    it.each([
      ['zero', 0],
      ['negative', -5],
      ['not a number', '45'],
      ['missing', undefined],
    ])('ignores a %s cap_minutes', async (_n, cap) => {
      setMe({ browseMaxMinutes: 10 })
      mockFetchNear.mockResolvedValue({ cap_minutes: cap })
      const { maxMinutes, loadCap } = useReachDistance()
      await loadCap()
      expect(maxMinutes.value).toBe(BROWSE_MINUTES_FALLBACK_MAX)
    })

    it('never opens the slider past the ceiling', async () => {
      setMe({ browseMaxMinutes: 10 })
      mockFetchNear.mockResolvedValue({ cap_minutes: 90 })
      const { maxMinutes, loadCap } = useReachDistance()
      await loadCap()
      expect(maxMinutes.value).toBe(BROWSE_MINUTES_MAX)
    })

    it('a non-band-capped axis ignores the server cap', async () => {
      setMe({ myPostsMaxMinutes: 10 })
      mockFetchNear.mockResolvedValue({ cap_minutes: 20 })
      const { maxMinutes, loadCap } = useReachDistance(null, {
        axis: 'myPosts',
      })
      await loadCap()
      expect(maxMinutes.value).toBe(BROWSE_MINUTES_MAX)
    })

    it('a failing lookup leaves the cap alone', async () => {
      setMe({ browseMaxMinutes: 10 })
      mockFetchNear.mockRejectedValue(new Error('x'))
      const { maxMinutes, loadCap } = useReachDistance(null, {
        withPolygon: true,
      })
      await loadCap()
      expect(maxMinutes.value).toBe(BROWSE_MINUTES_FALLBACK_MAX)
    })
  })

  describe('onSliderChange', () => {
    it('does nothing without a member', async () => {
      mockMe.value = null
      await useReachDistance().onSliderChange(10)
      expect(mockSaveAndGet).not.toHaveBeenCalled()
    })

    it('does nothing when the member has no settings', async () => {
      setMe(undefined)
      await useReachDistance().onSliderChange(10)
      expect(mockSaveAndGet).not.toHaveBeenCalled()
    })

    it('myPosts top stop stores the sentinel, and redraws the shape when asked', async () => {
      const onPersisted = vi.fn()
      setMe({ myPostsMaxMinutes: 10 })
      const { onSliderChange, sliderValue } = useReachDistance(onPersisted, {
        axis: 'myPosts',
        withPolygon: true,
      })
      await onSliderChange(500)
      const s = mockSaveAndGet.mock.calls[0][0].settings
      expect(s.myPostsMaxMinutes).toBe(BROWSE_MINUTES_MAX)
      expect(s.myPostsMaxDistance).toBe(BROWSE_DISTANCE_UNLIMITED)
      expect(sliderValue.value).toBe(BROWSE_MINUTES_MAX)
      expect(onPersisted).toHaveBeenCalledWith(BROWSE_DISTANCE_UNLIMITED)
      expect(mockFetchNear).toHaveBeenCalledWith(
        53.4,
        -1.3,
        BROWSE_MINUTES_MAX,
        true
      )
    })

    it('chitchat top stop writes metres 0', async () => {
      setMe({ newsfeedMinutes: 10, newsfeedarea: 1000 })
      await useReachDistance(null, { axis: 'chitchat' }).onSliderChange(45)
      const s = mockSaveAndGet.mock.calls[0][0].settings
      expect(s.newsfeedarea).toBe(0)
      expect(s.newsfeedDistance).toBe(BROWSE_DISTANCE_UNLIMITED)
    })

    it('chitchat derived radius writes rounded metres', async () => {
      setMe({ newsfeedMinutes: 10 })
      mockFetchNear.mockResolvedValue({ reach_radius_miles: 2 })
      const onPersisted = vi.fn()
      await useReachDistance(onPersisted, { axis: 'chitchat' }).onSliderChange(
        20
      )
      const s = mockSaveAndGet.mock.calls[0][0].settings
      expect(s.newsfeedarea).toBe(3219)
      expect(onPersisted).toHaveBeenCalledWith(2)
    })

    it('chitchat failed derivation fails open on miles and metres', async () => {
      setMe({ newsfeedMinutes: 10, newsfeedarea: 500 })
      mockFetchNear.mockRejectedValue(new Error('x'))
      await useReachDistance(null, { axis: 'chitchat' }).onSliderChange(20)
      const s = mockSaveAndGet.mock.calls[0][0].settings
      expect(s.newsfeedDistance).toBe(BROWSE_DISTANCE_UNLIMITED)
      expect(s.newsfeedarea).toBe(0)
    })

    it('at a below-ceiling top stop still derives a real radius and moves the handle', async () => {
      setMe({ browseMaxMinutes: 10 })
      mockFetchNear.mockResolvedValue({
        cap_minutes: 20,
        reach_radius_miles: 4,
      })
      const { onSliderChange, loadCap, sliderValue } = useReachDistance()
      await loadCap()
      await onSliderChange(25)
      const s = mockSaveAndGet.mock.calls.at(-1)[0].settings
      expect(s.browseMaxMinutes).toBe(20)
      expect(s.browseMaxDistance).toBe(4)
      expect(sliderValue.value).toBe(20)
    })
  })

  describe('legacy metres adoption', () => {
    it('shows the narrow end for the legacy "nearby" value without saving', async () => {
      setMe({ newsfeedarea: 'nearby' })
      const { sliderValue, loadCap } = useReachDistance(null, {
        axis: 'chitchat',
      })
      await loadCap()
      expect(sliderValue.value).toBe(BROWSE_MINUTES_MIN)
      expect(mockSaveAndGet).not.toHaveBeenCalled()
      expect(mockFetchNear).not.toHaveBeenCalled()
    })

    it.each([
      ['zero', 0],
      ['negative', -100],
      ['junk', 'wide'],
    ])(
      'ignores a %s legacy value and loads the cap normally',
      async (_n, v) => {
        setMe({ newsfeedarea: v })
        await useReachDistance(null, { axis: 'chitchat' }).loadCap()
        expect(mockSaveAndGet).not.toHaveBeenCalled()
        expect(mockFetchNear).toHaveBeenCalledTimes(1)
      }
    )

    it('does not adopt when minutes are already saved', async () => {
      setMe({ newsfeedarea: 5000, newsfeedMinutes: 20 })
      await useReachDistance(null, { axis: 'chitchat' }).loadCap()
      expect(mockFetchNear).toHaveBeenCalledTimes(1)
    })

    it('does not adopt on an axis without a metres key', async () => {
      setMe({ newsfeedarea: 5000 })
      await useReachDistance().loadCap()
      expect(mockFetchNear).toHaveBeenCalledTimes(1)
    })

    it('does not adopt for a member without settings', async () => {
      setMe(undefined)
      await useReachDistance(null, { axis: 'chitchat' }).loadCap()
      expect(mockSaveAndGet).not.toHaveBeenCalled()
    })

    it('bisects the radius onto the minutes scale and persists both keys', async () => {
      // 3218.688 metres is exactly 2 miles. Radius grows with minutes: 0.5 mile a minute.
      setMe({ newsfeedarea: 3218.688 })
      mockFetchNear.mockImplementation(async (_la, _ln, minutes) => ({
        reach_radius_miles: minutes / 2,
      }))
      const { sliderValue, loadCap } = useReachDistance(null, {
        axis: 'chitchat',
      })
      await loadCap()
      expect(mockFetchNear).toHaveBeenCalledTimes(3)
      const s = mockSaveAndGet.mock.calls[0][0].settings
      expect(typeof s.newsfeedMinutes).toBe('number')
      expect(s.newsfeedDistance).toBeCloseTo(2, 5)
      // The stored metres value is left untouched.
      expect(s.newsfeedarea).toBe(3218.688)
      expect(sliderValue.value).toBe(s.newsfeedMinutes)
    })

    it('leaves everything alone when routing is unavailable mid-bisection', async () => {
      setMe({ newsfeedarea: 3000 })
      mockFetchNear.mockResolvedValue({})
      const { sliderValue, loadCap } = useReachDistance(null, {
        axis: 'chitchat',
      })
      await loadCap()
      expect(mockSaveAndGet).not.toHaveBeenCalled()
      expect(sliderValue.value).not.toBe(BROWSE_MINUTES_MIN)
    })
  })

  describe('loadCap reconciliation', () => {
    it('persists a correction when the saved position is above the cap', async () => {
      setMe({ browseMaxMinutes: 40, browseMaxDistance: 12 })
      mockFetchNear.mockResolvedValue({
        cap_minutes: 20,
        reach_radius_miles: 5,
      })
      await useReachDistance().loadCap()
      const s = mockSaveAndGet.mock.calls[0][0].settings
      expect(s.browseMaxMinutes).toBe(20)
      expect(s.browseMaxDistance).toBe(5)
    })

    it('heals the sentinel stored beside a narrow budget', async () => {
      const onPersisted = vi.fn()
      setMe({
        newsfeedMinutes: 15,
        newsfeedDistance: BROWSE_DISTANCE_UNLIMITED,
        newsfeedarea: 0,
      })
      mockFetchNear.mockResolvedValue({ reach_radius_miles: 3 })
      await useReachDistance(onPersisted, { axis: 'chitchat' }).loadCap()
      const s = mockSaveAndGet.mock.calls[0][0].settings
      expect(s.newsfeedDistance).toBe(3)
      expect(s.newsfeedarea).toBe(4828)
      expect(onPersisted).toHaveBeenCalledWith(3)
    })

    it('heals without a callback or a metres key', async () => {
      setMe({
        browseMaxMinutes: 15,
        browseMaxDistance: BROWSE_DISTANCE_UNLIMITED,
      })
      mockFetchNear.mockResolvedValue({ reach_radius_miles: 3 })
      await useReachDistance().loadCap()
      expect(mockSaveAndGet.mock.calls[0][0].settings.browseMaxDistance).toBe(3)
    })

    it('writes nothing when the heal lookup still fails', async () => {
      setMe({
        browseMaxMinutes: 15,
        browseMaxDistance: BROWSE_DISTANCE_UNLIMITED,
      })
      mockFetchNear.mockResolvedValue({})
      await useReachDistance().loadCap()
      expect(mockSaveAndGet).not.toHaveBeenCalled()
    })

    it('does not heal a member with no location', async () => {
      setMe(
        { browseMaxMinutes: 15, browseMaxDistance: BROWSE_DISTANCE_UNLIMITED },
        { lat: null, lng: null }
      )
      await useReachDistance().loadCap()
      expect(mockSaveAndGet).not.toHaveBeenCalled()
    })

    it('does not heal when the budget is at the cap', async () => {
      setMe({
        browseMaxMinutes: 30,
        browseMaxDistance: BROWSE_DISTANCE_UNLIMITED,
      })
      await useReachDistance().loadCap()
      expect(mockSaveAndGet).not.toHaveBeenCalled()
    })

    it('redraws the shape when the real cap moves an unset member', async () => {
      setMe({})
      mockFetchNear.mockResolvedValue({ cap_minutes: 45 })
      await useReachDistance(null, { withPolygon: true }).loadCap()
      expect(mockFetchNear).toHaveBeenCalledTimes(2)
      expect(mockFetchNear.mock.calls[1][2]).toBe(45)
    })

    it('does not redraw when the shape is already for the right time', async () => {
      setMe({ browseMaxMinutes: 10 })
      await useReachDistance(null, { withPolygon: true }).loadCap()
      expect(mockFetchNear).toHaveBeenCalledTimes(1)
    })
  })
})
