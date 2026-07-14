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
  const homeId = homeGroupId(groups)
  if (homeId == null) return false
  const gid = group.groupid ?? group.id
  return gid != null && parseInt(gid) === homeId
}

/**
 * Coerce a heldby value - numeric, numeric-string, or object ({id, displayname}, the
 * legacy PHP-API shape) - to a plain numeric user id. Returns null for anything that
 * isn't held (null/undefined/unparseable).
 *
 * @param {number|string|{id:number}|null|undefined} h
 * @returns {number|null}
 */
export function normaliseHeldById(h) {
  if (h === null || h === undefined) return null
  if (typeof h === 'object') return h.id ?? null
  const n = parseInt(h)
  return Number.isNaN(n) ? null : n
}

/**
 * The messages_groups row for the given group on this message - i.e. the group's own
 * copy, as opposed to the legacy cross-group messages.heldby column, which is set
 * whenever ANY group holds ANY copy of a rippled post. Reading that legacy field leaked
 * a hold placed on one group's copy into every other group's view of the same message: a
 * mod saw their own already-approved copy display "Held by X, check with them before
 * releasing it" even though their copy was never held (Discourse 9904).
 *
 * Distinguishes "loaded, but no row" from "not loaded yet", so callers can tell a
 * genuinely-resolved absence apart from a still-loading message:
 *  - the matching row object if found
 *  - null if `message.groups` is a loaded array but has no row for this group
 *  - undefined if `message.groups` isn't an array yet (not loaded), or `groupid` can't
 *    be resolved to a number at all
 *
 * @param {{groups?: Array<{groupid:number|string, heldby?:number|string|{id:number}|null}>}} message
 * @param {number|string} groupid
 * @returns {object|null|undefined}
 */
export function messageGroupRow(message, groupid) {
  const groups = message?.groups
  if (!Array.isArray(groups)) return undefined
  const gid = parseInt(groupid)
  if (Number.isNaN(gid)) return undefined
  return groups.find((g) => parseInt(g.groupid) === gid) ?? null
}

/**
 * The id of the moderator holding THIS message on THIS group - see messageGroupRow for
 * the loaded/not-loaded distinction. This is the ONLY place that should turn a group row
 * into a held-by id; every consumer (the held-by banner, the Hold/Release button toggle,
 * the confirm-before-acting check) must call this rather than inspecting
 * message.heldby or a group row directly, so they can't drift out of sync with each
 * other.
 *
 * Returns:
 *  - a numeric user id if the group's own copy is held
 *  - null if the row is loaded and definitely NOT held (including a loaded-but-empty
 *    groups array, which conclusively has no row for this group)
 *  - undefined if the row can't be identified at all (message still loading) - callers
 *    must not guess who holds it in this case, since that mod may be on a group the
 *    reader can't act on.
 *
 * @param {object} message
 * @param {number|string} groupid
 * @returns {number|null|undefined}
 */
export function messageGroupHeldById(message, groupid) {
  const row = messageGroupRow(message, groupid)
  if (row === undefined || row === null) return row
  return normaliseHeldById(row.heldby)
}
