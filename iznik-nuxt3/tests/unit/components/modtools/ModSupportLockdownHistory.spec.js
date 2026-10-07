import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSupportLockdownHistory from '~/modtools/components/ModSupportLockdownHistory.vue'

vi.mock('~/composables/useTimeFormat', () => ({
  dateshort: (d) => 'date:' + d,
}))

const ALL_HELD = {
  mods: true,
  chat: true,
  posts: true,
  chitchat: true,
  events: true,
  push: true,
  email: true,
  export: true,
}

// plans/active/2026-09-27-lockdown-switch.md section 11.11: history rows said
// in words - what each row changed, and who did it.
describe('ModSupportLockdownHistory', () => {
  function createWrapper(history) {
    return mount(ModSupportLockdownHistory, { props: { history } })
  }

  function rowTexts(wrapper) {
    return wrapper
      .findAll('[data-testid="lockdown-history-row"]')
      .map((r) => r.findAll('td').map((td) => td.text()))
  }

  it('says so when there are no lockdowns', () => {
    const wrapper = createWrapper([])
    expect(wrapper.text()).toContain('No lockdowns yet.')
  })

  it('describes a press, a lift, a notice change and a close, newest first', () => {
    const wrapper = createWrapper([
      {
        id: 13,
        incidentid: 10,
        active: false,
        surfaces: {},
        notice: '',
        endnote: 'drill over',
        created: 'd13',
        changedbyname: 'Closer',
        endedbyname: 'Closer',
      },
      {
        id: 12,
        incidentid: 10,
        active: true,
        surfaces: { ...ALL_HELD, chat: false },
        notice: 'Back soon.',
        created: 'd12',
        changedbyname: 'Lifter',
      },
      {
        id: 11,
        incidentid: 10,
        active: true,
        surfaces: { ...ALL_HELD, chat: false },
        notice: '',
        created: 'd11',
        changedbyname: 'Lifter',
      },
      {
        id: 10,
        incidentid: 10,
        active: true,
        surfaces: ALL_HELD,
        reason: 'voucher wave',
        notice: '',
        created: 'd10',
        changedbyname: 'Presser',
      },
    ])

    expect(rowTexts(wrapper)).toEqual([
      ['date:d13', 'Closed: drill over', 'Closer'],
      ['date:d12', 'Member notice set', 'Lifter'],
      ['date:d11', 'Lifted Chat between members', 'Lifter'],
      ['date:d10', 'Pressed: voucher wave', 'Presser'],
    ])
  })

  it('describes holding an area again and removing the notice', () => {
    const wrapper = createWrapper([
      {
        id: 3,
        incidentid: 1,
        active: true,
        surfaces: { ...ALL_HELD, chat: true },
        notice: '',
        created: 'd3',
        changedbyname: 'S',
      },
      {
        id: 2,
        incidentid: 1,
        active: true,
        surfaces: { ...ALL_HELD, chat: false },
        notice: 'Back soon.',
        created: 'd2',
        changedbyname: 'S',
      },
    ])
    expect(rowTexts(wrapper)[0][1]).toBe(
      'Held again Chat between members; Member notice removed'
    )
  })

  it('treats a notice set after closing as a notice change, not a second close', () => {
    const wrapper = createWrapper([
      {
        id: 21,
        incidentid: 20,
        active: false,
        surfaces: {},
        notice: 'Things are back to normal.',
        created: 'd21',
        changedbyname: 'S',
      },
      {
        id: 20,
        incidentid: 20,
        active: false,
        surfaces: {},
        notice: '',
        endnote: '',
        created: 'd20',
        changedbyname: 'S',
      },
    ])
    expect(rowTexts(wrapper).map((r) => r[1])).toEqual([
      'Member notice set',
      'Closed',
    ])
  })

  it('does not compare rows from different lockdowns', () => {
    const wrapper = createWrapper([
      {
        id: 30,
        incidentid: 30,
        active: true,
        surfaces: ALL_HELD,
        reason: 'second',
        created: 'd30',
        changedbyname: 'S',
      },
      {
        id: 29,
        incidentid: 25,
        active: false,
        surfaces: {},
        created: 'd29',
        changedbyname: 'S',
      },
    ])
    expect(rowTexts(wrapper)[0][1]).toBe('Pressed: second')
  })
})
