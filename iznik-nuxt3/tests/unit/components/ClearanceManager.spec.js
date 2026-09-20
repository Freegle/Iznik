import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import ClearanceManager from '~/components/ClearanceManager.vue'

const h = vi.hoisted(() => ({
  myid: 99,
  fetch: vi.fn().mockResolvedValue({}),
  fetchMultiple: vi.fn().mockResolvedValue([]),
}))

const defaultItems = () => [
  {
    id: 10,
    position: 0,
    name: 'Chairs',
    quantity: 4,
    interest: [
      { userid: 1, quantity: 4, state: 'Reserved' },
      { userid: 2, quantity: 1, state: 'Interested' },
      { userid: 3, quantity: 1, state: 'Withdrawn' }, // inactive → not counted
    ],
  },
  {
    id: 11,
    position: 1,
    name: 'Desk',
    quantity: 2,
    interest: [{ userid: 2, quantity: 1, state: 'Interested' }],
  },
]

const mockMessage = {
  id: 1,
  subject: 'Office clearance',
  fromuser: 99,
  bulkcount: 2,
  bulkitems: defaultItems(),
}

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({ byId: () => mockMessage, fetch: h.fetch }),
}))
vi.mock('~/stores/user', () => ({
  useUserStore: () => ({ fetchMultiple: h.fetchMultiple, byId: () => null }),
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
vi.mock('~/components/NoticeMessage', () => ({
  default: {
    name: 'NoticeMessage',
    template: '<div class="notice-stub"><slot /></div>',
  },
}))
vi.mock('~/components/ClearanceManageItem', () => ({
  default: {
    name: 'ClearanceManageItem',
    template: '<div class="item-stub" />',
    props: ['message', 'item', 'index'],
  },
}))

const mountOpts = {
  props: { id: 1 },
  global: { stubs: { 'b-spinner': true, 'b-form-textarea': true } },
}

describe('ClearanceManager', () => {
  beforeEach(() => {
    h.myid = 99
    h.fetch.mockClear()
    h.fetchMultiple.mockClear()
    mockMessage.fromuser = 99
    mockMessage.bulkcount = 2
    mockMessage.bulkitems = defaultItems()
  })

  it('lets the offerer manage their own clearance', () => {
    const w = mount(ClearanceManager, mountOpts)
    expect(w.vm.canManage).toBe(true)
    expect(w.vm.isClearance).toBe(true)
    expect(w.vm.items.map((i) => i.id)).toEqual([10, 11]) // sorted by position
  })

  it('counts distinct interested people and fully-allocated items', () => {
    const w = mount(ClearanceManager, mountOpts)
    // Active interest across items: users 1 and 2 (user 3 withdrew).
    expect(w.vm.peopleInterested).toBe(2)
    // Chairs: 4 of 4 reserved → full. Desk: 0 of 2 → not full.
    expect(w.vm.fullyAllocated).toBe(1)
  })

  it('refuses management to a non-owner', () => {
    h.myid = 42
    const w = mount(ClearanceManager, mountOpts)
    expect(w.vm.canManage).toBe(false)
    expect(w.find('.notice-stub').exists()).toBe(true)
  })

  it('treats a post with no bulk items as not a clearance', () => {
    mockMessage.bulkcount = 0
    mockMessage.bulkitems = []
    const w = mount(ClearanceManager, mountOpts)
    expect(w.vm.isClearance).toBe(false)
  })

  it('loads the message then ALL interested users on mount (incl. withdrawn, for names)', async () => {
    mount(ClearanceManager, mountOpts)
    await flushPromises()
    expect(h.fetch).toHaveBeenCalledWith(1, true)
    expect(h.fetchMultiple).toHaveBeenCalledTimes(1)
    // Users 1 (Reserved) + 2 (Interested) + 3 (Withdrawn) — withdrawn included
    // so their name renders in the declined/withdrawn section.
    expect(h.fetchMultiple.mock.calls[0][0].sort()).toEqual([1, 2, 3])
  })
})
