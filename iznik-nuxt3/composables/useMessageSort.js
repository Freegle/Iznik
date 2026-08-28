// Pure sort for the Browse feed, extracted from PostMapAndList.vue so it can be unit
// tested and so the expensive sort keys are computed ONCE per message (a Schwartzian
// transform) rather than re-derived on every comparator call.
//
// Each message's distance and arrival timestamp are computed once - O(n) key work -
// leaving only cheap numeric compares in the O(n log n) sort.
//
//   messages     - array of feed summary objects
//   selectedSort - 'Unseen' | 'Nearby' | anything else (treated as Newest-first)
//   roadMiles    - optional (m) => miles|null accessor. When the badge shows ROAD
//                  miles, "Closest" must order by the same number, or the list reads
//                  10, 9, 9 and looks unsorted. Falls back to the server's crow
//                  distance per item while the engine has not answered for it yet.
//
// Whatever the sort, a paid pinned clearance (m.pinned) leads the feed and then the viewer's
// own recent posts (m.mine, flagged by the server) come next, newest-first, so members can
// always find their own posts instead of losing them in the reach order (Discourse 9933).
//
// Returns a NEW array; the input is not mutated (matches the old messages.slice()).
export function sortBrowseMessages(messages, selectedSort, roadMiles = null) {
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
  // ONE clock, shared with the card badge (usePostAgeBadge): visibleSince is the earliest
  // this member could have seen the post - the oldest arrival across the groups it is live on.
  // Ordering by anything else is how the feed came to show 5 days, 4 days, 5 days, 2 hours and
  // still be "sorted": the badge read a group arrival while this read m.posted, which never
  // moves. A repost bumps visibleSince and so lifts the post, which is what a repost is for.
  // posted is kept as the fallback for feeds cached before the server field existed.
  const recencyTs = (m) => {
    const visible = m.visibleSince ? new Date(m.visibleSince).getTime() : NaN
    if (Number.isFinite(visible) && visible > 0) return visible
    const posted = m.posted ? new Date(m.posted).getTime() : NaN
    if (Number.isFinite(posted) && posted > 0) return posted
    return new Date(m.arrival).getTime()
  }

  // The viewer's own posts pin to the top (below), newest-first, so compute their timestamp
  // even in 'Unseen' mode (which otherwise skips recency) - otherwise own posts would tie and
  // fall back to arbitrary DB order rather than most-recent-first.
  const decorated = list.map((m) => ({
    m,
    dist: !isNearby
      ? Infinity
      : (() => {
          const road = roadMiles ? roadMiles(m) : null
          if (Number.isFinite(road)) return road
          return Number.isFinite(m.distance) ? m.distance : Infinity
        })(),
    ts: needsRecency || m.mine ? recencyTs(m) : 0,
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
    // The viewer's own recent posts pin to the top of EVERY sort order (New to you / Newest /
    // Closest), just below any paid pinned clearance - members otherwise lose track of their
    // own posts in the reach-ordered feed and assume they aren't showing (Discourse 9933). The
    // server flags them via `mine`. Among the member's own posts, show the most recent first.
    const amine = a.m.mine ? 1 : 0
    const bmine = b.m.mine ? 1 : 0
    if (amine !== bmine) {
      return bmine - amine
    }
    if (amine && bmine) {
      return b.ts - a.ts
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
