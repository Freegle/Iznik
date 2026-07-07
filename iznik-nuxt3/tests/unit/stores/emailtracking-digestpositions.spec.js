import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

// Mock the API factory used by the store. api(config).emailtracking.fetchDigestPositions(params)
const fetchDigestPositions = vi.fn()
vi.mock('~/api', () => ({
  default: () => ({
    emailtracking: { fetchDigestPositions },
  }),
}))

describe('emailtracking store — digest positions', () => {
  let useEmailTrackingStore

  beforeEach(async () => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    const mod = await import('~/modtools/stores/emailtracking')
    useEmailTrackingStore = mod.useEmailTrackingStore
  })

  describe('digestPositionsChartData getter', () => {
    it('returns null when there are no positions', () => {
      const store = useEmailTrackingStore()
      expect(store.digestPositionsChartData).toBeNull()
    })

    it('builds a ComboChart 2D array with 1-based labels and rounded CTR', () => {
      const store = useEmailTrackingStore()
      store.digestPositions = [
        { position: 0, shown: 1000, clicks: 520, emails_clicked: 500, ctr: 50 },
        {
          position: 1,
          shown: 1000,
          clicks: 260,
          emails_clicked: 250,
          ctr: 25.04,
        },
      ]

      expect(store.digestPositionsChartData).toEqual([
        ['Position', 'Click-through rate (%)', 'Emails shown'],
        ['1', 50, 1000],
        ['2', 25, 1000],
      ])
    })
  })

  describe('hasDigestPositions getter', () => {
    it('is false when empty and true when populated', () => {
      const store = useEmailTrackingStore()
      expect(store.hasDigestPositions).toBe(false)
      store.digestPositions = [
        { position: 0, shown: 1, clicks: 1, emails_clicked: 1, ctr: 100 },
      ]
      expect(store.hasDigestPositions).toBe(true)
    })
  })

  describe('fetchDigestPositions action', () => {
    it('stores the response data and clears the loading flag', async () => {
      const rows = [
        { position: 0, shown: 10, clicks: 5, emails_clicked: 5, ctr: 50 },
      ]
      fetchDigestPositions.mockResolvedValueOnce({ data: rows })

      const store = useEmailTrackingStore()
      store.setFilters({ type: 'UnifiedDigestDaily', start: 'a', end: 'b' })
      await store.fetchDigestPositions()

      expect(fetchDigestPositions).toHaveBeenCalledWith({
        type: 'UnifiedDigestDaily',
        start: 'a',
        end: 'b',
      })
      expect(store.digestPositions).toEqual(rows)
      expect(store.digestPositionsLoading).toBe(false)
      expect(store.digestPositionsError).toBeNull()
    })

    it('records an error when the API rejects', async () => {
      fetchDigestPositions.mockRejectedValueOnce(new Error('nope'))

      const store = useEmailTrackingStore()
      await store.fetchDigestPositions()

      expect(store.digestPositionsError).toBe('nope')
      expect(store.digestPositionsLoading).toBe(false)
    })
  })
})
