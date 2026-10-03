import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { reactive } from 'vue'

// Self-moderating rework: /modtools/members has no cursor/paging concept -
// it takes filter ('new'|'flagged'|'banned'|'search'), q (search term) and
// since (hours, new/flagged only) and always returns the same flat set for
// the same params. These tests replace the old cursor-based-pagination
// suite (which exercised a store `context` property that never actually
// existed on modtools/stores/member.js - it was dead plumbing even before
// this rework).
const mockFetchMembers = vi.fn()
const mockListRef = reactive({})

vi.mock('~/modtools/stores/member', () => ({
  useMemberStore: () => ({
    fetchMembers: mockFetchMembers,
    clear: vi.fn(),
    get list() {
      return mockListRef
    },
  }),
}))

describe('useModMembers loadMore', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.resetModules()
    // Clear the reactive list without replacing the reference.
    Object.keys(mockListRef).forEach((k) => delete mockListRef[k])
  })

  afterEach(() => {
    vi.resetModules()
  })

  it('resets filter/since/search/show to their defaults', async () => {
    const { setupModMembers } =
      await import('~/modtools/composables/useModMembers')
    const { filter, since, search, show } = setupModMembers(true)

    expect(filter.value).toBe('new')
    expect(since.value).toBe(null)
    expect(search.value).toBe('')
    expect(show.value).toBe(0)
  })

  it('fetches with {filter, q} and no since for the new filter', async () => {
    mockFetchMembers.mockImplementationOnce(() => {})

    const { setupModMembers } =
      await import('~/modtools/composables/useModMembers')
    const { loadMore, filter, search } = setupModMembers(true)

    filter.value = 'new'
    search.value = 'alice'

    const state = { loaded: vi.fn(), complete: vi.fn() }
    await loadMore(state)

    expect(mockFetchMembers).toHaveBeenCalledWith({
      filter: 'new',
      q: 'alice',
    })
  })

  it('omits q when search is empty', async () => {
    mockFetchMembers.mockImplementationOnce(() => {})

    const { setupModMembers } =
      await import('~/modtools/composables/useModMembers')
    const { loadMore, filter } = setupModMembers(true)

    filter.value = 'banned'

    const state = { loaded: vi.fn(), complete: vi.fn() }
    await loadMore(state)

    expect(mockFetchMembers).toHaveBeenCalledWith({
      filter: 'banned',
      q: undefined,
    })
  })

  it('includes since for the flagged filter when set', async () => {
    mockFetchMembers.mockImplementationOnce(() => {})

    const { setupModMembers } =
      await import('~/modtools/composables/useModMembers')
    const { loadMore, filter, since } = setupModMembers(true)

    filter.value = 'flagged'
    since.value = 48

    const state = { loaded: vi.fn(), complete: vi.fn() }
    await loadMore(state)

    expect(mockFetchMembers).toHaveBeenCalledWith({
      filter: 'flagged',
      q: undefined,
      since: 48,
    })
  })

  it('does not send since for the banned filter even when set', async () => {
    mockFetchMembers.mockImplementationOnce(() => {})

    const { setupModMembers } =
      await import('~/modtools/composables/useModMembers')
    const { loadMore, filter, since } = setupModMembers(true)

    filter.value = 'banned'
    since.value = 48

    const state = { loaded: vi.fn(), complete: vi.fn() }
    await loadMore(state)

    expect(mockFetchMembers).toHaveBeenCalledWith({
      filter: 'banned',
      q: undefined,
    })
  })

  it('reveals more of an already-fetched batch without refetching', async () => {
    const batch = Array.from({ length: 30 }, (_, i) => ({
      id: i + 1,
      added: '2026-01-01',
    }))

    mockFetchMembers.mockImplementationOnce(() => {
      batch.forEach((m) => {
        mockListRef[m.id] = m
      })
    })

    const { setupModMembers } =
      await import('~/modtools/composables/useModMembers')
    const { loadMore, show } = setupModMembers(true)

    // First call: store is empty -> fetches from the server.
    const state1 = { loaded: vi.fn(), complete: vi.fn() }
    await loadMore(state1)

    expect(mockFetchMembers).toHaveBeenCalledTimes(1)
    expect(show.value).toBe(20)
    expect(state1.loaded).toHaveBeenCalled()

    // Second call: 30 already buffered, show (20) is still behind it -> just
    // reveals more, no second server call.
    const state2 = { loaded: vi.fn(), complete: vi.fn() }
    await loadMore(state2)

    expect(mockFetchMembers).toHaveBeenCalledTimes(1)
    expect(show.value).toBe(30)
    expect(state2.loaded).toHaveBeenCalled()
  })

  it('calls $state.complete() when a repeat fetch adds no new members', async () => {
    const batch = Array.from({ length: 5 }, (_, i) => ({
      id: i + 1,
      added: '2026-01-01',
    }))

    mockFetchMembers.mockImplementationOnce(() => {
      batch.forEach((m) => {
        mockListRef[m.id] = m
      })
    })

    const { setupModMembers } =
      await import('~/modtools/composables/useModMembers')
    const { loadMore, show, members } = setupModMembers(true)

    const state1 = { loaded: vi.fn(), complete: vi.fn() }
    await loadMore(state1)

    // Exhaust the buffer so the next loadMore has to ask the server again.
    show.value = members.value.length

    // Server returns the same set again - nothing new lands in the store.
    mockFetchMembers.mockImplementationOnce(() => {})
    const state2 = { loaded: vi.fn(), complete: vi.fn() }
    await loadMore(state2)

    expect(mockFetchMembers).toHaveBeenCalledTimes(2)
    expect(state2.complete).toHaveBeenCalled()
    expect(state2.loaded).not.toHaveBeenCalled()
  })

  it('sorts members newest-added first by default', async () => {
    mockFetchMembers.mockImplementationOnce(() => {
      mockListRef[1] = { id: 1, added: '2026-01-01' }
      mockListRef[2] = { id: 2, added: '2026-03-01' }
      mockListRef[3] = { id: 3, added: '2026-02-01' }
    })

    const { setupModMembers } =
      await import('~/modtools/composables/useModMembers')
    const { loadMore, members } = setupModMembers(true)

    const state = { loaded: vi.fn(), complete: vi.fn() }
    await loadMore(state)

    expect(members.value.map((m) => m.id)).toEqual([2, 3, 1])
  })
})
