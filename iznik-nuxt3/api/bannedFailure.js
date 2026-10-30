// A join/add refused because the member is banned from the group comes back as a
// 403 "Failed - banned" (see addMemberToGroup / putMembershipsPartner in
// iznik-server-go). A moderator's add of a TrashNothing member comes back as a 403
// "Failed - TrashNothing member" (putMemberships). Both are expected outcomes - a
// moderator may try to add a banned or TrashNothing member, or a banned member may try
// to rejoin - not faults, so keep them out of Sentry. Pass as the logError argument to
// $putv2.
export const notABannedFailure = (data) => {
  const s = typeof data === 'string' ? data : JSON.stringify(data ?? '')
  return !/banned|TrashNothing member/i.test(s)
}

function refusedWith(e, pattern) {
  if (e?.response?.status !== 403) {
    return false
  }
  const data = e?.response?.data
  const s = typeof data === 'string' ? data : JSON.stringify(data ?? '')
  return pattern.test(s)
}

// True if a caught error is the 403 "Failed - banned" refusal. Callers decide how to
// react: a member's own join is swallowed silently (don't reveal the ban), a moderator's
// add is surfaced so they know why nothing happened.
export function isBannedFailure(e) {
  return refusedWith(e, /banned/i)
}

// True if a caught error is the 403 refusal of a moderator's add of a TrashNothing member.
export function isTrashNothingFailure(e) {
  return refusedWith(e, /TrashNothing member/i)
}
