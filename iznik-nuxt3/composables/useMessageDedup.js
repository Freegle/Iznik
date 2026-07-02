// Pure de-duplication / grouping helpers for the Browse feed, extracted from
// MessageList.vue and PostMap.vue so they can be unit tested and so the hot paths run in
// O(n) instead of O(n^2). Behaviour (which item is kept, the output order) is unchanged.

// Build the dedup key for a message. Mirrors the original inline logic exactly: strip a
// trailing "(location)" so the same poster's crossposts of one item collapse, and when
// the subject has a "TYPE: ..." prefix key on type + the stripped remainder.
function dedupKey(message) {
  const stripLocation = (s) => s.replace(/\s*\([^)]*\)\s*$/, '').trimEnd()
  let key = message.fromuser + '|' + stripLocation(message.subject)
  const p = message.subject.indexOf(':')

  if (p !== -1) {
    key =
      message.fromuser +
      '|' +
      message.type +
      stripLocation(message.subject.substring(p))
  }

  return key
}

// De-duplicate the browse list: collapse a poster's crosspost/repost of the same item to
// a single entry, preferring the copy on a group the viewer belongs to (so replying does
// not silently sign them up to a non-member group - Discourse 9733 / 9729).
// firstSeenMessage always wins and is never displaced. The kept items are returned in
// their original input order.
//
// This is the O(n) form of MessageList.vue's deDuplicatedMessages: the member-group swap
// now looks the kept item up in an id->index Map instead of ret.findIndex() (which was an
// O(n) scan per swap, i.e. O(n^2) when many items are duplicates).
//
//   items            - list to dedup (feed summary objects with .id)
//   getMessage(id)   - full message detail for id (or undefined if not loaded yet)
//   exclude          - an id to drop entirely, or null
//   firstSeenMessage - id that must always be kept and never displaced, or null
//   isOnMyGroup(msg) - true if msg is on a group the viewer belongs to
//   failedIds        - Set of ids to skip (failed to load), or null
export function deduplicateMessages(
  items,
  {
    getMessage,
    exclude = null,
    firstSeenMessage = null,
    isOnMyGroup = () => false,
    failedIds = null,
  } = {}
) {
  const ret = []
  const retIndexById = new Map()
  const dups = Object.create(null) // dedupKey -> kept id
  const idsSeen = Object.create(null) // ids whose dedup key we've already processed
  const seen = new Set() // ids pushed raw because no detail was available yet

  const pushKept = (m) => {
    retIndexById.set(m.id, ret.length)
    ret.push(m)
  }

  for (const m of items || []) {
    if (failedIds && failedIds.has(m.id)) {
      continue
    }

    if (seen.has(m.id)) {
      continue
    }

    const message = getMessage ? getMessage(m.id) : undefined

    if (!message) {
      // No detail cached yet - keep it as-is; if it recurs we skip it via `seen`.
      seen.add(m.id)
      pushKept(m)
    } else if (m.id in idsSeen) {
      // Already processed this id.
    } else if (m.id !== exclude) {
      idsSeen[m.id] = true
      const key = dedupKey(message)
      const already = key in dups

      if (m.id === firstSeenMessage) {
        if (already) {
          // firstSeenMessage displaces the previously-kept copy of this item.
          const removeId = dups[key]
          const idx = retIndexById.get(removeId)
          if (idx !== undefined) {
            ret.splice(idx, 1)
            // Indices after the removed slot shifted - rebuild the map. This branch runs
            // at most once (firstSeenMessage is a single id), so the O(n) rebuild is fine.
            retIndexById.clear()
            for (let i = 0; i < ret.length; i++) {
              retIndexById.set(ret[i].id, i)
            }
          }
        }
        pushKept(m)
        dups[key] = m.id
      } else if (!already) {
        pushKept(m)
        dups[key] = m.id
      } else {
        // Duplicate of one we're already showing. Prefer the copy on a group the viewer
        // belongs to; firstSeenMessage is never replaced.
        const keptId = dups[key]
        if (
          keptId !== firstSeenMessage &&
          isOnMyGroup(message) &&
          !isOnMyGroup(getMessage ? getMessage(keptId) : undefined)
        ) {
          const idx = retIndexById.get(keptId)
          if (idx !== undefined) {
            ret[idx] = m
            retIndexById.delete(keptId)
            retIndexById.set(m.id, idx)
          }
          dups[key] = m.id
        }
      }
    }
  }

  return ret
}

// The complement of deduplicateMessages: the items that were dropped (crossposts, the
// excluded id, failed-to-load ids). O(n) via a Set of kept ids, replacing the previous
// O(n^2) forEach + Array.find() scan.
export function findDuplicates(items, keptList) {
  const keptIds = new Set((keptList || []).map((d) => d.id))
  return (items || []).filter((m) => !keptIds.has(m.id))
}

// Distinct groupids in first-appearance order. Replaces an O(n*u) includes()-in-loop.
export function distinctGroupIds(messages) {
  if (!messages) {
    return []
  }
  return [...new Set(messages.map((m) => m.groupid))]
}
