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
  Rejected: { label: 'Declined', variant: 'danger' },
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

// Quantity actually collected for an item: only Collected rows count.
// Used to decide whether every item in a clearance has been picked up.
export function collectedQuantity(interest = []) {
  return interest
    .filter((i) => i.state === 'Collected')
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
        { state: 'Rejected', label: 'Decline', variant: 'outline-danger' },
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
