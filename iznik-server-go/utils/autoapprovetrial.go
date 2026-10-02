package utils

import (
	"os"
	"strconv"
	"strings"
)

// AutoapproveTrialGroup mirrors the PHP rollout gate (AutoApproveCleanService::enabledGroupIds):
// FREEGLE_AUTOAPPROVE_ENABLED truthy enables post-moderation everywhere; otherwise only the
// groups listed in FREEGLE_AUTOAPPROVE_TRIAL_GROUPS (comma-separated ids) take part. Everything
// post-moderation shows to moderators (the countdown, the Check queue and its badge) is gated on
// this, so a community outside the trial looks as it did before.
func AutoapproveTrialGroup(gid uint64) bool {
	v := os.Getenv("FREEGLE_AUTOAPPROVE_ENABLED")
	if v == "true" || v == "1" {
		return true
	}

	for _, part := range strings.Split(os.Getenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS"), ",") {
		if id, err := strconv.ParseUint(strings.TrimSpace(part), 10, 64); err == nil && id == gid {
			return true
		}
	}

	return false
}

// AutoapproveTrialGroups returns the subset of gids that take part in post-moderation.
func AutoapproveTrialGroups(gids []uint64) []uint64 {
	var ret []uint64
	for _, gid := range gids {
		if AutoapproveTrialGroup(gid) {
			ret = append(ret, gid)
		}
	}

	return ret
}
