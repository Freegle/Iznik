import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const mockFetch = vi.fn()
const mockAdd = vi.fn()
const mockPatch = vi.fn()
const mockDel = vi.fn()
const mockHold = vi.fn()
const mockRelease = vi.fn()
const mockTest = vi.fn()
const mockApiFactory = vi.fn()

vi.mock('~/api', () => ({
  default: (config) => {
    mockApiFactory(config)
    return {
      admins: {
        fetch: mockFetch,
        add: mockAdd,
        patch: mockPatch,
        del: mockDel,
        hold: mockHold,
        release: mockRelease,
        test: mockTest,
      },
    }
  },
}))

const heldError = (heldby = 9, heldbyname = 'Jo') => ({
  response: { status: 409, data: { heldby, heldbyname } },
})

describe('admins store - branches', () => {
  let store

  beforeEach(async () => {
    vi.clearAllMocks()
    mockAdd.mockResolvedValue(42)
    mockPatch.mockResolvedValue()
    mockDel.mockResolvedValue()
    mockHold.mockResolvedValue()
    mockRelease.mockResolvedValue()
    mockFetch.mockResolvedValue({ id: 1 })
    setActivePinia(createPinia())
    const { useAdminsStore } = await import('~/modtools/stores/admins')
    store = useAdminsStore()
    store.config = { cfg: true }
  })

  it('init stores config and builds an api client from it', () => {
    store.init({ foo: 'bar' })
    expect(store.config).toEqual({ foo: 'bar' })
    expect(mockApiFactory).toHaveBeenCalledWith({ foo: 'bar' })
  })

  it('test passes params through and returns the api result', async () => {
    mockTest.mockResolvedValue({ ok: true })
    expect(await store.test({ id: 3 })).toEqual({ ok: true })
    expect(mockTest).toHaveBeenCalledWith({ id: 3 })
  })

  describe('fetch', () => {
    it.each([
      ['no params, array', undefined, [{ id: 1 }, { id: 2 }], [1, 2]],
      [
        'no params, object with admins',
        undefined,
        { admins: [{ id: 3 }] },
        [3],
      ],
      ['no params, object without admins', undefined, {}, []],
      ['no params, null', undefined, null, []],
      ['params without id, array', { pending: 1 }, [{ id: 4 }], [4]],
    ])('list fetch: %s', async (_n, params, data, ids) => {
      mockFetch.mockResolvedValue(data)
      const result = await store.fetch(params)
      expect(result.map((a) => a.id)).toEqual(ids)
      expect(Object.keys(store.list).map(Number)).toEqual(ids)
    })

    it.each([
      ['null', null],
      ['undefined', undefined],
      ['array', [{ id: 7 }]],
      ['object without id', { subject: 'x' }],
    ])('single fetch with %s response files nothing', async (_n, data) => {
      mockFetch.mockResolvedValue(data)
      const result = await store.fetch({ id: 7 })
      expect(result).toBeUndefined()
      expect(store.list).toEqual({})
    })

    it('propagates api rejection', async () => {
      mockFetch.mockRejectedValue(new Error('boom'))
      await expect(store.fetch({ id: 1 })).rejects.toThrow('boom')
    })
  })

  describe('add', () => {
    it.each([
      ['template only', { template: true }, true],
      ['editprotected only', { editprotected: 1 }, true],
      ['neither', { subject: 's' }, false],
    ])('%s', async (_n, params, patched) => {
      await store.add(params)
      expect(mockPatch).toHaveBeenCalledTimes(patched ? 1 : 0)
    })

    it('does not patch when add returns no id', async () => {
      mockAdd.mockResolvedValue(undefined)
      await store.add({ template: true })
      expect(mockPatch).not.toHaveBeenCalled()
    })
  })

  describe('approve', () => {
    it('passes the testtoken and refetches', async () => {
      await store.approve({ id: 7, testtoken: 'tok' })
      expect(mockPatch).toHaveBeenCalledWith({
        id: 7,
        pending: false,
        testtoken: 'tok',
      })
      expect(mockFetch).toHaveBeenCalledWith({ id: 7 })
      expect(store.list[1]).toBeUndefined()
    })

    it('on a hold conflict refreshes then throws the holder error', async () => {
      mockPatch.mockRejectedValue(heldError())
      await expect(store.approve({ id: 7 })).rejects.toMatchObject({
        heldByOtherMod: true,
        heldby: 9,
      })
      expect(mockFetch).toHaveBeenCalledTimes(1)
    })

    it('rethrows other errors without refreshing', async () => {
      mockPatch.mockRejectedValue(new Error('500'))
      await expect(store.approve({ id: 7 })).rejects.toThrow('500')
      expect(mockFetch).not.toHaveBeenCalled()
    })
  })

  describe('edit', () => {
    it('on a hold conflict refreshes then throws, naming the holder', async () => {
      mockPatch.mockRejectedValue(heldError(9, 'Jo'))
      await expect(store.edit({ id: 5, subject: 's' })).rejects.toThrow(
        /Jo is holding this/
      )
      expect(mockFetch).toHaveBeenCalledWith({ id: 5 })
    })

    it('still throws the holder error if the refresh fails', async () => {
      mockPatch.mockRejectedValue(heldError())
      mockFetch.mockRejectedValue(new Error('refresh down'))
      await expect(store.edit({ id: 5 })).rejects.toMatchObject({
        heldByOtherMod: true,
      })
    })

    it('rethrows non-hold errors', async () => {
      mockPatch.mockRejectedValue({ response: { status: 409, data: {} } })
      await expect(store.edit({ id: 5 })).rejects.toBeTruthy()
      expect(mockFetch).not.toHaveBeenCalled()
    })
  })

  describe('hold', () => {
    it('holds by id then refetches', async () => {
      await store.hold({ id: 8 })
      expect(mockHold).toHaveBeenCalledWith(8)
      expect(mockFetch).toHaveBeenCalledWith({ id: 8 })
      expect(store.list[1]).toBeUndefined()
    })

    it('on a conflict refreshes once and throws', async () => {
      mockHold.mockRejectedValue(heldError(4, null))
      await expect(store.hold({ id: 8 })).rejects.toThrow(
        /Another moderator is holding this/
      )
      expect(mockFetch).toHaveBeenCalledTimes(1)
    })

    it('rethrows other errors', async () => {
      mockHold.mockRejectedValue(new Error('nope'))
      await expect(store.hold({ id: 8 })).rejects.toThrow('nope')
    })
  })

  describe('release', () => {
    it('releases by id then refetches', async () => {
      mockFetch.mockResolvedValue({ id: 8, subject: 'r' })
      await store.release({ id: 8 })
      expect(mockRelease).toHaveBeenCalledWith(8)
      expect(store.list[8].subject).toBe('r')
    })

    it('does not refetch when release fails', async () => {
      mockRelease.mockRejectedValue(new Error('fail'))
      await expect(store.release({ id: 8 })).rejects.toThrow('fail')
      expect(mockFetch).not.toHaveBeenCalled()
    })
  })

  it('delete does not clear the entry when the api fails', async () => {
    store.list = { 5: { id: 5 } }
    mockDel.mockRejectedValue(new Error('x'))
    await expect(store.delete({ id: 5 })).rejects.toThrow('x')
    expect(store.list[5]).toBeTruthy()
  })

  it.each([
    ['numeric string', '3', { id: 3 }],
    ['number', 3, { id: 3 }],
    ['missing', 99, null],
    ['NaN', 'abc', null],
  ])('get getter: %s', (_n, id, expected) => {
    store.list = { 3: { id: 3 } }
    expect(store.get(id)).toEqual(expected)
  })
})
