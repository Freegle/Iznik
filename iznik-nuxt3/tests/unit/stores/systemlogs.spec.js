import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const mockSystemLogsFetch = vi.fn()
const mockPrefetchSwaggerDocs = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    systemlogs: {
      fetch: mockSystemLogsFetch,
    },
  }),
}))

vi.mock('~/modtools/composables/useSystemLogFormatter', () => ({
  prefetchSwaggerDocs: (...args) => mockPrefetchSwaggerDocs(...args),
}))

describe('systemlogs store', () => {
  let useSystemLogsStore

  beforeEach(async () => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    const mod = await import('~/modtools/stores/systemlogs')
    useSystemLogsStore = mod.useSystemLogsStore
  })

  describe('initial state', () => {
    it('starts with expected defaults', () => {
      const store = useSystemLogsStore()
      expect(store.loading).toBe(false)
      expect(store.error).toBeNull()
      expect(store.summaries).toEqual([])
      expect(store.traceChildren).toEqual({})
      expect(store.loadingTraces).toEqual({})
      expect(store.logItems).toEqual({})
      expect(store.nodeItems).toEqual({})
      expect(store.sources).toEqual([])
      expect(store.timeRange).toBe('24h')
      expect(store.sortDirection).toBe('backward')
      expect(store.showPolling).toBe(false)
      expect(store.appSource).toBe('fd')
      expect(store.collapseDuplicates).toBe(true)
      expect(store.hasMore).toBe(true)
      expect(store.lastTimestamp).toBeNull()
      expect(store.stats).toBeNull()
    })
  })

  describe('init', () => {
    it('stores config and prefetches swagger docs', () => {
      const store = useSystemLogsStore()
      const config = { some: 'config' }
      store.init(config)
      expect(store.config).toEqual(config)
      expect(mockPrefetchSwaggerDocs).toHaveBeenCalledTimes(1)
    })
  })

  describe('clear', () => {
    it('resets loaded data but leaves filters alone', () => {
      const store = useSystemLogsStore()
      store.summaries = [{ trace_id: '1' }]
      store.traceChildren = { 1: [] }
      store.loadingTraces = { 1: true }
      store.logItems = { 5: {} }
      store.nodeItems = { a: {} }
      store.hasMore = false
      store.lastTimestamp = '2026-01-01'
      store.stats = { total: 1 }
      store.error = 'oops'
      store.expandedGroups = { a: true }
      store.search = 'kept'

      store.clear()

      expect(store.summaries).toEqual([])
      expect(store.traceChildren).toEqual({})
      expect(store.loadingTraces).toEqual({})
      expect(store.logItems).toEqual({})
      expect(store.nodeItems).toEqual({})
      expect(store.hasMore).toBe(true)
      expect(store.lastTimestamp).toBeNull()
      expect(store.stats).toBeNull()
      expect(store.error).toBeNull()
      expect(store.expandedGroups).toEqual({})
      expect(store.search).toBe('kept')
    })
  })

  describe('storeLog / getLog', () => {
    it('stores and retrieves a log by id', () => {
      const store = useSystemLogsStore()
      store.storeLog({ id: 42, text: 'hello' })
      expect(store.getLog(42)).toEqual({ id: 42, text: 'hello' })
    })

    it('ignores logs with no id', () => {
      const store = useSystemLogsStore()
      store.storeLog({ text: 'no id' })
      store.storeLog(null)
      expect(store.logItems).toEqual({})
    })

    it('returns null for unknown log id', () => {
      const store = useSystemLogsStore()
      expect(store.getLog(999)).toBeNull()
    })
  })

  describe('storeNode / getNode', () => {
    it('stores and retrieves a node by key', () => {
      const store = useSystemLogsStore()
      store.storeNode('key-1', { foo: 'bar' })
      expect(store.getNode('key-1')).toEqual({ foo: 'bar' })
    })

    it('returns null for unknown node key', () => {
      const store = useSystemLogsStore()
      expect(store.getNode('missing')).toBeNull()
    })
  })

  describe('fetchSummaries', () => {
    it('replaces summaries and derives stats/hasMore/lastTimestamp', async () => {
      const store = useSystemLogsStore()
      store.init({})
      mockSystemLogsFetch.mockResolvedValue({
        summaries: [
          { first_log: { id: 1 }, first_timestamp: 't1' },
          { first_log: { id: 2 }, first_timestamp: 't2' },
        ],
        stats: { total: 2 },
      })

      await store.fetchSummaries()

      expect(store.summaries).toHaveLength(2)
      expect(store.stats).toEqual({ total: 2 })
      expect(store.lastTimestamp).toBe('t2')
      expect(store.getLog(1)).toEqual({ id: 1 })
      expect(store.getLog(2)).toEqual({ id: 2 })
      expect(store.loading).toBe(false)
      expect(store.error).toBeNull()
    })

    it('appends when params.append is set', async () => {
      const store = useSystemLogsStore()
      store.summaries = [{ first_log: { id: 0 } }]
      mockSystemLogsFetch.mockResolvedValue({
        summaries: [{ first_log: { id: 1 } }],
      })

      await store.fetchSummaries({ append: true })

      expect(store.summaries).toHaveLength(2)
    })

    it('includes sources filter only when set', async () => {
      const store = useSystemLogsStore()
      mockSystemLogsFetch.mockResolvedValue({ summaries: [] })
      store.sources = ['api', 'client']

      await store.fetchSummaries()

      expect(mockSystemLogsFetch).toHaveBeenCalledWith(
        expect.objectContaining({ sources: 'api,client' })
      )
    })

    it('omits sources filter when empty', async () => {
      const store = useSystemLogsStore()
      mockSystemLogsFetch.mockResolvedValue({ summaries: [] })

      await store.fetchSummaries()

      const callArg = mockSystemLogsFetch.mock.calls[0][0]
      expect(callArg.sources).toBeUndefined()
    })

    it('sets hasMore false when fewer results than the limit', async () => {
      const store = useSystemLogsStore()
      mockSystemLogsFetch.mockResolvedValue({
        summaries: [{ first_log: { id: 1 } }],
      })

      await store.fetchSummaries({ limit: 5 })

      expect(store.hasMore).toBe(false)
    })

    it('handles a response with no summaries key', async () => {
      const store = useSystemLogsStore()
      mockSystemLogsFetch.mockResolvedValue({})

      await store.fetchSummaries()

      expect(store.summaries).toEqual([])
      expect(store.hasMore).toBeFalsy()
      expect(store.lastTimestamp).toBeNull()
    })

    it('sets error and clears loading on failure', async () => {
      const store = useSystemLogsStore()
      mockSystemLogsFetch.mockRejectedValue(new Error('network down'))

      await store.fetchSummaries()

      expect(store.error).toBe('network down')
      expect(store.loading).toBe(false)
    })

    it('falls back to a generic error message', async () => {
      const store = useSystemLogsStore()

      mockSystemLogsFetch.mockRejectedValue({})

      await store.fetchSummaries()

      expect(store.error).toBe('Failed to fetch logs')
    })
  })

  describe('fetchTraceChildren', () => {
    it('does nothing without a traceId', async () => {
      const store = useSystemLogsStore()
      await store.fetchTraceChildren(null)
      expect(mockSystemLogsFetch).not.toHaveBeenCalled()
    })

    it('does nothing if children are already loaded', async () => {
      const store = useSystemLogsStore()
      store.traceChildren = { t1: [{ id: 1 }] }
      await store.fetchTraceChildren('t1')
      expect(mockSystemLogsFetch).not.toHaveBeenCalled()
    })

    it('does nothing if already loading', async () => {
      const store = useSystemLogsStore()
      store.loadingTraces = { t1: true }
      await store.fetchTraceChildren('t1')
      expect(mockSystemLogsFetch).not.toHaveBeenCalled()
    })

    it('fetches and stores children with explicit time bounds', async () => {
      const store = useSystemLogsStore()
      mockSystemLogsFetch.mockResolvedValue({ logs: [{ id: 9 }, { id: 10 }] })

      await store.fetchTraceChildren('t2', {
        start: '2026-01-01',
        end: '2026-01-02',
      })

      expect(mockSystemLogsFetch).toHaveBeenCalledWith(
        expect.objectContaining({
          trace_id: 't2',
          start: '2026-01-01',
          end: '2026-01-02',
        })
      )
      expect(store.traceChildren.t2).toEqual([{ id: 9 }, { id: 10 }])
      expect(store.getLog(9)).toEqual({ id: 9 })
      expect(store.loadingTraces.t2).toBe(false)
    })

    it('falls back to timeRange without explicit bounds', async () => {
      const store = useSystemLogsStore()
      store.timeRange = '7d'
      store.sources = ['api']
      mockSystemLogsFetch.mockResolvedValue({ logs: [] })

      await store.fetchTraceChildren('t3')

      expect(mockSystemLogsFetch).toHaveBeenCalledWith(
        expect.objectContaining({ start: '7d', sources: 'api' })
      )
    })

    it('marks children empty and clears loading on failure', async () => {
      const store = useSystemLogsStore()
      mockSystemLogsFetch.mockRejectedValue(new Error('boom'))

      await store.fetchTraceChildren('t4')

      expect(store.traceChildren.t4).toEqual([])
      expect(store.loadingTraces.t4).toBe(false)
    })
  })

  describe('addFiltersToParams', () => {
    it.each([
      ['types', ['a', 'b'], 'types', 'a,b'],
      ['subtypes', ['x'], 'subtypes', 'x'],
      ['levels', ['error'], 'levels', 'error'],
      ['search', 'hello', 'search', 'hello'],
      ['userid', 7, 'userid', 7],
      ['groupid', 3, 'groupid', 3],
      ['msgid', 99, 'msgid', 99],
      ['traceId', 'trace-1', 'trace_id', 'trace-1'],
      ['sessionId', 'sess-1', 'session_id', 'sess-1'],
      ['ipAddress', '1.2.3.4', 'ip', '1.2.3.4'],
      ['email', 'a@b.com', 'email', 'a@b.com'],
    ])('maps state.%s to params.%s', (stateKey, value, paramKey, expected) => {
      const store = useSystemLogsStore()
      store[stateKey] = value
      const params = {}
      store.addFiltersToParams(params)
      expect(params[paramKey]).toEqual(expected)
    })

    it('adds nothing when all filters are unset', () => {
      const store = useSystemLogsStore()
      const params = {}
      store.addFiltersToParams(params)
      expect(params).toEqual({})
    })
  })

  describe('setters', () => {
    it('setSources does not clear loaded data', () => {
      const store = useSystemLogsStore()
      store.summaries = [{ id: 1 }]
      store.setSources(['api'])
      expect(store.sources).toEqual(['api'])
      expect(store.summaries).toEqual([{ id: 1 }])
    })

    it.each([
      ['setTypes', 'types', ['a']],
      ['setSubtypes', 'subtypes', ['b']],
      ['setLevels', 'levels', ['c']],
      ['setSearch', 'search', 'q'],
      ['setTimeRange', 'timeRange', '7d'],
      ['setUserFilter', 'userid', 5],
      ['setGroupFilter', 'groupid', 6],
      ['setMsgFilter', 'msgid', 7],
      ['setSortDirection', 'sortDirection', 'forward'],
      ['setTraceFilter', 'traceId', 't1'],
      ['setSessionFilter', 'sessionId', 's1'],
      ['setIpFilter', 'ipAddress', '1.1.1.1'],
      ['setEmailFilter', 'email', 'x@y.com'],
    ])('%s sets state and clears loaded data', (action, key, value) => {
      const store = useSystemLogsStore()
      store.summaries = [{ id: 1 }]
      store.hasMore = false
      store[action](value)
      expect(store[key]).toEqual(value)
      expect(store.summaries).toEqual([])
      expect(store.hasMore).toBe(true)
    })

    it('setShowPolling does not clear loaded data', () => {
      const store = useSystemLogsStore()
      store.summaries = [{ id: 1 }]
      store.setShowPolling(true)
      expect(store.showPolling).toBe(true)
      expect(store.summaries).toEqual([{ id: 1 }])
    })

    it('setAppSource does not clear loaded data', () => {
      const store = useSystemLogsStore()
      store.summaries = [{ id: 1 }]
      store.setAppSource('mt')
      expect(store.appSource).toBe('mt')
      expect(store.summaries).toEqual([{ id: 1 }])
    })
  })

  describe('group/detail expansion toggles', () => {
    it('toggles group expansion on and off', () => {
      const store = useSystemLogsStore()
      expect(store.isGroupExpanded('g1')).toBe(false)
      store.toggleGroupExpanded('g1')
      expect(store.isGroupExpanded('g1')).toBe(true)
      store.toggleGroupExpanded('g1')
      expect(store.isGroupExpanded('g1')).toBe(false)
    })

    it('toggles detail expansion on and off', () => {
      const store = useSystemLogsStore()
      expect(store.isDetailsExpanded(1)).toBe(false)
      store.toggleDetails(1)
      expect(store.isDetailsExpanded(1)).toBe(true)
      store.toggleDetails(1)
      expect(store.isDetailsExpanded(1)).toBe(false)
    })
  })

  describe('entityIds getter', () => {
    it('collects unique ids from summaries and trace children', () => {
      const store = useSystemLogsStore()
      store.summaries = [
        {
          first_log: {
            user_id: 1,
            byuser_id: 2,
            group_id: 10,
            message_id: 100,
          },
        },
        { first_log: { user_id: 1 } }, // duplicate user_id
        { first_log: null }, // no log at all
      ]
      store.traceChildren = {
        t1: [{ user_id: 3, group_id: 10 }, { message_id: 101 }],
      }

      const { userIds, groupIds, messageIds } = store.entityIds

      expect(userIds.sort()).toEqual([1, 2, 3])
      expect(groupIds.sort()).toEqual([10])
      expect(messageIds.sort()).toEqual([100, 101])
    })

    it('returns empty arrays when nothing is loaded', () => {
      const store = useSystemLogsStore()
      expect(store.entityIds).toEqual({
        userIds: [],
        groupIds: [],
        messageIds: [],
      })
    })
  })

  describe('logsAsTree getter', () => {
    it('returns an empty array with no summaries', () => {
      const store = useSystemLogsStore()
      expect(store.logsAsTree).toEqual([])
    })

    it('builds a standalone node for a summary without a trace_id', () => {
      const store = useSystemLogsStore()
      store.summaries = [{ first_log: { id: 1, source: 'logs_table' } }]

      const tree = store.logsAsTree

      expect(tree).toHaveLength(1)
      expect(tree[0].type).toBe('standalone')
      expect(tree[0].nodeKey).toBe('standalone-1')
    })

    it('builds a collapsed trace-group node when not expanded', () => {
      const store = useSystemLogsStore()
      store.summaries = [
        {
          trace_id: 'tr-1',
          child_count: 3,
          sources: ['api'],
          route_summary: '/foo',
          first_timestamp: 't0',
          last_timestamp: 't1',
          first_log: { id: 1, source: 'logs_table' },
        },
      ]

      const tree = store.logsAsTree

      expect(tree[0].type).toBe('trace-group')
      expect(tree[0].expanded).toBe(false)
      expect(tree[0].children).toEqual([])
      expect(tree[0].nodeKey).toBe('trace-tr-1')
    })

    it('expands a trace-group and collapses consecutive duplicate children', () => {
      const store = useSystemLogsStore()
      store.expandedGroups = { 'tr-1': true }
      store.summaries = [
        {
          trace_id: 'tr-1',
          first_timestamp: 't0',
          last_timestamp: 't1',
          first_log: { id: 1, source: 'api', raw: {} },
        },
      ]
      store.traceChildren = {
        'tr-1': [
          { id: 1, source: 'api', raw: {} }, // parent - filtered out
          {
            id: 2,
            source: 'api',
            user_id: 5,
            raw: { method: 'GET', endpoint: '/foo' },
          },
          {
            id: 3,
            source: 'api',
            user_id: 5,
            raw: { method: 'GET', endpoint: '/foo' },
          }, // duplicate of 2, collapses
          {
            id: 4,
            source: 'api',
            user_id: 5,
            raw: { method: 'POST', endpoint: '/bar' },
          },
        ],
      }

      const tree = store.logsAsTree

      expect(tree[0].children).toHaveLength(2)
      expect(tree[0].children[0].count).toBe(2)
      expect(tree[0].children[0].entries).toHaveLength(2)
      expect(tree[0].children[1].count).toBe(1)
    })

    it('does not collapse duplicates when collapseDuplicates is false', () => {
      const store = useSystemLogsStore()
      store.expandedGroups = { 'tr-1': true }
      store.collapseDuplicates = false
      store.summaries = [
        {
          trace_id: 'tr-1',
          first_log: { id: 1, source: 'api', raw: {} },
        },
      ]
      store.traceChildren = {
        'tr-1': [
          { id: 1, source: 'api', raw: {} },
          { id: 2, source: 'api', raw: {} },
          { id: 3, source: 'api', raw: {} },
        ],
      }

      const tree = store.logsAsTree

      expect(tree[0].children).toHaveLength(2)
      expect(tree[0].children[0].count).toBeUndefined()
    })

    it('filters out api_headers logs', () => {
      const store = useSystemLogsStore()
      store.summaries = [
        { first_log: { id: 1, source: 'api_headers' } },
        { first_log: { id: 2, source: 'logs_table' } },
      ]

      const tree = store.logsAsTree

      expect(tree).toHaveLength(1)
      expect(tree[0].nodeKey).toBe('standalone-2')
    })

    it('filters out polling logs when showPolling is false', () => {
      const store = useSystemLogsStore()
      store.showPolling = false
      store.summaries = [
        {
          first_log: {
            id: 1,
            source: 'api',
            raw: { endpoint: '/apiv2/online' },
          },
        },
        {
          first_log: {
            id: 2,
            source: 'api',
            raw: { endpoint: '/apiv2/message/count?x=1' },
          },
        },
        {
          first_log: { id: 3, source: 'api', raw: { endpoint: '/apiv2/foo' } },
        },
      ]

      const tree = store.logsAsTree

      expect(tree).toHaveLength(1)
      expect(tree[0].nodeKey).toBe('standalone-3')
    })

    it('keeps polling logs when showPolling is true', () => {
      const store = useSystemLogsStore()
      store.showPolling = true
      store.summaries = [
        {
          first_log: {
            id: 1,
            source: 'api',
            raw: { endpoint: '/apiv2/online' },
          },
        },
      ]

      expect(store.logsAsTree).toHaveLength(1)
    })

    it('filters by appSource=fd, hiding ModTools client logs', () => {
      const store = useSystemLogsStore()
      store.appSource = 'fd'
      store.summaries = [
        {
          first_log: {
            id: 1,
            source: 'client',
            raw: { url: 'https://modtools.example.com/chats' },
          },
        },
        {
          first_log: {
            id: 2,
            source: 'client',
            raw: { url: 'https://freegle.example.com/chats' },
          },
        },
      ]

      const tree = store.logsAsTree

      expect(tree).toHaveLength(1)
      expect(tree[0].nodeKey).toBe('standalone-2')
    })

    it('filters by appSource=mt, hiding non-ModTools logs', () => {
      const store = useSystemLogsStore()
      store.appSource = 'mt'
      store.summaries = [
        {
          first_log: {
            id: 1,
            source: 'client',
            raw: { url: 'https://modtools.example.com/chats' },
          },
        },
        {
          first_log: {
            id: 2,
            source: 'client',
            raw: { url: 'https://freegle.example.com/chats' },
          },
        },
      ]

      const tree = store.logsAsTree

      expect(tree).toHaveLength(1)
      expect(tree[0].nodeKey).toBe('standalone-1')
    })

    it('shows everything for both apps when appSource=both', () => {
      const store = useSystemLogsStore()
      store.appSource = 'both'
      store.summaries = [
        {
          first_log: {
            id: 1,
            source: 'client',
            raw: { url: 'https://modtools.example.com/chats' },
          },
        },
        {
          first_log: {
            id: 2,
            source: 'client',
            raw: { url: 'https://freegle.example.com/chats' },
          },
        },
      ]

      expect(store.logsAsTree).toHaveLength(2)
    })

    it('identifies ModTools API logs via the modtools param in various truthy/falsy forms', () => {
      const store = useSystemLogsStore()
      store.appSource = 'mt'
      store.summaries = [
        {
          first_log: {
            id: 1,
            source: 'api',
            raw: { query_params: { modtools: 'true' } },
          },
        },
        {
          first_log: {
            id: 2,
            source: 'api',
            raw: { request_body: { modtools: 1 } },
          },
        },
        {
          first_log: {
            id: 3,
            source: 'api',
            raw: { query_params: { modtools: 'false' } },
          },
        },
        {
          first_log: { id: 4, source: 'api', raw: {} },
        },
      ]

      const keys = store.logsAsTree.map((n) => n.nodeKey)
      expect(keys).toEqual(['standalone-1', 'standalone-2'])
    })

    it('identifies ModTools logs_table logs by MODTOOLS_ONLY_ACTIONS and byuser mismatch', () => {
      const store = useSystemLogsStore()
      store.appSource = 'mt'
      store.summaries = [
        {
          first_log: {
            id: 1,
            source: 'logs_table',
            type: 'Message',
            subtype: 'Approved',
          },
        },
        {
          first_log: {
            id: 2,
            source: 'logs_table',
            type: 'Message',
            subtype: 'Created',
            byuser_id: 9,
            user_id: 1,
          },
        },
        {
          first_log: {
            id: 3,
            source: 'logs_table',
            type: 'Message',
            subtype: 'Created',
            byuser_id: 1,
            user_id: 1,
          },
        },
      ]

      const keys = store.logsAsTree.map((n) => n.nodeKey)
      expect(keys).toEqual(['standalone-1', 'standalone-2'])
    })

    it('treats email/batch sources as neither fd nor mt specific (shown by default)', () => {
      const store = useSystemLogsStore()
      store.appSource = 'fd'
      store.summaries = [
        { first_log: { id: 1, source: 'email' } },
        { first_log: { id: 2, source: 'batch' } },
      ]

      expect(store.logsAsTree).toHaveLength(2)
    })

    it('groups consecutive client page-load logs into a page-load-group', () => {
      const store = useSystemLogsStore()
      store.summaries = [
        {
          first_timestamp: 't0',
          last_timestamp: 't0',
          first_log: {
            id: 1,
            source: 'client',
            session_id: 'sess-1',
            raw: { page_load_phase: 'loading' },
          },
        },
        {
          first_timestamp: 't1',
          last_timestamp: 't1',
          first_log: {
            id: 2,
            source: 'client',
            session_id: 'sess-1',
            raw: { page_load_phase: 'interactive' },
          },
        },
      ]

      const tree = store.logsAsTree

      expect(tree).toHaveLength(1)
      expect(tree[0].type).toBe('page-load-group')
      expect(tree[0].childCount).toBe(2)
      expect(tree[0].children).toHaveLength(2)
    })

    it('uses ms_since_page_load as a page-load signal too', () => {
      const store = useSystemLogsStore()
      store.summaries = [
        {
          first_log: {
            id: 1,
            source: 'client',
            raw: { ms_since_page_load: 1000 },
          },
        },
        {
          first_log: {
            id: 2,
            source: 'client',
            raw: { ms_since_page_load: 1200 },
          },
        },
      ]

      const tree = store.logsAsTree
      expect(tree[0].type).toBe('page-load-group')
    })

    it('does not treat a slow ms_since_page_load as page-load', () => {
      const store = useSystemLogsStore()
      store.summaries = [
        {
          first_log: {
            id: 1,
            source: 'client',
            raw: { ms_since_page_load: 9000 },
          },
        },
      ]

      const tree = store.logsAsTree
      expect(tree[0].type).toBe('standalone')
    })

    it('flushes a lone page-load entry as a plain node, not a group', () => {
      const store = useSystemLogsStore()
      store.summaries = [
        {
          first_log: {
            id: 1,
            source: 'client',
            raw: { page_load_phase: 'loading' },
          },
        },
        { first_log: { id: 2, source: 'logs_table' } },
      ]

      const tree = store.logsAsTree

      expect(tree).toHaveLength(2)
      expect(tree[0].type).toBe('standalone')
      expect(tree[1].type).toBe('standalone')
    })

    it('flushes a trailing page-load group at the end of the list', () => {
      const store = useSystemLogsStore()
      store.summaries = [
        {
          first_log: {
            id: 1,
            source: 'client',
            session_id: 's1',
            raw: { page_load_phase: 'loading' },
          },
        },
        {
          first_log: {
            id: 2,
            source: 'client',
            session_id: 's1',
            raw: { page_load_phase: 'interactive' },
          },
        },
      ]

      const tree = store.logsAsTree
      expect(tree).toHaveLength(1)
      expect(tree[0].type).toBe('page-load-group')
    })

    it('flushes a multi-entry page-load group mid-list when a non-page-load log follows', () => {
      const store = useSystemLogsStore()
      store.summaries = [
        {
          first_log: {
            id: 1,
            source: 'client',
            session_id: 's1',
            raw: { page_load_phase: 'loading' },
          },
        },
        {
          first_log: {
            id: 2,
            source: 'client',
            session_id: 's1',
            raw: { page_load_phase: 'interactive' },
          },
        },
        { first_log: { id: 3, source: 'logs_table' } },
      ]

      const tree = store.logsAsTree

      expect(tree).toHaveLength(2)
      expect(tree[0].type).toBe('page-load-group')
      expect(tree[0].childCount).toBe(2)
      expect(tree[1].type).toBe('standalone')
    })

    it('flushes a trailing lone page-load entry as a plain node at end of list', () => {
      const store = useSystemLogsStore()
      store.summaries = [
        { first_log: { id: 1, source: 'logs_table' } },
        {
          first_log: {
            id: 2,
            source: 'client',
            session_id: 's2',
            raw: { page_load_phase: 'loading' },
          },
        },
      ]

      const tree = store.logsAsTree

      expect(tree).toHaveLength(2)
      expect(tree[1].type).toBe('standalone')
      expect(tree[1].nodeKey).toBe('standalone-2')
    })

    it('strips a bare /api prefix (not /apiv2) when checking polling suffixes', () => {
      const store = useSystemLogsStore()
      store.showPolling = false
      store.summaries = [
        {
          first_log: {
            id: 1,
            source: 'api',
            raw: { endpoint: '/api/online' },
          },
        },
        {
          first_log: { id: 2, source: 'api', raw: { endpoint: '/api/foo' } },
        },
      ]

      const tree = store.logsAsTree

      expect(tree).toHaveLength(1)
      expect(tree[0].nodeKey).toBe('standalone-2')
    })

    it('collapses duplicates for logs_table, client (grouped and ungrouped) and unknown sources', () => {
      const store = useSystemLogsStore()
      store.expandedGroups = { 'tr-1': true }
      store.summaries = [
        {
          trace_id: 'tr-1',
          first_log: { id: 1, source: 'api', raw: {} },
        },
      ]
      store.traceChildren = {
        'tr-1': [
          { id: 1, source: 'api', raw: {} }, // parent - filtered
          {
            id: 2,
            source: 'logs_table',
            user_id: 5,
            type: 'Message',
            subtype: 'Approved',
          },
          {
            id: 3,
            source: 'logs_table',
            user_id: 5,
            type: 'Message',
            subtype: 'Approved',
          }, // duplicate of 2
          {
            id: 4,
            source: 'client',
            user_id: 5,
            raw: { event_type: 'Ad impression', url: '/give' },
          },
          {
            id: 5,
            source: 'client',
            user_id: 5,
            raw: { event_type: 'Ad impression', url: '/find' },
          }, // same key as 4 (grouped by event type only)
          {
            id: 6,
            source: 'client',
            user_id: 5,
            raw: { event_type: 'PageView', url: '/give' },
          },
          {
            id: 7,
            source: 'email',
            user_id: 5,
            level: 'info',
            text: 'a welcome email',
          },
        ],
      }

      const tree = store.logsAsTree
      const groups = tree[0].children

      expect(groups).toHaveLength(4)
      expect(groups[0].count).toBe(2) // logs_table duplicates
      expect(groups[1].count).toBe(2) // client Ad impression duplicates
      expect(groups[2].count).toBe(1) // client PageView (different key)
      expect(groups[3].count).toBe(1) // email fallback
    })

    it('stores generated nodes for later ID-based lookup', () => {
      const store = useSystemLogsStore()
      store.summaries = [{ first_log: { id: 1, source: 'logs_table' } }]

      store.logsAsTree

      expect(store.getNode('standalone-1')).toBeTruthy()
    })
  })
})
