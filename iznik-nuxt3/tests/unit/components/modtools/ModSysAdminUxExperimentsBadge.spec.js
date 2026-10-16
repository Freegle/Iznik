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

  it('says a cleanup is overdue, which wins over the running count', () => {
    store.experiments.data = list(2, [
      {
        flow: 'give',
        running: [{ id: 1, status: 'running' }],
        history: [{ id: 2, status: 'adopted', cleanup_overdue: true }],
      },
    ])
    const w = make()
    expect(w.text()).toBe('Cleanup overdue')
    expect(w.find('.badge').classes()).toContain('bg-danger')
  })

  it('finds an overdue cleanup among the running ones as well', () => {
    store.experiments.data = list(1, [
      { flow: 'ask', running: [{ id: 1, cleanup_overdue: true }], history: [] },
    ])
    expect(make().text()).toBe('Cleanup overdue')
  })

  it('draws the running count in blue', () => {
    store.experiments.data = list(1)
    expect(make().find('.badge').classes()).toContain('ux-blue')
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
