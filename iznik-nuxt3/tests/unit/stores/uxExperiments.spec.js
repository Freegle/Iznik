import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const fetchers = {
  fetchExperiments: vi.fn(),
  createExperiment: vi.fn(),
  experimentAction: vi.fn(),
}

// The store follows modtools/stores/ux.js: it asks ~/api for the class, and has no init().
vi.mock('~/api', () => ({
  default: () => ({ uxexperiments: fetchers }),
}))

const LIST = { flows: [], running_count: 0, max_running: 5 }

describe('uxExperiments store', () => {
  let useUxExperimentsStore

  beforeEach(async () => {
    vi.clearAllMocks()
    fetchers.fetchExperiments.mockResolvedValue(LIST)
    fetchers.createExperiment.mockResolvedValue({ id: 7, status: 'draft' })
    fetchers.experimentAction.mockResolvedValue({ queued: true })
    setActivePinia(createPinia())
    useUxExperimentsStore = (await import('~/modtools/stores/uxExperiments'))
      .useUxExperimentsStore
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('starts idle, with nothing loaded and no error', () => {
    const store = useUxExperimentsStore()
    expect(store.experiments).toEqual({
      data: null,
      loading: false,
      error: null,
      missing: false,
    })
    expect(store.loadedAt).toBe(0)
    expect(store.init).toBeUndefined()
  })

  it('loads the list into its slot and notes when', async () => {
    const store = useUxExperimentsStore()
    const before = Date.now()
    await store.load()
    expect(fetchers.fetchExperiments).toHaveBeenCalledTimes(1)
    expect(store.experiments.data).toEqual(LIST)
    expect(store.experiments.loading).toBe(false)
    expect(store.loadedAt).toBeGreaterThanOrEqual(before)
  })

  it('is loading while the request is in flight', async () => {
    let resolve
    fetchers.fetchExperiments.mockReturnValue(new Promise((r) => (resolve = r)))
    const store = useUxExperimentsStore()
    const p = store.load()
    expect(store.experiments.loading).toBe(true)
    resolve(LIST)
    await p
    expect(store.experiments.loading).toBe(false)
  })

  it('records a message and clears old data when a load fails', async () => {
    const store = useUxExperimentsStore()
    await store.load()
    fetchers.fetchExperiments.mockRejectedValueOnce(new Error('boom'))
    await store.load()
    expect(store.experiments.error).toBe('boom')
    expect(store.experiments.data).toBeNull()
  })

  it('says the route is missing, and no fault, for a 404', async () => {
    fetchers.fetchExperiments.mockRejectedValue({ response: { status: 404 } })
    const store = useUxExperimentsStore()
    await store.load()
    expect(store.experiments.missing).toBe(true)
    expect(store.experiments.error).toBeNull()
  })

  it('creates a draft through the API, then loads the list again, and gives back the new id', async () => {
    const store = useUxExperimentsStore()
    const made = await store.createExperiment({ name: 'x' })
    expect(fetchers.createExperiment).toHaveBeenCalledWith({ name: 'x' })
    expect(made).toEqual({ id: 7, status: 'draft' })
    expect(fetchers.fetchExperiments).toHaveBeenCalledTimes(1)
    expect(store.experiments.data).toEqual(LIST)
  })

  it('does not load again when creating fails, and lets the caller see why', async () => {
    const err = Object.assign(new Error('API Error'), {
      response: {
        status: 409,
        data: { message: 'An experiment with that name already exists' },
      },
    })
    fetchers.createExperiment.mockRejectedValue(err)
    const store = useUxExperimentsStore()
    await expect(store.createExperiment({ name: 'x' })).rejects.toBe(err)
    expect(fetchers.fetchExperiments).not.toHaveBeenCalled()
  })

  it('sends an action through the API, then loads the list again', async () => {
    const store = useUxExperimentsStore()
    const out = await store.experimentAction(5, { action: 'stop' })
    expect(fetchers.experimentAction).toHaveBeenCalledWith(5, {
      action: 'stop',
    })
    expect(out).toEqual({ queued: true })
    expect(fetchers.fetchExperiments).toHaveBeenCalledTimes(1)
  })

  it('does not load again when an action fails', async () => {
    fetchers.experimentAction.mockRejectedValue(new Error('no'))
    const store = useUxExperimentsStore()
    await expect(
      store.experimentAction(5, { action: 'start' })
    ).rejects.toThrow('no')
    expect(fetchers.fetchExperiments).not.toHaveBeenCalled()
  })

  describe('loadIfStale', () => {
    it('loads when nothing has been loaded', async () => {
      const store = useUxExperimentsStore()
      await store.loadIfStale(30000)
      expect(fetchers.fetchExperiments).toHaveBeenCalledTimes(1)
    })

    it('skips a load that is fresh', async () => {
      const store = useUxExperimentsStore()
      await store.loadIfStale(30000)
      await store.loadIfStale(30000)
      expect(fetchers.fetchExperiments).toHaveBeenCalledTimes(1)
    })

    it('loads again once the answer is older than the age given', async () => {
      vi.useFakeTimers()
      const store = useUxExperimentsStore()
      await store.loadIfStale(30000)
      await vi.advanceTimersByTimeAsync(29000)
      await store.loadIfStale(30000)
      expect(fetchers.fetchExperiments).toHaveBeenCalledTimes(1)
      await vi.advanceTimersByTimeAsync(2000)
      await store.loadIfStale(30000)
      expect(fetchers.fetchExperiments).toHaveBeenCalledTimes(2)
    })

    it('shares one load between the nav badge and the section, which ask together', async () => {
      let resolve
      fetchers.fetchExperiments.mockReturnValue(
        new Promise((r) => (resolve = r))
      )
      const store = useUxExperimentsStore()
      const badge = store.loadIfStale(30000)
      const section = store.loadIfStale(30000)
      expect(fetchers.fetchExperiments).toHaveBeenCalledTimes(1)
      resolve(LIST)
      await Promise.all([badge, section])
      expect(store.experiments.data).toEqual(LIST)
    })

    it('asks again after a failure, however recent, because there is nothing to show', async () => {
      const store = useUxExperimentsStore()
      await store.loadIfStale(30000)
      fetchers.fetchExperiments.mockRejectedValueOnce(new Error('boom'))
      await store.load()
      expect(store.experiments.data).toBeNull()
      await store.loadIfStale(30000)
      expect(fetchers.fetchExperiments).toHaveBeenCalledTimes(3)
      expect(store.experiments.data).toEqual(LIST)
    })

    it('asks again when the route was missing, which may have been added since', async () => {
      fetchers.fetchExperiments.mockRejectedValueOnce({
        response: { status: 404 },
      })
      const store = useUxExperimentsStore()
      await store.loadIfStale(30000)
      expect(store.experiments.missing).toBe(true)
      await store.loadIfStale(30000)
      expect(fetchers.fetchExperiments).toHaveBeenCalledTimes(2)
    })
  })
})
