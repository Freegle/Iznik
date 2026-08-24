import turfdistance from 'turf-distance'
import turfpoint from 'turf-point'
import { BROWSE_DISTANCE_UNLIMITED } from '~/constants'

export function milesAway(flat, flng, tlat, tlng) {
  let ret = null

  if ((flat || flng) && (tlat || tlng)) {
    ret = turfdistance(
      turfpoint([flng, flat]),
      turfpoint([tlng, tlat]),
      'miles'
    )

    ret = ret > 2 ? Math.round(ret) : Math.round(ret * 10) / 10
  }

  return ret
}

// Single shared predicate for the Browse distance slider (settings.browseMaxDistance /
// selectedMaxDistance), used by both PostMap (map markers + coverage hull) and
// PostMapAndList (the post list) so the two views can never drift out of sync with
// each other. BROWSE_DISTANCE_UNLIMITED means "no client-side limit" - everything the
// reach feed returned passes. A post with no `distance` field (e.g. an older feed
// response, or a non-reach source that never set one) always passes too, rather than
// being defensively hidden.
export function isWithinDistance(distance, maxDistance) {
  return (
    maxDistance === BROWSE_DISTANCE_UNLIMITED ||
    distance == null ||
    distance <= maxDistance
  )
}

// Filter a list of messages (objects with a `.distance` field) down to those within
// maxDistance. Returns the SAME array reference (no-op) when maxDistance is the
// unlimited sentinel, so callers that rely on referential stability (e.g. to skip
// redundant recomputation downstream) aren't forced into a new array every time.
export function filterMessagesByDistance(messages, maxDistance) {
  const all = messages || []
  if (maxDistance === BROWSE_DISTANCE_UNLIMITED) return all
  return all.filter((m) => isWithinDistance(m.distance, maxDistance))
}
