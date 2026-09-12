import { ref, watch } from 'vue'

// The community header at the top of a feed filtered to one community ("Show posts from")
// is a full card: logo, tagline, description, links, volunteers, sponsors. That is useful
// while a community is new to you. Once you have been a member for a while it is just a
// tall block standing between you and the posts, so it starts collapsed to a small bar
// (logo, name, a button to show the full detail).
//
// Only members collapse. Someone who has not joined still needs the full header, because
// that is where the Join button and the description live.

export const GROUP_HEADER_FULL_DAYS = 7

// membership: the viewer's membership for this community ({added, ...}), or null/undefined
// when not a member. now: injectable clock for tests.
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

// The feed owns whether its community header is folded up. GroupHeader only renders the
// state and asks to change it, so the rule and the reset live here, next to each other.
//
// group: ref to the community shown (needs .id); memberships: ref to the viewer's
// memberships (useMe().myGroups entries, keyed by .id, or raw auth store rows keyed by
// .groupid). Returns a ref the parent binds with v-model:collapsed.
//
// The starting state is recomputed when the feed moves to another community, or when the
// membership's join date arrives or changes. It is NOT recomputed when the memberships
// array is merely replaced with equal data, so a member who has just pressed "Show
// details" is not folded up again by a routine refresh.
export function useGroupHeaderCollapsed(
  group,
  memberships,
  now = () => new Date()
) {
  const collapsed = ref(false)

  const membershipFor = () => {
    const id = parseInt(group.value?.id)
    return (
      (memberships.value || []).find(
        (g) => parseInt(g.id ?? g.groupid) === id
      ) || null
    )
  }

  watch(
    [() => group.value?.id, () => membershipFor()?.added ?? null],
    () => {
      collapsed.value = groupHeaderStartsCollapsed(membershipFor(), now())
    },
    { immediate: true }
  )

  return collapsed
}
