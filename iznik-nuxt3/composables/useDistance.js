import turfdistance from 'turf-distance'
import turfpoint from 'turf-point'
import { useAuthStore } from '~/stores/auth'
import { roadDistance } from '~/composables/useDriveDistance'
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
//
// The slider is a TRAVEL-TIME budget (settings.browseMaxMinutes is its source of
// truth; the miles radius is a crow-flies derivation for fast filtering). So when
// the reach engine has answered for a post, the true test - "can I drive there
// within the budget?" - wins over the radius approximation: a post across an
// unbridged river drops out even though it is crow-inside, and a post just past
// the radius but a quick drive stays. Posts the engine has not answered for keep
// the crow test, so nothing flickers on engine unavailability. minuteCheck is
// (m) => true|false|null with null meaning "unknown, use the radius".
export function filterMessagesByDistance(
  messages,
  maxDistance,
  minuteCheck = null
) {
  const all = messages || []
  if (maxDistance === BROWSE_DISTANCE_UNLIMITED) return all
  return all.filter((m) => {
    const road = minuteCheck ? minuteCheck(m) : null
    if (road !== null) return road
    return isWithinDistance(m.distance, maxDistance)
  })
}

// The per-message verdict for a drive-minutes budget. Pure so it can be unit tested.
//
// A summary that SHIPS roadmins (the feeds stamp them server-side in one batched routing
// call) decides synchronously on the first render, and the decision can never change once
// painted. The async per-post road lookup is only the fallback for payloads without the
// field (older cached feeds) - its late answer used to flip the verdict after paint, which
// is what made posts flash up and then collapse to "You're up to date" while the badge
// still counted them. null means "no road answer": the caller falls back to the crow rule,
// which is stable (a failed lookup stays failed, so that verdict doesn't change either).
export function roadMinuteVerdict(m, maxMins, asyncRoad = roadDistance) {
  if (typeof m?.roadmins === 'number') return m.roadmins <= maxMins
  if (m?.lat == null || m?.lng == null) return null
  const road = asyncRoad(m.lat, m.lng).value
  return road?.mins == null ? null : road.mins <= maxMins
}

// The shared road-aware minuteCheck for the browse slider, reading the viewer's
// travel-time budget from settings. Built HERE so PostMap and PostMapAndList
// construct byte-identical logic and the map can never disagree with the list.
// Returns null (pure crow behaviour) for members without a stored minutes budget
// (legacy miles-only writes).
export function browseSliderMinuteCheck() {
  const authStore = useAuthStore()
  const maxMins = authStore.user?.settings?.browseMaxMinutes
  if (typeof maxMins !== 'number' || maxMins <= 0) return null
  return (m) => roadMinuteVerdict(m, maxMins)
}
