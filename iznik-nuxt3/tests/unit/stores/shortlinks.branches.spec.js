import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useShortlinkStore } from '~/stores/shortlinks'

const mockFetch = vi.fn()
const mockAdd = vi.fn()
const mockApi = vi.fn()

vi.mock('~/api', () => ({
  default: (config) => {
    mockApi(config)
    return { shortlinks: { fetch: mockFetch, add: mockAdd } }
  },
}))

describe('shortlinks store branches', () => {
  let store
  const config = { public: { APIv2: 'http://x' } }

  beforeEach(() => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    store = useShortlinkStore()
    store.init(config)
  })

  it('starts empty and init stores the config', () => {
    expect(store.list).toEqual({})
    expect(store.config).toEqual(config)
  })

  describe('fetch', () => {
    it.each([
      [
        'shortlinks array keyed by each shortlink id',
        5,
        undefined,
        {
          shortlinks: [
            { id: 1, name: 'a' },
            { id: 2, name: 'b' },
          ],
        },
        { 1: { id: 1, name: 'a' }, 2: { id: 2, name: 'b' } },
      ],
      [
        'single shortlink keyed by the requested id',
        7,
        undefined,
        { shortlink: { id: 99, name: 'c' } },
        { 7: { id: 99, name: 'c' } },
      ],
      [
        'shortlinks array wins over single shortlink',
        7,
        undefined,
        { shortlinks: [{ id: 1 }], shortlink: { id: 9 } },
        { 1: { id: 1 } },
      ],
      ['neither leaves list unchanged', 3, undefined, {}, {}],
      [
        'empty shortlinks array adds nothing',
        3,
        undefined,
        { shortlinks: [] },
        {},
      ],
    ])('%s', async (_n, id, groupid, response, expected) => {
      mockFetch.mockResolvedValue(response)
      await store.fetch(id, groupid)
      expect(mockFetch).toHaveBeenCalledWith({ id, groupid })
      expect(mockApi).toHaveBeenCalledWith(config)
      expect(store.list).toEqual(expected)
    })

    it('merges into existing list when no groupid', async () => {
      store.list = { 50: { id: 50 } }
      mockFetch.mockResolvedValue({ shortlinks: [{ id: 1 }] })
      await store.fetch(undefined, undefined)
      expect(store.list).toEqual({ 50: { id: 50 }, 1: { id: 1 } })
    })

    it('clears the list first when groupid is truthy', async () => {
      store.list = { 50: { id: 50 } }
      mockFetch.mockResolvedValue({ shortlinks: [{ id: 1 }] })
      await store.fetch(undefined, 12)
      expect(mockFetch).toHaveBeenCalledWith({ id: undefined, groupid: 12 })
      expect(store.list).toEqual({ 1: { id: 1 } })
    })

    it('clears even if the groupid fetch returns nothing', async () => {
      store.list = { 50: { id: 50 } }
      mockFetch.mockResolvedValue({})
      await store.fetch(undefined, 12)
      expect(store.list).toEqual({})
    })
  })

  describe('add', () => {
    it('fetches the new shortlink and returns its id', async () => {
      mockAdd.mockResolvedValue(42)
      mockFetch.mockResolvedValue({ shortlink: { id: 42, name: 'n' } })
      const id = await store.add(3, 'n')
      expect(mockAdd).toHaveBeenCalledWith({ groupid: 3, name: 'n' })
      expect(mockFetch).toHaveBeenCalledWith({ id: 42 })
      expect(store.list[42]).toEqual({ id: 42, name: 'n' })
      expect(id).toBe(42)
    })

    it.each([[0], [null], [undefined], ['']])(
      'falsy id %p: no fetch, returns it',
      async (falsy) => {
        mockAdd.mockResolvedValue(falsy)
        const id = await store.add(3, 'n')
        expect(mockFetch).not.toHaveBeenCalled()
        expect(store.list).toEqual({})
        expect(id).toBe(falsy)
      }
    )
  })

  it('clear empties the list', () => {
    store.list = { 1: { id: 1 } }
    store.clear()
    expect(store.list).toEqual({})
  })

  it.each([
    [1, { id: 1 }],
    [2, undefined],
  ])('byId(%p) returns %p', (id, expected) => {
    store.list = { 1: { id: 1 } }
    expect(store.byId(id)).toEqual(expected)
  })
})
