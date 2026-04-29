import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { reactive } from 'vue'

const mockFetchMembers = vi.fn()
const mockStoreContext = { value: null }
const mockListRef = reactive({})

vi.mock('~/stores/member', () => ({
  useMemberStore: () => ({
    fetchMembers: mockFetchMembers,
    clear: vi.fn(),
    get context() {
      return mockStoreContext.value
    },
    set context(v) {
      mockStoreContext.value = v
    },
    get list() {
      return mockListRef
    },
    instance: 1,
    ratings: [],
    filtercount: null,
    rawindex: 0,
    getByGroup: (gid) =>
      Object.values(mockListRef).filter((m) => m.groupid === gid),
  }),
}))

vi.mock('@/stores/modgroup', () => ({
  useModGroupStore: () => ({
    fetchIfNeedBeMT: vi.fn().mockResolvedValue(undefined),
    get: vi.fn().mockResolvedValue(null),
  }),
}))

describe('useModMembers loadMore - cursor-based pagination', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.resetModules()
    mockStoreContext.value = null
    // Clear the reactive list without replacing the reference.
    Object.keys(mockListRef).forEach((k) => delete mockListRef[k])
  })

  afterEach(() => {
    vi.resetModules()
  })

  it('fetches second batch with stored context when bottom sentinel is reached', async () => {
    const firstBatch = Array.from({ length: 20 }, (_, i) => ({
      id: i + 101,
      userid: i + 101,
      groupid: 1,
      collection: 'Approved',
      added: '2026-01-01',
    }))
    const secondBatch = Array.from({ length: 20 }, (_, i) => ({
      id: i + 81,
      userid: i + 81,
      groupid: 1,
      collection: 'Approved',
      added: '2025-12-01',
    }))

    mockFetchMembers
      .mockImplementationOnce(async () => {
        firstBatch.forEach((m) => {
          mockListRef[m.id] = m
        })
        mockStoreContext.value = 101
      })
      .mockImplementationOnce(async () => {
        secondBatch.forEach((m) => {
          mockListRef[m.id] = m
        })
        mockStoreContext.value = null
      })

    const { setupModMembers } = await import(
      '~/modtools/composables/useModMembers'
    )
    const { loadMore, groupid, collection, context, show, members } =
      setupModMembers(true)

    groupid.value = 1
    collection.value = 'Approved'

    // First loadMore: store is empty → falls through to server fetch.
    const state1 = { loaded: vi.fn(), complete: vi.fn() }
    await loadMore(state1)

    expect(mockFetchMembers).toHaveBeenCalledTimes(1)
    expect(state1.loaded).toHaveBeenCalled()
    expect(context.value).toBe(101)

    // Advance show to exhaust the in-memory buffer.
    show.value = members.value.length // 20

    // Second loadMore: show === members.length → fetches next page with context.
    const state2 = { loaded: vi.fn(), complete: vi.fn() }
    await loadMore(state2)

    expect(mockFetchMembers).toHaveBeenCalledTimes(2)
    const secondCallParams = mockFetchMembers.mock.calls[1][0]
    expect(secondCallParams.context).toBe(101)
    expect(state2.loaded).toHaveBeenCalled()
  })

  it('calls $state.complete() when no new members arrive', async () => {
    const batch = Array.from({ length: 5 }, (_, i) => ({
      id: i + 1,
      userid: i + 1,
      groupid: 1,
      collection: 'Approved',
      added: '2026-01-01',
    }))

    mockFetchMembers.mockImplementationOnce(async () => {
      batch.forEach((m) => {
        mockListRef[m.id] = m
      })
      mockStoreContext.value = null
    })

    const { setupModMembers } = await import(
      '~/modtools/composables/useModMembers'
    )
    const { loadMore, groupid, collection, show, members } =
      setupModMembers(true)

    groupid.value = 1
    collection.value = 'Approved'

    const state1 = { loaded: vi.fn(), complete: vi.fn() }
    await loadMore(state1)

    // Exhaust the buffer.
    show.value = members.value.length

    // Next fetch returns no new members.
    mockFetchMembers.mockImplementationOnce(async () => {
      // list unchanged
    })
    const state2 = { loaded: vi.fn(), complete: vi.fn() }
    await loadMore(state2)

    expect(state2.complete).toHaveBeenCalled()
  })
})
