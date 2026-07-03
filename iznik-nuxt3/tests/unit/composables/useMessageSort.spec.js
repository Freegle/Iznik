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

    it('treats successful posts as not-unseen so they do not bob to the top', () => {
      const msgs = [
        { id: 1, unseen: false, score: 1 },
        { id: 2, unseen: true, successful: true, score: 9 },
      ]
      // 2 is unseen but successful -> treated as seen -> ordered by score within the
      // seen bucket. Both are seen; higher score first.
      expect(order(msgs, 'Unseen')).toEqual([2, 1])
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
  })

  describe('Nearby (Closest) sort', () => {
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
})
