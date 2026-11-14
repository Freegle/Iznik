import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const mockFetchv2 = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    config: {
      fetchv2: mockFetchv2,
    },
  }),
}))

describe('useRepostSettings', () => {
  let useRepostSettings
  let parseRepostSettings
  let useConfigStore
  let GROUP_REPOSTS

  beforeEach(async () => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    ;({ useRepostSettings, parseRepostSettings } =
      await import('~/composables/useRepostSettings'))
    ;({ useConfigStore } = await import('~/stores/config'))
    ;({ GROUP_REPOSTS } = await import('~/constants'))
  })

  describe('fallback values', () => {
    it('are the system values: 3 days, 7 days, 3 reposts, chase up after 5', () => {
      expect(GROUP_REPOSTS).toEqual({
        offer: 3,
        wanted: 7,
        max: 3,
        chaseups: 5,
      })
    })
  })

  describe('parseRepostSettings', () => {
    it('reads the row GET /config/reposts returns', () => {
      expect(
        parseRepostSettings([
          {
            id: 1,
            key: 'reposts',
            value: '{"offer":4,"wanted":9,"max":2,"chaseups":6}',
          },
        ])
      ).toEqual({ offer: 4, wanted: 9, max: 2, chaseups: 6 })
    })

    it('accepts the object, or its JSON, directly', () => {
      const want = { offer: 4, wanted: 9, max: 2, chaseups: 6 }

      expect(parseRepostSettings(want)).toEqual(want)
      expect(parseRepostSettings(JSON.stringify(want))).toEqual(want)
    })

    it('keeps zero, which means off', () => {
      expect(
        parseRepostSettings([
          { value: '{"offer":0,"wanted":7,"max":0,"chaseups":5}' },
        ])
      ).toEqual({ offer: 0, wanted: 7, max: 0, chaseups: 5 })
    })

    it.each([[undefined], [null], [[]], ['not json'], [[{ value: '[]' }]]])(
      'falls back when there is nothing usable (%j)',
      (raw) => {
        expect(parseRepostSettings(raw)).toEqual(GROUP_REPOSTS)
      }
    )

    it('falls back for a bad key only', () => {
      expect(
        parseRepostSettings({
          offer: 'soon',
          wanted: 9,
          max: -1,
          chaseups: 2.5,
        })
      ).toEqual({ offer: 3, wanted: 9, max: 3, chaseups: 5 })
    })

    it('does not change the fallback object', () => {
      const settings = parseRepostSettings({ offer: 11 })
      settings.wanted = 99

      expect(GROUP_REPOSTS.wanted).toBe(7)
    })
  })

  describe('useRepostSettings', () => {
    it('gives the fallback before anything is loaded', () => {
      const { settings } = useRepostSettings()

      expect(settings.value).toEqual(GROUP_REPOSTS)
    })

    it('loads the row once and follows it', async () => {
      useConfigStore().init({ public: {} })
      mockFetchv2.mockResolvedValue([
        { value: '{"offer":5,"wanted":10,"max":2,"chaseups":4}' },
      ])

      const { settings, load } = useRepostSettings()
      await load()
      await load()

      expect(mockFetchv2).toHaveBeenCalledTimes(1)
      expect(mockFetchv2).toHaveBeenCalledWith('reposts', false)
      expect(settings.value).toEqual({
        offer: 5,
        wanted: 10,
        max: 2,
        chaseups: 4,
      })
    })

    it('keeps the fallback when the API refuses the key (frontend ahead of the API)', async () => {
      useConfigStore().init({ public: {} })
      mockFetchv2.mockRejectedValue(new Error('403'))

      const { settings, load } = useRepostSettings()
      await expect(load()).resolves.toBeUndefined()

      expect(settings.value).toEqual(GROUP_REPOSTS)
    })

    it('keeps the fallback when the API answers with no rows', async () => {
      useConfigStore().init({ public: {} })
      mockFetchv2.mockResolvedValue([])

      const { settings, load } = useRepostSettings()
      await load()

      expect(settings.value).toEqual(GROUP_REPOSTS)
    })
  })
})
