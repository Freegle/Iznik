import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const mockFetchStdMsg = vi.fn()
const mockDeleteStdMsg = vi.fn().mockResolvedValue()
const mockPatchStdMsg = vi.fn().mockResolvedValue()
const mockAddStdMsg = vi.fn().mockResolvedValue({ id: 99 })
const mockFetchConfig = vi.fn().mockResolvedValue()

vi.mock('~/api', () => ({
  default: () => ({
    modconfigs: {
      fetchStdMsg: mockFetchStdMsg,
      deleteStdMsg: mockDeleteStdMsg,
      patchStdMsg: mockPatchStdMsg,
      addStdMsg: mockAddStdMsg,
    },
  }),
}))

vi.mock('~/stores/modconfig', () => ({
  useModConfigStore: () => ({
    fetchConfig: mockFetchConfig,
  }),
}))

describe('stdmsg store', () => {
  let useStdmsgStore

  beforeEach(async () => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    const mod = await import('~/modtools/stores/stdmsg')
    useStdmsgStore = mod.useStdmsgStore
  })

  it('set stores stdmsg by id', () => {
    const store = useStdmsgStore()
    store.set({ id: 5, title: 'Welcome' })
    expect(store.stdmsgs[5]).toEqual({ id: 5, title: 'Welcome' })
  })

  it('byId and byid return stdmsg or null', () => {
    const store = useStdmsgStore()
    store.stdmsgs[5] = { id: 5, title: 'Welcome' }

    expect(store.byId(5)).toEqual({ id: 5, title: 'Welcome' })
    expect(store.byid(5)).toEqual({ id: 5, title: 'Welcome' })
    expect(store.byId(999)).toBeNull()
  })

  it('fetch retrieves and stores stdmsg', async () => {
    const store = useStdmsgStore()
    store.config = {}
    mockFetchStdMsg.mockResolvedValue({ stdmsg: { id: 10, title: 'Reply' } })

    const result = await store.fetch(10)

    expect(result).toEqual({ id: 10, title: 'Reply' })
    expect(store.stdmsgs[10].title).toBe('Reply')
  })

  it('delete calls API and refreshes config', async () => {
    const store = useStdmsgStore()
    store.init({})

    await store.delete({ id: 5, configid: 1 })

    expect(mockDeleteStdMsg).toHaveBeenCalledWith({ id: 5, configid: 1 })
    expect(mockFetchConfig).toHaveBeenCalledWith({
      id: 1,
      configuring: true,
    })
  })

  it('update calls API and refreshes config', async () => {
    const store = useStdmsgStore()
    store.init({})

    await store.update({ id: 5, title: 'Updated', configid: 1 })

    expect(mockPatchStdMsg).toHaveBeenCalledWith({
      id: 5,
      title: 'Updated',
      configid: 1,
    })
    expect(mockFetchConfig).toHaveBeenCalledWith({
      id: 1,
      configuring: true,
    })
  })

  it('add creates stdmsg and refreshes config', async () => {
    const store = useStdmsgStore()
    store.init({})

    const id = await store.add({ title: 'New', configid: 2 })

    expect(id).toBe(99)
    expect(mockAddStdMsg).toHaveBeenCalledWith({ title: 'New', configid: 2 })
    expect(mockFetchConfig).toHaveBeenCalledWith({
      id: 2,
      configuring: true,
    })
  })

  it('can add multiple messages in sequence', async () => {
    const store = useStdmsgStore()
    store.init({})

    const id1 = await store.add({ title: 'First', configid: 2 })
    expect(id1).toBe(99)
    expect(mockFetchConfig).toHaveBeenCalledTimes(1)

    const id2 = await store.add({ title: 'Second', configid: 2 })
    expect(id2).toBe(99)
    expect(mockFetchConfig).toHaveBeenCalledTimes(2)
  })

  it('delete and add in sequence works correctly', async () => {
    const store = useStdmsgStore()
    store.init({})

    await store.delete({ id: 5, configid: 1 })
    expect(mockDeleteStdMsg).toHaveBeenCalledWith({ id: 5, configid: 1 })
    expect(mockFetchConfig).toHaveBeenCalledWith({
      id: 1,
      configuring: true,
    })

    const id = await store.add({ title: 'New', configid: 1 })
    expect(id).toBe(99)
    expect(mockAddStdMsg).toHaveBeenCalled()
    expect(mockFetchConfig).toHaveBeenCalledTimes(2)
  })
})
