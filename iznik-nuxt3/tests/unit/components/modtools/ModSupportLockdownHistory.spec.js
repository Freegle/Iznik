import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSupportLockdownHistory from '~/modtools/components/ModSupportLockdownHistory.vue'

// plans/active/2026-09-27-lockdown-switch.md section 10.9/10.10/11.2 (GET
// /modtools/lockdown/history - "last 50 rows"). Real shape confirmed against
// the Go handler (iznik-server-go/lockdown/handlers.go): each row has a
// numeric `startedby` but no `startedbyname` - "who started it" is not
// resolved to a name server-side for history rows, unlike `endedbyname`,
// which is. Shown as "#<id>" to match the id-display idiom used elsewhere.
describe('ModSupportLockdownHistory', () => {
  const history = [
    {
      incidentid: 2,
      reason: 'Phishing wave via chat',
      startedat: '2026-09-20T10:00:00Z',
      startedby: 1,
      endedat: '2026-09-20T14:00:00Z',
      endedby: 1,
      endedbyname: 'Jane Mod',
      endnote: 'All clear, spam senders banned',
    },
    {
      incidentid: 1,
      reason: 'Test press',
      startedat: '2026-09-01T09:00:00Z',
      startedby: 2,
      endedat: '2026-09-01T09:05:00Z',
      endedby: 2,
      endedbyname: 'Ed Admin',
      endnote: null,
    },
  ]

  function createWrapper(props = {}) {
    return mount(ModSupportLockdownHistory, {
      props: { history, ...props },
    })
  }

  it('renders one row per incident', () => {
    const wrapper = createWrapper()
    const rows = wrapper.findAll('[data-testid="lockdown-history-row"]')
    expect(rows).toHaveLength(2)
  })

  it('shows reason, who started and ended it, and the end note', () => {
    const wrapper = createWrapper()
    const rows = wrapper.findAll('[data-testid="lockdown-history-row"]')
    expect(rows[0].text()).toContain('Phishing wave via chat')
    expect(rows[0].text()).toContain('#1')
    expect(rows[0].text()).toContain('Jane Mod')
    expect(rows[0].text()).toContain('All clear, spam senders banned')
  })

  it('shows who started it as a plain id, never "undefined"', () => {
    const wrapper = createWrapper()
    const rows = wrapper.findAll('[data-testid="lockdown-history-row"]')
    expect(rows[1].text()).toContain('#2')
    expect(rows[0].text()).not.toContain('undefined')
    expect(rows[1].text()).not.toContain('undefined')
  })

  it('shows something sensible when there is no end note', () => {
    const wrapper = createWrapper()
    const rows = wrapper.findAll('[data-testid="lockdown-history-row"]')
    expect(rows[1].text()).toContain('Ed Admin')
    expect(rows[1].text()).not.toContain('null')
  })

  it('renders nothing alarming with an empty history', () => {
    const wrapper = createWrapper({ history: [] })
    expect(
      wrapper.findAll('[data-testid="lockdown-history-row"]')
    ).toHaveLength(0)
    expect(wrapper.text()).toContain('No previous lockdowns')
  })

  it('renders sensibly when history prop is not supplied', () => {
    const wrapper = mount(ModSupportLockdownHistory)
    expect(
      wrapper.findAll('[data-testid="lockdown-history-row"]')
    ).toHaveLength(0)
  })
})
