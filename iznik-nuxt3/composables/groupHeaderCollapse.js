// The community header at the top of a feed filtered to one community ("Show posts from")
// is a full card: logo, tagline, description, links, volunteers, sponsors. That is useful
// while a community is new to you. Once you have been a member for a while it is just a
// tall block standing between you and the posts, so it starts collapsed to a small bar
// (logo, name, a button to show the full detail).
//
// Only members collapse. Someone who has not joined still needs the full header, because
// that is where the Join button and the description live.

export const GROUP_HEADER_FULL_DAYS = 7

// membership: the viewer's membership row for this community from the auth store
// (authStore.groups entry: {groupid, added, ...}), or null/undefined when not a member.
// now: injectable clock for tests.
//
// Returns true when the header should start collapsed.
export function groupHeaderStartsCollapsed(membership, now = new Date()) {
  if (!membership) {
    return false
  }

  if (!membership.added) {
    // A membership with no join date is not a new one - the API always sends the date, so
    // this is a long-standing membership from before it was recorded.
    return true
  }

  const added = new Date(membership.added).getTime()

  if (Number.isNaN(added)) {
    return true
  }

  const nowMs = now instanceof Date ? now.getTime() : Number(now)
  const fullUntil = added + GROUP_HEADER_FULL_DAYS * 24 * 60 * 60 * 1000

  return nowMs >= fullUntil
}
