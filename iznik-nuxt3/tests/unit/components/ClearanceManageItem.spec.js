import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { vi } from 'vitest'

// Stub the candidate so we can inspect the props the item passes down without
// pulling in its store dependencies.
vi.mock('~/components/ClearanceCandidate', () => ({
  default: {
    name: 'ClearanceCandidate',
    template: '<div class="cc-stub" />',
    props: ['messageId', 'bulkitemid', 'interest', 'otherAllocations'],
  },
}))

import ClearanceManageItem from '~/components/ClearanceManageItem.vue'

const message = {
  id: 1,
  bulkitems: [
    {
      id: 10,
      name: 'Chairs',
      quantity: 10,
      condition: 'Good',
      attachments: [],
      interestcount: 4,
      interest: [
        { userid: 1, quantity: 4, state: 'Reserved' },
        { userid: 2, quantity: 2, state: 'Collected' },
        { userid: 3, quantity: 3, state: 'Interested', cancollect: 'Tue' },
        { userid: 4, quantity: 1, state: 'Interested' },
        { userid: 5, quantity: 1, state: 'Withdrawn' },
        { userid: 6, quantity: 1, state: 'Rejected' },
      ],
    },
    {
      id: 11,
      name: 'Desk',
      quantity: 1,
      attachments: [],
      interest: [{ userid: 1, quantity: 1, state: 'Reserved' }],
    },
  ],
}

const mountItem = (item) =>
  mount(ClearanceManageItem, {
    props: { message, item, index: 0 },
    global: {
      stubs: {
        // Render slot content so badge/button text appears in w.text().
        'b-badge': { template: '<span class="badge-stub"><slot /></span>' },
        'b-button': { template: '<button class="btn-stub"><slot /></button>' },
        'b-progress': true,
        'b-progress-bar': true,
      },
    },
  })

describe('ClearanceManageItem', () => {
  it('groups interest into allocated, pool and inactive', () => {
    const w = mountItem(message.bulkitems[0])
    expect(w.vm.allocatedRows.map((r) => r.userid).sort()).toEqual([1, 2])
    expect(w.vm.poolRows.map((r) => r.userid).sort()).toEqual([3, 4])
    expect(w.vm.inactiveRows.map((r) => r.userid).sort()).toEqual([5, 6])
  })

  it('computes allocated and remaining quantities', () => {
    const w = mountItem(message.bulkitems[0])
    expect(w.vm.allocated).toBe(6) // 4 reserved + 2 collected
    expect(w.vm.remaining).toBe(4) // of 10
  })

  it('orders the pool with collection-time givers first', () => {
    const w = mountItem(message.bulkitems[0])
    // user 3 gave a time, user 4 didn't.
    expect(w.vm.poolRows[0].userid).toBe(3)
  })

  it('flags items a candidate is already allocated elsewhere', () => {
    const w = mountItem(message.bulkitems[0])
    // user 1 is Reserved on the Desk (item 11) too.
    expect(w.vm.otherAllocationsFor(1)).toEqual(['Desk'])
    // user 3 isn't allocated anywhere else.
    expect(w.vm.otherAllocationsFor(3)).toEqual([])
  })

  it('shows a fully-allocated state when nothing remains', () => {
    const w = mountItem(message.bulkitems[1]) // Desk: 1 of 1 reserved
    expect(w.vm.remaining).toBe(0)
    expect(w.text()).toContain('Fully allocated')
  })

  it('renders nothing-yet text when there is no interest', () => {
    const w = mountItem({
      id: 12,
      name: 'Empty',
      quantity: 5,
      attachments: [],
      interest: [],
    })
    expect(w.vm.activeRows).toEqual([])
    expect(w.text()).toContain("No-one's asked for this yet")
  })
})
