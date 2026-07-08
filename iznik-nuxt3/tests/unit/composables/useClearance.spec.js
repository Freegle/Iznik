import { describe, it, expect } from 'vitest'
import {
  clearanceStateLabel,
  clearanceStateVariant,
  clearanceActions,
  isAllocatedState,
  isPoolState,
  isInactiveState,
  isActiveInterest,
  allocatedQuantity,
  distinctInterestedUsers,
} from '~/composables/useClearance'

describe('useClearance', () => {
  it('labels and variants map known states, falling back gracefully', () => {
    expect(clearanceStateLabel('Reserved')).toBe('Allocated')
    expect(clearanceStateLabel('Interested')).toBe('Wants it')
    expect(clearanceStateLabel('Wibble')).toBe('Wibble')
    expect(clearanceStateVariant('Collected')).toBe('primary')
    expect(clearanceStateVariant('Wibble')).toBe('secondary')
  })

  it('classifies states into groups', () => {
    expect(isAllocatedState('Reserved')).toBe(true)
    expect(isAllocatedState('Collected')).toBe(true)
    expect(isAllocatedState('Interested')).toBe(false)
    expect(isPoolState('Interested')).toBe(true)
    expect(isInactiveState('Withdrawn')).toBe(true)
    expect(isInactiveState('Rejected')).toBe(true)
    expect(isInactiveState('Reserved')).toBe(false)
  })

  it('treats withdrawn/rejected rows as inactive interest', () => {
    expect(isActiveInterest({ state: 'Interested' })).toBe(true)
    expect(isActiveInterest({ state: 'Reserved' })).toBe(true)
    expect(isActiveInterest({ state: 'Withdrawn' })).toBe(false)
    expect(isActiveInterest(null)).toBe(false)
  })

  it('sums only allocated quantities', () => {
    const interest = [
      { state: 'Reserved', quantity: 3 },
      { state: 'Collected', quantity: 2 },
      { state: 'Interested', quantity: 5 }, // not counted
      { state: 'Withdrawn', quantity: 9 }, // not counted
    ]
    expect(allocatedQuantity(interest)).toBe(5)
    expect(allocatedQuantity([])).toBe(0)
  })

  it('counts distinct interested users across items, ignoring inactive', () => {
    const items = [
      {
        interest: [
          { userid: 1, state: 'Interested' },
          { userid: 2, state: 'Reserved' },
        ],
      },
      {
        interest: [
          { userid: 2, state: 'Interested' }, // dup user
          { userid: 3, state: 'Withdrawn' }, // inactive → ignored
        ],
      },
    ]
    expect(distinctInterestedUsers(items)).toBe(2)
    expect(distinctInterestedUsers([])).toBe(0)
  })

  it('offers the right next-state actions per state', () => {
    expect(clearanceActions('Interested').map((a) => a.state)).toEqual([
      'Reserved',
      'Rejected',
    ])
    expect(clearanceActions('Reserved').map((a) => a.state)).toEqual([
      'Collected',
      'Interested',
    ])
    expect(clearanceActions('Collected').map((a) => a.state)).toEqual([
      'Reserved',
    ])
    expect(clearanceActions('Rejected').map((a) => a.state)).toEqual([
      'Interested',
    ])
    // The replier withdrew — nothing for the offerer to do.
    expect(clearanceActions('Withdrawn')).toEqual([])
  })
})
