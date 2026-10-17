// One way for every UX store to load a read and to fail. A slot is { data, loading, error, missing }.
// The tab changes its filters often, so a slow earlier answer must never replace a later one.

// The number of the newest request for each slot. Kept here, not in the store state, so it is never
// serialised and never reactive.
const newest = new WeakMap()

// What a panel says when the server has no route for its data yet.
export const NOT_SWITCHED_ON = 'This part is not switched on yet.'

export const idleSlot = () => ({
  data: null,
  loading: false,
  error: null,
  missing: false,
})

// A route the server does not have yet is not a fault. The site can go live before the Go API, and
// the panel then says that this part is not switched on.
const MISSING = [404, 501]

/**
 * Run `fetcher` and put the answer in `slot`. The newest call for a slot wins, whether the older
 * ones succeed or fail, and `loading` stays true until the newest call ends.
 *
 * `onLanded` is called, with no arguments, in the same step that puts the answer in the slot, and
 * only for an answer that was stored: not for an older one, and not for a failure. Whatever
 * describes the answer in the slot (what it was asked for) is set there, so it is never a tick
 * behind the answer, and never belongs to a request whose answer was thrown away.
 */
export async function loadInto(slot, fetcher, onLanded) {
  const mine = (newest.get(slot) || 0) + 1
  newest.set(slot, mine)
  slot.loading = true
  slot.error = null
  slot.missing = false
  try {
    const data = await fetcher()
    if (newest.get(slot) === mine) {
      slot.data = data
      if (onLanded) onLanded()
    }
  } catch (e) {
    if (newest.get(slot) === mine) {
      slot.data = null
      if (MISSING.includes(e?.response?.status)) {
        slot.missing = true
      } else {
        slot.error = e?.message || 'Unknown error'
      }
    }
  } finally {
    if (newest.get(slot) === mine) {
      slot.loading = false
    }
  }
}

/**
 * Whether a slot has finished with its first answer, good or bad. An idle slot, which nothing has
 * asked yet, is not settled: a section waits for it rather than drawing an empty state.
 */
export const settled = (slot) =>
  !slot.loading && (slot.data !== null || !!slot.error || slot.missing)
