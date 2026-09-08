import { describe, it, expect } from 'vitest'
import { cleanTitle, wantedCountFrom, scoreReplier, orderRepliers, allocate, remaining, eventsFor, unreadCount } from '~/composables/yourposts'

const T0 = new Date('2026-09-01T10:00:00Z').getTime()
const hours = (h) => new Date(T0 + h * 3600000).toISOString()

describe('yourposts', () => {
  it('cleans titles and reads wanted counts from reply text', () => {
    expect(cleanTitle('OFFER: Grey sofa (EH3)')).toBe('Grey sofa')
    expect(wantedCountFrom('Could I have two of the chairs please?')).toBe(2)
    expect(wantedCountFrom('3 please')).toBe(3)
    expect(wantedCountFrom('Is it still available?')).toBeNull()
  })

  it('scores repliers transparently and orders them, ties in reply order', () => {
    const post = { arrival: hours(0) }
    const early = { userid: 1, date: hours(0.5), snippet: 'Hi', miles: 12 }
    const near = { userid: 2, date: hours(5), snippet: 'I could collect tomorrow evening from you', miles: 1, ratings: { Up: 5, Down: 0 } }
    const s1 = scoreReplier(early, post)
    const s2 = scoreReplier(near, post)
    expect(s1.reasons).toContain('Replied first')
    expect(s2.reasons).toEqual(expect.arrayContaining(['5 thumbs up']))
    expect(s2.score).toBeGreaterThan(s1.score)
    const ordered = orderRepliers([early, near], post)
    expect(ordered[0].userid).toBe(2)
    const tie = orderRepliers([{ userid: 7, date: hours(30) }, { userid: 8, date: hours(31) }], post)
    expect(tie.map((r) => r.userid)).toEqual([7, 8])
    expect(scoreReplier({ userid: 9, date: hours(1), myRatingDown: true }, post).score).toBeLessThan(0)
  })

  it('allocates a split from what people asked for, never over the pool', () => {
    const alloc = allocate(4, [{ userid: 1, wanted: 3 }, { userid: 2 }, { userid: 3, wanted: 2 }])
    expect(alloc).toEqual([{ userid: 1, count: 3 }, { userid: 2, count: 1 }, { userid: 3, count: 0 }])
    expect(remaining({ availablenow: 4, promises: [{ userid: 1, count: 3 }] })).toBe(1)
    expect(remaining({ availablenow: 1, promises: [{ userid: 1 }] })).toBe(0)
  })

  it('tells the story of a post from its data, oldest first', () => {
    const post = { id: 5, type: 'Offer', subject: 'OFFER: Four chairs (EH3)', arrival: hours(0), availablenow: 4, promises: [{ userid: 2, promisedat: hours(6), count: 2 }], outcomes: [], heldreplies: 1 }
    const replies = [
      { userid: 1, displayname: 'Ali', date: hours(1), chatid: 11, snippet: 'Still going?' },
      { userid: 2, displayname: 'Jane', date: hours(2), chatid: 12, snippet: 'Could I have two please' },
    ]
    const trysts = [{ id: 1, msgid: 5, user1: 9, user2: 2, arrangedfor: hours(7) }]
    const now = T0 + 8 * 3600000
    const ev = eventsFor(post, { replies, trysts, now })
    const kinds = ev.map((e) => e.kind)
    expect(kinds).toEqual(['posted', 'reply', 'reply', 'chooser', 'promised', 'collected?', 'held'])
    expect(ev[1].chips[0].value).toBe('promise:5:1')
    expect(ev[3].chips[0].value).toBe('choose:5')
    expect(ev[4].text).toContain('Promised to Jane (2)')
    expect(ev[5].chips.map((c) => c.value)).toEqual(['taken:5:2', 'notyet:5:2', 'noshow:5:2'])
  })

  it('says a post has gone quiet only after three days with no replies and none held', () => {
    const post = { id: 6, type: 'Offer', subject: 'Lamp', arrival: hours(0), availablenow: 1, promises: [], outcomes: [] }
    const soon = eventsFor(post, { replies: [], trysts: [], now: T0 + 2 * 24 * 3600000 })
    expect(soon.map((e) => e.kind)).toEqual(['posted'])
    const later = eventsFor(post, { replies: [], trysts: [], now: T0 + 4 * 24 * 3600000 })
    expect(later.map((e) => e.kind)).toEqual(['posted', 'quiet'])
    const held = eventsFor({ ...post, heldreplies: 1 }, { replies: [], trysts: [], now: T0 + 4 * 24 * 3600000 })
    expect(held.map((e) => e.kind)).not.toContain('quiet')
  })

  it('collapses history before a repost and counts unread against a watermark', () => {
    const post = { id: 7, type: 'Offer', subject: 'Bike', arrival: hours(0), repostedat: hours(48), availablenow: 1, promises: [], outcomes: [] }
    const replies = [{ userid: 1, displayname: 'Old', date: hours(5), chatid: 1 }, { userid: 2, displayname: 'New', date: hours(50), chatid: 2 }]
    const ev = eventsFor(post, { replies, trysts: [], now: T0 + 60 * 3600000 })
    expect(ev.map((e) => e.kind)).toEqual(['posted', 'reposted', 'reply'])
    expect(ev[2].name).toBe('New')
    const n = unreadCount([post], () => ({ replies, trysts: [] }), { 7: T0 + 49 * 3600000 }, T0 + 60 * 3600000)
    expect(n).toBe(1)
  })
})
