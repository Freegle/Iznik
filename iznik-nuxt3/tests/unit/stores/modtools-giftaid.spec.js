import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

describe('modtools giftaid store', () => {
  let useGiftAidStore

  beforeEach(async () => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    const mod = await import('~/modtools/stores/giftaid')
    useGiftAidStore = mod.useGiftAidStore
  })

  it('starts with empty list', () => {
    const store = useGiftAidStore()
    expect(store.list).toEqual({})
  })

  it('add stores item by id', () => {
    const store = useGiftAidStore()
    store.add({ id: 1, amount: 10 })
    expect(store.list[1]).toEqual({ id: 1, amount: 10 })
  })

  it('add stores multiple items', () => {
    const store = useGiftAidStore()
    store.add({ id: 1, amount: 10 })
    store.add({ id: 2, amount: 20 })
    expect(Object.keys(store.list)).toHaveLength(2)
  })

  it('clear empties list', () => {
    const store = useGiftAidStore()
    store.add({ id: 1, amount: 10 })
    store.clear()
    expect(store.list).toEqual({})
  })

  it('byId returns item when found', () => {
    const store = useGiftAidStore()
    store.add({ id: 5, amount: 50 })
    expect(store.byId(5)).toEqual({ id: 5, amount: 50 })
  })

  it('byId returns null when not found', () => {
    const store = useGiftAidStore()
    expect(store.byId(999)).toBeNull()
  })

  it('byId parses string id to int', () => {
    const store = useGiftAidStore()
    store.add({ id: 3, amount: 30 })
    expect(store.byId('3')).toEqual({ id: 3, amount: 30 })
  })
})
