import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'

const bulkInterest = vi.fn().mockResolvedValue({})

const mockMessage = {
  id: 1,
  fromuser: 99,
  successful: false,
  bulkitems: [
    {
      id: 10,
      name: 'Desk',
      quantity: 4,
      condition: 'Good',
      attachments: [],
      interestcount: 0,
      interestedquantity: 0,
      yourinterest: null,
    },
    {
      id: 11,
      name: 'Chair',
      quantity: 14,
      condition: 'Used',
      attachments: [],
      interestcount: 1,
      interestedquantity: 2,
      yourinterest: { quantity: 2, state: 'Interested', cancollect: 'Tuesday' },
    },
  ],
}

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    byId: () => mockMessage,
    bulkInterest,
  }),
}))

vi.mock('~/components/NumberIncrementDecrement', () => ({
  default: {
    name: 'NumberIncrementDecrement',
    template: '<input class="num-stub" />',
    props: ['modelValue', 'min', 'max', 'size', 'label', 'labelSROnly'],
  },
}))
vi.mock('~/components/SpinButton', () => ({
  default: { name: 'SpinButton', template: '<button class="spin-stub" />' },
}))
vi.mock('~/components/NoticeMessage', () => ({
  default: { name: 'NoticeMessage', template: '<div class="notice-stub"><slot /></div>' },
}))

import BulkItemsInterest from '~/components/BulkItemsInterest.vue'

// Auto-stub the bootstrap-vue-next components not already stubbed by the global
// test setup (which throws on unresolved components).
const mountOpts = {
  props: { id: 1 },
  global: {
    stubs: {
      'b-badge': true,
      'b-form-checkbox': true,
      'b-form-checkbox-group': true,
      'b-form-group': true,
      'b-form-input': true,
    },
  },
}

describe('BulkItemsInterest', () => {
  beforeEach(() => {
    bulkInterest.mockClear()
    globalThis.__mockAuthStore = { user: { id: 5 }, forceLogin: false }
    mockMessage.bulkslots = [] // most tests have no fixed collection windows
  })

  it('seeds picks from existing interest', () => {
    const w = mount(BulkItemsInterest, mountOpts)
    expect(w.vm.picks[11].checked).toBe(true)
    expect(w.vm.picks[11].quantity).toBe(2)
    expect(w.vm.picks[10].checked).toBe(false)
  })

  it('buildPayload includes checked items and withdrawals', async () => {
    const w = mount(BulkItemsInterest, mountOpts)
    // Newly tick the desk, leave the chair as-is.
    w.vm.picks[10].checked = true
    w.vm.picks[10].quantity = 3
    await nextTick()
    const payload = w.vm.buildPayload()
    const byId = Object.fromEntries(payload.map((p) => [p.bulkitemid, p]))
    expect(byId[10].quantity).toBe(3)
    expect(byId[11].quantity).toBe(2)
  })

  it('buildPayload withdraws an item that was unticked', async () => {
    const w = mount(BulkItemsInterest, mountOpts)
    w.vm.picks[11].checked = false
    await nextTick()
    const payload = w.vm.buildPayload()
    const chair = payload.find((p) => p.bulkitemid === 11)
    expect(chair).toBeTruthy()
    expect(chair.quantity).toBe(0)
  })

  it('submitting while logged out triggers login instead of an API call', async () => {
    globalThis.__mockAuthStore = { user: null, forceLogin: false }
    const w = mount(BulkItemsInterest, mountOpts)
    await w.vm.submit()
    expect(bulkInterest).not.toHaveBeenCalled()
    expect(globalThis.__mockAuthStore.forceLogin).toBe(true)
  })

  it('submitting while logged in calls the store with the payload', async () => {
    const w = mount(BulkItemsInterest, mountOpts)
    w.vm.picks[10].checked = true
    w.vm.picks[10].quantity = 1
    await nextTick()
    await w.vm.submit()
    expect(bulkInterest).toHaveBeenCalledTimes(1)
    const [id, items] = bulkInterest.mock.calls[0]
    expect(id).toBe(1)
    expect(items.some((i) => i.bulkitemid === 10)).toBe(true)
  })

  it('cannot register until at least one item is turned on', async () => {
    const w = mount(BulkItemsInterest, mountOpts)
    w.vm.picks[11].checked = false // nothing on
    await nextTick()
    expect(w.vm.canRegister).toBe(false)
    expect(w.vm.registerHint).toContain('at least one item')
    w.vm.picks[11].checked = true
    await nextTick()
    expect(w.vm.canRegister).toBe(true)
  })

  it('with fixed windows, needs at least one ticked and joins them all', async () => {
    mockMessage.bulkslots = ['Tue 10–4', 'Wed 10–4']
    const w = mount(BulkItemsInterest, mountOpts)
    w.vm.picks[11].checked = true
    w.vm.picks[11].quantity = 1
    await nextTick()
    // An item is on, but no collection time chosen yet → blocked.
    expect(w.vm.canRegister).toBe(false)
    expect(w.vm.registerHint).toContain('collection time')
    // Tick all the times they can make.
    w.vm.cancollectTimes = ['Tue 10–4', 'Wed 10–4']
    await nextTick()
    expect(w.vm.canRegister).toBe(true)
    const chair = w.vm.buildPayload().find((p) => p.bulkitemid === 11)
    expect(chair.cancollect).toBe('Tue 10–4; Wed 10–4')
  })
})
