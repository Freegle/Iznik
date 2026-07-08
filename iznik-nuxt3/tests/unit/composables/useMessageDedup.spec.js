import { describe, it, expect } from 'vitest'
import {
  deduplicateMessages,
  findDuplicates,
  distinctGroupIds,
  dedupKey,
} from '~/composables/useMessageDedup'

// Build a getMessage(id) lookup from a list of detail objects.
function storeOf(details) {
  const map = {}
  for (const d of details) map[d.id] = d
  return (id) => map[id]
}

// isOnMyGroup predicate mirroring MessageList's, for a given set of member group ids.
function memberOf(groupIds) {
  const set = new Set(groupIds)
  return (message) =>
    !!message?.groups &&
    message.groups.some((g) => set.has(parseInt(g.groupid)))
}

describe('deduplicateMessages', () => {
  it('returns items unchanged when there are no duplicates', () => {
    const items = [{ id: 1 }, { id: 2 }]
    const getMessage = storeOf([
      { id: 1, fromuser: 5, type: 'Offer', subject: 'OFFER: Sofa' },
      { id: 2, fromuser: 6, type: 'Offer', subject: 'OFFER: Table' },
    ])
    const out = deduplicateMessages(items, { getMessage })
    expect(out.map((m) => m.id)).toEqual([1, 2])
  })

  it('collapses same poster + same item to one entry', () => {
    const items = [{ id: 1 }, { id: 2 }]
    const getMessage = storeOf([
      { id: 1, fromuser: 5, type: 'Offer', subject: 'OFFER: Sofa' },
      { id: 2, fromuser: 5, type: 'Offer', subject: 'OFFER: Sofa' },
    ])
    const out = deduplicateMessages(items, { getMessage })
    expect(out.map((m) => m.id)).toEqual([1])
  })

  it('strips a trailing (location) so crossposts of one item collapse (Discourse 9733/7)', () => {
    const items = [{ id: 1 }, { id: 2 }]
    const getMessage = storeOf([
      {
        id: 1,
        fromuser: 5,
        type: 'Offer',
        subject: 'OFFER: bike (Bethnal Green)',
      },
      { id: 2, fromuser: 5, type: 'Offer', subject: 'OFFER: bike (Bethel)' },
    ])
    const out = deduplicateMessages(items, { getMessage })
    expect(out).toHaveLength(1)
  })

  it('prefers the duplicate on a group the viewer belongs to (Discourse 9733/9729)', () => {
    const items = [{ id: 1 }, { id: 2 }]
    const getMessage = storeOf([
      {
        id: 1,
        fromuser: 5,
        type: 'Offer',
        subject: 'OFFER: Sofa',
        groups: [{ groupid: 20 }],
      },
      {
        id: 2,
        fromuser: 5,
        type: 'Offer',
        subject: 'OFFER: Sofa',
        groups: [{ groupid: 10 }],
      },
    ])
    const out = deduplicateMessages(items, {
      getMessage,
      isOnMyGroup: memberOf([10]),
    })
    // Keeps id 2 (member group 10), not id 1 (non-member group 20).
    expect(out.map((m) => m.id)).toEqual([2])
  })

  it('does not displace the kept copy when neither is on a member group', () => {
    const items = [{ id: 1 }, { id: 2 }]
    const getMessage = storeOf([
      {
        id: 1,
        fromuser: 5,
        type: 'Offer',
        subject: 'OFFER: Sofa',
        groups: [{ groupid: 20 }],
      },
      {
        id: 2,
        fromuser: 5,
        type: 'Offer',
        subject: 'OFFER: Sofa',
        groups: [{ groupid: 30 }],
      },
    ])
    const out = deduplicateMessages(items, {
      getMessage,
      isOnMyGroup: memberOf([10]),
    })
    expect(out.map((m) => m.id)).toEqual([1])
  })

  it('keeps firstSeenMessage and lets it displace an earlier-kept duplicate', () => {
    const items = [{ id: 1 }, { id: 2 }, { id: 3 }]
    const getMessage = storeOf([
      { id: 1, fromuser: 5, type: 'Offer', subject: 'OFFER: Sofa' },
      { id: 2, fromuser: 6, type: 'Offer', subject: 'OFFER: Table' },
      { id: 3, fromuser: 5, type: 'Offer', subject: 'OFFER: Sofa' },
    ])
    // 3 is a duplicate of 1 but is the firstSeenMessage -> it replaces 1.
    const out = deduplicateMessages(items, { getMessage, firstSeenMessage: 3 })
    expect(out.map((m) => m.id)).toEqual([2, 3])
  })

  it('never displaces firstSeenMessage even for a member-group duplicate', () => {
    const items = [{ id: 1 }, { id: 2 }]
    const getMessage = storeOf([
      {
        id: 1,
        fromuser: 5,
        type: 'Offer',
        subject: 'OFFER: Sofa',
        groups: [{ groupid: 20 }],
      },
      {
        id: 2,
        fromuser: 5,
        type: 'Offer',
        subject: 'OFFER: Sofa',
        groups: [{ groupid: 10 }],
      },
    ])
    const out = deduplicateMessages(items, {
      getMessage,
      firstSeenMessage: 1,
      isOnMyGroup: memberOf([10]),
    })
    // 1 is firstSeenMessage so it is kept even though 2 is on the member group.
    expect(out.map((m) => m.id)).toEqual([1])
  })

  it('drops the excluded id entirely', () => {
    const items = [{ id: 1 }, { id: 2 }]
    const getMessage = storeOf([
      { id: 1, fromuser: 5, type: 'Offer', subject: 'OFFER: Sofa' },
      { id: 2, fromuser: 6, type: 'Offer', subject: 'OFFER: Table' },
    ])
    const out = deduplicateMessages(items, { getMessage, exclude: 1 })
    expect(out.map((m) => m.id)).toEqual([2])
  })

  it('skips failed ids', () => {
    const items = [{ id: 1 }, { id: 2 }]
    const getMessage = storeOf([
      { id: 1, fromuser: 5, type: 'Offer', subject: 'OFFER: Sofa' },
      { id: 2, fromuser: 6, type: 'Offer', subject: 'OFFER: Table' },
    ])
    const out = deduplicateMessages(items, {
      getMessage,
      failedIds: new Set([1]),
    })
    expect(out.map((m) => m.id)).toEqual([2])
  })

  it('keeps items whose detail is not yet loaded, as-is', () => {
    const items = [{ id: 1 }, { id: 2 }]
    // Only id 2 has detail.
    const getMessage = storeOf([
      { id: 2, fromuser: 6, type: 'Offer', subject: 'OFFER: Table' },
    ])
    const out = deduplicateMessages(items, { getMessage })
    expect(out.map((m) => m.id)).toEqual([1, 2])
  })

  it('preserves input order of the kept items', () => {
    const items = [{ id: 3 }, { id: 1 }, { id: 2 }]
    const getMessage = storeOf([
      { id: 1, fromuser: 5, type: 'Offer', subject: 'OFFER: A' },
      { id: 2, fromuser: 6, type: 'Offer', subject: 'OFFER: B' },
      { id: 3, fromuser: 7, type: 'Offer', subject: 'OFFER: C' },
    ])
    const out = deduplicateMessages(items, { getMessage })
    expect(out.map((m) => m.id)).toEqual([3, 1, 2])
  })
})

describe('findDuplicates', () => {
  it('returns items not present in the kept list', () => {
    const items = [{ id: 1 }, { id: 2 }, { id: 3 }]
    const kept = [{ id: 1 }, { id: 3 }]
    expect(findDuplicates(items, kept).map((m) => m.id)).toEqual([2])
  })

  it('includes failed/excluded items that were dropped from the kept list', () => {
    const items = [{ id: 1 }, { id: 2 }]
    const getMessage = storeOf([
      { id: 1, fromuser: 5, type: 'Offer', subject: 'OFFER: Sofa' },
      { id: 2, fromuser: 6, type: 'Offer', subject: 'OFFER: Table' },
    ])
    const kept = deduplicateMessages(items, {
      getMessage,
      failedIds: new Set([1]),
    })
    // id 1 was skipped as failed, so it is a "duplicate" (not kept).
    expect(findDuplicates(items, kept).map((m) => m.id)).toEqual([1])
  })

  it('handles empty inputs', () => {
    expect(findDuplicates([], [])).toEqual([])
    expect(findDuplicates(undefined, undefined)).toEqual([])
  })
})

describe('distinctGroupIds', () => {
  it('returns distinct groupids in first-appearance order', () => {
    const msgs = [
      { groupid: 3 },
      { groupid: 1 },
      { groupid: 3 },
      { groupid: 2 },
      { groupid: 1 },
    ]
    expect(distinctGroupIds(msgs)).toEqual([3, 1, 2])
  })

  it('handles empty/undefined input', () => {
    expect(distinctGroupIds([])).toEqual([])
    expect(distinctGroupIds(undefined)).toEqual([])
  })
})

describe('dedupKey', () => {
  it('collapses the same poster crossposts of one item across groups', () => {
    // Same poster, same item, different trailing "(location)" -> one key, so the browse
    // feed shows one card and MessageList can mark every copy seen together.
    const a = { fromuser: 7, type: 'Offer', subject: 'OFFER: Sofa (Leeds LS1)' }
    const b = { fromuser: 7, type: 'Offer', subject: 'OFFER: Sofa (Leeds LS2)' }
    expect(dedupKey(a)).toBe(dedupKey(b))
  })

  it('separates different posters of the same item', () => {
    const a = { fromuser: 7, type: 'Offer', subject: 'OFFER: Sofa (Leeds)' }
    const b = { fromuser: 8, type: 'Offer', subject: 'OFFER: Sofa (Leeds)' }
    expect(dedupKey(a)).not.toBe(dedupKey(b))
  })

  it('separates different items from the same poster', () => {
    const a = { fromuser: 7, type: 'Offer', subject: 'OFFER: Sofa (Leeds)' }
    const b = { fromuser: 7, type: 'Offer', subject: 'OFFER: Table (Leeds)' }
    expect(dedupKey(a)).not.toBe(dedupKey(b))
  })
})
