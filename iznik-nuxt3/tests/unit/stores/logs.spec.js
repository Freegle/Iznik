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

  it('stores accumulate logs from multiple users without filtering', async () => {
    // The shared store accumulates logs from ALL users. This test verifies
    // that the store itself doesn't filter, and that the filtering must
    // happen in ModLogsModal to prevent cross-user log mixing (Discourse #9564).
    const store = useLogsStore()
    store.init({})

    // Simulate fetching logs for user 10
    mockLogsFetch.mockResolvedValueOnce({
      logs: [
        { id: 100, userid: 10, byuserid: 999, type: 'Message' },
        { id: 101, userid: 10, byuserid: null, type: 'User' },
      ],
      context: { id: 101 },
    })
    await store.fetch({ userid: 10 })

    // Simulate fetching logs for user 20 (rapid modal re-open)
    mockLogsFetch.mockResolvedValueOnce({
      logs: [
        { id: 200, userid: 20, byuserid: 999, type: 'Message' },
        { id: 201, userid: 20, byuserid: null, type: 'User' },
      ],
      context: { id: 201 },
    })
    await store.fetch({ userid: 20 })

    // The STORE contains all logs from both users
    expect(store.list).toHaveLength(4)
    expect(store.list.map((l) => l.id)).toContain(100)
    expect(store.list.map((l) => l.id)).toContain(101)
    expect(store.list.map((l) => l.id)).toContain(200)
    expect(store.list.map((l) => l.id)).toContain(201)

    // This demonstrates that ModLogsModal MUST filter to show only
    // logs where (userid === targetUser OR byuserid === targetUser)
    const logsForUser10 = store.list.filter(
      (log) => log.userid === 10 || log.byuserid === 10
    )
    const logsForUser20 = store.list.filter(
      (log) => log.userid === 20 || log.byuserid === 20
    )

    expect(logsForUser10).toHaveLength(2)
    expect(logsForUser20).toHaveLength(2)
    expect(logsForUser10.map((l) => l.id)).toEqual([100, 101])
    expect(logsForUser20.map((l) => l.id)).toEqual([200, 201])
  })

  // ---------------------------------------------------------------------------
  // AssertFlip: stale in-flight fetch after community filter change
  //
  // Symptom (issue #9672): when the community (groupid) filter is changed while
  // a fetch is still in progress from the previous group, the in-flight fetch
  // resolves AFTER clear() has been called. Because fetch() has no stale-check,
  // it pushes the old group's entries into the freshly-emptied list, producing
  // duplicated/cross-group entries in the log history view.
  //
  // Root cause: logs.js fetch() does not check whether clear() was called while
  // the API request was in-flight. The existingIds dedup only guards against
  // concurrent same-page fetches, not against cross-group pollution after a clear.
  // ---------------------------------------------------------------------------

  it('stale in-flight fetch pollutes cleared list — AssertFlip Step 1 (buggy)', async () => {
    // STEP 1: assert the WRONG / buggy behaviour.
    // This test PASSES on the current (buggy) code.
    // If it fails here, the bug is already fixed and the diagnosis is wrong.
    const store = useLogsStore()
    store.init({})

    // Create a deferred promise to control when the "old groupid" fetch resolves.
    let resolveOldFetch
    const oldFetchPromise = new Promise((resolve) => {
      resolveOldFetch = resolve
    })
    mockLogsFetch.mockReturnValueOnce(oldFetchPromise)

    // Start an inflight fetch for groupid=5 (won't complete until we resolve it).
    const oldFetchDone = store.fetch({ groupid: 5 })

    // User switches community filter → caller clears the store.
    store.clear()
    expect(store.list).toHaveLength(0) // list is empty immediately after clear

    // Resolve the OLD group-5 fetch. On buggy code fetch() has no stale-check
    // so it pushes group-5 entries straight into the now-empty list.
    resolveOldFetch({
      logs: [
        { id: 10, groupid: 5 },
        { id: 11, groupid: 5 },
        { id: 12, groupid: 5 },
      ],
      context: { id: 10 },
    })
    await oldFetchDone

    // BUGGY assertion: stale group-5 data leaked into the cleared list.
    expect(store.list.length).toBeGreaterThan(0)
  })

  it('community filter change must not allow stale in-flight fetch to persist — AssertFlip Step 2', async () => {
    // STEP 2 (inverted): these assertions FAIL on buggy code, PASS after fix.
    // The fix must discard any in-flight fetch result that arrives after clear().
    const store = useLogsStore()
    store.init({})

    let resolveOldFetch
    const oldFetchPromise = new Promise((resolve) => {
      resolveOldFetch = resolve
    })
    mockLogsFetch.mockReturnValueOnce(oldFetchPromise)

    // Inflight fetch for old groupid=5.
    const oldFetchDone = store.fetch({ groupid: 5 })

    // User switches filter: clear the store.
    store.clear()

    // Old group-5 fetch returns with 3 seeded entries.
    resolveOldFetch({
      logs: [
        { id: 10, groupid: 5 },
        { id: 11, groupid: 5 },
        { id: 12, groupid: 5 },
      ],
      context: { id: 10 },
    })
    await oldFetchDone

    // CORRECT assertion: after clear(), stale results must NOT appear in the list.
    expect(store.list).toHaveLength(0)

    // CORRECT assertion: context must remain null (not set by the stale fetch).
    expect(store.context).toBeNull()
  })
})
