import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const mockFetchStats = vi.fn()
const mockFetchTimeSeries = vi.fn()
const mockFetchStatsByType = vi.fn()
const mockFetchDigestPositions = vi.fn()
const mockFetchReengageEffectiveness = vi.fn()
const mockFetchTopClickedLinks = vi.fn()
const mockFetchUserEmails = vi.fn()
const mockSystemLogsFetch = vi.fn()
const mockSystemLogsFetchCounts = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    emailtracking: {
      fetchStats: mockFetchStats,
      fetchTimeSeries: mockFetchTimeSeries,
      fetchStatsByType: mockFetchStatsByType,
      fetchDigestPositions: mockFetchDigestPositions,
      fetchReengageEffectiveness: mockFetchReengageEffectiveness,
      fetchTopClickedLinks: mockFetchTopClickedLinks,
      fetchUserEmails: mockFetchUserEmails,
    },
    systemlogs: {
      fetch: mockSystemLogsFetch,
      fetchCounts: mockSystemLogsFetchCounts,
    },
  }),
}))

describe('emailtracking store', () => {
  let useEmailTrackingStore

  beforeEach(async () => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    const mod = await import('~/modtools/stores/emailtracking')
    useEmailTrackingStore = mod.useEmailTrackingStore
  })

  describe('init/clear/clearStats/clearUserEmails/setFilters', () => {
    it('init stores config', () => {
      const store = useEmailTrackingStore()
      store.init({ a: 1 })
      expect(store.config).toEqual({ a: 1 })
    })

    it('clear resets the general dashboard state', () => {
      const store = useEmailTrackingStore()
      store.stats = { x: 1 }
      store.ampStats = { y: 1 }
      store.statsError = 'e'
      store.timeSeries = [1]
      store.statsByType = [1]
      store.clickedLinks = [1]
      store.clickedLinksTotal = 5
      store.showAllClickedLinks = true
      store.aggregateClickedLinks = false
      store.digestPositions = [1]
      store.reengageStats = { z: 1 }
      store.userEmails = [1]
      store.userEmailsTotal = 3
      store.currentUserId = 9
      store.currentEmail = 'a@b.com'
      store.offset = 50

      store.clear()

      expect(store.stats).toBeNull()
      expect(store.ampStats).toBeNull()
      expect(store.statsError).toBeNull()
      expect(store.timeSeries).toEqual([])
      expect(store.statsByType).toEqual([])
      expect(store.clickedLinks).toEqual([])
      expect(store.clickedLinksTotal).toBe(0)
      expect(store.showAllClickedLinks).toBe(false)
      expect(store.aggregateClickedLinks).toBe(true)
      expect(store.digestPositions).toEqual([])
      expect(store.reengageStats).toBeNull()
      expect(store.userEmails).toEqual([])
      expect(store.userEmailsTotal).toBe(0)
      expect(store.currentUserId).toBeNull()
      expect(store.currentEmail).toBeNull()
      expect(store.offset).toBe(0)
    })

    it('clearStats resets just the aggregate stats', () => {
      const store = useEmailTrackingStore()
      store.stats = { x: 1 }
      store.ampStats = { y: 1 }
      store.statsError = 'e'
      store.clearStats()
      expect(store.stats).toBeNull()
      expect(store.ampStats).toBeNull()
      expect(store.statsError).toBeNull()
    })

    it('clearUserEmails resets user email browsing state', () => {
      const store = useEmailTrackingStore()
      store.userEmails = [1]
      store.userEmailsTotal = 2
      store.userEmailsError = 'e'
      store.currentUserId = 1
      store.currentEmail = 'a@b.com'
      store.offset = 10
      store.clearUserEmails()
      expect(store.userEmails).toEqual([])
      expect(store.userEmailsTotal).toBe(0)
      expect(store.userEmailsError).toBeNull()
      expect(store.currentUserId).toBeNull()
      expect(store.currentEmail).toBeNull()
      expect(store.offset).toBe(0)
    })

    it('setFilters merges into existing filters', () => {
      const store = useEmailTrackingStore()
      store.setFilters({ type: 'digest' })
      store.setFilters({ start: '2026-01-01' })
      expect(store.filters).toEqual({
        type: 'digest',
        start: '2026-01-01',
        end: '',
      })
    })
  })

  describe('fetchStats', () => {
    it('fetches with no filters set', async () => {
      const store = useEmailTrackingStore()
      mockFetchStats.mockResolvedValue({ stats: { total_sent: 10 } })

      await store.fetchStats()

      expect(mockFetchStats).toHaveBeenCalledWith({})
      expect(store.stats).toEqual({ total_sent: 10 })
      expect(store.ampStats).toBeNull()
      expect(store.statsLoading).toBe(false)
    })

    it('fetches with all filters applied and stores ampStats', async () => {
      const store = useEmailTrackingStore()
      store.filters = { type: 'digest', start: 's', end: 'e' }
      mockFetchStats.mockResolvedValue({
        stats: { total_sent: 1 },
        amp_stats: { total_with_amp: 2 },
      })

      await store.fetchStats()

      expect(mockFetchStats).toHaveBeenCalledWith({
        type: 'digest',
        start: 's',
        end: 'e',
      })
      expect(store.ampStats).toEqual({ total_with_amp: 2 })
    })

    it('sets a default error message on failure', async () => {
      const store = useEmailTrackingStore()

      mockFetchStats.mockRejectedValue({})

      await store.fetchStats()

      expect(store.statsError).toBe('Failed to fetch email statistics')
      expect(store.statsLoading).toBe(false)
    })

    it('uses the error message when present', async () => {
      const store = useEmailTrackingStore()
      mockFetchStats.mockRejectedValue(new Error('bad request'))

      await store.fetchStats()

      expect(store.statsError).toBe('bad request')
    })
  })

  describe('fetchTimeSeries', () => {
    it('fetches with filters and stores data', async () => {
      const store = useEmailTrackingStore()
      store.filters = { type: 'digest', start: 's', end: 'e' }
      mockFetchTimeSeries.mockResolvedValue({ data: [{ date: '2026-01-01' }] })

      await store.fetchTimeSeries()

      expect(mockFetchTimeSeries).toHaveBeenCalledWith({
        type: 'digest',
        start: 's',
        end: 'e',
      })
      expect(store.timeSeries).toEqual([{ date: '2026-01-01' }])
    })

    it('defaults to an empty array when data is missing', async () => {
      const store = useEmailTrackingStore()
      mockFetchTimeSeries.mockResolvedValue({})

      await store.fetchTimeSeries()

      expect(store.timeSeries).toEqual([])
    })

    it('records the error on failure', async () => {
      const store = useEmailTrackingStore()
      mockFetchTimeSeries.mockRejectedValue(new Error('boom'))

      await store.fetchTimeSeries()

      expect(store.timeSeriesError).toBe('boom')
      expect(store.timeSeriesLoading).toBe(false)
    })
  })

  describe('fetchStatsByType', () => {
    it('fetches with start/end filters (no type param)', async () => {
      const store = useEmailTrackingStore()
      store.filters = { type: 'ignored', start: 's', end: 'e' }
      mockFetchStatsByType.mockResolvedValue({ data: [{ email_type: 'x' }] })

      await store.fetchStatsByType()

      expect(mockFetchStatsByType).toHaveBeenCalledWith({
        start: 's',
        end: 'e',
      })
      expect(store.statsByType).toEqual([{ email_type: 'x' }])
    })

    it('records a default error message on failure', async () => {
      const store = useEmailTrackingStore()

      mockFetchStatsByType.mockRejectedValue({})

      await store.fetchStatsByType()

      expect(store.statsByTypeError).toBe('Failed to fetch stats by email type')
    })
  })

  describe('fetchDigestPositions error path', () => {
    it('records a default error message on failure', async () => {
      const store = useEmailTrackingStore()

      mockFetchDigestPositions.mockRejectedValue({})

      await store.fetchDigestPositions()

      expect(store.digestPositionsError).toBe(
        'Failed to fetch digest position stats'
      )
    })

    it('applies type/start/end filters', async () => {
      const store = useEmailTrackingStore()
      store.filters = { type: 't', start: 's', end: 'e' }
      mockFetchDigestPositions.mockResolvedValue({ data: [] })

      await store.fetchDigestPositions()

      expect(mockFetchDigestPositions).toHaveBeenCalledWith({
        type: 't',
        start: 's',
        end: 'e',
      })
    })
  })

  describe('fetchReengageEffectiveness', () => {
    it('fetches with no args', async () => {
      const store = useEmailTrackingStore()
      mockFetchReengageEffectiveness.mockResolvedValue({ sent: 100 })

      await store.fetchReengageEffectiveness()

      expect(mockFetchReengageEffectiveness).toHaveBeenCalledWith({})
      expect(store.reengageStats).toEqual({ sent: 100 })
    })

    it('fetches with start/end and falls back to null on empty response', async () => {
      const store = useEmailTrackingStore()
      mockFetchReengageEffectiveness.mockResolvedValue(null)

      await store.fetchReengageEffectiveness({ start: 's', end: 'e' })

      expect(mockFetchReengageEffectiveness).toHaveBeenCalledWith({
        start: 's',
        end: 'e',
      })
      expect(store.reengageStats).toBeNull()
    })

    it('records a default error message on failure', async () => {
      const store = useEmailTrackingStore()

      mockFetchReengageEffectiveness.mockRejectedValue({})

      await store.fetchReengageEffectiveness()

      expect(store.reengageError).toBe(
        'Failed to fetch reengagement effectiveness stats'
      )
      expect(store.reengageLoading).toBe(false)
    })
  })

  describe('fetchClickedLinks', () => {
    it('filters out amp:// tracking urls and adjusts the total', async () => {
      const store = useEmailTrackingStore()
      mockFetchTopClickedLinks.mockResolvedValue({
        data: [
          { url: 'https://example.com/a' },
          { normalized_url: 'amp://reply' },
          { url: 'amp://render' },
        ],
        total: 10,
      })

      await store.fetchClickedLinks()

      expect(store.clickedLinks).toEqual([{ url: 'https://example.com/a' }])
      expect(store.clickedLinksTotal).toBe(8)
      expect(store.showAllClickedLinks).toBe(false)
    })

    it('requests limit=0 when showAll is true, and aggregate=false when disabled', async () => {
      const store = useEmailTrackingStore()
      store.aggregateClickedLinks = false
      mockFetchTopClickedLinks.mockResolvedValue({ data: [], total: 0 })

      await store.fetchClickedLinks(true)

      expect(mockFetchTopClickedLinks).toHaveBeenCalledWith(
        expect.objectContaining({ limit: 0, aggregate: 'false' })
      )
      expect(store.showAllClickedLinks).toBe(true)
    })

    it('never lets the adjusted total go negative', async () => {
      const store = useEmailTrackingStore()
      mockFetchTopClickedLinks.mockResolvedValue({
        data: [{ url: 'amp://render' }],
        total: 0,
      })

      await store.fetchClickedLinks()

      expect(store.clickedLinksTotal).toBe(0)
    })

    it('applies start/end filters when set', async () => {
      const store = useEmailTrackingStore()
      store.filters = { start: 's', end: 'e' }
      mockFetchTopClickedLinks.mockResolvedValue({ data: [], total: 0 })

      await store.fetchClickedLinks()

      expect(mockFetchTopClickedLinks).toHaveBeenCalledWith(
        expect.objectContaining({ start: 's', end: 'e' })
      )
    })

    it('records a default error message on failure', async () => {
      const store = useEmailTrackingStore()

      mockFetchTopClickedLinks.mockRejectedValue({})

      await store.fetchClickedLinks()

      expect(store.clickedLinksError).toBe('Failed to fetch clicked links')
    })
  })

  describe('toggleShowAllClickedLinks / toggleAggregateClickedLinks', () => {
    it('toggles showAll and refetches', async () => {
      const store = useEmailTrackingStore()
      mockFetchTopClickedLinks.mockResolvedValue({ data: [], total: 0 })
      store.showAllClickedLinks = false

      store.toggleShowAllClickedLinks()
      await Promise.resolve()

      expect(mockFetchTopClickedLinks).toHaveBeenCalledWith(
        expect.objectContaining({ limit: 0 })
      )
    })

    it('toggles aggregate and refetches with the current showAll value', async () => {
      const store = useEmailTrackingStore()
      mockFetchTopClickedLinks.mockResolvedValue({ data: [], total: 0 })
      store.aggregateClickedLinks = true
      store.showAllClickedLinks = true

      store.toggleAggregateClickedLinks()
      await Promise.resolve()

      expect(store.aggregateClickedLinks).toBe(false)
      expect(mockFetchTopClickedLinks).toHaveBeenCalledWith(
        expect.objectContaining({ limit: 0, aggregate: 'false' })
      )
    })
  })

  describe('fetchUserEmails', () => {
    it('does nothing without a userIdOrEmail', async () => {
      const store = useEmailTrackingStore()
      await store.fetchUserEmails(null)
      expect(mockFetchUserEmails).not.toHaveBeenCalled()
    })

    it('resets pagination when not appending', async () => {
      const store = useEmailTrackingStore()
      store.offset = 50
      store.userEmails = [{ id: 1 }]
      mockFetchUserEmails.mockResolvedValue({
        userid: 42,
        emails: [{ id: 2 }],
        total: 1,
      })

      await store.fetchUserEmails(42)

      expect(store.offset).toBe(0)
      expect(store.currentUserId).toBe(42)
      expect(store.currentEmail).toBeNull()
      expect(store.userEmails).toEqual([{ id: 2 }])
      expect(store.userEmailsTotal).toBe(1)
    })

    it('resolves by email when the response carries an email field', async () => {
      const store = useEmailTrackingStore()
      mockFetchUserEmails.mockResolvedValue({
        email: 'found@example.com',
        emails: [],
        total: 0,
      })

      await store.fetchUserEmails('found@example.com')

      expect(store.currentEmail).toBe('found@example.com')
      expect(store.currentUserId).toBeNull()
    })

    it('falls back to numeric userIdOrEmail when the response has neither field', async () => {
      const store = useEmailTrackingStore()
      mockFetchUserEmails.mockResolvedValue({ emails: [{ id: 1 }], total: 1 })

      await store.fetchUserEmails(99)

      expect(store.currentUserId).toBe(99)
    })

    it('falls back to string email-like userIdOrEmail when the response has neither field', async () => {
      const store = useEmailTrackingStore()
      mockFetchUserEmails.mockResolvedValue({ emails: [], total: 0 })

      await store.fetchUserEmails('someone@example.com')

      expect(store.currentEmail).toBe('someone@example.com')
      expect(store.userEmailsError).toContain('someone@example.com')
    })

    it('appends when append=true', async () => {
      const store = useEmailTrackingStore()
      store.userEmails = [{ id: 1 }]
      mockFetchUserEmails.mockResolvedValue({
        userid: 1,
        emails: [{ id: 2 }],
        total: 2,
      })

      await store.fetchUserEmails(1, true)

      expect(store.userEmails).toEqual([{ id: 1 }, { id: 2 }])
    })

    it('sets a friendly not-found message for a numeric id with no results', async () => {
      const store = useEmailTrackingStore()
      mockFetchUserEmails.mockResolvedValue({ emails: [], total: 0 })

      await store.fetchUserEmails(123)

      expect(store.userEmailsError).toBe(
        'No email history found for user #123.'
      )
    })

    it('sets a friendly error message on rejection, keyed by email', async () => {
      const store = useEmailTrackingStore()
      mockFetchUserEmails.mockRejectedValue(new Error('down'))

      await store.fetchUserEmails('x@y.com')

      expect(store.userEmailsError).toBe('No email history found for x@y.com.')
      expect(store.userEmailsLoading).toBe(false)
    })

    it('sets a friendly error message on rejection, keyed by id', async () => {
      const store = useEmailTrackingStore()
      mockFetchUserEmails.mockRejectedValue(new Error('down'))

      await store.fetchUserEmails(7)

      expect(store.userEmailsError).toBe('No email history found for user #7.')
    })
  })

  describe('loadMoreUserEmails', () => {
    it('does nothing while already loading', async () => {
      const store = useEmailTrackingStore()
      store.userEmailsLoading = true
      await store.loadMoreUserEmails()
      expect(mockFetchUserEmails).not.toHaveBeenCalled()
    })

    it('does nothing when there is no more to load', async () => {
      const store = useEmailTrackingStore()
      store.userEmails = new Array(5).fill({})
      store.userEmailsTotal = 5
      await store.loadMoreUserEmails()
      expect(mockFetchUserEmails).not.toHaveBeenCalled()
    })

    it('advances the offset and fetches more by userId', async () => {
      const store = useEmailTrackingStore()
      store.userEmails = new Array(2).fill({})
      store.userEmailsTotal = 5
      store.limit = 50
      store.offset = 0
      store.currentUserId = 9
      mockFetchUserEmails.mockResolvedValue({ emails: [], total: 5 })

      await store.loadMoreUserEmails()

      expect(store.offset).toBe(50)
      expect(mockFetchUserEmails).toHaveBeenCalledWith(9, {
        limit: 50,
        offset: 50,
      })
    })

    it('falls back to currentEmail when no currentUserId is set', async () => {
      const store = useEmailTrackingStore()
      store.userEmails = new Array(1).fill({})
      store.userEmailsTotal = 5
      store.currentUserId = null
      store.currentEmail = 'e@x.com'
      mockFetchUserEmails.mockResolvedValue({ emails: [], total: 5 })

      await store.loadMoreUserEmails()

      expect(mockFetchUserEmails.mock.calls[0][0]).toBe('e@x.com')
    })
  })

  describe('setUserFilter', () => {
    it('clears user emails and sets the id when it changes', () => {
      const store = useEmailTrackingStore()
      store.userEmails = [{ id: 1 }]
      store.setUserFilter(5)
      expect(store.currentUserId).toBe(5)
      expect(store.userEmails).toEqual([])
    })

    it('is a no-op when the id is unchanged', () => {
      const store = useEmailTrackingStore()
      store.currentUserId = 5
      store.userEmails = [{ id: 1 }]
      store.setUserFilter(5)
      expect(store.userEmails).toEqual([{ id: 1 }])
    })
  })

  describe('fetchIncomingEmails', () => {
    it('maps raw log fields into a flattened entry shape', async () => {
      const store = useEmailTrackingStore()
      mockSystemLogsFetch.mockResolvedValue({
        logs: [
          {
            id: 1,
            timestamp: 't1',
            subtype: 'delivered',
            raw: { envelope_from: 'a@b.com', group_id: 3 },
          },
        ],
      })

      await store.fetchIncomingEmails()

      expect(store.incomingEntries).toEqual([
        {
          id: 1,
          timestamp: 't1',
          envelope_from: 'a@b.com',
          envelope_to: '',
          from_address: '',
          subject: '',
          message_id: '',
          routing_outcome: 'delivered',
          routing_reason: '',
          group_id: 3,
          group_name: '',
          user_id: null,
          to_user_id: null,
          chat_id: null,
          message_ref_id: null,
        },
      ])
      expect(store.incomingHasMore).toBe(false)
    })

    it('applies search and outcome filters, and sets hasMore at the page limit', async () => {
      const store = useEmailTrackingStore()
      store.incomingSearch = 'freegle'
      store.incomingOutcomeFilter = 'bounced'
      mockSystemLogsFetch.mockResolvedValue({
        logs: new Array(100).fill({ id: 1, raw: {} }),
      })

      await store.fetchIncomingEmails()

      expect(mockSystemLogsFetch).toHaveBeenCalledWith(
        expect.objectContaining({ search: 'freegle', subtypes: 'bounced' })
      )
      expect(store.incomingHasMore).toBe(true)
    })

    it('appends and paginates from the last entry timestamp', async () => {
      const store = useEmailTrackingStore()
      store.incomingEntries = [{ id: 1, timestamp: 'earlier' }]
      mockSystemLogsFetch.mockResolvedValue({
        logs: [{ id: 2, timestamp: 'later', raw: {} }],
      })

      await store.fetchIncomingEmails(true)

      expect(mockSystemLogsFetch).toHaveBeenCalledWith(
        expect.objectContaining({ end: 'earlier' })
      )
      expect(store.incomingEntries).toHaveLength(2)
    })

    it('handles a missing logs array gracefully', async () => {
      const store = useEmailTrackingStore()
      mockSystemLogsFetch.mockResolvedValue({})
      await store.fetchIncomingEmails()
      expect(store.incomingEntries).toEqual([])
    })

    it('records an error on failure', async () => {
      const store = useEmailTrackingStore()
      mockSystemLogsFetch.mockRejectedValue(new Error('down'))
      await store.fetchIncomingEmails()
      expect(store.incomingError).toBe('down')
      expect(store.incomingLoading).toBe(false)
    })
  })

  describe('fetchIncomingCounts', () => {
    it('stores counts and total', async () => {
      const store = useEmailTrackingStore()
      store.incomingSearch = 'x'
      mockSystemLogsFetchCounts.mockResolvedValue({
        counts: { delivered: 5 },
        total: 5,
      })

      await store.fetchIncomingCounts()

      expect(mockSystemLogsFetchCounts).toHaveBeenCalledWith(
        expect.objectContaining({ search: 'x' })
      )
      expect(store.incomingCounts).toEqual({ delivered: 5 })
      expect(store.incomingCountsTotal).toBe(5)
    })

    it('resets counts to empty on failure', async () => {
      const store = useEmailTrackingStore()
      store.incomingCounts = { a: 1 }
      mockSystemLogsFetchCounts.mockRejectedValue(new Error('fail'))

      await store.fetchIncomingCounts()

      expect(store.incomingCounts).toEqual({})
      expect(store.incomingCountsTotal).toBe(0)
      expect(store.incomingCountsLoading).toBe(false)
    })
  })

  describe('clearIncoming', () => {
    it('resets incoming email browsing state', () => {
      const store = useEmailTrackingStore()
      store.incomingEntries = [{ id: 1 }]
      store.incomingHasMore = false
      store.incomingError = 'e'
      store.incomingSearch = 's'
      store.incomingOutcomeFilter = 'o'
      store.incomingCounts = { a: 1 }
      store.incomingCountsTotal = 1

      store.clearIncoming()

      expect(store.incomingEntries).toEqual([])
      expect(store.incomingHasMore).toBe(true)
      expect(store.incomingError).toBeNull()
      expect(store.incomingSearch).toBe('')
      expect(store.incomingOutcomeFilter).toBe('')
      expect(store.incomingCounts).toEqual({})
      expect(store.incomingCountsTotal).toBe(0)
    })
  })

  describe('fetchBounceEvents', () => {
    it('maps raw bounce log fields', async () => {
      const store = useEmailTrackingStore()
      mockSystemLogsFetch.mockResolvedValue({
        logs: [
          {
            id: 1,
            timestamp: 't1',
            subtype: 'permanent',
            raw: { email: 'a@b.com', user_id: 4, is_permanent: true },
          },
        ],
      })

      await store.fetchBounceEvents()

      expect(store.bounceEntries).toEqual([
        {
          id: 1,
          timestamp: 't1',
          email: 'a@b.com',
          user_id: 4,
          is_permanent: true,
          reason: '',
          subtype: 'permanent',
        },
      ])
    })

    it('handles a missing logs array gracefully', async () => {
      const store = useEmailTrackingStore()
      mockSystemLogsFetch.mockResolvedValue({})
      await store.fetchBounceEvents()
      expect(store.bounceEntries).toEqual([])
    })

    it('records an error on failure', async () => {
      const store = useEmailTrackingStore()
      mockSystemLogsFetch.mockRejectedValue(new Error('down'))
      await store.fetchBounceEvents()
      expect(store.bounceError).toBe('down')
      expect(store.bounceLoading).toBe(false)
    })
  })

  describe('simple boolean getters', () => {
    it('reflect presence/absence of loaded data', () => {
      const store = useEmailTrackingStore()
      expect(store.hasStats).toBe(false)
      expect(store.hasAMPStats).toBe(false)
      expect(store.hasTimeSeries).toBe(false)
      expect(store.hasStatsByType).toBe(false)
      expect(store.hasClickedLinks).toBe(false)
      expect(store.hasUserEmails).toBe(false)
      expect(store.hasDigestPositions).toBe(false)
      expect(store.hasReengageStats).toBe(false)

      store.stats = {}
      store.ampStats = {}
      store.timeSeries = [1]
      store.statsByType = [1]
      store.clickedLinks = [1]
      store.userEmails = [1]
      store.digestPositions = [1]
      store.reengageStats = {}

      expect(store.hasStats).toBe(true)
      expect(store.hasAMPStats).toBe(true)
      expect(store.hasTimeSeries).toBe(true)
      expect(store.hasStatsByType).toBe(true)
      expect(store.hasClickedLinks).toBe(true)
      expect(store.hasUserEmails).toBe(true)
      expect(store.hasDigestPositions).toBe(true)
      expect(store.hasReengageStats).toBe(true)
    })
  })

  describe('hasMoreClickedLinks', () => {
    it('is true only when not showing all and total exceeds the page size', () => {
      const store = useEmailTrackingStore()
      store.clickedLinksTotal = 6
      store.showAllClickedLinks = false
      expect(store.hasMoreClickedLinks).toBe(true)
      store.showAllClickedLinks = true
      expect(store.hasMoreClickedLinks).toBe(false)
      store.showAllClickedLinks = false
      store.clickedLinksTotal = 5
      expect(store.hasMoreClickedLinks).toBe(false)
    })
  })

  describe('hasMoreUserEmails', () => {
    it('compares loaded count against the total', () => {
      const store = useEmailTrackingStore()
      store.userEmails = [1, 2]
      store.userEmailsTotal = 5
      expect(store.hasMoreUserEmails).toBe(true)
      store.userEmails = [1, 2, 3, 4, 5]
      expect(store.hasMoreUserEmails).toBe(false)
    })
  })

  describe('incomingOutcomeCounts getter', () => {
    it('capitalizes each subtype key', () => {
      const store = useEmailTrackingStore()
      store.incomingCounts = { delivered: 5, bounced: 2 }
      expect(store.incomingOutcomeCounts).toEqual({
        Delivered: 5,
        Bounced: 2,
      })
    })
  })

  describe('filteredIncomingEntries getter', () => {
    it('passes entries through unchanged', () => {
      const store = useEmailTrackingStore()
      store.incomingEntries = [{ id: 1 }]
      expect(store.filteredIncomingEntries).toEqual([{ id: 1 }])
    })
  })

  describe('formattedStats getter', () => {
    it('returns null with no stats loaded', () => {
      const store = useEmailTrackingStore()
      expect(store.formattedStats).toBeNull()
    })

    it('computes the actual bounce rate from totals', () => {
      const store = useEmailTrackingStore()
      store.stats = {
        total_sent: 200,
        total_bounces: 10,
        opened: 50,
        clicked: 20,
        linked_bounces: 8,
        open_rate: 25,
        click_rate: 10,
        click_to_open_rate: 40,
        bounce_rate: 4,
        permanent_bounces: 6,
        temporary_bounces: 4,
      }

      const formatted = store.formattedStats

      expect(formatted.totalSent).toBe(200)
      expect(formatted.actualBounceRate).toBe('5.0')
      expect(formatted.openRate).toBe('25.0')
      expect(formatted.totalBounces).toBe(10)
    })

    it('avoids dividing by zero when nothing was sent', () => {
      const store = useEmailTrackingStore()
      store.stats = {}
      expect(store.formattedStats.actualBounceRate).toBe('0.0')
      expect(store.formattedStats.totalSent).toBe(0)
    })
  })

  describe('formattedAMPStats getter', () => {
    it('returns null with no AMP stats loaded', () => {
      const store = useEmailTrackingStore()
      expect(store.formattedAMPStats).toBeNull()
    })

    it('formats every AMP field, falling back to defaults for missing values', () => {
      const store = useEmailTrackingStore()
      store.ampStats = {
        total_with_amp: 10,
        amp_response_rate: 33.333,
      }

      const formatted = store.formattedAMPStats

      expect(formatted.totalWithAMP).toBe(10)
      expect(formatted.totalWithoutAMP).toBe(0)
      expect(formatted.ampResponseRate).toBe('33.3')
      expect(formatted.nonAMPResponseRate).toBe('0.0')
    })

    it('falls back from response rate to action rate when response rate is absent', () => {
      const store = useEmailTrackingStore()
      store.ampStats = {
        amp_action_rate: 12.34,
        non_amp_action_rate: 5.67,
      }

      const formatted = store.formattedAMPStats

      expect(formatted.ampResponseRate).toBe('12.3')
      expect(formatted.nonAMPResponseRate).toBe('5.7')
    })
  })

  describe('ampComparisonChartData getter', () => {
    it('returns null with no AMP stats', () => {
      const store = useEmailTrackingStore()
      expect(store.ampComparisonChartData).toBeNull()
    })

    it('builds a 2D comparison table', () => {
      const store = useEmailTrackingStore()
      store.ampStats = {
        amp_action_rate: 1,
        non_amp_action_rate: 2,
        amp_click_rate: 3,
        non_amp_click_rate: 4,
        amp_reply_rate: 5,
        non_amp_reply_rate: 6,
      }

      const data = store.ampComparisonChartData

      expect(data[0]).toEqual(['Metric', 'AMP Emails', 'Non-AMP Emails'])
      expect(data).toHaveLength(4)
      expect(data[1]).toEqual(['Action Rate (%)', 1, 2])
    })
  })

  describe('timeSeriesChartData / volumeChartData getters', () => {
    it('return null with no time series data', () => {
      const store = useEmailTrackingStore()
      expect(store.timeSeriesChartData).toBeNull()
      expect(store.volumeChartData).toBeNull()
    })

    it('compute per-day rates, guarding against a zero-sent day', () => {
      const store = useEmailTrackingStore()
      store.timeSeries = [
        {
          date: '2026-01-01',
          sent: 100,
          opened: 50,
          clicked: 25,
          total_bounces: 5,
        },
        { date: '2026-01-02', sent: 0, opened: 0, clicked: 0 },
      ]

      const chart = store.timeSeriesChartData

      expect(chart[0]).toEqual([
        'Date',
        'Open Rate (%)',
        'Click Rate (%)',
        'Bounce Rate (%)',
      ])
      expect(chart[1][1]).toBe(50)
      expect(chart[2][1]).toBe(0)

      const volume = store.volumeChartData
      expect(volume[1][1]).toBe(100)
    })
  })

  describe('typeComparisonChartData getter', () => {
    it('returns null with no per-type stats', () => {
      const store = useEmailTrackingStore()
      expect(store.typeComparisonChartData).toBeNull()
    })

    it('capitalizes the email type for display', () => {
      const store = useEmailTrackingStore()
      store.statsByType = [
        { email_type: 'digest', open_rate: 10, click_rate: 5, bounce_rate: 1 },
      ]

      const data = store.typeComparisonChartData

      expect(data[1][0]).toBe('Digest')
    })
  })
})
