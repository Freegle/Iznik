import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSupportLockdownHistory from '~/modtools/components/ModSupportLockdownHistory.vue'

// plans/active/2026-09-27-lockdown-switch.md section 10.9/10.10/11.2 (GET
// /modtools/lockdown/history - "last 50 rows"). Real shape confirmed against
// the Go handler (iznik-server-go/lockdown/handlers.go, GetModtoolsLockdownHistory):
// a bare array, one row PER CHANGE, not one row per incident - an incident
// that presses, has its notice edited and is then lifted is three rows
// sharing one incidentid. `created` is the one field every row always has;
// `startedby`/`startedat` are carried forward onto every row of an incident
// once it is pressed (writeStateRow copies `next := fresh`), so they are not
// unique to a "start" row, and `endedby`/`endedbyname`/`endedat`/`endnote`
// are only non-null on the row that closed the incident - null on every row
// before that, including every row of an incident still active. A row is
// identified by its own `id`; `incidentid` repeats across an incident's rows
// so it cannot be used as a v-for key (rows would collide).
describe('ModSupportLockdownHistory', () => {
  const history = [
    {
      id: 30,
      incidentid: 28,
      reason: 'Phishing wave via chat',
      notice: null,
      changedby: 1,
      created: '2026-09-20T14:00:00Z',
      startedby: 1,
      startedat: '2026-09-20T10:00:00Z',
      endedby: 1,
      endedbyname: 'Jane Mod',
      endedat: '2026-09-20T14:00:00Z',
      endnote: 'All clear, spam senders banned',
    },
    {
      id: 29,
      incidentid: 28,
      reason: null,
      notice: 'security',
      changedby: 2,
      created: '2026-09-20T11:00:00Z',
      startedby: 1,
      startedat: '2026-09-20T10:00:00Z',
      endedby: 0,
      endedbyname: '',
      endedat: null,
      endnote: null,
    },
    {
      id: 28,
      incidentid: 28,
      reason: 'Phishing wave via chat',
      notice: null,
      changedby: 1,
      created: '2026-09-20T10:00:00Z',
      startedby: 1,
      startedat: '2026-09-20T10:00:00Z',
      endedby: 0,
      endedbyname: '',
      endedat: null,
      endnote: null,
    },
  ]

  function createWrapper(props = {}) {
    return mount(ModSupportLockdownHistory, {
      props: { history, ...props },
    })
  }

  it('renders one row per change, not one row per incident', () => {
    const wrapper = createWrapper()
    const rows = wrapper.findAll('[data-testid="lockdown-history-row"]')
    // All three rows share incidentid 28, and must all still render.
    expect(rows).toHaveLength(3)
  })

  it('shows when the change happened from created, and who by', () => {
    const wrapper = createWrapper()
    const rows = wrapper.findAll('[data-testid="lockdown-history-row"]')
    expect(rows[0].text()).toContain('Sep 20, 2026')
    expect(rows[0].text()).toContain('#1')
    expect(rows[1].text()).toContain('#2')
  })

  it('never shows Invalid Date for a row that has not ended', () => {
    const wrapper = createWrapper()
    const rows = wrapper.findAll('[data-testid="lockdown-history-row"]')
    // Rows 1 and 2 belong to a still-active incident: endedat is null on
    // both, even though startedat is set on every row of the incident.
    expect(rows[1].text()).not.toContain('Invalid Date')
    expect(rows[2].text()).not.toContain('Invalid Date')
  })

  it('shows who ended it and the end note only on the row that closed it', () => {
    const wrapper = createWrapper()
    const rows = wrapper.findAll('[data-testid="lockdown-history-row"]')
    expect(rows[0].text()).toContain('Jane Mod')
    expect(rows[0].text()).toContain('All clear, spam senders banned')
    expect(rows[1].text()).not.toContain('Jane Mod')
    expect(rows[2].text()).not.toContain('Jane Mod')
  })

  it('falls back to the notice when a row has no reason of its own', () => {
    const wrapper = createWrapper()
    const rows = wrapper.findAll('[data-testid="lockdown-history-row"]')
    expect(rows[1].text()).toContain('security')
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
