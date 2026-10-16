import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const fetchers = { fetchIdeas: vi.fn() }

// The store follows modtools/stores/ux.js: it asks ~/api for the class, and has no init().
vi.mock('~/api', () => ({
  default: () => ({ uxideas: fetchers }),
}))

const LIST = {
  counts: { open: 1, scheduled: 0, running: 0, done: 0, rejected: 0 },
  flows: [],
  ideas: [{ id: 1, title: 'An idea' }],
}

describe('uxIdeas store', () => {
  let useUxIdeasStore

  beforeEach(async () => {
    vi.clearAllMocks()
    fetchers.fetchIdeas.mockResolvedValue(LIST)
    setActivePinia(createPinia())
    useUxIdeasStore = (await import('~/modtools/stores/uxIdeas'))
      .useUxIdeasStore
  })

  it('starts idle, with nothing loaded, nothing asked and no problem slot', () => {
    const store = useUxIdeasStore()
    expect(store.ideas).toEqual({
      data: null,
      loading: false,
      error: null,
      missing: false,
    })
    expect(store.asked).toBe('')
    expect(store.forProblem).toEqual({})
    expect(store.init).toBeUndefined()
  })

  it('loads the list with the params into its slot, and notes what the answer was asked for', async () => {
    const store = useUxIdeasStore()
    await store.load({ status: 'open', flow: 'give' })
    expect(fetchers.fetchIdeas).toHaveBeenCalledWith({
      status: 'open',
      flow: 'give',
    })
    expect(store.ideas.data).toEqual(LIST)
    expect(store.ideas.loading).toBe(false)
    expect(store.asked).toBe(JSON.stringify({ status: 'open', flow: 'give' }))
  })

  it('asks for everything when it is given no params', async () => {
    const store = useUxIdeasStore()
    await store.load()
    expect(fetchers.fetchIdeas).toHaveBeenCalledWith({})
    expect(store.asked).toBe('{}')
  })

  it('is loading while the request is in flight, and still holds the older answer', async () => {
    const store = useUxIdeasStore()
    await store.load({ status: 'open' })
    let resolve
    fetchers.fetchIdeas.mockReturnValue(new Promise((r) => (resolve = r)))
    const p = store.load({ status: 'done' })
    expect(store.ideas.loading).toBe(true)
    // What the answer in the slot was asked for has not moved on yet, so a section can tell.
    expect(store.asked).toBe(JSON.stringify({ status: 'open' }))
    resolve(LIST)
    await p
    expect(store.ideas.loading).toBe(false)
    expect(store.asked).toBe(JSON.stringify({ status: 'done' }))
  })

  it('lets the newest request win when an older one answers last', async () => {
    const store = useUxIdeasStore()
    let first
    fetchers.fetchIdeas.mockReturnValueOnce(new Promise((r) => (first = r)))
    const slow = store.load({ status: 'open' })
    fetchers.fetchIdeas.mockResolvedValueOnce({ ...LIST, ideas: [{ id: 2 }] })
    await store.load({ status: 'done' })
    first(LIST)
    await slow
    expect(store.ideas.data.ideas).toEqual([{ id: 2 }])
    expect(store.asked).toBe(JSON.stringify({ status: 'done' }))
  })

  it('records a message and clears old data when a load fails, and leaves what was asked as it was', async () => {
    const store = useUxIdeasStore()
    await store.load({ status: 'open' })
    fetchers.fetchIdeas.mockRejectedValueOnce(new Error('boom'))
    await store.load({ status: 'done' })
    expect(store.ideas.error).toBe('boom')
    expect(store.ideas.data).toBeNull()
    expect(store.asked).toBe(JSON.stringify({ status: 'open' }))
  })

  it('says the route is missing, and no fault, for a 404 or a 501', async () => {
    const store = useUxIdeasStore()
    for (const status of [404, 501]) {
      fetchers.fetchIdeas.mockRejectedValueOnce({ response: { status } })
      await store.load()
      expect(store.ideas.missing, String(status)).toBe(true)
      expect(store.ideas.error).toBeNull()
    }
  })

  it('loads the ideas of one problem into that problem’s own slot', async () => {
    const store = useUxIdeasStore()
    await store.loadForProblem(12)
    expect(fetchers.fetchIdeas).toHaveBeenCalledWith({ problem_id: 12 })
    expect(store.forProblem[12].data).toEqual(LIST)
    expect(store.forProblem[12].loading).toBe(false)
    // The list the section draws is untouched.
    expect(store.ideas.data).toBeNull()
  })

  it('keeps a slot for each problem, so one problem’s answer is never another’s', async () => {
    const store = useUxIdeasStore()
    fetchers.fetchIdeas.mockResolvedValueOnce({ ...LIST, ideas: [{ id: 1 }] })
    await store.loadForProblem(12)
    fetchers.fetchIdeas.mockResolvedValueOnce({ ...LIST, ideas: [{ id: 2 }] })
    await store.loadForProblem(13)
    expect(store.forProblem[12].data.ideas).toEqual([{ id: 1 }])
    expect(store.forProblem[13].data.ideas).toEqual([{ id: 2 }])
  })

  it('says a problem’s route is missing in that problem’s slot only', async () => {
    const store = useUxIdeasStore()
    fetchers.fetchIdeas.mockRejectedValueOnce({ response: { status: 404 } })
    await store.loadForProblem(12)
    expect(store.forProblem[12].missing).toBe(true)
    expect(store.ideas.missing).toBe(false)
  })

  it('has no action that changes an idea', () => {
    const store = useUxIdeasStore()
    expect(
      Object.keys(store)
        .filter((k) => typeof store[k] === 'function')
        .sort()
    ).toEqual(expect.arrayContaining(['load', 'loadForProblem']))
    for (const name of ['schedule', 'reject', 'reopen', 'save', 'update']) {
      expect(store[name], name).toBeUndefined()
    }
  })
})
