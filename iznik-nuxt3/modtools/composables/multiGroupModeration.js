// Moderating a post on several of your communities at once.
//
// A post can be pending on many neighbouring communities run by the same moderator - its
// home community, and the communities it rippled into whose own rules held it. ModTools
// shows one card per post, and Approve, Reject and Delete on that card act on every one of
// those communities by default, by sending them as groupids (the Go API's
// resolveActionGroups). These helpers work out which copies that is.
//
// Copies are left alone, and named on the card, when the moderator may not act on them:
// one held by another moderator, or a rippled-in copy the home community has locked while
// it reviews the post. The server skips the same copies, so this is about saying so, not
// about enforcing it.

import { isHomeGroupRow } from '~/composables/rippleStatus'

// The collections ModTools shows in the Pending queue.
export const REVIEW_COLLECTIONS = ['Pending', 'PendingOther', 'Spam']

function heldbyId(row) {
  const h = row?.heldby
  if (!h) return null
  return parseInt(typeof h === 'object' ? h.id : h) || null
}

// Whether a copy is held by somebody other than myid.
export function heldByOther(row, myid) {
  const holder = heldbyId(row)
  return Boolean(holder) && holder !== parseInt(myid)
}

/**
 * Split the post's copies on communities this moderator runs, still waiting for a
 * moderator, into the ones an action can take and the ones it must leave alone.
 *
 * A home copy's lock on the rippled-in copies is lifted by approving the home copy, so
 * when an unheld home copy is among those being acted on, its locked copies go along with
 * it - the server approves them together.
 *
 * @param {Array} groups message.groups
 * @param {(groupid:number) => boolean} amAModOn
 * @param {number} myid
 * @returns {{all:Array, actionable:Array, held:Array, locked:Array}}
 */
export function splitPendingCopies(groups, amAModOn, myid) {
  const rows = Array.isArray(groups) ? groups : []
  const all = rows.filter(
    (g) =>
      REVIEW_COLLECTIONS.includes(g.collection) &&
      !g.deleted &&
      amAModOn(parseInt(g.groupid))
  )

  const held = all.filter((g) => heldByOther(g, myid))
  const free = all.filter((g) => !heldByOther(g, myid))
  const homeAmongFree = free.some((g) => isHomeGroupRow(rows, g.groupid))
  const locked = homeAmongFree
    ? []
    : free.filter((g) => parseInt(g.locked_by_home) === 1)
  const actionable = free.filter((g) => !locked.includes(g))

  return { all, actionable, held, locked }
}

/**
 * The groupids to send for an action on every community, with the community being looked
 * at first: when the member is to be told, the server writes from the first community in
 * the list that may write to them. Null when there is only one community to act on, or
 * when the community being looked at is not one of them (a copy held by somebody else or
 * locked by the home community), so the action goes out exactly as a single-community one
 * always has rather than acting everywhere except the copy on screen.
 *
 * @param {number|null} currentGroupid
 * @param {Array} actionable rows from splitPendingCopies
 * @returns {number[]|null}
 */
export function actionGroupids(currentGroupid, actionable) {
  const ids = (actionable || []).map((g) => parseInt(g.groupid))
  if (ids.length < 2) return null

  const current = parseInt(currentGroupid)
  if (!ids.includes(current)) return null

  return [current, ...ids.filter((id) => id !== current)]
}
