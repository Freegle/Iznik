import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

// Mock the API factory used by the store: api(config).emailtracking.* / .systemlogs.*
const fetchStats = vi.fn()
const fetchTimeSeries = vi.fn()
const fetchStatsByType = vi.fn()
const fetchTopClickedLinks = vi.fn()
const fetchUserEmails = vi.fn()
const systemlogsFetch = vi.fn()
const systemlogsFetchCounts = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    emailtracking: {
      fetchStats,
      fetchTimeSeries,
      fetchStatsByType,
      fetchTopClickedLinks,
      fetchUserEmails,
    },
    systemlogs: {
      fetch: systemlogsFetch,
      fetchCounts: systemlogsFetchCounts,
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

  describe('init', () => {
    it('stores the config for later use', () => {
      const store = useEmailTrackingStore()
      const config = { public: { APIv2: 'http://test' } }
      store.init(config)
      expect(store.config).toEqual(config)
    })
  })

  describe('setFilters', () => {
    it('merges new filters into the existing ones', () => {
      const store = useEmailTrackingStore()
      store.setFilters({ type: 'welcome' })
      store.setFilters({ start: '2026-01-01' })
      expect(store.filters).toEqual({
        type: 'welcome',
        start: '2026-01-01',
        end: '',
        cohort: '',
      })
    })
  })

  describe('fetchStats', () => {
    it('populates stats and ampStats on success, honouring the active filters', async () => {
      fetchStats.mockResolvedValueOnce({
        stats: { total_sent: 10 },
        amp_stats: { total_with_amp: 3 },
      })
      const store = useEmailTrackingStore()
      store.setFilters({ type: 'welcome', start: 'a', end: 'b' })

      await store.fetchStats()

      expect(fetchStats).toHaveBeenCalledWith({ type: 'welcome', start: 'a', end: 'b' })
      expect(store.stats).toEqual({ total_sent: 10 })
      expect(store.ampStats).toEqual({ total_with_amp: 3 })
      expect(store.statsLoading).toBe(false)
      expect(store.statsError).toBeNull()
    })

    it('defaults ampStats to null when absent from the response', async () => {
      fetchStats.mockResolvedValueOnce({ stats: {} })
      const store = useEmailTrackingStore()
      await store.fetchStats()
      expect(store.ampStats).toBeNull()
    })

    it('records an error message and clears loading on failure', async () => {
      fetchStats.mockRejectedValueOnce(new Error('boom'))
      const store = useEmailTrackingStore()
      await store.fetchStats()
      expect(store.statsError).toBe('boom')
      expect(store.statsLoading).toBe(false)
    })
  })

  describe('fetchTimeSeries', () => {
    it('populates timeSeries from response.data', async () => {
      fetchTimeSeries.mockResolvedValueOnce({ data: [{ date: '2026-01-01', sent: 10 }] })
      const store = useEmailTrackingStore()
      await store.fetchTimeSeries()
      expect(store.timeSeries).toEqual([{ date: '2026-01-01', sent: 10 }])
      expect(store.timeSeriesLoading).toBe(false)
    })

    it('defaults to an empty array when response.data is missing', async () => {
      fetchTimeSeries.mockResolvedValueOnce({})
      const store = useEmailTrackingStore()
      await store.fetchTimeSeries()
      expect(store.timeSeries).toEqual([])
    })

    it('records an error on failure', async () => {
      fetchTimeSeries.mockRejectedValueOnce(new Error('nope'))
      const store = useEmailTrackingStore()
      await store.fetchTimeSeries()
      expect(store.timeSeriesError).toBe('nope')
      expect(store.timeSeriesLoading).toBe(false)
    })
  })

  describe('fetchStatsByType', () => {
    it('populates statsByType from response.data', async () => {
      fetchStatsByType.mockResolvedValueOnce({ data: [{ email_type: 'welcome' }] })
      const store = useEmailTrackingStore()
      await store.fetchStatsByType()
      expect(store.statsByType).toEqual([{ email_type: 'welcome' }])
    })

    it('records an error on failure', async () => {
      fetchStatsByType.mockRejectedValueOnce(new Error('bad'))
      const store = useEmailTrackingStore()
      await store.fetchStatsByType()
      expect(store.statsByTypeError).toBe('bad')
      expect(store.statsByTypeLoading).toBe(false)
    })
  })

  describe('fetchClickedLinks', () => {
    it('filters out amp:// tracking urls and adjusts the total accordingly', async () => {
      fetchTopClickedLinks.mockResolvedValueOnce({
        data: [{ url: 'http://a' }, { url: 'amp://render' }, { url: 'http://b' }],
        total: 10,
      })
      const store = useEmailTrackingStore()

      await store.fetchClickedLinks()

      expect(store.clickedLinks).toEqual([{ url: 'http://a' }, { url: 'http://b' }])
      // 1 amp:// link filtered out of the 10 the API said existed.
      expect(store.clickedLinksTotal).toBe(9)
      expect(store.clickedLinksLoading).toBe(false)
    })

    it('requests limit 0 and sets showAllClickedLinks when showAll is true', async () => {
      fetchTopClickedLinks.mockResolvedValueOnce({ data: [], total: 0 })
      const store = useEmailTrackingStore()

      await store.fetchClickedLinks(true)

      expect(fetchTopClickedLinks).toHaveBeenCalledWith(
        expect.objectContaining({ limit: 0, aggregate: 'true' })
      )
      expect(store.showAllClickedLinks).toBe(true)
    })

    it('never lets the adjusted total go negative', async () => {
      fetchTopClickedLinks.mockResolvedValueOnce({
        data: [{ url: 'amp://render' }],
        total: 0,
      })
      const store = useEmailTrackingStore()
      await store.fetchClickedLinks()
      expect(store.clickedLinksTotal).toBe(0)
    })

    it('records an error on failure', async () => {
      fetchTopClickedLinks.mockRejectedValueOnce(new Error('fail'))
      const store = useEmailTrackingStore()
      await store.fetchClickedLinks()
      expect(store.clickedLinksError).toBe('fail')
    })
  })

  describe('toggleShowAllClickedLinks / toggleAggregateClickedLinks', () => {
    it('toggleShowAllClickedLinks re-fetches with the flipped flag', () => {
      const store = useEmailTrackingStore()
      const spy = vi.spyOn(store, 'fetchClickedLinks').mockResolvedValue()

      store.toggleShowAllClickedLinks()

      expect(spy).toHaveBeenCalledWith(true)
    })

    it('toggleAggregateClickedLinks flips the flag and re-fetches with the current showAll value', () => {
      const store = useEmailTrackingStore()
      const spy = vi.spyOn(store, 'fetchClickedLinks').mockResolvedValue()
      expect(store.aggregateClickedLinks).toBe(true)

      store.toggleAggregateClickedLinks()

      expect(store.aggregateClickedLinks).toBe(false)
      expect(spy).toHaveBeenCalledWith(false)
    })
  })

  describe('fetchUserEmails', () => {
    it('does nothing when no identifier is supplied', async () => {
      const store = useEmailTrackingStore()
      await store.fetchUserEmails(null)
      expect(fetchUserEmails).not.toHaveBeenCalled()
      expect(store.userEmailsLoading).toBe(false)
    })

    it('resolves the user id from the response', async () => {
      fetchUserEmails.mockResolvedValueOnce({
        userid: 42,
        emails: [{ id: 1 }],
        total: 1,
      })
      const store = useEmailTrackingStore()

      await store.fetchUserEmails(42)

      expect(store.currentUserId).toBe(42)
      expect(store.currentEmail).toBeNull()
      expect(store.userEmails).toEqual([{ id: 1 }])
      expect(store.userEmailsTotal).toBe(1)
      expect(store.userEmailsError).toBeNull()
    })

    it('resolves the email from the response when searching by recipient_email', async () => {
      fetchUserEmails.mockResolvedValueOnce({
        email: 'foo@example.com',
        emails: [{ id: 2 }],
        total: 1,
      })
      const store = useEmailTrackingStore()

      await store.fetchUserEmails('foo@example.com')

      expect(store.currentEmail).toBe('foo@example.com')
      expect(store.currentUserId).toBeNull()
    })

    it('falls back to the numeric identifier when the response has neither userid nor email', async () => {
      fetchUserEmails.mockResolvedValueOnce({ emails: [{ id: 3 }], total: 1 })
      const store = useEmailTrackingStore()

      await store.fetchUserEmails(99)

      expect(store.currentUserId).toBe(99)
      expect(store.currentEmail).toBeNull()
    })

    it('falls back to the email-shaped string identifier when the response has neither field', async () => {
      fetchUserEmails.mockResolvedValueOnce({ emails: [{ id: 4 }], total: 1 })
      const store = useEmailTrackingStore()

      await store.fetchUserEmails('bar@example.com')

      expect(store.currentEmail).toBe('bar@example.com')
    })

    it('appends rather than replaces when append is true', async () => {
      const store = useEmailTrackingStore()
      store.userEmails = [{ id: 1 }]
      store.offset = 50
      fetchUserEmails.mockResolvedValueOnce({
        userid: 42,
        emails: [{ id: 2 }],
        total: 2,
      })

      await store.fetchUserEmails(42, true)

      expect(store.userEmails).toEqual([{ id: 1 }, { id: 2 }])
      // append=true must not reset the offset/currentUserId/currentEmail block.
      expect(store.offset).toBe(50)
    })

    it('resets offset and prior identifiers when append is false', async () => {
      const store = useEmailTrackingStore()
      store.offset = 100
      store.userEmails = [{ id: 1 }]
      fetchUserEmails.mockResolvedValueOnce({ userid: 7, emails: [], total: 0 })

      await store.fetchUserEmails(7, false)

      expect(store.offset).toBe(0)
    })

    it('sets a friendly message when no email history is found', async () => {
      fetchUserEmails.mockResolvedValueOnce({ userid: 7, emails: [], total: 0 })
      const store = useEmailTrackingStore()

      await store.fetchUserEmails(7)

      expect(store.userEmailsError).toBe('No email history found for user #7.')
    })

    it('sets a friendly message using the email when searching by email and nothing found', async () => {
      fetchUserEmails.mockResolvedValueOnce({ email: 'z@example.com', emails: [], total: 0 })
      const store = useEmailTrackingStore()

      await store.fetchUserEmails('z@example.com')

      expect(store.userEmailsError).toBe('No email history found for z@example.com.')
    })

    it('sets a friendly error message when the API call rejects', async () => {
      fetchUserEmails.mockRejectedValueOnce(new Error('network error'))
      const store = useEmailTrackingStore()

      await store.fetchUserEmails(7)

      expect(store.userEmailsError).toBe('No email history found for user #7.')
      expect(store.userEmailsLoading).toBe(false)
    })
  })

  describe('loadMoreUserEmails', () => {
    it('does nothing while already loading', async () => {
      const store = useEmailTrackingStore()
      store.userEmailsLoading = true
      store.userEmails = [{ id: 1 }]
      store.userEmailsTotal = 5
      const spy = vi.spyOn(store, 'fetchUserEmails')

      await store.loadMoreUserEmails()

      expect(spy).not.toHaveBeenCalled()
    })

    it('does nothing when there are no more emails to load', async () => {
      const store = useEmailTrackingStore()
      store.userEmails = [{ id: 1 }, { id: 2 }]
      store.userEmailsTotal = 2
      const spy = vi.spyOn(store, 'fetchUserEmails')

      await store.loadMoreUserEmails()

      expect(spy).not.toHaveBeenCalled()
    })

    it('advances the offset and fetches the next page using the known identifier', async () => {
      const store = useEmailTrackingStore()
      store.userEmails = [{ id: 1 }]
      store.userEmailsTotal = 5
      store.currentUserId = 42
      fetchUserEmails.mockResolvedValueOnce({ userid: 42, emails: [{ id: 2 }], total: 5 })

      await store.loadMoreUserEmails()

      expect(store.offset).toBe(store.limit)
      expect(fetchUserEmails).toHaveBeenCalledWith(42, { limit: store.limit, offset: store.limit })
    })

    it('uses currentEmail when there is no currentUserId', async () => {
      const store = useEmailTrackingStore()
      store.userEmails = [{ id: 1 }]
      store.userEmailsTotal = 5
      store.currentEmail = 'x@example.com'
      fetchUserEmails.mockResolvedValueOnce({ email: 'x@example.com', emails: [], total: 5 })

      await store.loadMoreUserEmails()

      expect(fetchUserEmails).toHaveBeenCalledWith(
        'x@example.com',
        expect.any(Object)
      )
    })
  })

  describe('setUserFilter', () => {
    it('clears user emails and sets the new filter when the id changes', () => {
      const store = useEmailTrackingStore()
      store.userEmails = [{ id: 1 }]
      store.currentUserId = 1

      store.setUserFilter(2)

      expect(store.currentUserId).toBe(2)
      expect(store.userEmails).toEqual([])
    })

    it('is a no-op when the id is unchanged', () => {
      const store = useEmailTrackingStore()
      store.currentUserId = 5
      store.userEmails = [{ id: 1 }]

      store.setUserFilter(5)

      expect(store.currentUserId).toBe(5)
      expect(store.userEmails).toEqual([{ id: 1 }])
    })
  })

  describe('fetchIncomingEmails', () => {
    it('maps raw log fields into incoming entries and flags whether there is more', async () => {
      systemlogsFetch.mockResolvedValueOnce({
        logs: [
          {
            id: 1,
            timestamp: 't1',
            subtype: 'delivered',
            raw: { envelope_from: 'a@x.com', subject: 'Hi', group_id: 9 },
          },
        ],
      })
      const store = useEmailTrackingStore()

      await store.fetchIncomingEmails()

      expect(store.incomingEntries).toEqual([
        {
          id: 1,
          timestamp: 't1',
          envelope_from: 'a@x.com',
          envelope_to: '',
          from_address: '',
          subject: 'Hi',
          message_id: '',
          routing_outcome: 'delivered',
          routing_reason: '',
          group_id: 9,
          group_name: '',
          user_id: null,
          to_user_id: null,
          chat_id: null,
          message_ref_id: null,
        },
      ])
      expect(store.incomingHasMore).toBe(false)
    })

    it('flags hasMore true when exactly a full page of 100 logs is returned', async () => {
      const logs = Array.from({ length: 100 }, (_, i) => ({ id: i, timestamp: `t${i}`, raw: {} }))
      systemlogsFetch.mockResolvedValueOnce({ logs })
      const store = useEmailTrackingStore()

      await store.fetchIncomingEmails()

      expect(store.incomingHasMore).toBe(true)
    })

    it('includes search and outcome filters in the request params', async () => {
      systemlogsFetch.mockResolvedValueOnce({ logs: [] })
      const store = useEmailTrackingStore()
      store.incomingSearch = 'spam'
      store.incomingOutcomeFilter = 'bounced'

      await store.fetchIncomingEmails()

      expect(systemlogsFetch).toHaveBeenCalledWith(
        expect.objectContaining({ search: 'spam', subtypes: 'bounced' })
      )
    })

    it('appends to existing entries and paginates using the last entry timestamp', async () => {
      const store = useEmailTrackingStore()
      store.incomingEntries = [{ id: 1, timestamp: 'first', raw: {} }]
      systemlogsFetch.mockResolvedValueOnce({
        logs: [{ id: 2, timestamp: 'second', raw: {} }],
      })

      await store.fetchIncomingEmails(true)

      expect(systemlogsFetch).toHaveBeenCalledWith(
        expect.objectContaining({ end: 'first' })
      )
      expect(store.incomingEntries).toHaveLength(2)
      expect(store.incomingEntries[1].id).toBe(2)
    })

    it('records an error on failure', async () => {
      systemlogsFetch.mockRejectedValueOnce(new Error('down'))
      const store = useEmailTrackingStore()
      await store.fetchIncomingEmails()
      expect(store.incomingError).toBe('down')
      expect(store.incomingLoading).toBe(false)
    })
  })

  describe('fetchIncomingCounts', () => {
    it('populates counts and total on success', async () => {
      systemlogsFetchCounts.mockResolvedValueOnce({
        counts: { delivered: 5 },
        total: 5,
      })
      const store = useEmailTrackingStore()

      await store.fetchIncomingCounts()

      expect(store.incomingCounts).toEqual({ delivered: 5 })
      expect(store.incomingCountsTotal).toBe(5)
      expect(store.incomingCountsLoading).toBe(false)
    })

    it('resets counts to empty on failure', async () => {
      systemlogsFetchCounts.mockRejectedValueOnce(new Error('oops'))
      const store = useEmailTrackingStore()
      store.incomingCounts = { delivered: 5 }
      store.incomingCountsTotal = 5

      await store.fetchIncomingCounts()

      expect(store.incomingCounts).toEqual({})
      expect(store.incomingCountsTotal).toBe(0)
    })
  })

  describe('clearIncoming', () => {
    it('resets all incoming-related state', () => {
      const store = useEmailTrackingStore()
      store.incomingEntries = [{ id: 1 }]
      store.incomingHasMore = false
      store.incomingError = 'err'
      store.incomingSearch = 'x'
      store.incomingOutcomeFilter = 'y'
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
    it('maps raw bounce log fields with defaults', async () => {
      systemlogsFetch.mockResolvedValueOnce({
        logs: [{ id: 1, timestamp: 't1', raw: { email: 'x@y.com', is_permanent: true } }],
      })
      const store = useEmailTrackingStore()

      await store.fetchBounceEvents()

      expect(store.bounceEntries).toEqual([
        {
          id: 1,
          timestamp: 't1',
          email: 'x@y.com',
          user_id: 0,
          is_permanent: true,
          reason: '',
          subtype: '',
        },
      ])
    })

    it('records an error on failure', async () => {
      systemlogsFetch.mockRejectedValueOnce(new Error('bounce fail'))
      const store = useEmailTrackingStore()
      await store.fetchBounceEvents()
      expect(store.bounceError).toBe('bounce fail')
      expect(store.bounceLoading).toBe(false)
    })
  })

  describe('clear / clearStats / clearUserEmails', () => {
    it('clear resets the stats/links/user-email slices but leaves incoming state alone', () => {
      const store = useEmailTrackingStore()
      store.stats = { a: 1 }
      store.ampStats = { b: 1 }
      store.statsError = 'e'
      store.timeSeries = [1]
      store.statsByType = [1]
      store.clickedLinks = [1]
      store.clickedLinksTotal = 5
      store.showAllClickedLinks = true
      store.aggregateClickedLinks = false
      store.digestPositions = [1]
      store.userEmails = [1]
      store.userEmailsTotal = 5
      store.currentUserId = 9
      store.currentEmail = 'x@y.com'
      store.offset = 50
      store.incomingEntries = [{ id: 1 }]

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
      expect(store.userEmails).toEqual([])
      expect(store.userEmailsTotal).toBe(0)
      expect(store.currentUserId).toBeNull()
      expect(store.currentEmail).toBeNull()
      expect(store.offset).toBe(0)
      // clear() does not touch incoming-email state - clearIncoming() is separate.
      expect(store.incomingEntries).toEqual([{ id: 1 }])
    })

    it('clearStats only resets the aggregate stats slice', () => {
      const store = useEmailTrackingStore()
      store.stats = { a: 1 }
      store.ampStats = { b: 1 }
      store.statsError = 'e'
      store.timeSeries = [1]

      store.clearStats()

      expect(store.stats).toBeNull()
      expect(store.ampStats).toBeNull()
      expect(store.statsError).toBeNull()
      expect(store.timeSeries).toEqual([1])
    })

    it('clearUserEmails resets only the user-email slice', () => {
      const store = useEmailTrackingStore()
      store.userEmails = [1]
      store.userEmailsTotal = 5
      store.userEmailsError = 'e'
      store.currentUserId = 9
      store.currentEmail = 'x@y.com'
      store.offset = 50
      store.stats = { a: 1 }

      store.clearUserEmails()

      expect(store.userEmails).toEqual([])
      expect(store.userEmailsTotal).toBe(0)
      expect(store.userEmailsError).toBeNull()
      expect(store.currentUserId).toBeNull()
      expect(store.currentEmail).toBeNull()
      expect(store.offset).toBe(0)
      expect(store.stats).toEqual({ a: 1 })
    })
  })

  describe('getters', () => {
    it('hasStats / hasAMPStats reflect whether the aggregate stats are populated', () => {
      const store = useEmailTrackingStore()
      expect(store.hasStats).toBe(false)
      expect(store.hasAMPStats).toBe(false)
      store.stats = {}
      store.ampStats = {}
      expect(store.hasStats).toBe(true)
      expect(store.hasAMPStats).toBe(true)
    })

    it('hasTimeSeries / hasStatsByType / hasClickedLinks / hasUserEmails reflect array length', () => {
      const store = useEmailTrackingStore()
      expect(store.hasTimeSeries).toBe(false)
      expect(store.hasStatsByType).toBe(false)
      expect(store.hasClickedLinks).toBe(false)
      expect(store.hasUserEmails).toBe(false)

      store.timeSeries = [1]
      store.statsByType = [1]
      store.clickedLinks = [1]
      store.userEmails = [1]

      expect(store.hasTimeSeries).toBe(true)
      expect(store.hasStatsByType).toBe(true)
      expect(store.hasClickedLinks).toBe(true)
      expect(store.hasUserEmails).toBe(true)
    })

    it('hasMoreClickedLinks is true only when not showing all and the total exceeds 5', () => {
      const store = useEmailTrackingStore()
      store.clickedLinksTotal = 10
      expect(store.hasMoreClickedLinks).toBe(true)
      store.showAllClickedLinks = true
      expect(store.hasMoreClickedLinks).toBe(false)
      store.showAllClickedLinks = false
      store.clickedLinksTotal = 5
      expect(store.hasMoreClickedLinks).toBe(false)
    })

    it('hasMoreUserEmails compares loaded count against the reported total', () => {
      const store = useEmailTrackingStore()
      store.userEmails = [1, 2]
      store.userEmailsTotal = 5
      expect(store.hasMoreUserEmails).toBe(true)
      store.userEmailsTotal = 2
      expect(store.hasMoreUserEmails).toBe(false)
    })

    it('incomingOutcomeCounts capitalises each subtype key', () => {
      const store = useEmailTrackingStore()
      store.incomingCounts = { delivered: 5, bounced: 2 }
      expect(store.incomingOutcomeCounts).toEqual({ Delivered: 5, Bounced: 2 })
    })

    it('filteredIncomingEntries returns the entries as-is (server already filtered)', () => {
      const store = useEmailTrackingStore()
      store.incomingEntries = [{ id: 1 }]
      expect(store.filteredIncomingEntries).toBe(store.incomingEntries)
    })

    it('formattedStats is null with no stats and computes derived rates otherwise', () => {
      const store = useEmailTrackingStore()
      expect(store.formattedStats).toBeNull()

      store.stats = {
        total_sent: 200,
        total_bounces: 10,
        opened: 50,
        clicked: 20,
        linked_bounces: 8,
        open_rate: 25,
        click_rate: 10,
        click_to_open_rate: 40,
        bounce_rate: 5,
        permanent_bounces: 6,
        temporary_bounces: 4,
      }

      expect(store.formattedStats).toEqual({
        totalSent: 200,
        opened: 50,
        clicked: 20,
        linkedBounces: 8,
        openRate: '25.0',
        clickRate: '10.0',
        clickToOpenRate: '40.0',
        bounceRate: '5.0',
        totalBounces: 10,
        permanentBounces: 6,
        temporaryBounces: 4,
        actualBounceRate: '5.0',
      })
    })

    it('formattedStats reports a zero actual bounce rate when nothing was sent', () => {
      const store = useEmailTrackingStore()
      store.stats = { total_sent: 0, total_bounces: 0 }
      expect(store.formattedStats.actualBounceRate).toBe('0.0')
    })

    it('formattedAMPStats is null with no ampStats and formats rates otherwise', () => {
      const store = useEmailTrackingStore()
      expect(store.formattedAMPStats).toBeNull()

      store.ampStats = {
        total_with_amp: 100,
        amp_action_rate: 12.34,
        amp_response_rate: 5,
      }
      const formatted = store.formattedAMPStats
      expect(formatted.totalWithAMP).toBe(100)
      expect(formatted.ampActionRate).toBe('12.3')
      expect(formatted.ampResponseRate).toBe('5.0')
    })

    it('formattedAMPStats falls back from response rate to action rate when response rate is absent', () => {
      const store = useEmailTrackingStore()
      store.ampStats = { amp_action_rate: 7 }
      expect(store.formattedAMPStats.ampResponseRate).toBe('7.0')
    })

    it('ampComparisonChartData is null with no ampStats and builds a 2D chart array otherwise', () => {
      const store = useEmailTrackingStore()
      expect(store.ampComparisonChartData).toBeNull()

      store.ampStats = {
        amp_action_rate: 10,
        non_amp_action_rate: 5,
        amp_click_rate: 20,
        non_amp_click_rate: 8,
        amp_reply_rate: 3,
        non_amp_reply_rate: 1,
      }

      expect(store.ampComparisonChartData).toEqual([
        ['Metric', 'AMP Emails', 'Non-AMP Emails'],
        ['Action Rate (%)', 10, 5],
        ['Click Rate (%)', 20, 8],
        ['Reply Rate (%)', 3, 1],
      ])
    })

    it('timeSeriesChartData is null when empty and computes per-day rates otherwise', () => {
      const store = useEmailTrackingStore()
      expect(store.timeSeriesChartData).toBeNull()

      store.timeSeries = [
        { date: '2026-01-01', sent: 100, opened: 25, clicked: 10, total_bounces: 2 },
        { date: '2026-01-02', sent: 0, opened: 0, clicked: 0 },
      ]

      const data = store.timeSeriesChartData
      expect(data[0]).toEqual(['Date', 'Open Rate (%)', 'Click Rate (%)', 'Bounce Rate (%)'])
      expect(data[1][0]).toEqual(new Date('2026-01-01'))
      expect(data[1].slice(1)).toEqual([25, 10, 2])
      // A day with zero sends can't divide - every rate is 0, not NaN.
      expect(data[2].slice(1)).toEqual([0, 0, 0])
    })

    it('typeComparisonChartData is null when empty and capitalises the email type name otherwise', () => {
      const store = useEmailTrackingStore()
      expect(store.typeComparisonChartData).toBeNull()

      store.statsByType = [
        { email_type: 'welcome', open_rate: 30, click_rate: 12, bounce_rate: 1 },
      ]

      expect(store.typeComparisonChartData).toEqual([
        ['Email Type', 'Open Rate (%)', 'Click Rate (%)', 'Bounce Rate (%)'],
        ['Welcome', 30, 12, 1],
      ])
    })

    it('volumeChartData is null when empty and lists sent counts per day otherwise', () => {
      const store = useEmailTrackingStore()
      expect(store.volumeChartData).toBeNull()

      store.timeSeries = [{ date: '2026-01-01', sent: 42 }]

      const data = store.volumeChartData
      expect(data[0]).toEqual(['Date', 'Emails Sent'])
      expect(data[1][0]).toEqual(new Date('2026-01-01'))
      expect(data[1][1]).toBe(42)
    })
  })
})
