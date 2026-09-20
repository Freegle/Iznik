import { describe, it, expect } from 'vitest'
import { sortBrowseMessages } from '~/composables/useMessageSort'

// Small helper: sort and return the resulting id order.
function order(messages, sort) {
  return sortBrowseMessages(messages, sort).map((m) => m.id)
}

describe('sortBrowseMessages', () => {
  it('returns an empty array for empty/undefined input', () => {
    expect(sortBrowseMessages([], 'Unseen')).toEqual([])
    expect(sortBrowseMessages(undefined, 'Unseen')).toEqual([])
  })

  it('does not mutate the input array', () => {
    const input = [
      { id: 1, arrival: '2024-01-01T00:00:00Z' },
      { id: 2, arrival: '2024-01-02T00:00:00Z' },
    ]
    const snapshot = input.map((m) => m.id)
    sortBrowseMessages(input, 'Newest')
    expect(input.map((m) => m.id)).toEqual(snapshot)
  })

  describe('Unseen sort', () => {
    it('puts unseen before seen', () => {
      const msgs = [
        { id: 1, unseen: false, score: 5 },
        { id: 2, unseen: true, score: 1 },
      ]
      expect(order(msgs, 'Unseen')).toEqual([2, 1])
    })

    it('buckets an unseen successful post with the unseen ones, matching the feed split', () => {
      // MessageList splits its two grids on plain `m.unseen`. The sort used to require
      // `unseen && !successful`, so a freegled post the member had not seen was RENDERED in
      // the unseen grid while being ORDERED as if it were seen, landing in the wrong place
      // within its own block. Both now agree. Keeping freegled posts out of the way is done
      // upstream instead (over-a-week-old ones are dropped, the rest spaced out).
      const msgs = [
        { id: 'seen', unseen: false, score: 5, posted: '2024-06-01T00:00:00Z' },
        {
          id: 'unseenFreegled',
          unseen: true,
          successful: true,
          score: 1,
          posted: '2024-01-01T00:00:00Z',
        },
      ]
      expect(order(msgs, 'Unseen')).toEqual(['unseenFreegled', 'seen'])
    })

    it('treats an absent unseen flag the same as an explicit false', () => {
      const msgs = [
        { id: 'noFlag', score: 1, posted: '2024-01-01T00:00:00Z' },
        {
          id: 'explicit',
          unseen: false,
          score: 9,
          posted: '2024-06-01T00:00:00Z',
        },
      ]
      // Both seen -> newest first, rather than the missing flag being read as a difference.
      expect(order(msgs, 'Unseen')).toEqual(['explicit', 'noFlag'])
    })

    it('orders within a bucket by descending score', () => {
      const msgs = [
        { id: 1, unseen: true, score: 2 },
        { id: 2, unseen: true, score: 8 },
        { id: 3, unseen: true, score: 5 },
      ]
      expect(order(msgs, 'Unseen')).toEqual([2, 3, 1])
    })

    it('treats a missing score as 0', () => {
      const msgs = [
        { id: 1, unseen: true, score: undefined },
        { id: 2, unseen: true, score: 3 },
        { id: 3, unseen: true, score: -1 },
      ]
      expect(order(msgs, 'Unseen')).toEqual([2, 1, 3])
    })

    it('keeps input order for equal scores (stable)', () => {
      const msgs = [
        { id: 10, unseen: true, score: 4 },
        { id: 11, unseen: true, score: 4 },
        { id: 12, unseen: true, score: 4 },
      ]
      expect(order(msgs, 'Unseen')).toEqual([10, 11, 12])
    })

    // Once a member has seen everything, the SEEN block is the whole feed. Ordering it by
    // score put a very old post at the top and made the feed look stuck: the browse score's
    // freshness weight is 0 by default (RIPPLE_BROWSE_W_FRESH) and its budget term is
    // exp(-(views + 3*replies) / ageHours / k), which climbs towards its maximum as an
    // unengaged post ages. So the highest-scoring post is the nearest thing nobody ever
    // wanted. Score decides what is worth surfacing as NEW; once you have seen a post that
    // judgement is spent, and the only order a member can predict is by date.
    describe('the seen block (member is caught up)', () => {
      it('orders seen posts newest-first, not by score', () => {
        const msgs = [
          {
            id: 'oldHighScore',
            unseen: false,
            score: 9,
            posted: '2024-01-01T00:00:00Z',
          },
          {
            id: 'newLowScore',
            unseen: false,
            score: 1,
            posted: '2024-06-01T00:00:00Z',
          },
        ]
        expect(order(msgs, 'Unseen')).toEqual(['newLowScore', 'oldHighScore'])
      })

      it('still orders the unseen block by score', () => {
        // Only the seen block changes; the unseen block keeps the rippling relevance order.
        const msgs = [
          {
            id: 'newLowScore',
            unseen: true,
            score: 1,
            posted: '2024-06-01T00:00:00Z',
          },
          {
            id: 'oldHighScore',
            unseen: true,
            score: 9,
            posted: '2024-01-01T00:00:00Z',
          },
        ]
        expect(order(msgs, 'Unseen')).toEqual(['oldHighScore', 'newLowScore'])
      })

      it('keeps every unseen post above every seen post regardless of date', () => {
        const msgs = [
          {
            id: 'seenNew',
            unseen: false,
            score: 0,
            posted: '2024-06-01T00:00:00Z',
          },
          {
            id: 'unseenOld',
            unseen: true,
            score: 0,
            posted: '2024-01-01T00:00:00Z',
          },
        ]
        expect(order(msgs, 'Unseen')).toEqual(['unseenOld', 'seenNew'])
      })

      it('uses visibleSince so a repost lifts a seen post back up', () => {
        // Same clock as the card's age badge: a repost bumps visibleSince, which is what a
        // repost is for. posted never moves, so ordering by it would strand the repost.
        const msgs = [
          {
            id: 'reposted',
            unseen: false,
            posted: '2024-01-01T00:00:00Z',
            visibleSince: '2024-09-01T00:00:00Z',
          },
          {
            id: 'newerPost',
            unseen: false,
            posted: '2024-06-01T00:00:00Z',
            visibleSince: '2024-06-01T00:00:00Z',
          },
        ]
        expect(order(msgs, 'Unseen')).toEqual(['reposted', 'newerPost'])
      })

      it('keeps input order for seen posts with no usable date', () => {
        // No posted/visibleSince/arrival at all must not produce a NaN comparator and an
        // arbitrary order; they tie and stay as they came.
        const msgs = [
          { id: 'a', unseen: false, score: 1 },
          { id: 'b', unseen: false, score: 9 },
        ]
        expect(order(msgs, 'Unseen')).toEqual(['a', 'b'])
      })
    })
  })

  describe('Nearby (Closest) sort', () => {
    it('orders by ROAD miles when the accessor answers, crow per item otherwise', () => {
      // Badges show road miles when the engine answered, so Closest must order by
      // the same number: 'roadNear' (10 crow but 4 by road) sorts before
      // 'roadFar' (9 crow but 9.5 by road). 'unknown' has no road answer yet and
      // keeps its crow position.
      const msgs = [
        { id: 'roadFar', distance: 9, arrival: '2024-01-01T00:00:00Z' },
        { id: 'roadNear', distance: 10, arrival: '2024-01-01T00:00:00Z' },
        { id: 'unknown', distance: 6, arrival: '2024-01-01T00:00:00Z' },
      ]
      const road = { roadFar: 9.5, roadNear: 4 }
      const ids = sortBrowseMessages(
        msgs,
        'Nearby',
        (m) => road[m.id] ?? null
      ).map((m) => m.id)
      expect(ids).toEqual(['roadNear', 'unknown', 'roadFar'])
    })

    it('orders nearest-first by the server distance', () => {
      const msgs = [
        { id: 'far', distance: 12, arrival: '2024-01-01T00:00:00Z' },
        { id: 'near', distance: 2, arrival: '2024-01-01T00:00:00Z' },
        { id: 'mid', distance: 7, arrival: '2024-01-01T00:00:00Z' },
      ]
      expect(order(msgs, 'Nearby')).toEqual(['near', 'mid', 'far'])
    })

    it('follows the server distance, not a client re-derivation from lat/lng', () => {
      // 'badgeNear' is geographically further in raw lat/lng but the server says it is
      // closer (smaller `distance`). The list must follow the badge (server distance), so
      // the old map-centre haversine ordering (which would put 'badgeFar' first) is gone.
      const msgs = [
        {
          id: 'badgeFar',
          lat: 51.5,
          lng: -0.1,
          distance: 9,
          arrival: '2024-01-01T00:00:00Z',
        },
        {
          id: 'badgeNear',
          lat: 60,
          lng: 10,
          distance: 2,
          arrival: '2024-01-01T00:00:00Z',
        },
      ]
      expect(order(msgs, 'Nearby')).toEqual(['badgeNear', 'badgeFar'])
    })

    it('sorts posts with no server distance last', () => {
      const msgs = [
        { id: 'nodist', arrival: '2024-01-01T00:00:00Z' },
        { id: 'near', distance: 3, arrival: '2024-01-01T00:00:00Z' },
      ]
      expect(order(msgs, 'Nearby')).toEqual(['near', 'nodist'])
    })

    it('breaks equal-distance ties by recency (newest first)', () => {
      const msgs = [
        { id: 'older', distance: 4, arrival: '2024-01-01T00:00:00Z' },
        { id: 'newer', distance: 4, arrival: '2024-06-01T00:00:00Z' },
      ]
      expect(order(msgs, 'Nearby')).toEqual(['newer', 'older'])
    })

    it('falls back to recency when no post has a server distance', () => {
      const msgs = [
        { id: 'older', arrival: '2024-01-01T00:00:00Z' },
        { id: 'newer', arrival: '2024-06-01T00:00:00Z' },
      ]
      // Nothing to sort by distance -> newest arrival wins.
      expect(order(msgs, 'Nearby')).toEqual(['newer', 'older'])
    })
  })

  describe('Newest / other sorts', () => {
    it('orders by descending arrival', () => {
      const msgs = [
        { id: 1, arrival: '2024-01-01T00:00:00Z' },
        { id: 2, arrival: '2024-03-01T00:00:00Z' },
        { id: 3, arrival: '2024-02-01T00:00:00Z' },
      ]
      expect(order(msgs, 'Newest')).toEqual([2, 3, 1])
      // An unknown sort is treated the same (recency).
      expect(order(msgs, 'Whatever')).toEqual([2, 3, 1])
    })
  })

  // Discourse 9844: "Newest posted" must order by ORIGINAL post time (`posted`), not the
  // reach-feed `arrival` (messages_spatial.arrival), which rippling bumps forward every time
  // a post's reach grows. Ordering by arrival floated days-old posts to the top of "Newest".
  describe('"Newest posted" uses posted (original), not the ripple-bumped arrival', () => {
    it('orders by posted even when arrival says otherwise', () => {
      // `old` was posted first but rippled MOST recently (newest arrival); `new` was posted
      // later but has an older arrival. Newest-posted must put `new` first.
      const msgs = [
        {
          id: 'old',
          posted: '2024-01-01T00:00:00Z',
          arrival: '2024-06-01T00:00:00Z',
        },
        {
          id: 'new',
          posted: '2024-05-01T00:00:00Z',
          arrival: '2024-02-01T00:00:00Z',
        },
      ]
      expect(order(msgs, 'Newest')).toEqual(['new', 'old'])
    })

    it('uses posted for the Nearby equal-distance recency tiebreak too', () => {
      const msgs = [
        {
          id: 'postedOlder',
          distance: 4,
          posted: '2024-01-01T00:00:00Z',
          arrival: '2024-09-01T00:00:00Z',
        },
        {
          id: 'postedNewer',
          distance: 4,
          posted: '2024-06-01T00:00:00Z',
          arrival: '2024-02-01T00:00:00Z',
        },
      ]
      expect(order(msgs, 'Nearby')).toEqual(['postedNewer', 'postedOlder'])
    })

    it('falls back to arrival when posted is absent (older cached feed)', () => {
      const msgs = [
        { id: 1, arrival: '2024-01-01T00:00:00Z' },
        { id: 2, arrival: '2024-03-01T00:00:00Z' },
      ]
      expect(order(msgs, 'Newest')).toEqual([2, 1])
    })

    it('ignores the Go zero-time sentinel (year 0001) and falls back to arrival', () => {
      // A feed path that did not populate posted serialises Go's zero time as year 0001,
      // which parses to a large NEGATIVE epoch. Treating that as the post time would wrongly
      // sink the post; we must fall back to its real arrival instead.
      const msgs = [
        {
          id: 'zeroPosted',
          posted: '0001-01-01T00:00:00Z',
          arrival: '2024-06-01T00:00:00Z',
        },
        {
          id: 'realPosted',
          posted: '2024-01-01T00:00:00Z',
          arrival: '2024-01-01T00:00:00Z',
        },
      ]
      // zeroPosted falls back to its arrival (June) -> newest; realPosted (Jan) second.
      expect(order(msgs, 'Newest')).toEqual(['zeroPosted', 'realPosted'])
    })
  })

  // A pinned post (a paid bulk-offer clearance) must lead the feed under every sort mode,
  // ahead of a higher-scoring / nearer / newer post.
  describe('pinned posts', () => {
    it('floats a pinned post to the top under Unseen sort, ahead of a higher score', () => {
      const msgs = [
        { id: 1, unseen: true, score: 9 },
        { id: 2, unseen: true, score: 1, pinned: true },
      ]
      expect(order(msgs, 'Unseen')).toEqual([2, 1])
    })

    it('keeps a pinned post first under Nearby sort, ahead of a nearer post', () => {
      const centre = { lat: 51.5, lng: -0.1 }
      const msgs = [
        { id: 'near', lat: 51.5, lng: -0.1, arrival: '2024-01-01T00:00:00Z' },
        {
          id: 'pinnedFar',
          lat: 53.0,
          lng: -0.1,
          arrival: '2024-01-01T00:00:00Z',
          pinned: true,
        },
      ]
      expect(order(msgs, 'Nearby', centre)).toEqual(['pinnedFar', 'near'])
    })

    it('keeps a pinned post first under Newest sort, ahead of a newer post', () => {
      const msgs = [
        { id: 'newer', arrival: '2024-03-01T00:00:00Z' },
        { id: 'pinnedOlder', arrival: '2024-01-01T00:00:00Z', pinned: true },
      ]
      expect(order(msgs, 'Newest')).toEqual(['pinnedOlder', 'newer'])
    })

    it('orders multiple pinned posts among themselves by the normal rule', () => {
      const msgs = [
        { id: 1, unseen: true, score: 1, pinned: true },
        { id: 2, unseen: true, score: 9, pinned: true },
        { id: 3, unseen: true, score: 5 },
      ]
      // Both pinned lead (higher score first among them), then the unpinned post.
      expect(order(msgs, 'Unseen')).toEqual([2, 1, 3])
    })
  })

  // Discourse 9933: members could not find their own posts in the reach-ordered feed and
  // assumed they were not showing. The viewer's own posts (m.mine, flagged by the server)
  // pin to the top of every sort order, just below any paid pinned clearance, newest-first.
  describe('own posts (mine)', () => {
    it('floats the viewer own post to the top under Unseen, ahead of an unseen higher score', () => {
      const msgs = [
        { id: 1, unseen: true, score: 9 },
        {
          id: 2,
          unseen: false,
          score: 0,
          mine: true,
          posted: '2024-05-01T00:00:00Z',
        },
      ]
      // Own post leads even though it is seen and scores 0.
      expect(order(msgs, 'Unseen')).toEqual([2, 1])
    })

    it('keeps the viewer own post first under Newest, ahead of a newer non-own post', () => {
      const msgs = [
        { id: 'newer', posted: '2024-06-01T00:00:00Z' },
        { id: 'mineOlder', posted: '2024-01-01T00:00:00Z', mine: true },
      ]
      expect(order(msgs, 'Newest')).toEqual(['mineOlder', 'newer'])
    })

    it('does not pin the viewer own COMPLETED posts - they sort like any freegled card', () => {
      // The mygroups feed carries freegled posts (spaced social-proof cards). Once `mine`
      // reached that feed, a member's own Taken posts pinned above everything new; a
      // completed post belongs in My Posts, not the top of browse.
      const msgs = [
        { id: 'newer', posted: '2024-06-01T00:00:00Z' },
        {
          id: 'mineTaken',
          posted: '2024-01-01T00:00:00Z',
          mine: true,
          successful: true,
        },
        { id: 'mineOpen', posted: '2024-02-01T00:00:00Z', mine: true },
      ]
      expect(order(msgs, 'Newest')).toEqual(['mineOpen', 'newer', 'mineTaken'])
    })

    it('keeps the viewer own post first under Nearby, ahead of a nearer non-own post', () => {
      const msgs = [
        { id: 'near', distance: 0.5 },
        {
          id: 'mineFar',
          distance: 50,
          mine: true,
          posted: '2024-01-01T00:00:00Z',
        },
      ]
      expect(order(msgs, 'Nearby')).toEqual(['mineFar', 'near'])
    })

    it('orders multiple own posts newest-first among themselves, ignoring score', () => {
      const msgs = [
        {
          id: 'mineOld',
          mine: true,
          posted: '2024-01-01T00:00:00Z',
          unseen: true,
          score: 9,
        },
        {
          id: 'mineNew',
          mine: true,
          posted: '2024-06-01T00:00:00Z',
          unseen: true,
          score: 1,
        },
        { id: 'other', unseen: true, score: 5 },
      ]
      expect(order(msgs, 'Unseen')).toEqual(['mineNew', 'mineOld', 'other'])
    })

    it('keeps a paid pinned clearance above the viewer own post', () => {
      const msgs = [
        { id: 'mine', mine: true, posted: '2024-06-01T00:00:00Z' },
        { id: 'paid', pinned: true, posted: '2024-01-01T00:00:00Z' },
      ]
      expect(order(msgs, 'Newest')).toEqual(['paid', 'mine'])
    })
  })
})
