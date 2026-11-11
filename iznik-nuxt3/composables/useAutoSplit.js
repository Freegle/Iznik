// Posts with an item list, and which of them are clearances.
//
// A clearance and an automatically split post both arrive with bulkitems (the item list,
// each item with its own photos and its own available flag). Only a clearance gets the
// clearance extras: "Register interest" instead of a reply, collection slots, the
// Clearances card in My Posts, the per-person counts when it is marked TAKEN. A split
// post is an ordinary member's OFFER that the server turned into an item list
// (message.autosplit), and is replied to and marked TAKEN like any other.
//
// Only an OFFER is ever split. The server undoes a split when the post is changed to a
// WANTED, and isAutoSplit checks the type as well, so no item list, "Mark TAKEN" or
// "Which would you like?" can show on a WANTED. docs/developers/reference/auto-split.md.

export function hasItemList(message) {
  return (message?.bulkcount || message?.bulkitems?.length || 0) > 0
}

export function isAutoSplit(message) {
  return (
    !!message?.autosplit && message?.type === 'Offer' && hasItemList(message)
  )
}

export function isClearance(message) {
  return hasItemList(message) && !message?.autosplit
}

// The first line a reply to a split post starts with when the replier picks items, so the
// poster can see which ones they want. It is ordinary text in the reply box, which the
// replier sees and can change before sending.
const CHOICE_PREFIX = "I'd like: "

export function itemChoiceLine(names) {
  return names.length ? CHOICE_PREFIX + names.join(', ') : ''
}

// Put the choice line at the top of a reply, replacing one already there.
export function withItemChoice(text, names) {
  const lines = (text || '').split('\n')
  const rest = lines[0]?.startsWith(CHOICE_PREFIX)
    ? lines.slice(1).join('\n')
    : text || ''
  const line = itemChoiceLine(names)
  if (!line) return rest
  return rest ? line + '\n' + rest : line
}
