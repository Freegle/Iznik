import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const mockFetch = vi.fn()
const mockAdd = vi.fn().mockResolvedValue(42)
const mockPatch = vi.fn().mockResolvedValue()
const mockDel = vi.fn().mockResolvedValue()
const mockHold = vi.fn().mockResolvedValue()
const mockRelease = vi.fn().mockResolvedValue()

vi.mock('~/api', () => ({
  default: () => ({
    admins: {
      fetch: mockFetch,
      add: mockAdd,
      patch: mockPatch,
      del: mockDel,
      hold: mockHold,
      release: mockRelease,
    },
  }),
}))

describe('admins store', () => {
  let useAdminsStore

  beforeEach(async () => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    const mod = await import('~/modtools/stores/admins')
    useAdminsStore = mod.useAdminsStore
  })

  it('stores single admin on fetch with id', async () => {
    const store = useAdminsStore()
    store.config = {}
    mockFetch.mockResolvedValue({ id: 1, subject: 'Test Admin' })

    await store.fetch({ id: 1 })

    expect(store.list[1]).toEqual({ id: 1, subject: 'Test Admin' })
  })

  it('never files a list under a single id', async () => {
    const store = useAdminsStore()
    store.config = {}
    mockFetch.mockResolvedValue([{ id: 1 }, { id: 2 }])

    await store.fetch({ id: 7 })

    expect(store.list[7]).toBeUndefined()
  })

  it('stores array of admins on list fetch', async () => {
    const store = useAdminsStore()
    store.config = {}
    mockFetch.mockResolvedValue([
      { id: 1, subject: 'A' },
      { id: 2, subject: 'B' },
    ])

    await store.fetch({})

    expect(store.list[1].subject).toBe('A')
    expect(store.list[2].subject).toBe('B')
  })

  it('edit saves, then re-reads the admin by id alone', async () => {
    const store = useAdminsStore()
    store.config = {}
    const long = 'x'.repeat(20000)
    mockFetch.mockResolvedValue({ id: 5, subject: 'Saved', mjml: long })

    await store.edit({ id: 5, subject: 'Saved', text: long, mjml: long })

    expect(mockPatch).toHaveBeenCalledWith(
      expect.objectContaining({ id: 5, mjml: long })
    )
    // The whole text and MJML in a GET URL is refused by the server.
    expect(mockFetch).toHaveBeenCalledWith({ id: 5 })
    expect(store.list[5].subject).toBe('Saved')
  })

  it('clear empties list', () => {
    const store = useAdminsStore()
    store.list = { 1: { id: 1 } }
    store.clear()
    expect(store.list).toEqual({})
  })

  it('clearAdmin removes single entry', () => {
    const store = useAdminsStore()
    store.list = { 1: { id: 1 }, 2: { id: 2 } }
    store.clearAdmin(1)
    expect(store.list[1]).toBeUndefined()
    expect(store.list[2]).toBeTruthy()
  })

  it('add calls patch for template/editprotected', async () => {
    const store = useAdminsStore()
    store.config = {}

    await store.add({ template: true, editprotected: 1 })

    expect(mockAdd).toHaveBeenCalled()
    expect(mockPatch).toHaveBeenCalledWith({
      id: 42,
      template: true,
      editprotected: 1,
    })
  })

  it('approve sends pending as boolean false (not numeric 0)', async () => {
    const store = useAdminsStore()
    store.config = {}
    mockFetch.mockResolvedValue({ id: 7, pending: false })

    await store.approve({ id: 7 })

    expect(mockPatch).toHaveBeenCalledWith({
      id: 7,
      pending: false,
    })
    // Go API decodes `pending` into *bool, so a numeric 0 would 400.
    const call = mockPatch.mock.calls[0][0]
    expect(typeof call.pending).toBe('boolean')
  })

  it('delete removes from list', async () => {
    const store = useAdminsStore()
    store.config = {}
    store.list = { 5: { id: 5 } }

    await store.delete({ id: 5 })

    expect(mockDel).toHaveBeenCalledWith({ id: 5 })
    expect(store.list[5]).toBeUndefined()
  })

  it('get getter returns admin by id', () => {
    const store = useAdminsStore()
    store.list = { 3: { id: 3, subject: 'Found' } }

    expect(store.get(3)).toEqual({ id: 3, subject: 'Found' })
    expect(store.get(999)).toBeNull()
  })
})
