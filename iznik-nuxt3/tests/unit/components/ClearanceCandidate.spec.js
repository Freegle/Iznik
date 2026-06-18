import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const h = vi.hoisted(() => ({
  bulkInterestState: vi.fn().mockResolvedValue({}),
}))

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({ bulkInterestState: h.bulkInterestState }),
}))
vi.mock('~/stores/user', () => ({
  useUserStore: () => ({
    byId: (id) => (id === 7 ? { displayname: 'Sam' } : null),
  }),
}))
vi.mock('~/components/UserRatings', () => ({
  default: {
    name: 'UserRatings',
    template: '<span class="ur-stub" />',
    props: ['id'],
  },
}))

import ClearanceCandidate from '~/components/ClearanceCandidate.vue'

const mountOpts = {
  global: {
    stubs: { 'b-button': true, 'b-badge': true },
  },
}

const mountRow = (interest, otherAllocations = []) =>
  mount(ClearanceCandidate, {
    ...mountOpts,
    props: { messageId: 1, bulkitemid: 10, interest, otherAllocations },
  })

describe('ClearanceCandidate', () => {
  beforeEach(() => h.bulkInterestState.mockClear())

  it('shows the display name from the user store (fallback when unknown)', () => {
    const known = mountRow({ userid: 7, quantity: 2, state: 'Interested' })
    expect(known.vm.displayName).toBe('Sam')
    const unknown = mountRow({ userid: 99, quantity: 1, state: 'Interested' })
    expect(unknown.vm.displayName).toBe('Freegler')
  })

  it('offers allocate/decline for an interested candidate', () => {
    const w = mountRow({ userid: 7, quantity: 2, state: 'Interested' })
    expect(w.vm.actions.map((a) => a.state)).toEqual(['Reserved', 'Rejected'])
    expect(w.vm.inactive).toBe(false)
  })

  it('offers collect/un-allocate for an allocated candidate', () => {
    const w = mountRow({ userid: 7, quantity: 2, state: 'Reserved' })
    expect(w.vm.actions.map((a) => a.state)).toEqual([
      'Collected',
      'Interested',
    ])
  })

  it('treats withdrawn as inactive with no actions', () => {
    const w = mountRow({ userid: 7, quantity: 0, state: 'Withdrawn' })
    expect(w.vm.actions).toEqual([])
    expect(w.vm.inactive).toBe(true)
  })

  it('setState calls the store with the right args and emits changed', async () => {
    const w = mountRow({ userid: 7, quantity: 2, state: 'Interested' })
    await w.vm.setState('Reserved')
    expect(h.bulkInterestState).toHaveBeenCalledWith(1, 10, 7, 'Reserved')
    expect(w.emitted().changed).toBeTruthy()
    expect(w.emitted().changed[0][0]).toMatchObject({
      userid: 7,
      state: 'Reserved',
    })
  })

  it('renders an "also collecting" hint when allocated elsewhere', () => {
    const w = mountRow({ userid: 7, quantity: 1, state: 'Interested' }, [
      'Desk',
      'Lamp',
    ])
    expect(w.find('[data-testid="also-collecting"]').exists()).toBe(true)
  })
})
