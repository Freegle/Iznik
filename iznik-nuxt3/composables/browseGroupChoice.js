// "Show posts from" can name a single community, and that choice is sticky like sort and post
// type. It lives in settings.browseGroup rather than in browseView, which records only the two
// whole-feed views ('nearby' / 'mygroups'); naming one community narrows whichever of those is
// active, client-side, so it is a separate axis.
//
// Resolving the stored id needs the member's group list, which arrives after their settings do.
// An empty list therefore means "not loaded yet", not "not a member" - treating it as the latter
// would drop the saved choice on every cold load, which is the failure this whole key exists to
// stop. Once the list is there, a community the member has left stops filtering the feed to posts
// they can no longer see.
//
// Returns the group id to filter the feed by, or 0 for no group filter.
export function resolveBrowseGroup(saved, groups) {
  const id = parseInt(saved)

  if (!(id > 0)) {
    return 0
  }

  if (!groups?.length) {
    return id
  }

  return groups.some((g) => parseInt(g.id) === id) ? id : 0
}
