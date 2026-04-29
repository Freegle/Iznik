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

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ work: null }),
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
