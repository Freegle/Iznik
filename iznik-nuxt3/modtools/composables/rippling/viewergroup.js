/**
 * Which group the catchment tab should open on, given the viewer's own groups.
 *
 * The tab draws nothing until a group is chosen, and landing on an empty map read as
 * the feature being broken (Discourse 9808/728). A moderator almost always came to
 * look at one of their own groups, so open on it — but only when there is exactly one
 * candidate. With several, any pick is a guess, and a map confidently showing the
 * wrong group is worse than an empty one with the cursor in the picker.
 *
 * @param {string[]} myGroupNames the viewer's group names, in any case
 * @param {Map<string,object>} groupsByName lower-cased name -> group, the explorer's own list
 * @returns {object|null} the group to open on, or null to leave it to the user
 */
export function pickViewerGroup(myGroupNames, groupsByName) {
  if (!Array.isArray(myGroupNames) || !groupsByName) return null

  const seen = new Set()
  const mine = []
  for (const name of myGroupNames) {
    if (typeof name !== 'string') continue
    const key = name.trim().toLowerCase()
    if (!key || seen.has(key)) continue
    seen.add(key)
    // A name we don't recognise is not a candidate: the picker only accepts groups
    // from its own list, so seeding one would leave the box filled and the map empty.
    const g = groupsByName.get(key)
    if (g) mine.push(g)
  }

  return mine.length === 1 ? mine[0] : null
}
