import { getDistance } from '~/composables/useMap'

// Pure sort for the Browse feed, extracted from PostMapAndList.vue so it can be unit
// tested and so the expensive sort keys are computed ONCE per message (a Schwartzian
// transform) rather than re-derived on every comparator call.
//
// The previous inline comparator called getDistance() (a haversine with several trig
// ops) and new Date(m.arrival).getTime() for BOTH operands on every comparison, so a
// full sort did O(n log n) trig/date evaluations. On a large feed (a heavy-membership
// user's list can be thousands of posts) that dominated the sort. Here each message's
// distance and arrival timestamp are computed once - O(n) key work - leaving only cheap
// numeric compares in the O(n log n) sort. The resulting order is identical to the old
// comparator (same null->Infinity handling, same score/recency fallbacks, same stable
// tie behaviour).
//
//   messages     - array of feed summary objects
//   selectedSort - 'Unseen' | 'Nearby' | anything else (treated as Newest-first)
//   centre       - { lat, lng } viewer location, or null; only used for 'Nearby'
//
// Returns a NEW array; the input is not mutated (matches the old messages.slice()).
export function sortBrowseMessages(messages, selectedSort, centre) {
  const list = messages || []

  // Rippling-out (#1): "Nearby" orders nearest-first from the viewer's location. Only
  // active when we actually have a centre - otherwise we fall through to recency below.
  const nearbyRef =
    selectedSort === 'Nearby' && centre?.lat != null && centre?.lng != null
      ? [centre.lat, centre.lng]
      : null

  // 'Unseen' orders by unseen-bucket then relevance score and never consults arrival, so
  // only pay for Date parsing when a recency tiebreak/order is actually needed.
  const needsRecency = selectedSort !== 'Unseen'

  const decorated = list.map((m) => ({
    m,
    dist:
      nearbyRef && m.lat != null && m.lng != null
        ? getDistance(nearbyRef, [m.lat, m.lng])
        : Infinity,
    ts: needsRecency ? new Date(m.arrival).getTime() : 0,
  }))

  decorated.sort((a, b) => {
    // A pinned post (a paid bulk-offer clearance) always leads the feed, whatever the
    // sort mode - the server floats it to the top and the client must not undo that when
    // it re-sorts by distance/recency (which ignore the server's score).
    const apin = a.m.pinned ? 1 : 0
    const bpin = b.m.pinned ? 1 : 0
    if (apin !== bpin) {
      return bpin - apin
    }
    if (selectedSort === 'Unseen') {
      // Unseen first, then by descending rippling relevance score. Successful posts are
      // not treated as unseen so they don't bob to the top. Missing score -> 0.
      const am = a.m
      const bm = b.m
      const aunseen = am.unseen && !am.successful
      const bunseen = bm.unseen && !bm.successful

      if (aunseen && !bunseen) {
        return -1
      } else if (!aunseen && bunseen) {
        return 1
      } else {
        return (bm.score ?? 0) - (am.score ?? 0)
      }
    } else if (nearbyRef) {
      // Nearby: nearest-first (posts with no coordinates sort last via Infinity), then
      // recency as a tiebreak.
      if (a.dist !== b.dist) {
        return a.dist - b.dist
      }
      return b.ts - a.ts
    } else {
      // Descending date/time (Newest posted; also Nearby with no known location).
      return b.ts - a.ts
    }
  })

  return decorated.map((d) => d.m)
}
