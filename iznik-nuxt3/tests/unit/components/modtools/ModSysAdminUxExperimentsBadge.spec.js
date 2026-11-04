import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxExperimentsBadge from '~/modtools/components/ModSysAdminUxExperimentsBadge.vue'
import { useUxExperimentsStore } from '~/modtools/stores/uxExperiments'

const list = (runningCount, flows = []) => ({
  running_count: runningCount,
  max_running: 5,
  flows,
})

describe('ModSysAdminUxExperimentsBadge', () => {
  let store

  beforeEach(() => {
    setActivePinia(createPinia())
    store = useUxExperimentsStore()
    store.loadIfStale = vi.fn().mockResolvedValue(undefined)
  })

  const make = () => mount(ModSysAdminUxExperimentsBadge)

  it('asks for the list when it is drawn, unless a recent one is held', () => {
    make()
    expect(store.loadIfStale).toHaveBeenCalledWith(30000)
  })

  it('says how many are running', () => {
    store.experiments.data = list(1)
    expect(make().text()).toBe('1 running')
    store.experiments.data = list(3)
    expect(make().text()).toBe('3 running')
  })

  it('says nothing when none is running', () => {
    store.experiments.data = list(0)
    expect(make().text()).toBe('')
  })

  it('shows a red number of experiments with a verdict, which wins over the running count', () => {
    store.experiments.data = list(2, [
      {
        flow: 'give',
        running: [{ id: 1, status: 'running' }],
        history: [{ id: 2, status: 'adopted' }],
      },
    ])
    const w = make()
    expect(w.text()).toBe('1')
    expect(w.find('.badge').classes()).toContain('bg-danger')
  })

  it('says in its title what is waiting', () => {
    store.experiments.data = list(0, [
      {
        flow: 'give',
        running: [],
        history: [
          { id: 2, status: 'adopted' },
          { id: 3, status: 'reverted' },
        ],
      },
    ])
    const w = make()
    expect(w.text()).toBe('2')
    expect(w.find('.badge').attributes('title')).toBe(
      '2 experiments have a verdict: 1 to confirm, 1 to remove code'
    )
  })

  it('adds the running count to the title', () => {
    store.experiments.data = list(1, [
      {
        flow: 'give',
        running: [{ id: 1, status: 'running' }],
        history: [{ id: 2, status: 'stopped' }],
      },
    ])
    expect(make().find('.badge').attributes('title')).toBe(
      '1 experiment has a verdict: 1 to remove code. 1 running.'
    )
  })

  it.each(['adopted', 'reverted', 'inconclusive', 'stopped'])(
    'counts an experiment that is %s as soon as it has a verdict, overdue or not',
    (status) => {
      store.experiments.data = list(0, [
        {
          flow: 'give',
          running: [],
          history: [{ id: 2, status, cleanup_overdue: false }],
        },
      ])
      expect(make().text()).toBe('1')
    }
  )

  it('does not count a closed experiment, or one with its cleanup recorded', () => {
    store.experiments.data = list(0, [
      {
        flow: 'give',
        running: [],
        history: [
          { id: 2, status: 'closed' },
          { id: 3, status: 'reverted', cleanup_pr_url: 'https://x/1' },
          { id: 4, status: 'adopted', cleanup_pr_url: 'https://x/2' },
        ],
      },
    ])
    expect(make().text()).toBe('')
  })

  it('draws the running count in blue, with no title', () => {
    store.experiments.data = list(1)
    const badge = make().find('.badge')
    expect(badge.classes()).toContain('ux-blue')
    expect(badge.attributes('title')).toBeUndefined()
  })

  it('draws nothing before the list has come, when the route is missing, or on an error', () => {
    expect(make().text()).toBe('')
    store.experiments.missing = true
    expect(make().text()).toBe('')
    store.experiments.missing = false
    store.experiments.error = 'boom'
    expect(make().text()).toBe('')
  })

  it('draws nothing for a list with no count in it', () => {
    store.experiments.data = {}
    expect(make().text()).toBe('')
  })
})
