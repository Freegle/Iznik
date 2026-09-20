import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import ClearanceManageItem from '~/components/ClearanceManageItem.vue'

vi.mock('~/components/ClearanceCandidate', () => ({
  default: {
    name: 'ClearanceCandidate',
    template: '<div class="cc-stub" />',
    props: [
      'messageId',
      'bulkitemid',
      'interest',
      'otherAllocations',
      'helperState',
      'score',
      'aiSent',
    ],
  },
}))

const message = {
  id: 1,
  bulkitems: [
    {
      id: 10,
      name: 'Chairs',
      quantity: 10,
      attachments: [],
      interestcount: 3,
      interest: [
        { userid: 3, quantity: 3, state: 'Interested', cancollect: 'Tue' },
        { userid: 4, quantity: 1, state: 'Interested' },
        { userid: 7, quantity: 1, state: 'Interested' },
      ],
    },
  ],
}

// Helper overlay: user 3 QUALIFIED with a high score, user 4 GATHERING (outreach),
// user 7 QUALIFIED with a lower score. User 3 has been messaged by the Helper.
const helperByUser = {
  3: {
    id: 30,
    userid: 3,
    state: 'QUALIFIED',
    item_states: [{ bulkitemid: 10, state: 'QUALIFIED', score: 90 }],
  },
  4: {
    id: 40,
    userid: 4,
    state: 'GATHERING',
    item_states: [{ bulkitemid: 10, state: 'GATHERING', score: null }],
  },
  7: {
    id: 70,
    userid: 7,
    state: 'QUALIFIED',
    item_states: [{ bulkitemid: 10, state: 'QUALIFIED', score: 50 }],
  },
}
const sentUsers = new Set([3])

const mountItem = () =>
  mount(ClearanceManageItem, {
    props: {
      message,
      item: message.bulkitems[0],
      index: 0,
      helperByUser,
      sentUsers,
    },
    global: {
      stubs: {
        'b-badge': { template: '<span class="badge-stub"><slot /></span>' },
        'b-button': { template: '<button class="btn-stub"><slot /></button>' },
        'b-progress': true,
        'b-progress-bar': true,
      },
    },
  })

describe('ClearanceManageItem — Helper overlay', () => {
  it('splits the pool into decision (QUALIFIED) and outreach (GATHERING) groups', () => {
    const w = mountItem()
    expect(w.vm.decisionRows.map((r) => r.userid).sort()).toEqual([3, 7])
    expect(w.vm.outreachRows.map((r) => r.userid)).toEqual([4])
    expect(w.vm.needsYouRows).toEqual([])
  })

  it('surfaces an escalated candidate into the needs-you group with its reason', () => {
    const esc = {
      id: 80,
      userid: 8,
      state: 'ESCALATED',
      escalation_reason: 'Asked for photos',
      item_states: [{ bulkitemid: 10, state: 'ESCALATED', score: null }],
    }
    const w = mount(ClearanceManageItem, {
      props: {
        message: {
          id: 1,
          bulkitems: [
            {
              id: 10,
              name: 'Chairs',
              quantity: 10,
              attachments: [],
              interest: [{ userid: 8, quantity: 1, state: 'Interested' }],
            },
          ],
        },
        item: {
          id: 10,
          name: 'Chairs',
          quantity: 10,
          attachments: [],
          interest: [{ userid: 8, quantity: 1, state: 'Interested' }],
        },
        index: 0,
        helperByUser: { 8: esc },
        sentUsers: new Set(),
      },
      global: {
        stubs: {
          'b-badge': { template: '<span><slot /></span>' },
          'b-button': { template: '<button><slot /></button>' },
          'b-progress': true,
          'b-progress-bar': true,
        },
      },
    })
    expect(w.vm.needsYouRows.map((r) => r.userid)).toEqual([8])
    expect(w.vm.decisionRows).toEqual([])
    expect(w.vm.noteFor(8)).toBe('Asked for photos')
  })

  it('orders decision candidates by Helper score (highest first)', () => {
    const w = mountItem()
    // user 3 score 90 before user 7 score 50.
    expect(w.vm.decisionRows[0].userid).toBe(3)
    expect(w.vm.decisionRows[1].userid).toBe(7)
  })

  it('exposes per-item FSM states for the summary', () => {
    const w = mountItem()
    expect(w.vm.itemStates.map((s) => s.state).sort()).toEqual([
      'GATHERING',
      'QUALIFIED',
      'QUALIFIED',
    ])
  })

  it('resolves helper state, score and AI-sent per candidate', () => {
    const w = mountItem()
    expect(w.vm.helperStateFor(3)).toBe('QUALIFIED')
    expect(w.vm.scoreFor(3)).toBe(90)
    expect(w.vm.aiSentFor(3)).toBe(true)
    expect(w.vm.aiSentFor(4)).toBe(false)
  })

  it('keeps the outreach group collapsed until toggled', () => {
    const w = mountItem()
    expect(w.vm.showOutreach).toBe(false)
    expect(w.find('[data-testid="toggle-outreach"]').exists()).toBe(true)
  })
})
