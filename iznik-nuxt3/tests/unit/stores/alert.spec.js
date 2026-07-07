import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const mockFetch = vi.fn()
const mockAdd = vi.fn().mockResolvedValue({ id: 10 })
const mockRecord = vi.fn().mockResolvedValue()

vi.mock('~/api', () => ({
  default: () => ({
    alert: {
      fetch: mockFetch,
      add: mockAdd,
      record: mockRecord,
    },
  }),
}))

describe('alert store', () => {
  let useAlertStore

  beforeEach(async () => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    const mod = await import('~/modtools/stores/alert')
    useAlertStore = mod.useAlertStore
  })

  it('stores list of alerts from fetch', async () => {
    const store = useAlertStore()
    store.config = {}
    mockFetch.mockResolvedValue({
      alerts: [
        { id: 1, subject: 'A' },
        { id: 2, subject: 'B' },
      ],
    })

    await store.fetch({})

    expect(store.list[1].subject).toBe('A')
    expect(store.list[2].subject).toBe('B')
  })

  it('stores single alert from fetch', async () => {
    const store = useAlertStore()
    store.config = {}
    mockFetch.mockResolvedValue({ alert: { id: 5, subject: 'Single' } })

    await store.fetch({ id: 5 })

    expect(store.list[5].subject).toBe('Single')
  })

  it('add creates alert and fetches it', async () => {
    const store = useAlertStore()
    store.config = {}
    mockFetch.mockResolvedValue({ alert: { id: 10, subject: 'New' } })

    const id = await store.add({ subject: 'New' })

    expect(id).toBe(10)
    expect(mockFetch).toHaveBeenCalledWith({ id: 10 })
  })

  it('record sends click tracking', async () => {
    const store = useAlertStore()
    store.config = {}

    await store.record({ id: 7 })

    expect(mockRecord).toHaveBeenCalledWith({
      trackid: 7,
      action: 'clicked',
    })
  })

  it('clear empties list', () => {
    const store = useAlertStore()
    store.list = { 1: { id: 1 } }
    store.clear()
    expect(store.list).toEqual({})
  })

  it('get getter returns alert by id', () => {
    const store = useAlertStore()
    store.list = { 3: { id: 3, subject: 'Found' } }

    expect(store.get(3)).toEqual({ id: 3, subject: 'Found' })
    expect(store.get(999)).toBeNull()
  })
})
