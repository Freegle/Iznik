import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

// Mutable myid so we can mount as the offerer, the replier, or a bystander.
const h = vi.hoisted(() => ({
  myid: 99,
  bulkInterest: vi.fn().mockResolvedValue({}),
}))

const mockMessage = {
  id: 1,
  fromuser: 99, // the offerer
  bulkitems: [
    {
      id: 10,
      name: 'Desk',
      quantity: 4,
      attachments: [],
      yourinterest: null,
      // Replier 7 previously withdrew the desk.
      interest: [{ userid: 7, quantity: 0, state: 'Withdrawn' }],
    },
    {
      id: 11,
      name: 'Chair',
      quantity: 14,
      attachments: [],
      // From replier 7's own perspective.
      yourinterest: { quantity: 2, state: 'Interested', cancollect: 'Tue' },
      // From the offerer's perspective (full list).
      interest: [
        { userid: 7, quantity: 3, state: 'Interested', cancollect: 'Tue' },
      ],
    },
  ],
}

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    byId: () => mockMessage,
    bulkInterest: h.bulkInterest,
  }),
}))
vi.mock('~/stores/user', () => ({
  useUserStore: () => ({ byId: () => ({ displayname: 'Sam' }) }),
}))
vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    myid: {
      get value() {
        return h.myid
      },
    },
  }),
}))

import BulkInterestEditor from '~/components/BulkInterestEditor.vue'

const mountOpts = {
  global: {
    stubs: {
      'b-form-checkbox': true,
      'b-form-select': true,
      'b-badge': true,
    },
  },
}

const mountAs = (myid, interestuserid) => {
  h.myid = myid
  return mount(BulkInterestEditor, {
    ...mountOpts,
    props: { messageid: 1, interestuserid },
  })
}

describe('BulkInterestEditor', () => {
  beforeEach(() => h.bulkInterest.mockClear())

  it("offerer view seeds from the replier's interest list", () => {
    const w = mountAs(99, 7) // offerer editing replier 7
    expect(w.vm.editable).toBe(true)
    expect(w.vm.editingOwn).toBe(false)
    expect(w.vm.picks[11].checked).toBe(true)
    expect(w.vm.picks[11].quantity).toBe(3)
    expect(w.vm.picks[10].checked).toBe(false) // withdrawn item is off
  })

  it('replier view seeds from their own yourinterest but is read-only', () => {
    const w = mountAs(7, 7) // replier viewing their own interest
    expect(w.vm.editingOwn).toBe(true)
    // The replier's own view is read-only now — they change interest via chat.
    expect(w.vm.editable).toBe(false)
    expect(w.vm.picks[11].checked).toBe(true)
    expect(w.vm.picks[11].quantity).toBe(2)
  })

  it('a bystander (neither offerer nor the replier) cannot edit', () => {
    const w = mountAs(42, 7)
    expect(w.vm.editable).toBe(false)
  })

  it('buildPayload turns items on at quantity and withdraws those turned off', () => {
    const w = mountAs(99, 7)
    w.vm.picks[10].checked = true // turn the desk on
    w.vm.picks[10].quantity = 2
    w.vm.picks[11].checked = false // turn the chair off (was active → withdraw)
    const byId = Object.fromEntries(
      w.vm.buildPayload().map((p) => [p.bulkitemid, p])
    )
    expect(byId[10].quantity).toBe(2)
    expect(byId[11].quantity).toBe(0)
  })

  it('offerer edits record interest against the replier (interestuserid passed)', async () => {
    const w = mountAs(99, 7)
    w.vm.picks[10].checked = true
    w.vm.picks[10].quantity = 1
    await w.vm.onChange({ id: 10 })
    expect(h.bulkInterest).toHaveBeenCalledTimes(1)
    // 3rd arg is the replier's id — the offerer is recording on their behalf.
    expect(h.bulkInterest.mock.calls[0][2]).toBe(7)
  })

  it('replier edits record their own interest (no interestuserid)', async () => {
    const w = mountAs(7, 7)
    w.vm.picks[10].checked = true
    w.vm.picks[10].quantity = 1
    await w.vm.onChange({ id: 10 })
    expect(h.bulkInterest).toHaveBeenCalledTimes(1)
    expect(h.bulkInterest.mock.calls[0][2]).toBeUndefined()
  })
})
