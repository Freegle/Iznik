package utils

import (
	"os"
	"strconv"
	"strings"
)

// AutomodShadowGroup is true for a group listed in FREEGLE_AUTOMOD_SHADOW_GROUPS
// (comma-separated ids): the automod chart runs and records a decision on every Pending
// post but changes nothing - no countdown, no auto-approval. This is deliberately not
// covered by FREEGLE_AUTOAPPROVE_ENABLED: that flag is the approve path's own global
// switch (AutoapproveTrialGroup), and shadow mode is a separate, more cautious trial
// phase that an approve-everywhere flag should not silently promote.
func AutomodShadowGroup(gid uint64) bool {
	for _, part := range strings.Split(os.Getenv("FREEGLE_AUTOMOD_SHADOW_GROUPS"), ",") {
		if id, err := strconv.ParseUint(strings.TrimSpace(part), 10, 64); err == nil && id == gid {
			return true
		}
	}

	return false
}

// AutomodGroup is true for a group in either automod list, shadow or approve. This gates
// everything the automod chart's decision touches in ModTools - the message payload's
// automod block, the feedback endpoint and the session automod flag.
func AutomodGroup(gid uint64) bool {
	return AutomodShadowGroup(gid) || AutoapproveTrialGroup(gid)
}

// AutomodMode returns which automod list a group is in: "approve" (chart decisions can
// auto-approve a clean post), "shadow" (chart runs, decides nothing) or "" (neither list,
// automod plays no part for this group). A group should never be in both lists, but if it
// were, "approve" is the stronger claim so it wins.
func AutomodMode(gid uint64) string {
	if AutoapproveTrialGroup(gid) {
		return "approve"
	}

	if AutomodShadowGroup(gid) {
		return "shadow"
	}

	return ""
}
