import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSupportLockdownStats from '~/modtools/components/ModSupportLockdownStats.vue'

// plans/active/2026-09-27-lockdown-switch.md section 11.11: one table, how
// many of each kind of thing have been held so far. Nothing else.
describe('ModSupportLockdownStats', () => {
  function createWrapper(stats) {
    return mount(ModSupportLockdownStats, { props: { stats } })
  }

  function count(wrapper, key) {
    return wrapper.find(`[data-testid="lockdown-count-${key}"]`).text()
  }

  it('shows a row per kind, with its count', () => {
    const wrapper = createWrapper({
      counts: {
        chat: 12,
        post: 3,
        chitchat: 1,
        events: 2,
        email: 400,
        push: 90,
        export: 1,
        refused: 5,
      },
    })
    expect(count(wrapper, 'chat')).toBe('Chat messages12')
    expect(count(wrapper, 'post')).toBe('Posts3')
    expect(count(wrapper, 'chitchat')).toBe('ChitChat posts1')
    expect(count(wrapper, 'events')).toContain('2')
    expect(count(wrapper, 'email')).toBe('Emails not sent400')
    expect(count(wrapper, 'push')).toBe('App notifications not sent90')
    expect(count(wrapper, 'export')).toBe('Downloads refused1')
    expect(count(wrapper, 'refused')).toContain('5')
    expect(wrapper.findAll('tr')).toHaveLength(8)
  })

  it('shows zeros before any stats have arrived', () => {
    const wrapper = createWrapper(null)
    expect(count(wrapper, 'chat')).toBe('Chat messages0')
    expect(count(wrapper, 'email')).toBe('Emails not sent0')
  })

  it('has no triage, samples or release controls', () => {
    const wrapper = createWrapper({ counts: {} })
    expect(wrapper.find('button').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Risky')
    expect(wrapper.text()).not.toContain('Spam')
  })
})
