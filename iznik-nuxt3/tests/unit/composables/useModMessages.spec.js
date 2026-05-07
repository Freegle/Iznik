import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { ref } from 'vue'

// Minimal mock for APIError (same shape as the real class)
class APIError extends Error {
  constructor(opts, msg) {
    super(msg)
    this.response = opts.response
  }
}

// Mocks used by the composable
const mockClearContext = vi.fn()
const mockClear = vi.fn()
const mockFetchMessagesMT = vi.fn()
const mockAll = ref([])
const mockGetByGroup = vi.fn(() => [])
const mockStoreContext = ref(null)

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    clearContext: mockClearContext,
    clear: mockClear,
    fetchMessagesMT: mockFetchMessagesMT,
    get all() {
      return mockAll.value
    },
    getByGroup: mockGetByGroup,
    get context() {
      return mockStoreContext.value
    },
    set context(v) {
      mockStoreContext.value = v
    },
    list: {},
  }),
}))

let mockAuthWork = null
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ work: mockAuthWork }),
}))

const mockMiscGet = vi.fn(() => undefined)
vi.mock('@/stores/misc', () => ({
  useMiscStore: () => ({
    get: mockMiscGet,
    deferGetMessages: false,
  }),
}))

describe('useModMessages getMessages', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.resetModules()
    mockAuthWork = null
    mockFetchMessagesMT.mockResolvedValue([1, 2, 3])
  })

  afterEach(() => {
    vi.resetModules()
  })

  it('resolves without throwing when fetchMessagesMT returns data', async () => {
    const { setupModMessages } = await import(
      '~/modtools/composables/useModMessages'
    )
    const { getMessages, collection } = setupModMessages()
    collection.value = 'Pending'

    await expect(getMessages()).resolves.not.toThrow()
  })

  it('handles a 401 APIError from fetchMessagesMT without throwing', async () => {
    mockFetchMessagesMT.mockRejectedValue(
      new APIError(
        { response: { status: 401 } },
        'API Error GET /modtools/messages -> status: 401'
      )
    )

    const { setupModMessages } = await import(
      '~/modtools/composables/useModMessages'
    )
    const { getMessages, collection } = setupModMessages()
    collection.value = 'Pending'

    await expect(getMessages()).resolves.toBeUndefined()
  })

  it('syncs pagination context after getMessages so loadMore continues', async () => {
    const paginationCtx = { Date: 1700000000, ID: 42 }
    mockFetchMessagesMT.mockImplementation(() => {
      mockStoreContext.value = paginationCtx
      return Promise.resolve([1, 2, 3])
    })
    mockAll.value = [{ id: 1 }, { id: 2 }, { id: 3 }]

    const { setupModMessages } = await import(
      '~/modtools/composables/useModMessages'
    )
    const { getMessages, collection, context } = setupModMessages()
    collection.value = 'Approved'
    await getMessages()

    // context ref should be synced from the store so loadMore() can paginate.
    expect(context.value).toEqual(paginationCtx)
  })

  it('resets show count to 0 on 401 so UI does not show stale message count', async () => {
    mockFetchMessagesMT.mockResolvedValue([1, 2, 3])
    mockAll.value = [{ id: 1 }, { id: 2 }, { id: 3 }]

    const { setupModMessages } = await import(
      '~/modtools/composables/useModMessages'
    )
    const { getMessages, collection, show } = setupModMessages()
    collection.value = 'Pending'
    await getMessages()
    expect(show.value).toBe(3)

    mockFetchMessagesMT.mockRejectedValue(
      new APIError(
        { response: { status: 401 } },
        'API Error GET /modtools/messages -> status: 401'
      )
    )
    await getMessages()
    expect(show.value).toBe(0)
  })
})

describe('useModMessages sorting with getContextArrival', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.resetModules()
    mockAuthWork = null
  })

  afterEach(() => {
    vi.resetModules()
  })

  it('sorts by contextual group arrival when groupid is set', async () => {
    // Message A arrived earlier on group 10 but later on group 20.
    // Message B arrived later on group 10 but earlier on group 20.
    // When filtering by group 10, A should come after B (older arrival on that group).
    const msgA = {
      id: 1,
      arrival: '2026-01-01',
      groups: [
        { groupid: 10, arrival: '2026-01-01', collection: 'Pending' },
        { groupid: 20, arrival: '2026-01-05', collection: 'Pending' },
      ],
    }
    const msgB = {
      id: 2,
      arrival: '2026-01-03',
      groups: [
        { groupid: 10, arrival: '2026-01-03', collection: 'Pending' },
      ],
    }

    mockGetByGroup.mockReturnValue([msgA, msgB])
    mockAll.value = [msgA, msgB]
    mockFetchMessagesMT.mockResolvedValue([1, 2])

    const { setupModMessages } = await import(
      '~/modtools/composables/useModMessages'
    )
    const { getMessages, collection, groupid, messages, show } =
      setupModMessages(true)
    collection.value = 'Pending'
    groupid.value = 10
    await getMessages()

    // B arrived later on group 10 (Jan 3) so should sort first (newest first).
    const sorted = messages.value
    expect(sorted[0].id).toBe(2)
    expect(sorted[1].id).toBe(1)
  })

  it('falls back to first group arrival when contextGid has no match', async () => {
    const msgA = {
      id: 1,
      arrival: '2026-01-01',
      groups: [{ groupid: 10, arrival: '2026-01-05', collection: 'Pending' }],
    }
    const msgB = {
      id: 2,
      arrival: '2026-01-03',
      groups: [{ groupid: 10, arrival: '2026-01-02', collection: 'Pending' }],
    }

    mockAll.value = [msgA, msgB]
    mockFetchMessagesMT.mockResolvedValue([1, 2])

    const { setupModMessages } = await import(
      '~/modtools/composables/useModMessages'
    )
    const { getMessages, collection, messages, show } =
      setupModMessages(true)
    collection.value = 'Pending'
    // No groupid set — should use groups[0].arrival
    await getMessages()

    const sorted = messages.value
    expect(sorted[0].id).toBe(1) // Jan 5 arrival is newest
    expect(sorted[1].id).toBe(2) // Jan 2
  })

  it('falls back to message arrival when groups array is empty', async () => {
    const msgA = { id: 1, arrival: '2026-01-01', groups: [] }
    const msgB = { id: 2, arrival: '2026-01-03', groups: [] }

    mockAll.value = [msgA, msgB]
    mockFetchMessagesMT.mockResolvedValue([1, 2])

    const { setupModMessages } = await import(
      '~/modtools/composables/useModMessages'
    )
    const { getMessages, collection, messages } = setupModMessages(true)
    collection.value = 'Pending'
    await getMessages()

    const sorted = messages.value
    expect(sorted[0].id).toBe(2) // Jan 3 is newest
    expect(sorted[1].id).toBe(1) // Jan 1
  })
})

describe('useModMessages vector search (listingIdOrder) sorting', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.resetModules()
    mockAuthWork = null
  })

  afterEach(() => {
    vi.resetModules()
  })

  it('sorts messages by score order when listingIdOrder is set', async () => {
    const msgA = { id: 1, arrival: '2026-01-05', groups: [] }
    const msgB = { id: 2, arrival: '2026-01-04', groups: [] }
    const msgC = { id: 3, arrival: '2026-01-03', groups: [] }

    mockAll.value = [msgA, msgB, msgC]
    mockFetchMessagesMT.mockResolvedValue([1, 2, 3])

    const { setupModMessages } = await import(
      '~/modtools/composables/useModMessages'
    )
    const { getMessages, collection, messages, listingIdOrder } =
      setupModMessages(true)
    collection.value = 'Approved'
    await getMessages()

    // Simulate vector search results arriving in score order (best match first)
    listingIdOrder.value = [3, 1, 2]

    const sorted = messages.value
    expect(sorted.map((m) => m.id)).toEqual([3, 1, 2])
  })

  it('assigns Infinity rank to messages absent from listingIdOrder so they sort last', async () => {
    const msgA = { id: 1, arrival: '2026-01-05', groups: [] }
    const msgB = { id: 2, arrival: '2026-01-04', groups: [] }
    const msgC = { id: 99, arrival: '2026-01-03', groups: [] } // not in order

    mockAll.value = [msgA, msgB, msgC]
    mockFetchMessagesMT.mockResolvedValue([1, 2, 99])

    const { setupModMessages } = await import(
      '~/modtools/composables/useModMessages'
    )
    const { getMessages, collection, messages, listingIdOrder } =
      setupModMessages(true)
    collection.value = 'Approved'
    await getMessages()

    // Only 1 and 2 have explicit scores; 99 is absent — should sort last
    listingIdOrder.value = [2, 1]

    const sorted = messages.value
    expect(sorted[0].id).toBe(2)
    expect(sorted[1].id).toBe(1)
    expect(sorted[2].id).toBe(99)
  })
})

describe('useModMessages visibleMessages computed', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.resetModules()
    mockAuthWork = null
  })

  afterEach(() => {
    vi.resetModules()
  })

  it('returns empty array when show is 0', async () => {
    mockAll.value = [
      { id: 1, arrival: '2026-01-01', groups: [] },
      { id: 2, arrival: '2026-01-02', groups: [] },
    ]
    mockFetchMessagesMT.mockResolvedValue([1, 2])

    const { setupModMessages } = await import(
      '~/modtools/composables/useModMessages'
    )
    const { collection, show, visibleMessages } = setupModMessages(true)
    collection.value = 'Pending'
    // show defaults to 0 after reset — do NOT call getMessages()
    expect(visibleMessages.value).toEqual([])
  })

  it('slices messages to show count', async () => {
    mockAll.value = [
      { id: 1, arrival: '2026-01-05', groups: [] },
      { id: 2, arrival: '2026-01-04', groups: [] },
      { id: 3, arrival: '2026-01-03', groups: [] },
      { id: 4, arrival: '2026-01-02', groups: [] },
      { id: 5, arrival: '2026-01-01', groups: [] },
    ]
    mockFetchMessagesMT.mockResolvedValue([1, 2, 3, 4, 5])

    const { setupModMessages } = await import(
      '~/modtools/composables/useModMessages'
    )
    const { getMessages, collection, show, visibleMessages } =
      setupModMessages(true)
    collection.value = 'Pending'
    await getMessages() // sets show to 5

    show.value = 3
    expect(visibleMessages.value).toHaveLength(3)
    expect(visibleMessages.value.map((m) => m.id)).toEqual([1, 2, 3])
  })
})

describe('useModMessages work computed', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.resetModules()
    mockAuthWork = null
    mockFetchMessagesMT.mockResolvedValue([])
  })

  afterEach(() => {
    vi.resetModules()
  })

  it('returns 0 when auth work is null', async () => {
    mockAuthWork = null

    const { setupModMessages } = await import(
      '~/modtools/composables/useModMessages'
    )
    const { work, workType } = setupModMessages(true)
    workType.value = 'Pending'

    expect(work.value).toBe(0)
  })

  it('returns 0 when workType is not set', async () => {
    mockAuthWork = { Pending: 7 }

    const { setupModMessages } = await import(
      '~/modtools/composables/useModMessages'
    )
    const { work } = setupModMessages(true)
    // workType defaults to null after reset

    expect(work.value).toBe(0)
  })

  it('returns single work count for a string workType', async () => {
    mockAuthWork = { Pending: 12, PendingOther: 3 }

    const { setupModMessages } = await import(
      '~/modtools/composables/useModMessages'
    )
    const { work, workType } = setupModMessages(true)
    workType.value = 'Pending'

    expect(work.value).toBe(12)
  })

  it('sums across multiple workTypes when workType is an array', async () => {
    mockAuthWork = { Pending: 5, PendingOther: 8 }

    const { setupModMessages } = await import(
      '~/modtools/composables/useModMessages'
    )
    const { work, workType } = setupModMessages(true)
    workType.value = ['Pending', 'PendingOther']

    expect(work.value).toBe(13)
  })
})
