// A join/add refused because the member is banned from the group comes back as a
// 403 "Failed - banned" (see addMemberToGroup / putMembershipsPartner in
// iznik-server-go). That is an expected outcome - a moderator may try to add a banned
// member, or a banned member may try to rejoin - not a fault, so keep it out of Sentry.
// Pass as the logError argument to $putv2.
export const notABannedFailure = (data) => {
  const s = typeof data === 'string' ? data : JSON.stringify(data ?? '')
  return !/banned/i.test(s)
}

// True if a caught error is the 403 "Failed - banned" refusal. Callers decide how to
// react: a member's own join is swallowed silently (don't reveal the ban), a moderator's
// add is surfaced so they know why nothing happened.
export function isBannedFailure(e) {
  if (e?.response?.status !== 403) {
    return false
  }
  const data = e?.response?.data
  const s = typeof data === 'string' ? data : JSON.stringify(data ?? '')
  return /banned/i.test(s)
}
