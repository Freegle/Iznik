import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const mockFetch = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    comment: {
      fetch: mockFetch,
    },
  }),
}))

vi.mock('~/stores/user', () => ({
  useUserStore: () => ({
    fetch: vi.fn().mockResolvedValue(),
    list: {
      100: { id: 100, displayname: 'Alice' },
      200: { id: 200, displayname: 'Bob' },
    },
  }),
}))

describe('comment store', () => {
  let useCommentStore

  beforeEach(async () => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    const mod = await import('~/modtools/stores/comment')
    useCommentStore = mod.useCommentStore
  })

  it('stores list of comments and sets context', async () => {
    const store = useCommentStore()
    store.config = {}
    mockFetch.mockResolvedValue({
      comments: [
        { id: 1, body: 'First', userid: 100 },
        { id: 2, body: 'Second', byuserid: 200 },
      ],
      context: { id: 'abc' },
    })

    await store.fetch({})

    expect(store.list[1].body).toBe('First')
    expect(store.list[2].body).toBe('Second')
    expect(store.context).toEqual({ id: 'abc' })
  })

  it('stores single comment by id', async () => {
    const store = useCommentStore()
    store.config = {}
    mockFetch.mockResolvedValue({ id: 5, body: 'Single', userid: 100 })

    await store.fetch({ id: 5 })

    expect(store.list[5].body).toBe('Single')
  })

  it('enriches comments with user data', async () => {
    const store = useCommentStore()
    store.config = {}
    mockFetch.mockResolvedValue({
      comments: [{ id: 1, body: 'Test', userid: 100, byuserid: 200 }],
      context: null,
    })

    await store.fetch({})

    expect(store.list[1].user.displayname).toBe('Alice')
    expect(store.list[1].byuser.displayname).toBe('Bob')
  })

  it('converts object context to id for V2 API', async () => {
    const store = useCommentStore()
    store.config = {}
    mockFetch.mockResolvedValue({ comments: [], context: null })

    await store.fetch({ context: { id: 'xyz' } })

    expect(mockFetch).toHaveBeenCalledWith({ context: 'xyz' })
  })

  it('clear resets list and context', () => {
    const store = useCommentStore()
    store.list = { 1: { id: 1 } }
    store.context = { id: 'abc' }
    store.clear()
    expect(store.list).toEqual({})
    expect(store.context).toBeNull()
  })

  it('sortedList sorts flagged first, then by reviewed date desc', () => {
    const store = useCommentStore()
    store.list = {
      1: { id: 1, flagged: false, reviewed: '2026-01-01' },
      2: { id: 2, flagged: true, reviewed: '2026-01-01' },
      3: { id: 3, flagged: false, reviewed: '2026-03-01' },
    }

    const sorted = store.sortedList
    expect(sorted[0].id).toBe(2) // flagged first
    expect(sorted[1].id).toBe(3) // newer date
    expect(sorted[2].id).toBe(1) // older date
  })

  it('byId getter returns comment or null', () => {
    const store = useCommentStore()
    store.list = { 5: { id: 5, body: 'Found' } }

    expect(store.byId(5)).toEqual({ id: 5, body: 'Found' })
    expect(store.byId(999)).toBeNull()
  })
})
