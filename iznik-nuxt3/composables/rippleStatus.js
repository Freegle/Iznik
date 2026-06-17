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
  if (!Array.isArray(groups) || groups.length < 2) return false

  const ctxId = parseInt(contextGroupid)
  const ctx =
    groups.find((g) => parseInt(g.groupid) === ctxId) || groups[0]
  if (!ctx || !ctx.arrival) return false

  const ctxArrival = new Date(ctx.arrival).getTime()
  if (Number.isNaN(ctxArrival)) return false

  const arrivals = groups
    .map((g) => new Date(g.arrival).getTime())
    .filter((t) => !Number.isNaN(t))
  if (!arrivals.length) return false

  const earliest = Math.min(...arrivals)
  return ctxArrival > earliest + thresholdMs
}
