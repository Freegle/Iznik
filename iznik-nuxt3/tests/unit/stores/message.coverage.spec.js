/**
 * Broad coverage pass over stores/message.js actions/getters that
 * message.spec.js and message.heldByOtherMod.spec.js don't touch: the
 * fetch()/processMessageBatch()/fetchMultiple() batching pipeline,
 * handleFetchError(), fetchInBounds/search/similar/matches/fetchMyGroups/
 * fetchByUser, bulkInterest(State), Helper actions, update(), remove/clear,
 * the promise/renege/addBy/removeBy/intend family, markSeen()'s 401 swallow,
 * fetchMT/fetchReach/updateMT, delete/approveedits/revertedits/backToPending,
 * approve/reject's fromuser re-fetch, reply/spam/move, searchMember, and the
 * remaining getters.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

import { useMessageStore } from '~/stores/message'
import { useAuthStore } from '~/stores/auth'
import { APIError } from '~/api/APIErrors'

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

const mockApiFetch = vi.fn()
const mockFetchByUser = vi.fn()
const mockSave = vi.fn()
const mockSearch = vi.fn()
const mockSimilar = vi.fn()
const mockMatches = vi.fn()
const mockInbounds = vi.fn()
const mockMygroups = vi.fn()
const mockFetchMessages = vi.fn()
const mockView = vi.fn()
const mockMarkSeen = vi.fn()
const mockCount = vi.fn()
const mockNearbyMarkSeen = vi.fn()
const mockHold = vi.fn()
const mockRelease = vi.fn()
const mockFetchMT = vi.fn()
const mockBulkInterest = vi.fn()
const mockBulkInterestState = vi.fn()
const mockGetHelper = vi.fn()
const mockHelper = vi.fn()
const mockUpdate = vi.fn()
const mockAddBy = vi.fn()
const mockRemoveBy = vi.fn()
const mockIntend = vi.fn()
const mockReach = vi.fn()
const mockDelete = vi.fn()
const mockApprove = vi.fn()
const mockReject = vi.fn()
const mockReply = vi.fn()
const mockSpam = vi.fn()
const mockApproveEdits = vi.fn()
const mockRevertEdits = vi.fn()
const mockGroupFetchBatch = vi.fn()
const mockUserFetch = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    message: {
      fetch: mockApiFetch,
      fetchByUser: mockFetchByUser,
      save: mockSave,
      inbounds: mockInbounds,
      search: mockSearch,
      similar: mockSimilar,
      matches: mockMatches,
      mygroups: mockMygroups,
      fetchMessages: mockFetchMessages,
      view: mockView,
      markSeen: mockMarkSeen,
      count: mockCount,
      hold: mockHold,
      release: mockRelease,
      fetchMT: mockFetchMT,
      bulkInterest: mockBulkInterest,
      bulkInterestState: mockBulkInterestState,
      getHelper: mockGetHelper,
      helper: mockHelper,
      update: mockUpdate,
      addBy: mockAddBy,
      removeBy: mockRemoveBy,
      intend: mockIntend,
      reach: mockReach,
      delete: mockDelete,
      approve: mockApprove,
      reject: mockReject,
      reply: mockReply,
      spam: mockSpam,
      approveEdits: mockApproveEdits,
      revertEdits: mockRevertEdits,
    },
  }),
}))

vi.mock('~/stores/auth', () => ({
  useAuthStore: vi.fn(),
}))

vi.mock('~/stores/group', () => ({
  useGroupStore: () => ({ fetchBatch: mockGroupFetchBatch }),
}))

vi.mock('~/stores/user', () => ({
  useUserStore: () => ({ fetch: mockUserFetch }),
}))

const mockNearbyStore = { markSeen: mockNearbyMarkSeen, messageList: [] }
vi.mock('~/stores/nearby', () => ({
  useNearbyStore: () => mockNearbyStore,
}))

const mockMiscStore = { modtools: false }
vi.mock('~/stores/misc', () => ({
  useMiscStore: () => mockMiscStore,
}))

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
  useAuthStore.mockReturnValue({ user: { id: 1 } })
  mockNearbyStore.messageList = []
})

describe('message store - fetch() cache/batch behaviour', () => {
  it('returns the cached message immediately without hitting the batch pipeline', async () => {
    const store = useMessageStore()
    store.init({})
    store.list[5] = { id: 5, addedToCache: Math.round(Date.now() / 1000) }

    const result = await store.fetch(5)

    expect(result).toBe(store.list[5])
    expect(mockApiFetch).not.toHaveBeenCalled()
  })

  it('re-fetches when the cached entry expired more than 10 minutes ago', async () => {
    const store = useMessageStore()
    store.init({})
    store.list[6] = {
      id: 6,
      addedToCache: Math.round(Date.now() / 1000) - 601,
    }
    mockApiFetch.mockResolvedValue([{ id: 6, subject: 'fresh' }])

    const p = store.fetch(6)
    await wait(70)
    await p

    expect(mockApiFetch).toHaveBeenCalledWith('6', false)
    expect(store.list[6].subject).toBe('fresh')
  })

  it('bypasses a fresh cache entry when force is true', async () => {
    const store = useMessageStore()
    store.init({})
    store.list[7] = { id: 7, addedToCache: Math.round(Date.now() / 1000) }
    mockApiFetch.mockResolvedValue([{ id: 7, subject: 'forced' }])

    const p = store.fetch(7, true)
    await wait(70)
    await p

    expect(mockApiFetch).toHaveBeenCalledWith('7', false)
  })

  it('awaits an in-flight fetch for the same id and returns the cached value', async () => {
    const store = useMessageStore()
    store.init({})
    store.list[8] = { id: 8, subject: 'settled' }
    store.fetching[8] = Promise.resolve()

    const result = await store.fetch(8)

    expect(result).toEqual({ id: 8, subject: 'settled' })
    expect(mockApiFetch).not.toHaveBeenCalled()
  })

  it('swallows a 404 from an in-flight fetch via handleFetchError', async () => {
    const store = useMessageStore()
    store.init({})
    // Deliberately not cached: fetch()'s early "already have it" return only
    // triggers when this.list[id] is set, so leaving it empty is what routes
    // this call through the "already fetching" branch being tested here.
    store.fetching[9] = Promise.reject(
      new APIError({ response: { status: 404 } }, 'gone')
    )

    const result = await store.fetch(9)

    expect(result).toBeUndefined()
    expect(store.list[9]).toBeUndefined()
  })

  it('propagates a non-404 error from an in-flight fetch', async () => {
    const store = useMessageStore()
    store.init({})
    store.fetching[10] = Promise.reject(new Error('boom'))

    await expect(store.fetch(10)).rejects.toThrow('boom')
  })

  it('batches concurrent fetch() calls for different ids into one API call', async () => {
    const store = useMessageStore()
    store.init({})
    mockApiFetch.mockResolvedValue([
      { id: 20, subject: 'a' },
      { id: 21, subject: 'b' },
    ])

    const p1 = store.fetch(20)
    const p2 = store.fetch(21)
    await wait(70)
    const [r1, r2] = await Promise.all([p1, p2])

    expect(mockApiFetch).toHaveBeenCalledTimes(1)
    expect(mockApiFetch).toHaveBeenCalledWith('20,21', false)
    expect(r1.subject).toBe('a')
    expect(r2.subject).toBe('b')
  })
})

describe('message store - handleFetchError()', () => {
  it('removes a 404d message from list and the current user byUserList', () => {
    const store = useMessageStore()
    store.init({})
    store.list[30] = { id: 30 }
    store.byUserList[1] = [{ id: 30 }, { id: 31 }]

    store.handleFetchError(30, new APIError({ response: { status: 404 } }, 'x'))

    expect(store.list[30]).toBeUndefined()
    expect(store.byUserList[1]).toEqual([{ id: 31 }])
  })

  it('does not touch byUserList when there is no logged in user', () => {
    useAuthStore.mockReturnValue({ user: null })
    const store = useMessageStore()
    store.init({})
    store.list[30] = { id: 30 }

    store.handleFetchError(30, new APIError({ response: { status: 404 } }, 'x'))

    expect(store.list[30]).toBeUndefined()
  })

  it('rethrows a non-404 APIError', () => {
    const store = useMessageStore()
    store.init({})

    expect(() =>
      store.handleFetchError(
        30,
        new APIError({ response: { status: 500 } }, 'x')
      )
    ).toThrow()
  })

  it('rethrows an error that is not an APIError at all', () => {
    const store = useMessageStore()
    store.init({})

    expect(() => store.handleFetchError(30, new Error('plain'))).toThrow(
      'plain'
    )
  })
})

describe('message store - processMessageBatch()', () => {
  it('does nothing when there are no pending fetches', async () => {
    const store = useMessageStore()
    store.init({})

    await store.processMessageBatch()

    expect(mockApiFetch).not.toHaveBeenCalled()
  })

  it('skips ids that are already cached and not forced, but still resolves them', async () => {
    const store = useMessageStore()
    store.init({})
    store.list[40] = { id: 40, addedToCache: Math.round(Date.now() / 1000) }
    let resolved
    store.pendingFetches = [
      {
        id: 40,
        force: false,
        resolve: (v) => (resolved = v),
        reject: () => {},
      },
    ]

    await store.processMessageBatch()

    expect(mockApiFetch).not.toHaveBeenCalled()
    expect(resolved).toBe(store.list[40])
  })

  it('logs and swallows an error from fetchMultiple', async () => {
    const store = useMessageStore()
    store.init({})
    mockApiFetch.mockRejectedValue(new Error('down'))
    let resolved = 'not-called'
    store.pendingFetches = [
      {
        id: 41,
        force: false,
        resolve: (v) => (resolved = v),
        reject: () => {},
      },
    ]

    await expect(store.processMessageBatch()).resolves.toBeUndefined()
    expect(resolved).toBeUndefined()
  })
})

describe('message store - fetchMultiple()', () => {
  it('skips ids already cached and not forced, and never calls the API', async () => {
    const store = useMessageStore()
    store.init({})
    store.list[50] = { id: 50 }

    await store.fetchMultiple([50], false)

    expect(mockApiFetch).not.toHaveBeenCalled()
    expect(mockGroupFetchBatch).not.toHaveBeenCalled()
  })

  it('re-fetches an already cached id when force is true', async () => {
    const store = useMessageStore()
    store.init({})
    store.list[51] = { id: 51, subject: 'stale' }
    mockApiFetch.mockResolvedValue([{ id: 51, subject: 'new' }])

    await store.fetchMultiple([51], true)

    expect(mockApiFetch).toHaveBeenCalledWith('51', false)
    expect(store.list[51].subject).toBe('new')
  })

  it('splits more than 19 ids into multiple chunks', async () => {
    const store = useMessageStore()
    store.init({})
    const ids = Array.from({ length: 20 }, (_, i) => 100 + i)
    mockApiFetch.mockImplementation((joined) =>
      Promise.resolve(
        joined.split(',').map((id) => ({ id: parseInt(id), subject: 'x' }))
      )
    )

    await store.fetchMultiple(ids, false)

    expect(mockApiFetch).toHaveBeenCalledTimes(2)
    expect(store.list[100]).toBeDefined()
    expect(store.list[119]).toBeDefined()
  })

  it('stores a single object response keyed by its own id', async () => {
    const store = useMessageStore()
    store.init({})
    mockApiFetch.mockResolvedValue({ id: 60, subject: 'solo' })

    await store.fetchMultiple([60], false)

    expect(store.list[60].subject).toBe('solo')
    expect(store.list[60].addedToCache).toBeDefined()
  })

  it('logs and does not throw for an unexpected response shape', async () => {
    const store = useMessageStore()
    store.init({})
    mockApiFetch.mockResolvedValue('unexpected-string')
    const errSpy = vi.spyOn(console, 'error').mockImplementation(() => {})

    await expect(store.fetchMultiple([61], false)).resolves.toBeUndefined()

    expect(errSpy).toHaveBeenCalledWith('Failed to fetch', 'unexpected-string')
    errSpy.mockRestore()
  })

  it('swallows a 404 from the batch API call', async () => {
    const store = useMessageStore()
    store.init({})
    mockApiFetch.mockRejectedValue(
      new APIError({ response: { status: 404 } }, 'gone')
    )

    await expect(store.fetchMultiple([62], false)).resolves.toBeUndefined()
    expect(store.fetching[62]).toBeNull()
  })

  it('rethrows a non-404 error from the batch API call', async () => {
    const store = useMessageStore()
    store.init({})
    mockApiFetch.mockRejectedValue(new Error('server exploded'))

    await expect(store.fetchMultiple([63], false)).rejects.toThrow(
      'server exploded'
    )
    // finally block still ran and cleared the fetching flag
    expect(store.fetching[63]).toBeNull()
  })

  it('batch-fetches the groups referenced by the newly fetched messages', async () => {
    const store = useMessageStore()
    store.init({})
    mockApiFetch.mockResolvedValue([
      { id: 70, groups: [{ groupid: 200 }, { groupid: 201 }] },
      { id: 71, groups: [{ groupid: 201 }] },
    ])

    await store.fetchMultiple([70, 71], false)

    expect(mockGroupFetchBatch).toHaveBeenCalledWith([200, 201])
  })

  it('does not call fetchBatch when no groups are referenced', async () => {
    const store = useMessageStore()
    store.init({})
    mockApiFetch.mockResolvedValue([{ id: 72 }])

    await store.fetchMultiple([72], false)

    expect(mockGroupFetchBatch).not.toHaveBeenCalled()
  })
})

describe('message store - fetchInBounds()', () => {
  it('fetches and caches by composite key', async () => {
    const store = useMessageStore()
    store.init({})
    mockInbounds.mockResolvedValue([{ id: 1 }])

    const result = await store.fetchInBounds(1, 2, 3, 4, 5, 10, true)

    expect(result).toEqual([{ id: 1 }])
    expect(store.bounds['1:2:3:4:5']).toEqual([{ id: 1 }])
  })

  it('returns the cached value without calling the API again', async () => {
    const store = useMessageStore()
    store.init({})
    store.bounds['1:2:3:4:5'] = [{ id: 'cached' }]

    const result = await store.fetchInBounds(1, 2, 3, 4, 5, 10, true)

    expect(result).toEqual([{ id: 'cached' }])
    expect(mockInbounds).not.toHaveBeenCalled()
  })

  it('does not use the cache when cache=false', async () => {
    const store = useMessageStore()
    store.init({})
    store.bounds['1:2:3:4:5'] = [{ id: 'cached' }]
    mockInbounds.mockResolvedValue([{ id: 'fresh' }])

    const result = await store.fetchInBounds(1, 2, 3, 4, 5, 10, false)

    expect(result).toEqual([{ id: 'fresh' }])
  })
})

describe('message store - search/similar/matches passthroughs', () => {
  it('search() clears the store then delegates to the API', async () => {
    const store = useMessageStore()
    store.init({})
    store.count = 99
    mockSearch.mockResolvedValue(['result'])

    const result = await store.search({ search: 'sofa' })

    expect(store.count).toBe(0)
    expect(mockSearch).toHaveBeenCalledWith({ search: 'sofa' })
    expect(result).toEqual(['result'])
  })

  it('similar() forwards id and limit', async () => {
    const store = useMessageStore()
    store.init({})
    mockSimilar.mockResolvedValue([{ id: 5 }])

    const result = await store.similar(123, 4)

    expect(mockSimilar).toHaveBeenCalledWith(123, 4)
    expect(result).toEqual([{ id: 5 }])
  })

  it('matches() forwards query/lat/lng/limit', async () => {
    const store = useMessageStore()
    store.init({})
    mockMatches.mockResolvedValue([{ id: 6 }])

    const result = await store.matches('chair', 1.1, 2.2, 3)

    expect(mockMatches).toHaveBeenCalledWith('chair', 1.1, 2.2, 3)
    expect(result).toEqual([{ id: 6 }])
  })
})

describe('message store - fetchMyGroups()', () => {
  it('stores the combined feed when no gid is given', async () => {
    const store = useMessageStore()
    store.init({})
    mockMygroups.mockResolvedValue([{ id: 1 }, { id: 2 }])

    const result = await store.fetchMyGroups()

    expect(store.myGroupsList).toEqual([{ id: 1 }, { id: 2 }])
    expect(result).toEqual([{ id: 1 }, { id: 2 }])
  })

  it('does not overwrite myGroupsList for a single-group fetch', async () => {
    const store = useMessageStore()
    store.init({})
    store.myGroupsList = [{ id: 'existing' }]
    mockMygroups.mockResolvedValue([{ id: 99 }])

    await store.fetchMyGroups(5)

    expect(store.myGroupsList).toEqual([{ id: 'existing' }])
  })

  it('a concurrent call awaits the in-flight fetch instead of firing a second request', async () => {
    const store = useMessageStore()
    store.init({})
    let resolveApi
    mockMygroups.mockReturnValue(
      new Promise((resolve) => {
        resolveApi = resolve
      })
    )

    const p1 = store.fetchMyGroups()
    const p2 = store.fetchMyGroups()
    resolveApi([{ id: 1 }])
    const [r1, r2] = await Promise.all([p1, p2])

    expect(mockMygroups).toHaveBeenCalledTimes(1)
    expect(r1).toEqual([{ id: 1 }])
    expect(r2).toEqual([{ id: 1 }])
  })
})

describe('message store - fetchByUser()', () => {
  it('awaits the server for a fresh (non-active) request and marks own messages seen', async () => {
    useAuthStore.mockReturnValue({ user: { id: 1 } })
    const store = useMessageStore()
    store.init({})
    mockFetchByUser.mockResolvedValue([{ id: 1, unseen: true }])

    const result = await store.fetchByUser(1, false, false)

    expect(result[0].unseen).toBe(false)
    expect(store.byUserList[1]).toEqual(result)
  })

  it('defaults to an empty array when the API returns something non-array', async () => {
    const store = useMessageStore()
    store.init({})
    mockFetchByUser.mockResolvedValue(null)

    const result = await store.fetchByUser(1, false, false)

    expect(result).toEqual([])
  })

  it('returns the cache immediately for active messages and refreshes in the background', async () => {
    const store = useMessageStore()
    store.init({})
    store.byUserList[1] = [{ id: 1, unseen: true }]
    let resolveApi
    mockFetchByUser.mockReturnValue(
      new Promise((resolve) => {
        resolveApi = resolve
      })
    )

    const result = await store.fetchByUser(1, true, false)
    expect(result).toEqual([{ id: 1, unseen: true }])

    resolveApi([{ id: 1, unseen: true }])
    await wait(10)
    expect(store.byUserList[1][0].unseen).toBe(false)
  })

  it('force=true always awaits the server even when cached', async () => {
    const store = useMessageStore()
    store.init({})
    store.byUserList[1] = [{ id: 'stale' }]
    mockFetchByUser.mockResolvedValue([{ id: 'fresh' }])

    const result = await store.fetchByUser(1, true, true)

    // userid (1) matches the mocked logged-in user, so isOwnMessages forces
    // unseen=false on every returned message.
    expect(result).toEqual([{ id: 'fresh', unseen: false }])
  })

  it("does not mark unseen=false for someone else's messages", async () => {
    useAuthStore.mockReturnValue({ user: { id: 999 } })
    const store = useMessageStore()
    store.init({})
    mockFetchByUser.mockResolvedValue([{ id: 1, unseen: true }])

    const result = await store.fetchByUser(1, false, false)

    expect(result[0].unseen).toBe(true)
  })
})

describe('message store - bulkInterest()/bulkInterestState()', () => {
  it('bulkInterest posts then refetches the message', async () => {
    const store = useMessageStore()
    store.init({})
    mockBulkInterest.mockResolvedValue({ ret: 0 })
    mockApiFetch.mockResolvedValue([{ id: 80, subject: 'refetched' }])

    const dataPromise = store.bulkInterest(80, [{ bulkitemid: 1 }], 5, 'note')
    await wait(70)
    const data = await dataPromise

    expect(mockBulkInterest).toHaveBeenCalledWith(
      80,
      [{ bulkitemid: 1 }],
      5,
      'note'
    )
    expect(data).toEqual({ ret: 0 })
    expect(store.list[80].subject).toBe('refetched')
  })

  it('bulkInterestState posts then refetches the message', async () => {
    const store = useMessageStore()
    store.init({})
    mockBulkInterestState.mockResolvedValue({ ret: 0 })
    mockApiFetch.mockResolvedValue([{ id: 81, subject: 'refetched' }])

    const dataPromise = store.bulkInterestState(81, 2, 9, 'Collected')
    await wait(70)
    await dataPromise

    expect(mockBulkInterestState).toHaveBeenCalledWith(81, 2, 9, 'Collected')
    expect(store.list[81].subject).toBe('refetched')
  })
})

describe('message store - Freegle Helper actions', () => {
  it('fetchHelper stores the result keyed by msgid', async () => {
    const store = useMessageStore()
    store.init({})
    mockGetHelper.mockResolvedValue({ batch: {} })

    const result = await store.fetchHelper(900)

    expect(mockGetHelper).toHaveBeenCalledWith(900, false)
    expect(store.helper[900]).toEqual({ batch: {} })
    expect(result).toEqual({ batch: {} })
  })

  it('helperSetStatus posts the status and omits automode when absent', async () => {
    const store = useMessageStore()
    store.init({})
    mockGetHelper.mockResolvedValue({ status: 'Paused' })

    await store.helperSetStatus(901, 'Paused')

    expect(mockHelper).toHaveBeenCalledWith({
      action: 'SetStatus',
      msgid: 901,
      status: 'Paused',
    })
  })

  it('helperSetStatus includes automode when given', async () => {
    const store = useMessageStore()
    store.init({})
    mockGetHelper.mockResolvedValue({})

    await store.helperSetStatus(901, 'Running', 'automatic')

    expect(mockHelper).toHaveBeenCalledWith({
      action: 'SetStatus',
      msgid: 901,
      status: 'Running',
      automode: 'automatic',
    })
  })

  it('helperResolveProposal resolves, refetches the message and the helper state', async () => {
    const store = useMessageStore()
    store.init({})
    mockApiFetch.mockResolvedValue([{ id: 902, subject: 'x' }])
    mockGetHelper.mockResolvedValue({ proposals: [] })

    const p = store.helperResolveProposal(902, 55, 'send', 'hi')
    await wait(70)
    const result = await p

    expect(mockHelper).toHaveBeenCalledWith({
      action: 'ResolveProposal',
      proposalid: 55,
      decision: 'send',
      text: 'hi',
    })
    expect(store.list[902].subject).toBe('x')
    expect(result).toEqual({ proposals: [] })
  })

  it('helperResolveProposal omits text when null', async () => {
    const store = useMessageStore()
    store.init({})
    mockApiFetch.mockResolvedValue([{ id: 903 }])
    mockGetHelper.mockResolvedValue({})

    const p = store.helperResolveProposal(903, 56, 'dismiss', null)
    await wait(70)
    await p

    expect(mockHelper).toHaveBeenCalledWith({
      action: 'ResolveProposal',
      proposalid: 56,
      decision: 'dismiss',
    })
  })
})

describe('message store - update()', () => {
  it('removes the message from list and byUserList when the server reports deleted', async () => {
    const store = useMessageStore()
    store.init({})
    store.list[100] = { id: 100 }
    store.byUserList[1] = [{ id: 100 }]
    mockUpdate.mockResolvedValue({ deleted: true })

    const data = await store.update({ id: 100 })

    expect(data).toEqual({ deleted: true })
    expect(store.list[100]).toBeUndefined()
    expect(store.byUserList[1]).toEqual([])
  })

  it('refetches and syncs byUserList on a normal update', async () => {
    const store = useMessageStore()
    store.init({})
    store.byUserList[1] = [{ id: 101, hasoutcome: false }]
    mockUpdate.mockResolvedValue({ id: 101 })
    mockApiFetch.mockResolvedValue([{ id: 101, subject: 'updated' }])

    const p = store.update({ id: 101 })
    await wait(70)
    await p

    expect(store.byUserList[1][0].subject).toBe('updated')
  })

  it('sets hasoutcome on the matching byUserList entry for an Outcome action', async () => {
    const store = useMessageStore()
    store.init({})
    store.byUserList[1] = [{ id: 102, hasoutcome: false }]
    mockUpdate.mockResolvedValue({ id: 102 })
    mockApiFetch.mockResolvedValue([{ id: 102, subject: 'given away' }])

    const p = store.update({ id: 102, action: 'Outcome' })
    await wait(70)
    await p

    expect(store.byUserList[1][0].hasoutcome).toBe(true)
  })

  it('does not throw when the message is not present in byUserList', async () => {
    const store = useMessageStore()
    store.init({})
    store.byUserList[1] = [{ id: 999 }]
    mockUpdate.mockResolvedValue({ id: 103 })
    mockApiFetch.mockResolvedValue([{ id: 103 }])

    const p = store.update({ id: 103 })
    await wait(70)
    await expect(p).resolves.toEqual({ id: 103 })
  })
})

describe('message store - remove()/clear()/clearContext()', () => {
  it('remove() deletes the parsed id from list', () => {
    const store = useMessageStore()
    store.init({})
    store.list[110] = { id: 110 }

    store.remove({ id: '110' })

    expect(store.list[110]).toBeUndefined()
  })

  it('clear() resets state and clears the ModTools context', () => {
    const store = useMessageStore()
    store.init({})
    store.list[1] = { id: 1 }
    store.context = { some: 'context' }

    store.clear()

    expect(store.list).toEqual({})
    expect(store.context).toBeNull()
  })

  it('clearContext() only clears the context', () => {
    const store = useMessageStore()
    store.init({})
    store.list[1] = { id: 1 }
    store.context = { some: 'context' }

    store.clearContext()

    expect(store.context).toBeNull()
    expect(store.list[1]).toBeDefined()
  })
})

describe.each([
  ['promise', mockUpdate, { action: 'Promise' }],
  ['renege', mockUpdate, { action: 'Renege' }],
])('message store - %s()', (method, mockFn, extra) => {
  it(`calls the API with the right action and refetches`, async () => {
    const store = useMessageStore()
    store.init({})
    mockFn.mockResolvedValue({})
    mockApiFetch.mockResolvedValue([{ id: 120 }])

    const p = store[method](120, 55)
    await wait(70)
    await p

    expect(mockFn).toHaveBeenCalledWith({ id: 120, userid: 55, ...extra })
    expect(mockApiFetch).toHaveBeenCalledWith('120', false)
  })
})

describe('message store - addBy()/removeBy()/intend()', () => {
  it('addBy calls the API and refetches', async () => {
    const store = useMessageStore()
    store.init({})
    mockAddBy.mockResolvedValue({})
    mockApiFetch.mockResolvedValue([{ id: 130 }])

    const p = store.addBy(130, 7, 2)
    await wait(70)
    await p

    expect(mockAddBy).toHaveBeenCalledWith(130, 7, 2)
    expect(mockApiFetch).toHaveBeenCalledWith('130', false)
  })

  it('removeBy calls the API and refetches', async () => {
    const store = useMessageStore()
    store.init({})
    mockRemoveBy.mockResolvedValue({})
    mockApiFetch.mockResolvedValue([{ id: 131 }])

    const p = store.removeBy(131, 8)
    await wait(70)
    await p

    expect(mockRemoveBy).toHaveBeenCalledWith(131, 8)
  })

  it('intend calls the API without refetching', async () => {
    const store = useMessageStore()
    store.init({})
    mockIntend.mockResolvedValue({})

    await store.intend(132, 'Taken')

    expect(mockIntend).toHaveBeenCalledWith(132, 'Taken')
    expect(mockApiFetch).not.toHaveBeenCalled()
  })
})

describe('message store - markSeen() error handling', () => {
  beforeEach(() => {
    mockCount.mockResolvedValue({ count: 0 })
  })

  it('silently swallows a 401 (expired session)', async () => {
    const store = useMessageStore()
    store.init({})
    const err = new Error('unauthorized')
    err.response = { status: 401 }
    mockMarkSeen.mockRejectedValue(err)

    await expect(store.markSeen([1])).resolves.toBeUndefined()
    expect(mockNearbyMarkSeen).not.toHaveBeenCalled()
  })

  it('rethrows any other error', async () => {
    const store = useMessageStore()
    store.init({})
    mockMarkSeen.mockRejectedValue(new Error('server error'))

    await expect(store.markSeen([1])).rejects.toThrow('server error')
  })
})

describe('message store - fetchMessagesMT()', () => {
  beforeEach(() => {
    mockMiscStore.modtools = true
  })

  it('returns early when there are no messages', async () => {
    const store = useMessageStore()
    store.init({})
    mockFetchMessages.mockResolvedValue({ messages: [] })

    const result = await store.fetchMessagesMT({ groupid: 1 })

    expect(result).toBeUndefined()
  })

  it('returns early when messages is missing entirely', async () => {
    const store = useMessageStore()
    store.init({})
    mockFetchMessages.mockResolvedValue({})

    const result = await store.fetchMessagesMT({ groupid: 1 })

    expect(result).toBeUndefined()
  })

  it('does not overwrite context for a Draft collection fetch', async () => {
    const store = useMessageStore()
    store.init({})
    store.context = 'existing-context'
    mockFetchMessages.mockResolvedValue({
      messages: [1],
      context: { Date: 1 },
    })
    store.fetchMT = vi.fn().mockResolvedValue({ id: 1 })

    await store.fetchMessagesMT({ collection: 'Draft', context: null })

    expect(store.context).toBe('existing-context')
  })

  it('defaults a missing subject to an empty string and logs individual fetch failures', async () => {
    const store = useMessageStore()
    store.init({})
    mockFetchMessages.mockResolvedValue({ messages: [1, 2] })
    store.fetchMT = vi.fn().mockImplementation(({ id }) => {
      if (id === 1) throw new Error('missing')
      return { id: 2 }
    })
    const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})

    const ids = await store.fetchMessagesMT({ collection: 'Approved' })

    expect(ids).toEqual([1, 2])
    expect(store.list[2].subject).toBe('')
    expect(store.list[1]).toBeUndefined()
    logSpy.mockRestore()
  })
})

describe('message store - fetchMT()/fetchReach()/updateMT()', () => {
  it('fetchMT defaults a missing subject to an empty string', async () => {
    const store = useMessageStore()
    store.init({})
    mockFetchMT.mockResolvedValue({ id: 1 })

    const result = await store.fetchMT({ id: 1 })

    expect(result.subject).toBe('')
  })

  it('fetchMT leaves an existing subject untouched', async () => {
    const store = useMessageStore()
    store.init({})
    mockFetchMT.mockResolvedValue({ id: 1, subject: 'Sofa' })

    const result = await store.fetchMT({ id: 1 })

    expect(result.subject).toBe('Sofa')
  })

  it('fetchMT returns falsy responses untouched', async () => {
    const store = useMessageStore()
    store.init({})
    mockFetchMT.mockResolvedValue(null)

    const result = await store.fetchMT({ id: 1 })

    expect(result).toBeNull()
  })

  it('fetchReach forwards to the API', async () => {
    const store = useMessageStore()
    store.init({})
    mockReach.mockResolvedValue({ pct: 50 })

    const result = await store.fetchReach(5, false)

    expect(mockReach).toHaveBeenCalledWith(5, false)
    expect(result).toEqual({ pct: 50 })
  })

  it('updateMT forwards to the API', async () => {
    const store = useMessageStore()
    store.init({})
    mockUpdate.mockResolvedValue({ ok: true })

    const result = await store.updateMT({ id: 1, action: 'Approve' })

    expect(mockUpdate).toHaveBeenCalledWith({ id: 1, action: 'Approve' })
    expect(result).toEqual({ ok: true })
  })
})

describe('message store - delete/approveedits/revertedits/backToPending', () => {
  it('delete() calls the API then removes the message from list', async () => {
    const store = useMessageStore()
    store.init({})
    store.list[200] = { id: 200 }
    mockDelete.mockResolvedValue({})

    await store.delete({
      id: 200,
      groupid: 9,
      subject: 's',
      stdmsgid: 1,
      body: 'b',
    })

    expect(mockDelete).toHaveBeenCalledWith(200, 9, 's', 1, 'b')
    expect(store.list[200]).toBeUndefined()
  })

  it('approveedits() calls the API then removes the message', async () => {
    const store = useMessageStore()
    store.init({})
    store.list[201] = { id: 201 }
    mockApproveEdits.mockResolvedValue({})

    await store.approveedits({ id: 201 })

    expect(mockApproveEdits).toHaveBeenCalledWith(201)
    expect(store.list[201]).toBeUndefined()
  })

  it('revertedits() calls the API then removes the message', async () => {
    const store = useMessageStore()
    store.init({})
    store.list[202] = { id: 202 }
    mockRevertEdits.mockResolvedValue({})

    await store.revertedits({ id: 202 })

    expect(mockRevertEdits).toHaveBeenCalledWith(202)
    expect(store.list[202]).toBeUndefined()
  })

  it('backToPending() posts the action then removes the message', async () => {
    const store = useMessageStore()
    store.init({})
    store.list[203] = { id: 203 }
    mockUpdate.mockResolvedValue({})

    await store.backToPending(203, 44)

    expect(mockUpdate).toHaveBeenCalledWith({
      id: 203,
      groupid: 44,
      action: 'BackToPending',
    })
    expect(store.list[203]).toBeUndefined()
  })
})

describe('message store - approve()/reject() re-fetch the sender', () => {
  it('approve() refetches the sender by numeric fromuser id', async () => {
    const store = useMessageStore()
    store.init({})
    store.list[300] = { id: 300, fromuser: 42 }
    mockApprove.mockResolvedValue({})
    mockFetchMT.mockResolvedValue({ id: 300, groups: [] })

    await store.approve(300, 1, 's', 2, 'b')

    expect(mockApprove).toHaveBeenCalledWith(300, 1, 's', 2, 'b')
    expect(mockUserFetch).toHaveBeenCalledWith(42, true)
  })

  it('approve() refetches the sender by object fromuser.id', async () => {
    const store = useMessageStore()
    store.init({})
    store.list[301] = { id: 301, fromuser: { id: 43 } }
    mockApprove.mockResolvedValue({})
    mockFetchMT.mockResolvedValue({ id: 301, groups: [] })

    await store.approve(301, 1)

    expect(mockUserFetch).toHaveBeenCalledWith(43, true)
  })

  it('approve() does not refetch a sender when the message has none cached', async () => {
    const store = useMessageStore()
    store.init({})
    mockApprove.mockResolvedValue({})
    mockFetchMT.mockResolvedValue({ id: 302, groups: [] })

    await store.approve(302, 1)

    expect(mockUserFetch).not.toHaveBeenCalled()
  })

  it('reject() refetches the sender the same way', async () => {
    const store = useMessageStore()
    store.init({})
    store.list[303] = { id: 303, fromuser: 44 }
    mockReject.mockResolvedValue({})
    mockFetchMT.mockResolvedValue({ id: 303, groups: [] })

    await store.reject(303, 1, 's', 2, 'b')

    expect(mockReject).toHaveBeenCalledWith(303, 1, 's', 2, 'b')
    expect(mockUserFetch).toHaveBeenCalledWith(44, true)
  })
})

describe('message store - reply()/spam()/move()', () => {
  it('reply() posts without removing the message from list', async () => {
    const store = useMessageStore()
    store.init({})
    store.list[400] = { id: 400 }
    mockReply.mockResolvedValue({})

    await store.reply({
      id: 400,
      groupid: 1,
      subject: 's',
      stdmsgid: 2,
      body: 'b',
    })

    expect(mockReply).toHaveBeenCalledWith(400, 1, 's', 2, 'b')
    expect(store.list[400]).toBeDefined()
  })

  it('spam() removes the message from list', async () => {
    const store = useMessageStore()
    store.init({})
    store.list[401] = { id: 401 }
    mockSpam.mockResolvedValue({})

    await store.spam({ id: 401, groupid: 1 })

    expect(mockSpam).toHaveBeenCalledWith(401, 1)
    expect(store.list[401]).toBeUndefined()
  })

  it('move() updates then refetches the message', async () => {
    const store = useMessageStore()
    store.init({})
    mockUpdate.mockResolvedValue({})
    mockFetchMT.mockResolvedValue({ id: 402, subject: 'moved' })

    await store.move({ id: 402, groupid: 7 })

    expect(mockUpdate).toHaveBeenCalledWith({
      id: 402,
      groupid: 7,
      action: 'Move',
    })
    expect(store.list[402].subject).toBe('moved')
  })
})

describe('message store - searchMember()', () => {
  it('clears the store then fetches full details for each matched id', async () => {
    const store = useMessageStore()
    store.init({})
    store.count = 5
    mockFetchMessages.mockResolvedValue({ messages: [500, 501] })
    store.fetchMT = vi.fn().mockImplementation(({ id }) => ({ id }))

    await store.searchMember('bob', 9)

    expect(mockFetchMessages).toHaveBeenCalledWith({
      subaction: 'searchmemb',
      search: 'bob',
      groupid: 9,
    })
    expect(store.count).toBe(0)
    expect(store.list[500]).toBeDefined()
    expect(store.list[501]).toBeDefined()
  })

  it('returns early when there are no matches', async () => {
    const store = useMessageStore()
    store.init({})
    mockFetchMessages.mockResolvedValue({ messages: [] })
    store.fetchMT = vi.fn()

    await store.searchMember('nobody', 9)

    expect(store.fetchMT).not.toHaveBeenCalled()
  })

  it('logs and continues when an individual fetch fails', async () => {
    const store = useMessageStore()
    store.init({})
    mockFetchMessages.mockResolvedValue({ messages: [502, 503] })
    store.fetchMT = vi.fn().mockImplementation(({ id }) => {
      if (id === 502) throw new Error('gone')
      return { id }
    })
    const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})

    await store.searchMember('x', 9)

    expect(store.list[502]).toBeUndefined()
    expect(store.list[503]).toBeDefined()
    logSpy.mockRestore()
  })
})

describe('message store - remaining getters', () => {
  it('helperById returns the helper state for a msgid', () => {
    const store = useMessageStore()
    store.init({})
    store.helper[900] = { batch: {} }

    expect(store.helperById(900)).toEqual({ batch: {} })
  })

  it('inBounds returns the cached array for a matching key', () => {
    const store = useMessageStore()
    store.init({})
    store.bounds['1:2:3:4:5'] = [{ id: 1 }]

    expect(store.inBounds(1, 2, 3, 4, 5)).toEqual([{ id: 1 }])
  })

  it('inBounds returns an empty array when the key is not cached', () => {
    const store = useMessageStore()
    store.init({})

    expect(store.inBounds(1, 2, 3, 4, 5)).toEqual([])
  })

  it('all returns every cached message', () => {
    const store = useMessageStore()
    store.init({})
    store.list = { 1: { id: 1 }, 2: { id: 2 } }

    expect(store.all).toEqual([{ id: 1 }, { id: 2 }])
  })

  it('byUser returns the cached list for a userid', () => {
    const store = useMessageStore()
    store.init({})
    store.byUserList[9] = [{ id: 1 }]

    expect(store.byUser(9)).toEqual([{ id: 1 }])
  })

  it('byUser returns an empty array for an unknown userid', () => {
    const store = useMessageStore()
    store.init({})

    expect(store.byUser(999)).toEqual([])
  })
})
