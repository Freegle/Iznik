import { ref } from 'vue'

// Shared handling for "another moderator is holding this" refusals.
//
// The server enforces holds (see the hold checks in iznik-server-go), so a
// moderator acting from a stale screen gets a 409 instead of the action going
// through. The store has already re-fetched by the time we get here, so the
// "Held by X" state is on screen - but they still clicked a button and must be
// told plainly that it did not happen, and by whom.
//
// Wrap the action in guardHold() and render heldError somewhere visible.
export function useHeldNotice() {
  const heldError = ref(null)

  async function guardHold(fn) {
    heldError.value = null

    try {
      return await fn()
    } catch (e) {
      if (!e?.heldByOtherMod) throw e
      heldError.value = e.message
    }
  }

  function clearHeldError() {
    heldError.value = null
  }

  return { heldError, guardHold, clearHeldError }
}
