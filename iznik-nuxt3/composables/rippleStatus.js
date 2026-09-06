// Rippling-out helpers shared by the moderation UI (#6).
//
// A post is *posted* to its origin group and then rippled into nearby groups later
// (added to messages_groups with a newer arrival). For a moderator viewing the post under
// a given (context) group, it has "rippled in" — and is starting to become available to
// that group's members — when the context group's row is meaningfully newer than the
// earliest group's row. Mirrors the 10-minute origin window used server-side
// (MessageOriginGroup): groups added within 10 minutes are treated as the same origin
// (e.g. legacy same-second cross-posts); later than that is a genuine ripple-in.

export const RIPPLE_ORIGIN_WINDOW_MS = 10 * 60 * 1000

/**
 * @param {Array<{groupid:number|string, arrival:string}>} groups message.groups
 * @param {number|string} contextGroupid the group the post is being viewed under
 * @param {number} [thresholdMs] origin window in ms (default 10 minutes)
 * @returns {boolean} true if the post rippled into the context group from elsewhere
 */
export function isRippledInToContextGroup(
  groups,
  contextGroupid,
  thresholdMs = RIPPLE_ORIGIN_WINDOW_MS
) {
  if (!Array.isArray(groups)) return false

  // Require an explicit, matching context group. We deliberately do NOT fall back
  // to groups[0]: message.groups has no guaranteed order, so guessing the context
  // from position would false-positive the banner in the all-groups view (where no
  // single group is being moderated). No context → no banner.
  const ctxId = parseInt(contextGroupid)
  if (Number.isNaN(ctxId)) return false
  const ctx = groups.find((g) => parseInt(g.groupid) === ctxId)
  if (!ctx) return false

  // Prefer the authoritative messages_groups.rippled_in column when the API supplies it.
  // The arrival heuristic below is fragile: the approve path stamps arrival=NOW() on the
  // origin row, which can make the origin look NEWER than the rippled-in copy and so hide
  // the banner even though the row really did ripple in (Discourse 9808/303). rippled_in
  // is set when the row was created by the rippling engine, so it's unambiguous.
  if (ctx.rippled_in !== undefined && ctx.rippled_in !== null) {
    return ctx.rippled_in === 1 || ctx.rippled_in === true
  }

  // Fallback for older API responses without rippled_in: arrival-time ordering. A post on
  // a single group can't have rippled in, so it needs at least two groups here.
  if (groups.length < 2 || !ctx.arrival) return false

  const ctxArrival = new Date(ctx.arrival).getTime()
  if (Number.isNaN(ctxArrival)) return false

  const arrivals = groups
    .map((g) => new Date(g.arrival).getTime())
    .filter((t) => !Number.isNaN(t))
  if (!arrivals.length) return false

  const earliest = Math.min(...arrivals)
  return ctxArrival > earliest + thresholdMs
}

/**
 * Of the supplied group rows, return the groupid (as a number) with the earliest
 * arrival - the post's origin-most copy.
 *
 * An edit under review belongs to the post's ORIGIN group, not to a copy that rippled
 * in later. The moderation UI must therefore anchor edit review to this group rather
 * than to the most-recent rippled-in copy - otherwise a moderator who is only a backup
 * on a receiving group is told they are "moderating for" that backup group for an edit
 * that actually lives on the origin group (Discourse 9518).
 *
 * Falls back to the first row's id when no arrival is parseable, and returns null for
 * empty/invalid input.
 *
 * @param {Array<{groupid:number|string, arrival:string}>} groups
 * @returns {number|null}
 */
export function earliestArrivalGroupId(groups) {
  if (!Array.isArray(groups) || groups.length === 0) return null

  let best = null
  for (const g of groups) {
    const t = g && g.arrival ? new Date(g.arrival).getTime() : NaN
    if (Number.isNaN(t)) continue
    if (best === null || t < best.t) best = { id: parseInt(g.groupid), t }
  }

  if (best === null) {
    const id = parseInt(groups[0].groupid)
    return Number.isNaN(id) ? null : id
  }
  return best.id
}

/**
 * The home/origin group of a post: the group it was actually posted to, as opposed to a
 * neighbouring group it later rippled into. Prefer the authoritative rippled_in flag
 * (rippled_in falsy = a home group); among the home candidates (or all groups if no
 * rippled_in info is present) pick the earliest arrival. We don't rely on arrival alone
 * because the approve path stamps arrival=NOW() on the origin row, which can make it look
 * newer than its rippled-in copies.
 *
 * @param {Array<{groupid:number|string, arrival?:string, rippled_in?:number|boolean}>} groups
 * @returns {number|null}
 */
export function homeGroupId(groups) {
  if (!Array.isArray(groups) || groups.length === 0) return null
  const notRippled = groups.filter(
    (g) => g && g.rippled_in !== 1 && g.rippled_in !== true
  )
  const pool = notRippled.length ? notRippled : groups
  return earliestArrivalGroupId(pool)
}

/**
 * Return a copy of the post's group rows with the home/origin group moved to the front,
 * preserving the relative order of the rest. The group list is truncated in the UI
 * (ShowMore limit), so without this the home group could be hidden behind "more".
 *
 * @param {Array<{groupid:number|string, arrival?:string, rippled_in?:number|boolean}>} groups
 * @returns {Array} a new array (input is never mutated)
 */
export function homeGroupFirst(groups) {
  if (!Array.isArray(groups)) return []
  if (groups.length < 2) return [...groups]
  const homeId = homeGroupId(groups)
  if (homeId == null) return [...groups]
  const idx = groups.findIndex((g) => g && parseInt(g.groupid) === homeId)
  if (idx <= 0) return [...groups]
  const copy = [...groups]
  const [home] = copy.splice(idx, 1)
  copy.unshift(home)
  return copy
}

/**
 * For a post viewed by a member, work out whether it rippled into the member's OWN area on a
 * later calendar day than it was first posted, and if so return the two dates to surface
 * ("first posted X, available in your area from Y").
 *
 *   firstPosted   = the home/origin group's arrival - when it was first posted.
 *   availableFrom = the arrival of the rippled-in copy on one of the viewer's groups - when it
 *                   became available in their area.
 *
 * Returns null when there's no ripple to surface: a single-group post, no group of the viewer's
 * received a rippled-in copy (e.g. the viewer is the poster, or the post is local to them), the
 * origin arrival is unknown, or the ripple landed on the SAME calendar day as the original post
 * (surfacing "first posted today, available today" would be noise - we only flag cross-day gaps).
 *
 * @param {Array<{groupid:number|string, arrival?:string, rippled_in?:number|boolean}>} groups message.groups
 * @param {Iterable<{id:number|string}|number|string>} myGroups the viewer's groups (useMe().myGroups) or ids
 * @returns {{firstPosted:string, availableFrom:string}|null}
 */
export function rippledInAreaDates(groups, myGroups) {
  if (!Array.isArray(groups) || groups.length < 2) return null

  // Normalise the viewer's group ids into a set (accepts {id} objects or bare ids).
  const mine = new Set()
  for (const g of myGroups || []) {
    const raw = g && typeof g === 'object' ? (g.id ?? g.groupid) : g
    const n = parseInt(raw)
    if (!Number.isNaN(n)) mine.add(n)
  }
  if (!mine.size) return null

  // The rippled-in copy on one of the viewer's own groups - i.e. when it reached their area.
  const myRipple = groups.find(
    (g) =>
      (g.rippled_in === 1 || g.rippled_in === true) &&
      g.arrival &&
      mine.has(parseInt(g.groupid))
  )
  if (!myRipple) return null

  // When it was first posted: the home/origin group's arrival.
  const homeId = homeGroupId(groups)
  const home = groups.find((g) => parseInt(g.groupid) === homeId)
  const firstPosted = home?.arrival
  if (!firstPosted) return null

  const posted = new Date(firstPosted)
  const reached = new Date(myRipple.arrival)
  if (Number.isNaN(posted.getTime()) || Number.isNaN(reached.getTime())) {
    return null
  }

  // Only surface when it reached the viewer's area on a strictly LATER calendar day.
  // Same-day ripples aren't worth explaining; and a reached-before-posted anomaly (the
  // approve path can stamp the origin row's arrival to NOW, making it look newer than the
  // rippled-in copy - see homeGroupId) would read backwards, so suppress that too.
  const postedDay = new Date(
    posted.getFullYear(),
    posted.getMonth(),
    posted.getDate()
  )
  const reachedDay = new Date(
    reached.getFullYear(),
    reached.getMonth(),
    reached.getDate()
  )
  if (reachedDay <= postedDay) return null

  return { firstPosted, availableFrom: myRipple.arrival }
}

/**
 * Is this group the post's home/origin group? Used to mark it with a home icon in the
 * group lists. Accepts either a raw messages_groups entry ({groupid}) or a resolved
 * group-store object ({id}); `groups` must be the raw message.groups array (it carries
 * the rippled_in / arrival info homeGroupId needs).
 *
 * @param {{groupid?:number|string, id?:number|string}} group
 * @param {Array<{groupid:number|string, arrival?:string, rippled_in?:number|boolean}>} groups
 * @returns {boolean}
 */
export function isHomeGroup(group, groups) {
  if (!group) return false
  const gid = group.groupid ?? group.id
  if (gid == null || !Array.isArray(groups) || groups.length === 0) return false
  return isHomeGroupRow(groups, gid)
}

/**
 * Is the post's copy on `groupid` one the member posted DIRECTLY, as opposed to one
 * rippling created? This is the test every "may this community say something to the
 * poster?" decision needs, and it is per ROW: a TrashNothing cross-post is one post sent
 * directly to several communities, whose mails land a second apart, and every one of
 * those copies is home (Discourse 10115). homeGroupId still picks ONE of them, for the
 * things that need a single anchor (which chat a Blank Reply joins).
 *
 * Mirrors HomeGroups / NotifyPosterFlag in iznik-server-go (message/message.go).
 *
 * Fails open: with nothing known about the groups the answer is true, as the server's
 * "no home row survives" case is - better to offer the message than silently withhold
 * it. A row without rippled_in (an older API response) falls back to the earliest-arrival
 * heuristic homeGroupId uses.
 *
 * @param {Array<{groupid:number|string, arrival?:string, rippled_in?:number|boolean}>} groups
 * @param {number|string} groupid
 * @returns {boolean}
 */
export function isHomeGroupRow(groups, groupid) {
  if (!Array.isArray(groups) || groups.length === 0) return true
  const gid = parseInt(groupid)
  if (Number.isNaN(gid)) return true
  const row = groups.find((g) => g && parseInt(g.groupid) === gid)
  if (!row) return false
  if (row.rippled_in !== undefined && row.rippled_in !== null) {
    return !(row.rippled_in === 1 || row.rippled_in === true)
  }
  return homeGroupId(groups) === gid
}
