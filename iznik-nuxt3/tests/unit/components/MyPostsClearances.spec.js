import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

// Full messages in the store, keyed by id. Only id 1 is a clearance.
const store = {
  1: {
    id: 1,
    subject: 'Office clearance',
    bulkcount: 2,
    bulkitems: [
      { interest: [{ userid: 7, state: 'Interested' }] },
      { interest: [{ userid: 8, state: 'Reserved' }] },
    ],
  },
  2: { id: 2, subject: 'Single sofa', bulkcount: 0, bulkitems: [] },
}

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({ byId: (id) => store[id] || null }),
}))

import MyPostsClearances from '~/components/MyPostsClearances.vue'

const mountOpts = (posts) => ({
  props: { posts },
  global: { stubs: { 'b-button': true, 'b-badge': true } },
})

describe('MyPostsClearances', () => {
  it('lists only the posts that are clearances', () => {
    const w = mount(
      MyPostsClearances,
      mountOpts([{ id: 1 }, { id: 2 }, { id: 3 }])
    )
    expect(w.vm.clearances.map((c) => c.id)).toEqual([1])
    expect(w.find('[data-testid="clearance-row-1"]').exists()).toBe(true)
    expect(w.find('[data-testid="clearance-row-2"]').exists()).toBe(false)
  })

  it('surfaces the item and interested counts', () => {
    const w = mount(MyPostsClearances, mountOpts([{ id: 1 }]))
    const c = w.vm.clearances[0]
    expect(c.bulkcount).toBe(2)
    expect(c.interested).toBe(2) // users 7 and 8
    expect(c.subject).toBe('Office clearance')
  })

  it('links each clearance to its management page', () => {
    const w = mount(MyPostsClearances, mountOpts([{ id: 1 }]))
    expect(w.find('[data-testid="manage-1"]').exists()).toBe(true)
  })

  it('renders nothing when the user has no clearances', () => {
    const w = mount(MyPostsClearances, mountOpts([{ id: 2 }]))
    expect(w.find('[data-testid="myposts-clearances"]').exists()).toBe(false)
  })
})
