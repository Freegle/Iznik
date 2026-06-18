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
    byId: (id) => {
      if (id === 7) return { displayname: 'Sam' } // no ratings
      if (id === 8)
        return { displayname: 'Pat', info: { ratings: { Up: 5, Down: 1 } } }
      return null
    },
  }),
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

  it('shows a read-only reputation only when the freegler has ratings', () => {
    // User 7 has no ratings → no clutter.
    const none = mountRow({ userid: 7, quantity: 1, state: 'Interested' })
    expect(none.vm.reputation).toBeNull()
    expect(none.find('[data-testid="reputation"]').exists()).toBe(false)
    // User 8 has thumbs → compact read-only reputation shown.
    const rated = mountRow({ userid: 8, quantity: 1, state: 'Interested' })
    expect(rated.vm.reputation).toMatchObject({ up: 5, down: 1 })
    expect(rated.find('[data-testid="reputation"]').exists()).toBe(true)
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
