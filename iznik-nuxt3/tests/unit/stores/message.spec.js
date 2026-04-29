import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

import { useMessageStore } from '~/stores/message'
import { useAuthStore } from '~/stores/auth'

const mockFetchByUser = vi.fn()
const mockSave = vi.fn()
const mockFetch = vi.fn()
const mockSearch = vi.fn()
const mockFetchMessages = vi.fn()
const mockFetchMT = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    message: {
      fetchByUser: mockFetchByUser,
      save: mockSave,
      fetch: mockFetch,
      search: mockSearch,
      fetchMessages: mockFetchMessages,
    },
  }),
}))

vi.mock('~/stores/auth', () => ({
  useAuthStore: vi.fn(),
}))

vi.mock('~/stores/group', () => ({
  useGroupStore: () => ({}),
}))

vi.mock('~/stores/user', () => ({
  useUserStore: () => ({}),
}))

vi.mock('~/stores/isochrone', () => ({
  useIsochroneStore: () => ({}),
}))

const mockMiscStore = { modtools: false }
vi.mock('~/stores/misc', () => ({
  useMiscStore: () => mockMiscStore,
}))

describe('message store - patch()', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    mockMiscStore.modtools = false
  })

  it('syncs updated message into byUserList after patching', async () => {
    useAuthStore.mockReturnValue({ user: { id: 99 } })

    const store = useMessageStore()

    // Pre-populate byUserList with an expired message
    store.byUserList[99] = [
      { id: 1001, subject: 'Sofa', hasoutcome: true },
      { id: 1002, subject: 'Chair', hasoutcome: false },
    ]

    // PATCH returns success; subsequent fetch returns hasoutcome=false (server cleared it)
    mockSave.mockResolvedValue({ id: 1001 })
    mockFetch.mockResolvedValue({
      id: 1001,
      subject: 'Sofa',
      hasoutcome: false,
    })

    // Simulate what fetch() does: it puts the fetched message into this.list
    store.list[1001] = { id: 1001, subject: 'Sofa', hasoutcome: false }

    // Spy on the store's fetch method to avoid real API calls
    const fetchSpy = vi.spyOn(store, 'fetch').mockImplementation((id) => {
      store.list[id] = { id, subject: 'Sofa', hasoutcome: false }
    })

    await store.patch({ id: 1001, deadline: '2026-05-01' })

    // The fixed patch() must sync the updated state into byUserList
    expect(store.byUserList[99][0].hasoutcome).toBe(false)
    // Other entries should be unaffected
    expect(store.byUserList[99][1].hasoutcome).toBe(false)

    fetchSpy.mockRestore()
  })

  it('does not touch byUserList when message is not present', async () => {
    useAuthStore.mockReturnValue({ user: { id: 99 } })

    const store = useMessageStore()
    store.byUserList[99] = [{ id: 2001, subject: 'Other', hasoutcome: false }]
    store.list[1001] = { id: 1001, subject: 'Sofa', hasoutcome: false }

    const fetchSpy = vi.spyOn(store, 'fetch').mockImplementation((id) => {
      store.list[id] = { id, subject: 'Sofa', hasoutcome: false }
    })

    mockSave.mockResolvedValue({ id: 1001 })

    await store.patch({ id: 1001, deadline: '2026-05-01' })

    // Unrelated entries unchanged
    expect(store.byUserList[99][0].hasoutcome).toBe(false)
    expect(store.byUserList[99].length).toBe(1)

    fetchSpy.mockRestore()
  })
})

describe('message store - fetchActivePostCount', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('sets counter to 0 when user is not logged in', async () => {
    useAuthStore.mockReturnValue({ user: null })

    const store = useMessageStore()
    store.activePostsCounter = 5

    await store.fetchActivePostCount()

    expect(store.activePostsCounter).toBe(0)
    expect(mockFetchByUser).not.toHaveBeenCalled()
  })

  it('counts active messages when user is logged in', async () => {
    useAuthStore.mockReturnValue({ user: { id: 42 } })
    mockFetchByUser.mockResolvedValue([
      { id: 1, subject: 'Sofa' },
      { id: 2, subject: 'Chair' },
    ])

    const store = useMessageStore()
    await store.fetchActivePostCount()

    expect(mockFetchByUser).toHaveBeenCalledWith(42, true)
    expect(store.activePostsCounter).toBe(2)
  })

  it('sets counter to 0 when API returns non-array', async () => {
    useAuthStore.mockReturnValue({ user: { id: 42 } })
    mockFetchByUser.mockResolvedValue(null)

    const store = useMessageStore()
    await store.fetchActivePostCount()

    expect(store.activePostsCounter).toBe(0)
  })
})

describe('message store - searchMT()', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    mockMiscStore.modtools = true
  })

  it('calls V2 search API with vector searchmode', async () => {
    useAuthStore.mockReturnValue({ user: { id: 1 } })
    mockSearch.mockResolvedValue([
      { id: 101, msgid: 101, matchedon: { type: 'Vector', word: 'sofa' } },
      { id: 102, msgid: 102, matchedon: { type: 'Vector', word: 'sofa' } },
    ])

    const store = useMessageStore()
    // Mock fetchMT to avoid real API calls
    store.fetchMT = vi.fn().mockImplementation(({ id }) => {
      const msg = { id, subject: 'Test' }
      return msg
    })

    const ids = await store.searchMT({
      term: 'sofa',
      groupid: 123,
      searchmode: 'vector',
    })

    expect(mockSearch).toHaveBeenCalledWith({
      search: 'sofa',
      messagetype: 'All',
      groupids: '123',
      searchmode: 'vector',
    })
    expect(store.fetchMT).toHaveBeenCalledTimes(2)
    expect(store.list[101]).toBeDefined()
    expect(store.list[102]).toBeDefined()
    expect(store.list[101].matchedon).toEqual({ type: 'Vector', word: 'sofa' })
    expect(store.list[102].matchedon).toEqual({ type: 'Vector', word: 'sofa' })
    expect(ids).toEqual(expect.arrayContaining([101, 102]))
  })

  it('preserves score order from API response', async () => {
    useAuthStore.mockReturnValue({ user: { id: 1 } })
    mockSearch.mockResolvedValue([
      { id: 301, matchedon: { type: 'Vector', word: 'sofa' } },
      { id: 302, matchedon: { type: 'Vector', word: 'sofa' } },
      { id: 303, matchedon: { type: 'Vector', word: 'sofa' } },
    ])

    const store = useMessageStore()
    store.fetchMT = vi.fn().mockImplementation(({ id }) => {
      return { id, subject: 'Test' }
    })

    const ids = await store.searchMT({
      term: 'sofa',
      searchmode: 'vector',
    })

    // Order should match API response order (score-ranked)
    expect(ids).toEqual([301, 302, 303])
  })

  it('handles empty vector search results', async () => {
    useAuthStore.mockReturnValue({ user: { id: 1 } })
    mockSearch.mockResolvedValue([])

    const store = useMessageStore()
    store.fetchMT = vi.fn()

    const ids = await store.searchMT({
      term: 'nonexistent',
      searchmode: 'vector',
    })

    expect(mockSearch).toHaveBeenCalled()
    expect(store.fetchMT).not.toHaveBeenCalled()
    expect(ids).toEqual([])
  })

  it('handles null vector search results', async () => {
    useAuthStore.mockReturnValue({ user: { id: 1 } })
    mockSearch.mockResolvedValue(null)

    const store = useMessageStore()
    store.fetchMT = vi.fn()

    const ids = await store.searchMT({
      term: 'nonexistent',
      searchmode: 'vector',
    })

    expect(store.fetchMT).not.toHaveBeenCalled()
    expect(ids).toEqual([])
  })

  it('omits groupids when no groupid provided', async () => {
    useAuthStore.mockReturnValue({ user: { id: 1 } })
    mockSearch.mockResolvedValue([])

    const store = useMessageStore()
    await store.searchMT({
      term: 'chair',
      searchmode: 'vector',
    })

    expect(mockSearch).toHaveBeenCalledWith({
      search: 'chair',
      messagetype: 'All',
      groupids: undefined,
      searchmode: 'vector',
    })
  })

  it('handles fetchMT failure for individual results gracefully', async () => {
    useAuthStore.mockReturnValue({ user: { id: 1 } })
    mockSearch.mockResolvedValue([
      { id: 201 },
      { id: 202 },
    ])

    const store = useMessageStore()
    store.fetchMT = vi.fn().mockImplementation(({ id }) => {
      if (id === 201) throw new Error('Not found')
      const msg = { id, subject: 'Chair' }
      store.list[id] = msg
      return msg
    })

    const ids = await store.searchMT({
      term: 'chair',
      groupid: 100,
      searchmode: 'vector',
    })

    // First result failed but second should still be in list
    expect(store.list[201]).toBeUndefined()
    expect(store.list[202]).toBeDefined()
    expect(ids).toEqual([202])
  })

  it('uses keyword search when searchmode is not vector', async () => {
    useAuthStore.mockReturnValue({ user: { id: 1 } })
    mockFetchMessages.mockResolvedValue({
      messages: [301, 302],
    })

    const store = useMessageStore()
    store.fetchMT = vi.fn().mockImplementation(({ id }) => {
      const msg = { id, subject: 'Bike' }
      store.list[id] = msg
      return msg
    })

    await store.searchMT({
      term: 'bike',
      groupid: 50,
    })

    expect(mockFetchMessages).toHaveBeenCalledWith(
      expect.objectContaining({
        subaction: 'searchall',
        search: 'bike',
        exactonly: true,
        groupid: 50,
      })
    )
    expect(store.fetchMT).toHaveBeenCalledTimes(2)
  })

  it('handles empty keyword search results', async () => {
    useAuthStore.mockReturnValue({ user: { id: 1 } })
    mockFetchMessages.mockResolvedValue({ messages: [] })

    const store = useMessageStore()
    store.fetchMT = vi.fn()

    await store.searchMT({ term: 'nothing' })

    expect(store.fetchMT).not.toHaveBeenCalled()
  })
})

describe('getByGroup', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('returns messages matching any group in the groups array', () => {
    const store = useMessageStore()

    // Manually populate the store's list with test data.
    store.list = {
      1: { id: 1, subject: 'Sofa', groups: [{ groupid: 10 }, { groupid: 20 }] },
      2: { id: 2, subject: 'Chair', groups: [{ groupid: 20 }] },
      3: { id: 3, subject: 'Table', groups: [{ groupid: 30 }] },
    }

    // Group 20 should match messages 1 and 2.
    const result = store.getByGroup(20)
    expect(result).toHaveLength(2)
    expect(result.map((m) => m.id).sort()).toEqual([1, 2])
  })

  it('returns empty array when no messages match the group', () => {
    const store = useMessageStore()
    store.list = {
      1: { id: 1, subject: 'Sofa', groups: [{ groupid: 10 }] },
    }

    expect(store.getByGroup(99)).toHaveLength(0)
  })

  it('handles messages with empty groups array', () => {
    const store = useMessageStore()
    store.list = {
      1: { id: 1, subject: 'Sofa', groups: [] },
    }

    expect(store.getByGroup(10)).toHaveLength(0)
  })
})

describe('message store - fetchMessagesMT() pagination context', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    mockMiscStore.modtools = false
  })

  // The Go server expects `context` as a JSON-encoded string
  // (it calls json.Unmarshal on the query value). If we pass the object
  // directly URLSearchParams coerces it to "[object Object]" which fails
  // to parse, so the server silently falls back to page 1 and the infinite
  // scroll caps at one page (~100 messages) regardless of how far the user
  // scrolls.
  it('serialises the pagination context as a JSON string before sending', async () => {
    useAuthStore.mockReturnValue({ user: { id: 1 } })
    mockFetchMessages.mockResolvedValue({
      messages: [1001, 1002],
      context: { Date: 1700000000, ID: 1001 },
    })

    const store = useMessageStore()
    store.fetchMT = vi.fn().mockResolvedValue({ id: 1, subject: 'x' })

    await store.fetchMessagesMT({
      groupid: 1,
      collection: 'Approved',
      context: { Date: 1700001234, ID: 2002 },
      limit: 30,
    })

    const sent = mockFetchMessages.mock.calls[0][0]
    expect(typeof sent.context).toBe('string')
    expect(JSON.parse(sent.context)).toEqual({ Date: 1700001234, ID: 2002 })
  })

  it('leaves a null context alone', async () => {
    useAuthStore.mockReturnValue({ user: { id: 1 } })
    mockFetchMessages.mockResolvedValue({ messages: [] })

    const store = useMessageStore()
    store.fetchMT = vi.fn()

    await store.fetchMessagesMT({
      groupid: 1,
      collection: 'Approved',
      context: null,
    })

    expect(mockFetchMessages.mock.calls[0][0].context).toBeNull()
  })
})
