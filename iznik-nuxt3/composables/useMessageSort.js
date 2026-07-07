// Pure sort for the Browse feed, extracted from PostMapAndList.vue so it can be unit
// tested and so the expensive sort keys are computed ONCE per message (a Schwartzian
// transform) rather than re-derived on every comparator call.
//
// Each message's distance and arrival timestamp are computed once - O(n) key work -
// leaving only cheap numeric compares in the O(n log n) sort.
//
//   messages     - array of feed summary objects
//   selectedSort - 'Unseen' | 'Nearby' | anything else (treated as Newest-first)
//
// Returns a NEW array; the input is not mutated (matches the old messages.slice()).
export function sortBrowseMessages(messages, selectedSort) {
  const list = messages || []

  // "Nearby" (labelled "Closest") orders nearest-first by the SERVER's per-post distance
  // - `m.distance`, the blurred great-circle miles from the viewer that the feed already
  // returns and that each card's distance badge and the distance slider both use. Using
  // that single value (rather than re-deriving distance client-side from the MAP centre,
  // which is not the viewer's location and drifts as the map is panned/fitted) is what
  // keeps the list order and the badges in agreement. Posts with no server distance sort
  // last (Infinity) and then fall through to the recency tie-break.
  const isNearby = selectedSort === 'Nearby'

  // 'Unseen' orders by unseen-bucket then relevance score and never consults arrival, so
  // only pay for Date parsing when a recency tiebreak/order is actually needed.
  const needsRecency = selectedSort !== 'Unseen'

  // "Newest posted" (and the recency tiebreak) means ORIGINAL post time - `m.posted`,
  // which the server exposes as the stable messages.arrival. We must NOT use `m.arrival`
  // here: on the reach feed that is the messages_spatial arrival, which the reach engine
  // bumps forward every time the post ripples into a new group, so ordering by it floats a
  // days-old post to the top the moment its reach grows again while the card still shows the
  // original "3 days ago" - the exact "Newest posted isn't chronological" bug (Discourse
  // 9844). Fall back to `m.arrival` only when `posted` is absent (older cached feeds); guard
  // against the Go zero-time sentinel (serialised as year 0001, i.e. a large negative epoch)
  // that appears on any path that didn't populate posted.
  const recencyTs = (m) => {
    const posted = m.posted ? new Date(m.posted).getTime() : NaN
    if (Number.isFinite(posted) && posted > 0) return posted
    return new Date(m.arrival).getTime()
  }

  const decorated = list.map((m) => ({
    m,
    dist: isNearby && Number.isFinite(m.distance) ? m.distance : Infinity,
    ts: needsRecency ? recencyTs(m) : 0,
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
    } else if (isNearby) {
      // Nearby: nearest-first by server distance (posts with no distance sort last via
      // Infinity), then recency as a tiebreak. When no post has a server distance this
      // degenerates to pure recency, matching the old "no known location" fallback.
      if (a.dist !== b.dist) {
        return a.dist - b.dist
      }
      return b.ts - a.ts
    } else {
      // Descending date/time (Newest posted).
      return b.ts - a.ts
    }
  })

  return decorated.map((d) => d.m)
}
