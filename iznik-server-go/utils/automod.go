package utils

// AutomodShadowGroup is true for a group listed in FREEGLE_AUTOMOD_SHADOW_GROUPS
// (comma-separated ids): the automod chart runs and records a decision on every Pending
// post but changes nothing - no countdown, no auto-approval. Shadow wins over the approve
// list and over FREEGLE_AUTOAPPROVE_ENABLED (see AutoapproveTrialGroup): that flag is the
// approve path's global switch, and shadow mode is a separate, more cautious trial phase
// that an approve-everywhere flag must not silently promote.
func AutomodShadowGroup(gid uint64) bool {
	return envListHasGroup("FREEGLE_AUTOMOD_SHADOW_GROUPS", gid)
}

// AutomodGroup is true for a group in either automod list, shadow or approve. This gates
// everything the automod chart's decision touches in ModTools - the message payload's
// automod block, the feedback endpoint and the session automod flag.
func AutomodGroup(gid uint64) bool {
	return AutomodShadowGroup(gid) || AutoapproveTrialGroup(gid)
}

// AutomodMode returns which automod list a group is in: "shadow" (chart runs, decides
// nothing), "approve" (chart decisions can auto-approve a clean post) or "" (neither list,
// automod plays no part for this group). A group in both lists is shadow: the batch's
// AutomodMode::for() makes the same choice, so the countdown and the cron agree.
func AutomodMode(gid uint64) string {
	if AutomodShadowGroup(gid) {
		return "shadow"
	}

	if AutoapproveTrialGroup(gid) {
		return "approve"
	}

	return ""
}
