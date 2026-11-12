package utils

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

func TestAutomodShadowGroup_ListedGroup(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "1,2, 3")

	assert.True(t, AutomodShadowGroup(2))
	assert.False(t, AutomodShadowGroup(4))
}

func TestAutomodShadowGroup_EmptyList(t *testing.T) {
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")

	assert.False(t, AutomodShadowGroup(1))
}

func TestAutomodShadowGroup_NotPromotedByAutoapproveEnabled(t *testing.T) {
	// Shadow mode is a separate, more cautious trial phase - the approve path's global
	// switch must not silently turn every group into a shadow group too.
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")

	assert.False(t, AutomodShadowGroup(1))
}

func TestAutomodGroup_ShadowOnly(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "")
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", "")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "5")

	assert.True(t, AutomodGroup(5))
	assert.False(t, AutomodGroup(6))
}

func TestAutomodGroup_ApproveOnly(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", "7")

	assert.True(t, AutomodGroup(7))
	assert.False(t, AutomodGroup(8))
}

func TestAutomodGroup_Neither(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", "")

	assert.False(t, AutomodGroup(9))
}

func TestAutomodMode_Approve(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "")
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", "10")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")

	assert.Equal(t, "approve", AutomodMode(10))
}

func TestAutomodMode_Shadow(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "")
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", "")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "11")

	assert.Equal(t, "shadow", AutomodMode(11))
}

func TestAutomodMode_Neither(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "")
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", "")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")

	assert.Equal(t, "", AutomodMode(12))
}

func TestAutomodMode_ShadowWinsOverApprove(t *testing.T) {
	// A group should never be in both lists, but shadow is the more cautious claim if it
	// is, and the batch's AutomodMode::for() picks the same.
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "")
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", "13")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "13")

	assert.Equal(t, "shadow", AutomodMode(13))
	assert.False(t, AutoapproveTrialGroup(13))
	assert.True(t, AutomodGroup(13))
}

func TestAutomodMode_EnabledEverywhereKeepsShadowGroupsShadow(t *testing.T) {
	// FREEGLE_AUTOAPPROVE_ENABLED makes AutoapproveTrialGroup true for every group that
	// is not a shadow group. A shadow group was chosen for the more cautious phase on
	// purpose, so the global switch does not promote it.
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", "")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "15")

	assert.Equal(t, "approve", AutomodMode(14))
	assert.True(t, AutoapproveTrialGroup(14))
	assert.Equal(t, "shadow", AutomodMode(15))
	assert.False(t, AutoapproveTrialGroup(15))
	assert.Equal(t, []uint64{14}, AutoapproveTrialGroups([]uint64{14, 15}))
}

func TestAutoapproveDelayMinutes(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_DELAY_MINUTES", "")
	assert.Equal(t, 20, AutoapproveDelayMinutes())
	t.Setenv("FREEGLE_AUTOAPPROVE_DELAY_MINUTES", " 45 ")
	assert.Equal(t, 45, AutoapproveDelayMinutes())
	t.Setenv("FREEGLE_AUTOAPPROVE_DELAY_MINUTES", "0")
	assert.Equal(t, 20, AutoapproveDelayMinutes())
}
