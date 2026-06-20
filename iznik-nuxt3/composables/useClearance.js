// Shared helpers for the bulk-offer ("clearance") management view. The interest
// rows on a bulk item are persisted with one of five states
// (Interested/Reserved/Collected/Withdrawn/Rejected — see
// messages_bulk_items_interest). This module maps those raw states to the
// decisions an offerer actually makes ("allocate", "decline", "mark collected")
// and provides the allocation maths the management UI shows. Keeping it pure
// (no Vue reactivity) means it can be unit-tested directly and reused across the
// manager, item and candidate components without duplication.

// Persisted states grouped for the management UI.
export const CLEARANCE_ALLOCATED_STATES = ['Reserved', 'Collected']
export const CLEARANCE_POOL_STATES = ['Interested']
export const CLEARANCE_INACTIVE_STATES = ['Withdrawn', 'Rejected']

// Offerer-facing label + badge variant per persisted state. Reserved is shown as
// "Allocated" because that's the offerer's mental model — the API also sends the
// access instructions to the replier on that transition.
export const CLEARANCE_STATE_META = {
  Interested: { label: 'Wants it', variant: 'secondary' },
  Reserved: { label: 'Allocated', variant: 'success' },
  Collected: { label: 'Collected', variant: 'primary' },
  // "Excluded" not "Declined": the offerer set them aside, but the polite note is
  // only sent when allocations are finalised — until then they can be restored.
  Rejected: { label: 'Excluded', variant: 'danger' },
  Withdrawn: { label: 'Withdrew', variant: 'light' },
}

export function clearanceStateLabel(state) {
  return CLEARANCE_STATE_META[state]?.label || state || 'Unknown'
}

export function clearanceStateVariant(state) {
  return CLEARANCE_STATE_META[state]?.variant || 'secondary'
}

export function isAllocatedState(state) {
  return CLEARANCE_ALLOCATED_STATES.includes(state)
}

export function isPoolState(state) {
  return CLEARANCE_POOL_STATES.includes(state)
}

export function isInactiveState(state) {
  return CLEARANCE_INACTIVE_STATES.includes(state)
}

// Whether an interest row still counts as "live". Mirrors the API's
// interestcount, which excludes withdrawn/rejected rows.
export function isActiveInterest(interest) {
  return !!interest && !isInactiveState(interest.state)
}

// Quantity allocated for an item: the sum of quantities across Reserved or
// Collected rows. Drives the "allocated N of M" progress.
export function allocatedQuantity(interest = []) {
  return interest
    .filter((i) => isAllocatedState(i.state))
    .reduce((sum, i) => sum + (parseInt(i.quantity, 10) || 0), 0)
}

// The number of distinct people with live interest across a set of items.
export function distinctInterestedUsers(items = []) {
  const ids = new Set()
  for (const item of items) {
    for (const i of item.interest || []) {
      if (isActiveInterest(i)) ids.add(i.userid)
    }
  }
  return ids.size
}

// The next-state buttons an offerer can press from a given state, as
// { state, label, variant } entries. Withdrawn has none — the replier backed
// out, so there's nothing for the offerer to decide.
export function clearanceActions(state) {
  switch (state) {
    case 'Interested':
      return [
        { state: 'Reserved', label: 'Allocate', variant: 'success' },
        { state: 'Rejected', label: 'Exclude', variant: 'outline-danger' },
      ]
    case 'Reserved':
      return [
        { state: 'Collected', label: 'Mark collected', variant: 'primary' },
        {
          state: 'Interested',
          label: 'Un-allocate',
          variant: 'outline-secondary',
        },
      ]
    case 'Collected':
      return [
        {
          state: 'Reserved',
          label: 'Undo collected',
          variant: 'outline-secondary',
        },
      ]
    case 'Rejected':
      return [
        { state: 'Interested', label: 'Restore', variant: 'outline-secondary' },
      ]
    default:
      return []
  }
}

// ---------------------------------------------------------------------------
// Freegle Helper (AI concierge) FSM. The helper_repliers / helper_item_states
// `state` column uses these values. The management page shows the same FSM the
// Helper reasons over, so these helpers map each state to an offerer-facing label,
// a badge variant, and a coarse group used to lay out the page.
// ---------------------------------------------------------------------------

// Coarse groups for page layout.
export const HELPER_GROUP_ALLOCATED = ['ALLOCATED', 'CONFIRMED', 'COLLECTED']
// Ready for a human decision — the scoring pool.
export const HELPER_GROUP_POOL = ['QUALIFIED']
// The AI couldn't handle the chat and needs the offerer — surfaced, never collapsed.
export const HELPER_GROUP_NEEDSYOU = ['ESCALATED']
// The Helper is still working these (contacted / chasing / parked).
// On the page these are "outreach": collapsed until the person replies.
export const HELPER_GROUP_OUTREACH = ['NEW', 'GATHERING', 'PARKED_REPLIED', 'PARKED_QUIET']
export const HELPER_GROUP_INACTIVE = ['TIMED_OUT', 'WITHDRAWN', 'REJECTED']

export const HELPER_STATE_META = {
  NEW: { label: 'New reply', variant: 'info', group: 'outreach' },
  GATHERING: { label: 'Gathering info', variant: 'info', group: 'outreach' },
  QUALIFIED: { label: 'Ready to decide', variant: 'warning', group: 'pool' },
  ALLOCATED: { label: 'Allocated', variant: 'success', group: 'allocated' },
  CONFIRMED: { label: 'Confirmed', variant: 'success', group: 'allocated' },
  COLLECTED: { label: 'Collected', variant: 'primary', group: 'allocated' },
  PARKED_REPLIED: { label: 'Fallback', variant: 'secondary', group: 'outreach' },
  PARKED_QUIET: { label: 'Fallback', variant: 'secondary', group: 'outreach' },
  ESCALATED: { label: 'Needs you', variant: 'danger', group: 'needsyou' },
  TIMED_OUT: { label: 'No reply', variant: 'light', group: 'inactive' },
  WITHDRAWN: { label: 'Withdrew', variant: 'light', group: 'inactive' },
  REJECTED: { label: 'Excluded', variant: 'light', group: 'inactive' },
}

export function helperStateLabel(state) {
  return HELPER_STATE_META[state]?.label || state || 'Unknown'
}

export function helperStateVariant(state) {
  return HELPER_STATE_META[state]?.variant || 'secondary'
}

export function helperStateGroup(state) {
  return HELPER_STATE_META[state]?.group || 'outreach'
}

export function isOutreachState(state) {
  return helperStateGroup(state) === 'outreach'
}

export function isNeedsYouState(state) {
  return helperStateGroup(state) === 'needsyou'
}

// The single decision-relevant status chip for a candidate row. Merges the
// authoritative interest state with the Helper FSM state so the row shows ONE
// status, not two competing badges:
//  - Reserved/Collected/Rejected (Withdrawn) -> the clearance outcome wins.
//  - otherwise -> the Helper FSM state (Ready to decide / Gathering info / Needs
//    you), or null when the Helper isn't running (nothing to add beyond "wants it",
//    which is implied by being listed).
export function candidateStatus(interestState, helperState) {
  if (interestState && interestState !== 'Interested') {
    return {
      label: clearanceStateLabel(interestState),
      variant: clearanceStateVariant(interestState),
    }
  }
  if (helperState) {
    return { label: helperStateLabel(helperState), variant: helperStateVariant(helperState) }
  }
  return null
}

// Format a 0–100 Helper score for display. Null/undefined → ''.
export function formatScore(score) {
  if (score === null || score === undefined || score === '') return ''
  const n = Number(score)
  return Number.isFinite(n) ? String(Math.round(n)) : ''
}

// Given the helper item_states for ONE bulk item, count candidates per FSM group,
// for the per-item summary shown at the top of each item. `states` is an array of
// item-state objects (each with a `state`).
export function summariseItemStates(states = []) {
  const summary = { allocated: 0, pool: 0, needsyou: 0, outreach: 0, inactive: 0, total: 0 }
  for (const s of states) {
    const g = helperStateGroup(s.state)
    if (summary[g] === undefined) continue
    summary[g]++
    summary.total++
  }
  return summary
}
