import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const mockLogsFetch = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    logs: {
      fetch: mockLogsFetch,
    },
  }),
}))

// Mock dependent stores used by _enrichLogs.
const mockUserFetchMultiple = vi.fn().mockResolvedValue()
const mockMessageFetchMultiple = vi.fn().mockResolvedValue()
const mockModGroupFetchIfNeedBeMT = vi.fn().mockResolvedValue()
const mockStdmsgFetch = vi.fn().mockResolvedValue()
const mockModConfigFetchById = vi.fn().mockResolvedValue()

vi.mock('~/stores/user', () => ({
  useUserStore: () => ({
    list: {},
    fetchMultiple: mockUserFetchMultiple,
  }),
}))

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    list: {},
    fetchMultiple: mockMessageFetchMultiple,
  }),
}))

vi.mock('~/stores/modgroup', () => ({
  useModGroupStore: () => ({
    list: {},
    fetchIfNeedBeMT: mockModGroupFetchIfNeedBeMT,
  }),
}))

vi.mock('~/stores/stdmsg', () => ({
  useStdmsgStore: () => ({
    byid: vi.fn().mockReturnValue(null),
    fetch: mockStdmsgFetch,
  }),
}))

vi.mock('~/stores/modconfig', () => ({
  useModConfigStore: () => ({
    configsById: {},
    fetchById: mockModConfigFetchById,
  }),
}))

describe('logs store', () => {
  let useLogsStore

  beforeEach(async () => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    const mod = await import('~/modtools/stores/logs')
    useLogsStore = mod.useLogsStore
  })

  it('starts with empty state', () => {
    const store = useLogsStore()
    expect(store.list).toEqual([])
    expect(store.context).toBeNull()
    expect(store.params).toBeNull()
  })

  it('clear resets list and context', () => {
    const store = useLogsStore()
    store.list = [{ id: 1 }]
    store.context = { id: 'abc' }
    store.clear()
    expect(store.list).toEqual([])
    expect(store.context).toBeNull()
  })

  it('fetch with id appends to list from log field', async () => {
    const store = useLogsStore()
    store.init({})
    mockLogsFetch.mockResolvedValue({
      log: [{ id: 1, type: 'Message' }],
    })

    await store.fetch({ id: 123 })

    expect(store.list).toHaveLength(1)
    expect(store.list[0].id).toBe(1)
  })

  it('fetch without id appends from logs field and sets context', async () => {
    const store = useLogsStore()
    store.init({})
    mockLogsFetch.mockResolvedValue({
      logs: [{ id: 2, type: 'User' }],
      context: { id: 'ctx1' },
    })

    const ret = await store.fetch({})

    expect(store.list).toHaveLength(1)
    expect(store.context).toEqual({ id: 'ctx1' })
    expect(ret).toEqual({ id: 'ctx1' })
  })

  it('fetch passes context id from previous fetch', async () => {
    const store = useLogsStore()
    store.init({})
    store.context = { id: 'prev-ctx' }
    mockLogsFetch.mockResolvedValue({ logs: [], context: null })

    await store.fetch({ groupid: 1 })

    expect(mockLogsFetch).toHaveBeenCalledWith(
      expect.objectContaining({ context: 'prev-ctx', groupid: 1 })
    )
  })

  it('fetch removes context param before adding from state', async () => {
    const store = useLogsStore()
    store.init({})
    store.context = null
    mockLogsFetch.mockResolvedValue({ logs: [], context: null })

    await store.fetch({ context: 'stale' })

    // context should be deleted since store.context is null
    expect(mockLogsFetch).toHaveBeenCalledWith(
      expect.not.objectContaining({ context: expect.anything() })
    )
  })

  it('fetch accumulates logs across calls', async () => {
    const store = useLogsStore()
    store.init({})
    mockLogsFetch.mockResolvedValueOnce({
      logs: [{ id: 1 }],
      context: { id: 'c1' },
    })
    mockLogsFetch.mockResolvedValueOnce({
      logs: [{ id: 2 }],
      context: { id: 'c2' },
    })

    await store.fetch({})
    await store.fetch({})

    expect(store.list).toHaveLength(2)
  })

  it('dedupes logs across concurrent fetches returning same page', async () => {
    // Reproduces Discourse 9518.181: rapid repeated opens of ModLogsModal
    // ran fetchChunk concurrently. Both saw context=null and returned page 1,
    // pushing identical rows into the shared store list.
    const store = useLogsStore()
    store.init({})
    mockLogsFetch.mockResolvedValue({
      logs: [
        { id: 10, type: 'Message' },
        { id: 11, type: 'Message' },
        { id: 12, type: 'Message' },
      ],
      context: { id: 12 },
    })

    await Promise.all([store.fetch({}), store.fetch({}), store.fetch({})])

    expect(store.list).toHaveLength(3)
    expect(store.list.map((l) => l.id).sort()).toEqual([10, 11, 12])
  })

  it('dedupes logs when sequential fetch returns overlapping rows', async () => {
    // Reproduces pagination with DESC-ordered logs from Go API.
    // The API query is: SELECT ... WHERE logs.id < context ORDER BY logs.id DESC
    // So pagination moves backwards through log IDs (newest → oldest).
    const store = useLogsStore()
    store.init({})
    // First fetch: newest logs (e.g., IDs 100, 99)
    mockLogsFetch.mockResolvedValueOnce({
      logs: [{ id: 100 }, { id: 99 }],
      context: { id: 99 }, // context = oldest in this page, used for next fetch's WHERE id < 99
    })
    // Second fetch with context=99: gets id < 99, so returns [98, 97]
    mockLogsFetch.mockResolvedValueOnce({
      logs: [{ id: 98 }, { id: 97 }],
      context: { id: 97 },
    })

    await store.fetch({})
    await store.fetch({})

    expect(store.list).toHaveLength(4)
    // List builds chronologically: [100, 99] then append [98, 97] = [100, 99, 98, 97]
    expect(store.list.map((l) => l.id)).toEqual([100, 99, 98, 97])
  })

  it('setParams stores params', () => {
    const store = useLogsStore()
    store.setParams({ groupid: 5 })
    expect(store.params).toEqual({ groupid: 5 })
  })

  it('byId getter finds log by id', () => {
    const store = useLogsStore()
    store.list = [
      { id: 1, type: 'A' },
      { id: 2, type: 'B' },
    ]
    expect(store.byId(2)).toEqual({ id: 2, type: 'B' })
  })

  it('byId getter returns null when not found', () => {
    const store = useLogsStore()
    expect(store.byId(999)).toBeNull()
  })

  it('_enrichLogs fetches user data for userid and byuserid', async () => {
    const store = useLogsStore()
    store.init({})
    const logs = [{ userid: 10, byuserid: 20 }]

    await store._enrichLogs(logs)

    expect(mockUserFetchMultiple).toHaveBeenCalledWith([10, 20], true)
  })

  it('_enrichLogs fetches messages for msgid', async () => {
    const store = useLogsStore()
    store.init({})
    const logs = [{ msgid: 100 }]

    await store._enrichLogs(logs)

    expect(mockMessageFetchMultiple).toHaveBeenCalledWith([100])
  })

  it('_enrichLogs fetches groups for groupid', async () => {
    const store = useLogsStore()
    store.init({})
    const logs = [{ groupid: 50 }]

    await store._enrichLogs(logs)

    expect(mockModGroupFetchIfNeedBeMT).toHaveBeenCalledWith(50)
  })

  it('_enrichLogs skips empty id sets', async () => {
    const store = useLogsStore()
    store.init({})
    const logs = [{ type: 'noop' }]

    await store._enrichLogs(logs)

    expect(mockUserFetchMultiple).not.toHaveBeenCalled()
    expect(mockMessageFetchMultiple).not.toHaveBeenCalled()
  })
})
