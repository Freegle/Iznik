import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const fetchers = {
  fetchProblemGroups: vi.fn(),
  fetchProblems: vi.fn(),
  fetchValues: vi.fn(),
  fetchQuestions: vi.fn(),
}

// The store follows modtools/stores/ux.js: it asks ~/api for the class, and has no init().
vi.mock('~/api', () => ({
  default: () => ({ uxsurveys: fetchers }),
}))

const GROUPS = { answers: 120, left_over: 4, groups: [] }
const LIST = (n, total = n) => ({
  total,
  problems: Array.from({ length: n }, (_, i) => ({
    id: i + 1,
    title: `P${i}`,
  })),
})

// A request whose answer is given by hand, so two can be in flight together.
const later = () => {
  let resolve
  let reject
  const promise = new Promise((res, rej) => {
    resolve = res
    reject = rej
  })
  return { promise, resolve, reject }
}

describe('uxSurveys store', () => {
  let useUxSurveysStore

  beforeEach(async () => {
    vi.clearAllMocks()
    fetchers.fetchProblemGroups.mockResolvedValue(GROUPS)
    fetchers.fetchProblems.mockResolvedValue(LIST(2))
    fetchers.fetchValues.mockResolvedValue({ values: [] })
    fetchers.fetchQuestions.mockResolvedValue({ questions: [] })
    setActivePinia(createPinia())
    useUxSurveysStore = (await import('~/modtools/stores/uxSurveys'))
      .useUxSurveysStore
  })

  it('starts with every slot idle, nothing asked for, and no init()', () => {
    const store = useUxSurveysStore()
    for (const k of ['groups', 'problems', 'values', 'questions']) {
      expect(store[k], k).toEqual({
        data: null,
        loading: false,
        error: null,
        missing: false,
      })
    }
    expect(store.problemsFor).toBeNull()
    expect(store.init).toBeUndefined()
  })

  describe('groups', () => {
    it('loads into its slot, and sends a funnel and a search but not a blank one', async () => {
      const store = useUxSurveysStore()
      await store.loadGroups({ flow: 'give', q: "can't" })
      expect(fetchers.fetchProblemGroups).toHaveBeenCalledWith({
        flow: 'give',
        q: "can't",
      })
      expect(store.groups.data).toEqual(GROUPS)

      await store.loadGroups({ flow: '', q: undefined })
      expect(fetchers.fetchProblemGroups).toHaveBeenLastCalledWith({})
    })

    it('is loading while the request is in flight', async () => {
      const wait = later()
      fetchers.fetchProblemGroups.mockReturnValue(wait.promise)
      const store = useUxSurveysStore()
      const p = store.loadGroups()
      expect(store.groups.loading).toBe(true)
      wait.resolve(GROUPS)
      await p
      expect(store.groups.loading).toBe(false)
    })

    it('records a message and clears old data when a load fails', async () => {
      const store = useUxSurveysStore()
      await store.loadGroups()
      fetchers.fetchProblemGroups.mockRejectedValueOnce(new Error('boom'))
      await store.loadGroups()
      expect(store.groups.error).toBe('boom')
      expect(store.groups.data).toBeNull()
    })

    it('says the route is missing, and no fault, for a 404 and for a 501', async () => {
      const store = useUxSurveysStore()
      for (const status of [404, 501]) {
        fetchers.fetchProblemGroups.mockRejectedValueOnce({
          response: { status },
        })
        await store.loadGroups()
        expect(store.groups.missing, String(status)).toBe(true)
        expect(store.groups.error, String(status)).toBeNull()
      }
    })

    it('keeps the newest answer when an older request lands last', async () => {
      const slow = later()
      fetchers.fetchProblemGroups.mockReturnValueOnce(slow.promise)
      const store = useUxSurveysStore()
      const first = store.loadGroups({ flow: 'give' })
      fetchers.fetchProblemGroups.mockResolvedValueOnce({
        ...GROUPS,
        answers: 7,
      })
      await store.loadGroups({ flow: 'ask' })
      slow.resolve({ ...GROUPS, answers: 999 })
      await first
      expect(store.groups.data.answers).toBe(7)
    })
  })

  describe('problems', () => {
    it('asks for one group and notes what the list in the slot is for', async () => {
      const store = useUxSurveysStore()
      await store.loadProblems({
        group: 'Photos and posting',
        flow: 'give',
        q: 'photo',
        limit: 30,
      })
      expect(fetchers.fetchProblems).toHaveBeenCalledWith({
        group: 'Photos and posting',
        flow: 'give',
        q: 'photo',
        limit: 30,
      })
      expect(store.problems.data).toEqual(LIST(2))
      expect(store.problemsFor).toEqual({
        group: 'Photos and posting',
        flow: 'give',
        q: 'photo',
        limit: 30,
      })
    })

    it('sends the search as typed, with its apostrophe', async () => {
      const store = useUxSurveysStore()
      await store.loadProblems({ group: 'Other', q: "can't", limit: 30 })
      expect(fetchers.fetchProblems.mock.calls[0][0].q).toBe("can't")
    })

    it('drops the list at once when another group is asked for, so it never shows under the wrong heading', async () => {
      const store = useUxSurveysStore()
      await store.loadProblems({ group: 'Photos and posting', limit: 30 })
      expect(store.problems.data).not.toBeNull()

      const wait = later()
      fetchers.fetchProblems.mockReturnValue(wait.promise)
      const p = store.loadProblems({ group: 'Finding things', limit: 30 })
      expect(store.problems.data).toBeNull()
      expect(store.problemsFor).toBeNull()
      expect(store.problems.loading).toBe(true)
      wait.resolve(LIST(1))
      await p
      expect(store.problemsFor.group).toBe('Finding things')
    })

    it('drops the list when only the funnel or only the search changes', async () => {
      const store = useUxSurveysStore()
      await store.loadProblems({ group: 'Other', limit: 30 })
      fetchers.fetchProblems.mockReturnValue(new Promise(() => {}))
      store.loadProblems({ group: 'Other', flow: 'give', limit: 30 })
      expect(store.problems.data).toBeNull()

      setActivePinia(createPinia())
      const again = useUxSurveysStore()
      fetchers.fetchProblems.mockResolvedValue(LIST(2))
      await again.loadProblems({ group: 'Other', limit: 30 })
      fetchers.fetchProblems.mockReturnValue(new Promise(() => {}))
      again.loadProblems({ group: 'Other', q: 'x', limit: 30 })
      expect(again.problems.data).toBeNull()
    })

    it('keeps the list on screen while a longer one for the same group loads, then replaces it', async () => {
      const store = useUxSurveysStore()
      fetchers.fetchProblems.mockResolvedValueOnce(LIST(30, 45))
      await store.loadProblems({ group: 'Other', limit: 30 })
      expect(store.problems.data.problems).toHaveLength(30)

      const wait = later()
      fetchers.fetchProblems.mockReturnValueOnce(wait.promise)
      const p = store.loadProblems({ group: 'Other', limit: 60 })
      expect(store.problems.data.problems).toHaveLength(30)
      expect(store.problemsFor.limit).toBe(30)
      expect(store.problems.loading).toBe(true)

      wait.resolve(LIST(45, 45))
      await p
      expect(store.problems.data.problems).toHaveLength(45)
      expect(store.problemsFor.limit).toBe(60)
      expect(store.problems.loading).toBe(false)
    })

    it('ignores a late answer for the group that was open before', async () => {
      const slow = later()
      fetchers.fetchProblems.mockReturnValueOnce(slow.promise)
      const store = useUxSurveysStore()
      const first = store.loadProblems({
        group: 'Photos and posting',
        limit: 30,
      })
      fetchers.fetchProblems.mockResolvedValueOnce(LIST(1))
      await store.loadProblems({ group: 'Finding things', limit: 30 })
      slow.resolve(LIST(9))
      await first
      expect(store.problems.data.problems).toHaveLength(1)
      expect(store.problemsFor.group).toBe('Finding things')
    })

    it('does not note a list for a request that failed', async () => {
      fetchers.fetchProblems.mockRejectedValue(new Error('boom'))
      const store = useUxSurveysStore()
      await store.loadProblems({ group: 'Other', limit: 30 })
      expect(store.problems.error).toBe('boom')
      expect(store.problems.data).toBeNull()
      expect(store.problemsFor).toBeNull()
    })
  })

  it('loads the values, which take no params', async () => {
    fetchers.fetchValues.mockResolvedValue({ values: [{ id: 1 }] })
    const store = useUxSurveysStore()
    await store.loadValues()
    expect(fetchers.fetchValues).toHaveBeenCalledWith()
    expect(store.values.data).toEqual({ values: [{ id: 1 }] })
  })

  it('loads the questions, with a kind or a status when one is given', async () => {
    const store = useUxSurveysStore()
    await store.loadQuestions()
    expect(fetchers.fetchQuestions).toHaveBeenLastCalledWith({})
    await store.loadQuestions({ kind: 'awareness', status: 'open' })
    expect(fetchers.fetchQuestions).toHaveBeenLastCalledWith({
      kind: 'awareness',
      status: 'open',
    })
    expect(store.questions.data).toEqual({ questions: [] })
  })

  it('keeps each read in a slot of its own, so one failing leaves the others', async () => {
    fetchers.fetchValues.mockRejectedValue(new Error('values broke'))
    const store = useUxSurveysStore()
    await store.loadGroups()
    await store.loadValues()
    expect(store.values.error).toBe('values broke')
    expect(store.groups.error).toBeNull()
    expect(store.groups.data).toEqual(GROUPS)
  })
})
