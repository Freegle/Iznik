// A moderation action refused because another moderator holds the item comes back
// as a 409 carrying `heldby` (see the hold enforcement in iznik-server-go). That is
// an expected, handled outcome - the whole point of a hold - not a fault, so keep it
// out of Sentry. Pass as the logError argument to $postv2/$patchv2.
export const notAHeldConflict = (data) => !data?.heldby

// Turn that 409 into a tagged, readable error, or return null if this was some
// other failure and should propagate untouched.
export function asHeldByOtherError(e) {
  if (e?.response?.status !== 409 || !e?.response?.data?.heldby) return null

  const who = e.response.data.heldbyname || 'Another moderator'
  const held = new Error(
    `${who} is holding this. Check with them, or release it first.`
  )
  held.heldByOtherMod = true
  held.heldby = e.response.data.heldby
  return held
}

// Run a moderation action the server may refuse because someone else holds the
// item. On refusal our copy is normally stale - the hold happened after we last
// fetched - so run the caller's refresh to bring the "Held by X" state onto the
// screen, then hand the caller an error naming the holder.
export async function runHoldAware(fn, refresh) {
  try {
    return await fn()
  } catch (e) {
    const held = asHeldByOtherError(e)
    if (!held) throw e

    if (refresh) {
      try {
        await refresh()
      } catch (refreshError) {
        // Leave the stale copy in place; the thrown error still explains why.
      }
    }

    throw held
  }
}
