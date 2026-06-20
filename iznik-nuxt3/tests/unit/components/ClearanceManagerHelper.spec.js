import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

const h = vi.hoisted(() => ({
  fetch: vi.fn().mockResolvedValue({}),
  fetchMultiple: vi.fn().mockResolvedValue([]),
  fetchHelper: vi.fn().mockResolvedValue({}),
  helperSetStatus: vi.fn().mockResolvedValue({}),
  helperResolveProposal: vi.fn().mockResolvedValue({}),
  helperState: null,
}))

const mockMessage = {
  id: 1,
  subject: 'Office clearance',
  fromuser: 99,
  bulkcount: 1,
  bulkitems: [
    {
      id: 10,
      position: 0,
      name: 'Chairs',
      quantity: 4,
      interest: [{ userid: 2, quantity: 1, state: 'Interested' }],
    },
  ],
}

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    byId: () => mockMessage,
    fetch: h.fetch,
    helperById: () => h.helperState,
    fetchHelper: h.fetchHelper,
    helperSetStatus: h.helperSetStatus,
    helperResolveProposal: h.helperResolveProposal,
  }),
}))
vi.mock('~/stores/user', () => ({
  useUserStore: () => ({ fetchMultiple: h.fetchMultiple, byId: () => null }),
}))
vi.mock('~/composables/useMe', () => ({
  useMe: () => ({ myid: { get value() { return 99 } } }),
}))
vi.mock('~/components/NoticeMessage', () => ({
  default: { name: 'NoticeMessage', template: '<div class="notice-stub"><slot /></div>' },
}))
vi.mock('~/components/ClearanceManageItem', () => ({
  default: {
    name: 'ClearanceManageItem',
    template: '<div class="item-stub" />',
    props: ['message', 'item', 'index', 'helperByUser', 'sentUsers'],
  },
}))
vi.mock('~/components/HelperStatusBar', () => ({
  default: { name: 'HelperStatusBar', template: '<div class="hsb-stub" />', props: ['batch'] },
}))
vi.mock('~/components/HelperProposalCard', () => ({
  default: { name: 'HelperProposalCard', template: '<div class="hpc-stub" />', props: ['proposal'] },
}))

import ClearanceManager from '~/components/ClearanceManager.vue'

const mountOpts = { props: { id: 1 }, global: { stubs: { 'b-spinner': true } } }

describe('ClearanceManager — Helper integration', () => {
  beforeEach(() => {
    h.fetch.mockClear()
    h.fetchHelper.mockClear()
    h.helperSetStatus.mockClear()
    h.helperResolveProposal.mockClear()
    h.helperState = null
  })

  it('loads helper state on mount', async () => {
    mount(ClearanceManager, mountOpts)
    await flushPromises()
    expect(h.fetchHelper).toHaveBeenCalledWith(1)
  })

  it('exposes the batch, pending proposals, helperByUser and sentUsers', () => {
    h.helperState = {
      batch: { status: 'active' },
      repliers: [
        { id: 20, userid: 2, state: 'QUALIFIED', item_states: [] },
        { id: 30, userid: 3, state: 'GATHERING', item_states: [] },
      ],
      proposals: [
        { id: 1, status: 'pending', type: 'allocation' },
        { id: 2, status: 'sent', type: 'message' },
      ],
      sent: [{ id: 5, replierid: 20 }],
    }
    const w = mount(ClearanceManager, mountOpts)
    expect(w.vm.helperBatch).toEqual({ status: 'active' })
    expect(w.vm.pendingProposals.map((p) => p.id)).toEqual([1]) // only pending
    expect(Object.keys(w.vm.helperByUser).sort()).toEqual(['2', '3'])
    expect(w.vm.sentUsers.has(2)).toBe(true) // replier 20 -> userid 2
    expect(w.vm.sentUsers.has(3)).toBe(false)
  })

  it('pause/resume call the store', async () => {
    h.helperState = { batch: { status: 'active' }, repliers: [], proposals: [], sent: [] }
    const w = mount(ClearanceManager, mountOpts)
    await w.vm.setStatus('paused')
    expect(h.helperSetStatus).toHaveBeenCalledWith(1, 'paused')
  })

  it('resolving a proposal calls the store with msgid, id, decision and text', async () => {
    h.helperState = { batch: { status: 'active' }, repliers: [], proposals: [], sent: [] }
    const w = mount(ClearanceManager, mountOpts)
    await w.vm.onResolve({ id: 7, decision: 'send', text: 'hi' })
    expect(h.helperResolveProposal).toHaveBeenCalledWith(1, 7, 'send', 'hi')
  })

  it('shows no helper UI when the Helper has not been started', () => {
    h.helperState = { batch: null, repliers: [], proposals: [], sent: [] }
    const w = mount(ClearanceManager, mountOpts)
    expect(w.vm.helperBatch).toBe(null)
    expect(w.vm.pendingProposals).toEqual([])
  })
})
