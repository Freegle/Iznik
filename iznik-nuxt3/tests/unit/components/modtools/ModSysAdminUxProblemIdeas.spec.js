import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxProblemIdeas from '~/modtools/components/ModSysAdminUxProblemIdeas.vue'
import { useUxIdeasStore } from '~/modtools/stores/uxIdeas'

const idea = (id, title, status, score, experiment = null) => ({
  id,
  flow: 'give',
  title,
  status,
  score,
  type: 'experiment',
  experiment,
})

const IDEAS = [
  idea(3, 'Move the skip link', 'open', 4.5),
  idea(5, 'Retry failed uploads', 'running', 2, {
    id: 4,
    name: 'give-retry',
    title: 'Retry a failed photo',
    status: 'running',
  }),
]

describe('ModSysAdminUxProblemIdeas', () => {
  let store

  beforeEach(() => {
    setActivePinia(createPinia())
    store = useUxIdeasStore()
    store.loadForProblem = vi.fn().mockImplementation(async (id) => {
      store.forProblem[id] = {
        data: { counts: {}, flows: [], ideas: IDEAS },
        loading: false,
        error: null,
        missing: false,
      }
    })
  })

  const make = (problem = { id: 12, title: 'Photos will not upload' }) =>
    mount(ModSysAdminUxProblemIdeas, { props: { problem } })

  it('asks for the ideas of the problem it is drawn for', async () => {
    make()
    await flushPromises()
    expect(store.loadForProblem).toHaveBeenCalledTimes(1)
    expect(store.loadForProblem).toHaveBeenCalledWith(12)
  })

  it('draws a heading and a line for each idea', async () => {
    const w = make()
    await flushPromises()
    expect(w.find('.heading').text()).toBe('Ideas for this problem:')
    const lines = w.findAll('.idea')
    expect(lines).toHaveLength(2)
    expect(lines[0].find('.title').text()).toBe('Move the skip link')
    expect(lines[0].find('.status').text()).toBe('Waiting')
    expect(lines[0].find('.score').text()).toBe('Score 4.5')
    expect(lines[0].find('.experiment').exists()).toBe(false)
  })

  it('names the experiment an idea became, with its status in words', async () => {
    const w = make()
    await flushPromises()
    const second = w.findAll('.idea')[1]
    expect(second.find('.status').text()).toBe('Being tried')
    expect(second.find('.experiment').text()).toBe(
      'Experiment: Retry a failed photo, Running.'
    )
  })

  it('words an adopted experiment as the Experiments section does, kept for now while its holdout lasts', async () => {
    const adopted = (holdout) => ({
      id: 4,
      name: 'give-retry',
      title: 'Retry a failed photo',
      status: 'adopted',
      holdout_until: holdout,
    })
    store.loadForProblem = vi.fn().mockImplementation(async (id) => {
      store.forProblem[id] = {
        data: {
          counts: {},
          flows: [],
          ideas: [
            idea(
              5,
              'In the holdout',
              'done',
              2,
              adopted('2026-10-30 09:00:00')
            ),
            idea(6, 'Out of it', 'done', 1, adopted(null)),
          ],
        },
        loading: false,
        error: null,
        missing: false,
      }
    })
    const w = make()
    await flushPromises()
    const lines = w.findAll('.idea .experiment').map((e) => e.text())
    expect(lines).toEqual([
      'Experiment: Retry a failed photo, Kept for now.',
      'Experiment: Retry a failed photo, Kept.',
    ])
  })

  it('keeps the order the route sent, which is best score first', async () => {
    const w = make()
    await flushPromises()
    expect(w.findAll('.idea .title').map((t) => t.text())).toEqual([
      'Move the skip link',
      'Retry failed uploads',
    ])
  })

  describe('draws nothing at all, not even the heading', () => {
    it('when the problem has no ideas', async () => {
      store.loadForProblem = vi.fn().mockImplementation(async (id) => {
        store.forProblem[id] = {
          data: { counts: {}, flows: [], ideas: [] },
          loading: false,
          error: null,
          missing: false,
        }
      })
      const w = make()
      await flushPromises()
      expect(w.html()).toBe('<!--v-if-->')
      expect(w.text()).toBe('')
    })

    it('while they load', () => {
      store.loadForProblem = vi.fn().mockResolvedValue(undefined)
      const w = make()
      expect(w.text()).toBe('')
    })

    it('when the route is not there yet', async () => {
      store.loadForProblem = vi.fn().mockImplementation(async (id) => {
        store.forProblem[id] = {
          data: null,
          loading: false,
          error: null,
          missing: true,
        }
      })
      const w = make()
      await flushPromises()
      expect(w.text()).toBe('')
      expect(w.find('.heading').exists()).toBe(false)
    })

    it('when asking failed', async () => {
      store.loadForProblem = vi.fn().mockImplementation(async (id) => {
        store.forProblem[id] = {
          data: null,
          loading: false,
          error: 'boom',
          missing: false,
        }
      })
      const w = make()
      await flushPromises()
      expect(w.text()).toBe('')
    })

    it('for an answer that has no ideas list at all', async () => {
      store.loadForProblem = vi.fn().mockImplementation(async (id) => {
        store.forProblem[id] = {
          data: {},
          loading: false,
          error: null,
          missing: false,
        }
      })
      const w = make()
      await flushPromises()
      expect(w.text()).toBe('')
    })
  })

  it('asks again for another problem when the same component is shown for one', async () => {
    const w = make()
    await flushPromises()
    await w.setProps({ problem: { id: 13, title: 'Another problem' } })
    expect(store.loadForProblem).toHaveBeenLastCalledWith(13)
    expect(store.loadForProblem).toHaveBeenCalledTimes(2)
    // It does not ask again when the same problem comes back with a new title.
    await w.setProps({
      problem: { id: 13, title: 'Another problem, retitled' },
    })
    expect(store.loadForProblem).toHaveBeenCalledTimes(2)
  })

  it('draws the ideas of its own problem and not another’s', async () => {
    store.forProblem[99] = {
      data: { ideas: [idea(8, 'Belongs to another problem', 'open', 1)] },
      loading: false,
      error: null,
      missing: false,
    }
    const w = make()
    await flushPromises()
    expect(w.text()).not.toContain('Belongs to another problem')
    expect(w.text()).toContain('Move the skip link')
  })
})
