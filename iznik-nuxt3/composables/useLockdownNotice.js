import { ref } from 'vue'

// Shared handling for "changes are paused" refusals (see
// plans/active/2026-09-27-lockdown-switch.md section 11.3). Support/Admin
// callers are exempt from every refusal, so this only ever fires for a
// member or ordinary moderator whose write reached the server while a
// lockdown surface was held.
//
// Wrap the write in guardLockdown() and render lockdownError somewhere
// visible instead of an error modal.
export function useLockdownNotice() {
  const lockdownError = ref(null)

  async function guardLockdown(fn) {
    lockdownError.value = null

    try {
      return await fn()
    } catch (e) {
      if (!e?.lockdownRefused) throw e
      lockdownError.value = e.message
    }
  }

  function clearLockdownError() {
    lockdownError.value = null
  }

  return { lockdownError, guardLockdown, clearLockdownError }
}
