import { describe, it, expect } from 'vitest'
import {
  splitPendingCopies,
  actionGroupids,
  heldByOther,
} from '~/modtools/composables/multiGroupModeration'

// A post pending on several of a moderator's communities is moderated from one card, and
// Approve, Reject and Delete act on every copy the moderator may act on. These pin which
// copies those are.

const ME = 7
const mine = new Set([1, 2, 3, 4])
const amAModOn = (id) => mine.has(id)

describe('splitPendingCopies', () => {
  it('takes every pending copy on a community I run', () => {
    const groups = [
      { groupid: 1, collection: 'Pending', rippled_in: 0 },
      { groupid: 2, collection: 'Pending', rippled_in: 1 },
      { groupid: 3, collection: 'Spam', rippled_in: 1 },
    ]

    const split = splitPendingCopies(groups, amAModOn, ME)

    expect(split.all.map((g) => g.groupid)).toEqual([1, 2, 3])
    expect(split.actionable.map((g) => g.groupid)).toEqual([1, 2, 3])
    expect(split.held).toEqual([])
    expect(split.locked).toEqual([])
  })

  it('ignores copies on communities I do not run, and copies no longer waiting', () => {
    const groups = [
      { groupid: 1, collection: 'Pending' },
      { groupid: 2, collection: 'Approved' },
      { groupid: 3, collection: 'Rejected' },
      { groupid: 99, collection: 'Pending' },
    ]

    const split = splitPendingCopies(groups, amAModOn, ME)

    expect(split.all.map((g) => g.groupid)).toEqual([1])
  })

  it('leaves alone a copy held by another volunteer, but not one I hold', () => {
    const groups = [
      { groupid: 1, collection: 'Pending', heldby: ME },
      { groupid: 2, collection: 'Pending', heldby: 55 },
      { groupid: 3, collection: 'Pending', heldby: { id: 56 } },
    ]

    const split = splitPendingCopies(groups, amAModOn, ME)

    expect(split.actionable.map((g) => g.groupid)).toEqual([1])
    expect(split.held.map((g) => g.groupid)).toEqual([2, 3])
  })

  it('leaves alone a copy locked by the home community when the home copy is not mine to approve', () => {
    const groups = [
      { groupid: 50, collection: 'Pending', rippled_in: 0 },
      { groupid: 2, collection: 'Pending', rippled_in: 1, locked_by_home: 1 },
      { groupid: 3, collection: 'Pending', rippled_in: 1, locked_by_home: 0 },
    ]

    const split = splitPendingCopies(groups, amAModOn, ME)

    expect(split.actionable.map((g) => g.groupid)).toEqual([3])
    expect(split.locked.map((g) => g.groupid)).toEqual([2])
  })

  it('takes the locked copies along when I am approving the home copy too', () => {
    const groups = [
      { groupid: 1, collection: 'Pending', rippled_in: 0 },
      { groupid: 2, collection: 'Pending', rippled_in: 1, locked_by_home: 1 },
    ]

    const split = splitPendingCopies(groups, amAModOn, ME)

    expect(split.actionable.map((g) => g.groupid)).toEqual([1, 2])
    expect(split.locked).toEqual([])
  })

  it('copes with a message that has no groups', () => {
    expect(splitPendingCopies(undefined, amAModOn, ME).all).toEqual([])
  })
})

describe('actionGroupids', () => {
  const rows = (...ids) => ids.map((groupid) => ({ groupid }))

  it('puts the community being looked at first', () => {
    expect(actionGroupids(3, rows(1, 2, 3))).toEqual([3, 1, 2])
  })

  it('is null for a single community, so the action goes out as it always has', () => {
    expect(actionGroupids(1, rows(1))).toBeNull()
    expect(actionGroupids(1, [])).toBeNull()
  })

  it('is null when the community being looked at cannot be acted on', () => {
    // Acting everywhere except the copy on screen would be the opposite of what was asked.
    expect(actionGroupids(4, rows(1, 2))).toBeNull()
  })
})

describe('heldByOther', () => {
  it('reads the holder as an id or an object', () => {
    expect(heldByOther({ heldby: 5 }, ME)).toBe(true)
    expect(heldByOther({ heldby: { id: ME } }, ME)).toBe(false)
    expect(heldByOther({ heldby: null }, ME)).toBe(false)
  })
})
