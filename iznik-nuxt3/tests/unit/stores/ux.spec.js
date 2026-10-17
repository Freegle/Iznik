import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const fetchers = {
  fetchFunnels: vi.fn(),
  fetchOutcomes: vi.fn(),
  fetchFields: vi.fn(),
  fetchFriction: vi.fn(),
  fetchAdoption: vi.fn(),
  fetchRegret: vi.fn(),
  fetchSearch: vi.fn(),
  fetchHealth: vi.fn(),
  fetchSamples: vi.fn(),
}

vi.mock('~/api', () => ({
  default: () => ({ ux: fetchers }),
}))

describe('ux store', () => {
  let useUxStore

  beforeEach(async () => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    useUxStore = (await import('~/modtools/stores/ux')).useUxStore
  })

  it('starts with every panel empty, idle and without error', () => {
    const store = useUxStore()
    for (const k of [
      'health',
      'funnels',
      'outcomes',
      'fields',
      'friction',
      'adoption',
      'regret',
      'search',
      'samples',
    ]) {
      expect(store[k]).toEqual({
        data: null,
        loading: false,
        error: null,
        missing: false,
      })
    }
  })

  it('stores the response and passes the dates to the API', async () => {
    fetchers.fetchHealth.mockResolvedValue({ metrics: [] })
    const store = useUxStore()
    await store.load('health', { start: '2026-10-01', end: '2026-10-07' })
    expect(fetchers.fetchHealth).toHaveBeenCalledWith({
      start: '2026-10-01',
      end: '2026-10-07',
    })
    expect(store.health.data).toEqual({ metrics: [] })
    expect(store.health.loading).toBe(false)
    expect(store.health.error).toBeNull()
  })

  it('is loading while a request is in flight', async () => {
    let resolve
    fetchers.fetchFunnels.mockReturnValue(new Promise((r) => (resolve = r)))
    const store = useUxStore()
    const p = store.load('funnels', {})
    expect(store.funnels.loading).toBe(true)
    resolve({ flows: [] })
    await p
    expect(store.funnels.loading).toBe(false)
  })

  it('records an error message and clears old data on failure', async () => {
    fetchers.fetchFriction.mockResolvedValueOnce({ components: [1] })
    fetchers.fetchFriction.mockRejectedValueOnce(new Error('boom'))
    const store = useUxStore()
    await store.load('friction', {})
    await store.load('friction', {})
    expect(store.friction.error).toBe('boom')
    expect(store.friction.data).toBeNull()
    expect(store.friction.loading).toBe(false)
  })

  it('falls back to a generic message when the error has none', async () => {
    fetchers.fetchRegret.mockRejectedValue({})
    const store = useUxStore()
    await store.load('regret', {})
    expect(store.regret.error).toBe('Unknown error')
  })

  describe('loadSlots', () => {
    const params = { start: '2026-10-01', end: '2026-10-07' }

    it('loads only the slots it is given, with the params', async () => {
      for (const f of Object.values(fetchers)) f.mockResolvedValue({})
      const store = useUxStore()
      await store.loadSlots(['health', 'funnels'], params)
      expect(fetchers.fetchHealth).toHaveBeenCalledWith(params)
      expect(fetchers.fetchFunnels).toHaveBeenCalledWith(params)
      expect(fetchers.fetchOutcomes).not.toHaveBeenCalled()
      expect(fetchers.fetchFriction).not.toHaveBeenCalled()
    })

    it('never loads samples, which are opened on demand', async () => {
      for (const f of Object.values(fetchers)) f.mockResolvedValue({})
      const store = useUxStore()
      await store.loadSlots(['samples', 'health', 'nothing'], params)
      expect(fetchers.fetchSamples).not.toHaveBeenCalled()
      expect(fetchers.fetchHealth).toHaveBeenCalledTimes(1)
    })

    it('skips a slot that already holds the same params', async () => {
      for (const f of Object.values(fetchers)) f.mockResolvedValue({})
      const store = useUxStore()
      await store.loadSlots(['health', 'funnels'], params)
      await store.loadSlots(['health', 'funnels', 'outcomes'], { ...params })
      expect(fetchers.fetchHealth).toHaveBeenCalledTimes(1)
      expect(fetchers.fetchFunnels).toHaveBeenCalledTimes(1)
      expect(fetchers.fetchOutcomes).toHaveBeenCalledTimes(1)
    })

    it('treats the same params in another order as the same', async () => {
      for (const f of Object.values(fetchers)) f.mockResolvedValue({})
      const store = useUxStore()
      await store.loadSlots(['health'], { start: 'a', end: 'b' })
      await store.loadSlots(['health'], { end: 'b', start: 'a' })
      expect(fetchers.fetchHealth).toHaveBeenCalledTimes(1)
    })

    it('loads again when a filter changes', async () => {
      for (const f of Object.values(fetchers)) f.mockResolvedValue({})
      const store = useUxStore()
      await store.loadSlots(['funnels'], params)
      await store.loadSlots(['funnels'], { ...params, device: 'mobile' })
      await store.loadSlots(['funnels'], {
        ...params,
        device: 'mobile',
        signed_in: '1',
      })
      expect(fetchers.fetchFunnels).toHaveBeenCalledTimes(3)
      expect(fetchers.fetchFunnels).toHaveBeenLastCalledWith({
        ...params,
        device: 'mobile',
        signed_in: '1',
      })
    })

    it('loads again after forget(), as a refresh', async () => {
      for (const f of Object.values(fetchers)) f.mockResolvedValue({})
      const store = useUxStore()
      await store.loadSlots(['health'], params)
      store.forget()
      await store.loadSlots(['health'], params)
      expect(fetchers.fetchHealth).toHaveBeenCalledTimes(2)
    })

    it('does not stop the others when one fails', async () => {
      for (const f of Object.values(fetchers)) f.mockResolvedValue({})
      fetchers.fetchAdoption.mockRejectedValue(new Error('nope'))
      const store = useUxStore()
      await store.loadSlots(['health', 'adoption', 'search'], params)
      expect(store.adoption.error).toBe('nope')
      expect(store.health.data).toEqual({})
      expect(store.search.data).toEqual({})
    })

    describe('a slot whose read failed', () => {
      it('is asked again by the next call with the same params', async () => {
        fetchers.fetchFunnels.mockRejectedValueOnce(new Error('blip'))
        fetchers.fetchFunnels.mockResolvedValueOnce({ flows: ['ok'] })
        fetchers.fetchHealth.mockResolvedValue({})
        const store = useUxStore()
        await store.loadSlots(['health', 'funnels'], params)
        expect(store.funnels.error).toBe('blip')

        await store.loadSlots(['health', 'funnels'], { ...params })
        expect(fetchers.fetchFunnels).toHaveBeenCalledTimes(2)
        expect(store.funnels.error).toBeNull()
        expect(store.funnels.data).toEqual({ flows: ['ok'] })
        // The slot that had succeeded is left alone.
        expect(fetchers.fetchHealth).toHaveBeenCalledTimes(1)
      })

      it('is asked again every time while it keeps failing', async () => {
        fetchers.fetchFunnels.mockRejectedValue(new Error('down'))
        const store = useUxStore()
        await store.loadSlots(['funnels'], params)
        await store.loadSlots(['funnels'], params)
        await store.loadSlots(['funnels'], params)
        expect(fetchers.fetchFunnels).toHaveBeenCalledTimes(3)
      })

      it('still says what it was asked with, so the field figures keep their dates', async () => {
        fetchers.fetchFunnels.mockRejectedValue(new Error('down'))
        const store = useUxStore()
        await store.loadSlots(['funnels'], { ...params, device: 'app' })
        expect(store.requested('funnels')).toEqual({ ...params, device: 'app' })
      })
    })

    it('does not ask again for a slot whose route is missing, which asking cannot fix', async () => {
      fetchers.fetchSearch.mockRejectedValue(
        Object.assign(new Error('nope'), { response: { status: 404 } })
      )
      const store = useUxStore()
      await store.loadSlots(['search'], params)
      await store.loadSlots(['search'], params)
      expect(store.search.missing).toBe(true)
      expect(fetchers.fetchSearch).toHaveBeenCalledTimes(1)
    })

    it('does not ask again for a slot that is still loading', async () => {
      let answer
      fetchers.fetchFunnels.mockReturnValue(new Promise((r) => (answer = r)))
      const store = useUxStore()
      const first = store.loadSlots(['funnels'], params)
      await store.loadSlots(['funnels'], params)
      expect(fetchers.fetchFunnels).toHaveBeenCalledTimes(1)
      answer({ flows: [] })
      await first
    })
  })

  describe('notes', () => {
    // The server says which filters it used. A note compares that with what the answer was asked
    // for, so it must be the request that produced the answer in the slot, not the newest request:
    // while a changed filter loads, the slot still holds the answer to the one before.
    const echo = (device = '', signed_in = '') => ({
      flows: [],
      filters: { device, signed_in },
      ignored: [],
    })
    const OLD = 'The server did not say which filters it used'

    it('is empty when nothing was asked for', async () => {
      fetchers.fetchFunnels.mockResolvedValue(echo())
      const store = useUxStore()
      await store.load('funnels', { start: 'a', end: 'b' })
      expect(store.notes('funnels')).toEqual([])
    })

    it('is empty for a slot nothing has loaded', () => {
      expect(useUxStore().notes('funnels')).toEqual([])
    })

    it('says nothing about an old server while a changed filter loads', async () => {
      fetchers.fetchFunnels.mockResolvedValueOnce(echo())
      let answer
      fetchers.fetchFunnels.mockReturnValueOnce(
        new Promise((r) => (answer = r))
      )
      const store = useUxStore()
      await store.load('funnels', { start: 'a', end: 'b' })

      const pending = store.load('funnels', {
        start: 'a',
        end: 'b',
        device: 'mobile',
      })
      // The new request is the one the slot was last asked, but its answer is not in yet.
      expect(store.requested('funnels').device).toBe('mobile')
      expect(store.funnels.loading).toBe(true)
      expect(store.notes('funnels')).toEqual([])

      answer(echo('mobile'))
      await pending
      expect(store.notes('funnels')).toEqual([])
    })

    it('says nothing while one device changes to another', async () => {
      fetchers.fetchFunnels.mockResolvedValueOnce(echo('mobile'))
      let answer
      fetchers.fetchFunnels.mockReturnValueOnce(
        new Promise((r) => (answer = r))
      )
      const store = useUxStore()
      await store.load('funnels', { device: 'mobile' })
      const pending = store.load('funnels', { device: 'tablet' })
      expect(store.notes('funnels')).toEqual([])
      answer(echo('tablet'))
      await pending
      expect(store.notes('funnels')).toEqual([])
    })

    it('says what the server left alone, once its answer is in', async () => {
      fetchers.fetchAdoption.mockResolvedValue({
        features: [],
        filters: { device: '', signed_in: '' },
        ignored: ['device'],
      })
      const store = useUxStore()
      await store.load('adoption', { device: 'mobile' })
      expect(store.notes('adoption')).toEqual([
        'Not filtered by device. Shows every device.',
      ])
    })

    it('still warns when a server that was asked for a filter does not say it used it', async () => {
      fetchers.fetchAdoption.mockResolvedValue({ features: [] })
      const store = useUxStore()
      await store.load('adoption', { device: 'mobile' })
      expect(store.notes('adoption')).toEqual([expect.stringContaining(OLD)])
    })

    it('describes the answer that is in the slot when a slower earlier one arrives last', async () => {
      let first
      fetchers.fetchFunnels.mockReturnValueOnce(new Promise((r) => (first = r)))
      fetchers.fetchFunnels.mockResolvedValueOnce(echo('web'))
      const store = useUxStore()
      const slow = store.load('funnels', { device: 'app' })
      await store.load('funnels', { device: 'web' })
      first(echo('app'))
      await slow
      expect(store.funnels.data.filters.device).toBe('web')
      expect(store.notes('funnels')).toEqual([])
    })

    it('goes on describing the answer after forget()', async () => {
      fetchers.fetchAdoption.mockResolvedValue({
        features: [],
        filters: { device: '', signed_in: '' },
        ignored: ['device'],
      })
      const store = useUxStore()
      await store.load('adoption', { device: 'mobile' })
      store.forget()
      expect(store.notes('adoption')).toHaveLength(1)
    })

    it('is empty when the read failed, since there is no answer to describe', async () => {
      fetchers.fetchAdoption.mockResolvedValueOnce({ features: [] })
      fetchers.fetchAdoption.mockRejectedValueOnce(new Error('boom'))
      const store = useUxStore()
      await store.load('adoption', { device: 'mobile' })
      expect(store.notes('adoption')).toHaveLength(1)
      await store.load('adoption', { device: 'mobile' })
      expect(store.notes('adoption')).toEqual([])
    })

    it('gives the outcomes their own words for Signed in', async () => {
      fetchers.fetchOutcomes.mockResolvedValue({
        flows: [],
        filters: { device: '', signed_in: '' },
        ignored: ['signed_in'],
      })
      const store = useUxStore()
      await store.load('outcomes', { signed_in: '1' })
      expect(store.notes('outcomes')).toEqual([
        'Started and finished count everyone. Signed in changes the steps only.',
      ])
    })
  })

  describe('requested', () => {
    it('says what a slot was last asked with, and nothing for one never asked', async () => {
      fetchers.fetchFunnels.mockResolvedValue({})
      const store = useUxStore()
      expect(store.requested('funnels')).toEqual({})
      await store.load('funnels', { start: 'a', end: 'b', device: 'app' })
      expect(store.requested('funnels')).toEqual({
        start: 'a',
        end: 'b',
        device: 'app',
      })
    })

    it('does not hand out the object the store keeps', async () => {
      fetchers.fetchFunnels.mockResolvedValue({})
      const store = useUxStore()
      const asked = { start: 'a' }
      await store.load('funnels', asked)
      asked.start = 'changed'
      expect(store.requested('funnels')).toEqual({ start: 'a' })
    })

    it('is empty again after forget()', async () => {
      fetchers.fetchFunnels.mockResolvedValue({})
      const store = useUxStore()
      await store.load('funnels', { start: 'a' })
      store.forget()
      expect(store.requested('funnels')).toEqual({})
    })
  })

  it('a route the server does not have is "missing", not an error', async () => {
    fetchers.fetchSearch.mockRejectedValue(
      Object.assign(new Error('nope'), { response: { status: 404 } })
    )
    const store = useUxStore()
    await store.load('search', {})
    expect(store.search.missing).toBe(true)
    expect(store.search.error).toBeNull()
  })

  it('keeps the newest answer when a slower earlier one arrives last', async () => {
    let first
    fetchers.fetchFunnels.mockReturnValueOnce(new Promise((r) => (first = r)))
    fetchers.fetchFunnels.mockResolvedValueOnce({ flows: ['new'] })
    const store = useUxStore()
    const a = store.load('funnels', { device: 'app' })
    await store.load('funnels', { device: 'web' })
    first({ flows: ['old'] })
    await a
    expect(store.funnels.data).toEqual({ flows: ['new'] })
    expect(store.requested('funnels')).toEqual({ device: 'web' })
  })

  it('loads samples for a date, flow and step', async () => {
    fetchers.fetchSamples.mockResolvedValue({ sessions: [] })
    const store = useUxStore()
    await store.load('samples', {
      date: '2026-10-07',
      flow: 'post',
      step: 'photos',
    })
    expect(fetchers.fetchSamples).toHaveBeenCalledWith({
      date: '2026-10-07',
      flow: 'post',
      step: 'photos',
    })
    expect(store.samples.data).toEqual({ sessions: [] })
  })
})
