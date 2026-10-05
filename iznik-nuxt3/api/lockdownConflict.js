// A write refused because a lockdown surface is held (see
// plans/active/2026-09-27-lockdown-switch.md section 11) comes back as a 409
// carrying `lockdown: true` and a ready-to-show `status` message. That is an
// expected, handled outcome - the whole point of the lockdown - not a fault,
// so keep it out of Sentry. Pass as the logError argument to
// $postv2/$patchv2.
export const notALockdownConflict = (data) => !data?.lockdown

const DEFAULT_MESSAGE =
  'Changes are paused for a few hours while we deal with a security incident.'

// Turn that 409 into a tagged, readable error, or return null if this was some
// other failure and should propagate untouched.
export function asLockdownError(e) {
  if (e?.response?.status !== 409 || !e?.response?.data?.lockdown) return null

  const lockdown = new Error(e.response.data.status || DEFAULT_MESSAGE)
  lockdown.lockdownRefused = true
  return lockdown
}

// Run a write the server may refuse because a lockdown surface is held. On
// refusal our copy may be stale - the press happened after we last fetched -
// so run the caller's refresh to bring any "paused" state onto the screen,
// then hand the caller an error carrying the paused message.
export async function runLockdownAware(fn, refresh) {
  try {
    return await fn()
  } catch (e) {
    const lockdown = asLockdownError(e)
    if (!lockdown) throw e

    if (refresh) {
      try {
        await refresh()
      } catch (refreshError) {
        // Leave the stale copy in place; the thrown error still explains why.
      }
    }

    throw lockdown
  }
}
