import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const mockListConfigs = vi.fn()
const mockFetchConfig = vi.fn()
const mockPatchConfig = vi.fn()
const mockAddModConfig = vi.fn()
const mockDeleteConfig = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    modconfigs: {
      listConfigs: mockListConfigs,
      fetchConfig: mockFetchConfig,
      patchConfig: mockPatchConfig,
      addModConfig: mockAddModConfig,
      deleteConfig: mockDeleteConfig,
    },
  }),
}))

describe('modconfig store', () => {
  let useModConfigStore

  beforeEach(async () => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    const mod = await import('~/modtools/stores/modconfig')
    useModConfigStore = mod.useModConfigStore
  })

  it('starts with empty configs', () => {
    const store = useModConfigStore()
    expect(store.configs).toEqual([])
    expect(store.configsById).toEqual({})
    expect(store.current).toBeNull()
  })

  it('fetch stores configs list', async () => {
    const store = useModConfigStore()
    store.init({})
    const configs = [{ id: 1, name: 'Config A' }, { id: 2, name: 'Config B' }]
    mockListConfigs.mockResolvedValue(configs)

    await store.fetch({ all: true })

    expect(mockListConfigs).toHaveBeenCalledWith({ all: true })
    expect(store.configs).toEqual(configs)
  })

  it('fetch handles null response', async () => {
    const store = useModConfigStore()
    store.init({})
    mockListConfigs.mockResolvedValue(null)

    await store.fetch({ all: false })

    expect(store.configs).toEqual([])
  })

  it('fetchById caches config', async () => {
    const store = useModConfigStore()
    store.init({})
    const config = { id: 5, name: 'Cached' }
    mockFetchConfig.mockResolvedValue(config)

    const result = await store.fetchById(5)

    expect(result).toEqual(config)
    expect(store.configsById[5]).toEqual(config)
  })

  it('fetchById uses cache on second call', async () => {
    const store = useModConfigStore()
    store.init({})
    const config = { id: 5, name: 'Cached' }
    mockFetchConfig.mockResolvedValue(config)

    await store.fetchById(5)
    await store.fetchById(5)

    expect(mockFetchConfig).toHaveBeenCalledTimes(1)
  })

  it('fetchById returns null for falsy id', async () => {
    const store = useModConfigStore()
    const result = await store.fetchById(0)
    expect(result).toBeNull()
    expect(mockFetchConfig).not.toHaveBeenCalled()
  })

  it('fetchById returns null when API returns null', async () => {
    const store = useModConfigStore()
    store.init({})
    mockFetchConfig.mockResolvedValue(null)

    const result = await store.fetchById(99)

    expect(result).toBeNull()
  })

  it('fetchConfig sets current', async () => {
    const store = useModConfigStore()
    store.init({})
    const config = { id: 3, name: 'Current' }
    mockFetchConfig.mockResolvedValue(config)

    await store.fetchConfig({ id: 3 })

    expect(store.current).toEqual(config)
  })

  it('updateConfig patches then refetches', async () => {
    const store = useModConfigStore()
    store.init({})
    mockPatchConfig.mockResolvedValue()
    mockFetchConfig.mockResolvedValue({ id: 3, name: 'Updated' })

    await store.updateConfig({ id: 3, name: 'Updated' })

    expect(mockPatchConfig).toHaveBeenCalledWith({ id: 3, name: 'Updated' })
    expect(mockFetchConfig).toHaveBeenCalledWith({ id: 3, configuring: true })
  })

  it('add creates config and returns id', async () => {
    const store = useModConfigStore()
    store.init({})
    mockAddModConfig.mockResolvedValue(42)
    mockFetchConfig.mockResolvedValue({ id: 42, name: 'New' })

    const id = await store.add({ name: 'New' })

    expect(id).toBe(42)
    expect(mockFetchConfig).toHaveBeenCalledWith({ id: 42, configuring: true })
  })

  it('delete calls API', async () => {
    const store = useModConfigStore()
    store.init({})
    mockDeleteConfig.mockResolvedValue()

    await store.delete({ id: 10 })

    expect(mockDeleteConfig).toHaveBeenCalledWith({ id: 10 })
  })
})
