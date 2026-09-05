// Pure sort for the Browse feed, extracted from PostMapAndList.vue so it can be unit
// tested and so the expensive sort keys are computed ONCE per message (a Schwartzian
// transform) rather than re-derived on every comparator call.
//
// Each message's distance and arrival timestamp are computed once - O(n) key work -
// leaving only cheap numeric compares in the O(n log n) sort.
//
//   messages     - array of feed summary objects
//   selectedSort - 'Unseen' | 'Nearby' | anything else (treated as Newest-first)
//                  'Unseen' puts unseen posts first (in rippling relevance order) and then
//                  the already-seen ones NEWEST-FIRST.
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

  // Every sort now needs a recency key. 'Unseen' used to skip it (bucket then score, never
  // a date), but its SEEN block is ordered by date - see the comparator below - so the
  // Schwartzian pass always pays for one Date parse per message.

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
    const arrived = new Date(m.arrival).getTime()
    // A message with no usable date at all must yield a NUMBER, not NaN: `b.ts - a.ts` on
    // NaN is NaN, which is not a valid comparator result and leaves the order arbitrary.
    // 0 makes them tie, so they keep the order they arrived in.
    return Number.isFinite(arrived) ? arrived : 0
  }

  const decorated = list.map((m) => ({
    m,
    dist: !isNearby
      ? Infinity
      : (() => {
          const road = roadMiles ? roadMiles(m) : null
          if (Number.isFinite(road)) return road
          return Number.isFinite(m.distance) ? m.distance : Infinity
        })(),
    ts: recencyTs(m),
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
    // Only OPEN own posts pin: the mygroups feed also carries freegled posts (they render as
    // the spaced social-proof cards), and once `mine` reached that feed the member's own
    // completed posts were being pinned over everything new. A completed post belongs in My
    // Posts; here it takes its chances in the normal order like anyone else's freegled card.
    const amine = a.m.mine && !a.m.successful ? 1 : 0
    const bmine = b.m.mine && !b.m.successful ? 1 : 0
    if (amine !== bmine) {
      return bmine - amine
    }
    if (amine && bmine) {
      return b.ts - a.ts
    }
    if (selectedSort === 'Unseen') {
      // Unseen first, then seen. The unseen test is plain `m.unseen`, exactly what
      // MessageList splits its two grids on: when the two disagreed, an unseen successful
      // post was rendered in the unseen grid but ordered as if it were seen, so it landed
      // in the wrong place within its own block. Successful posts are wanted here (they are
      // the social-proof cards); what stops them dominating is upstream, where the feed
      // already drops any freegled post over a week old and spaces the rest out.
      const am = a.m
      const bm = b.m
      // Coerced: the feed's own split is truthiness (`filter(m => m.unseen)`), and an
      // absent `unseen` must compare equal to an explicit false, not differ from it.
      const aunseen = !!am.unseen
      const bunseen = !!bm.unseen

      if (aunseen !== bunseen) {
        return aunseen ? -1 : 1
      }

      if (aunseen) {
        // Within the unseen block, the rippling relevance order. Missing score -> 0.
        return (bm.score ?? 0) - (am.score ?? 0)
      }

      // Within the SEEN block, newest first - NOT by score. Score decides what is worth
      // surfacing as new; once you have seen a post that judgement is spent, and date is
      // the only order a member can predict. Ordering it by score is what made a caught-up
      // feed look stuck: RIPPLE_BROWSE_W_FRESH is 0, so score carries no recency at all,
      // and its budget term - exp(-(views + 3*replies) / ageHours / k) - climbs towards its
      // maximum as an unengaged post ages. The top card was therefore the nearest thing
      // nobody had ever wanted, which is usually weeks old.
      return b.ts - a.ts
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
