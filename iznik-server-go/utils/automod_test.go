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

func TestAutomodMode_ApproveWinsOverShadow(t *testing.T) {
	// A group should never be in both lists, but approve is the stronger claim if it is.
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "")
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", "13")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "13")

	assert.Equal(t, "approve", AutomodMode(13))
}

func TestAutomodMode_EnabledEverywhereStillShadowFalse(t *testing.T) {
	// FREEGLE_AUTOAPPROVE_ENABLED makes AutoapproveTrialGroup true everywhere, so
	// AutomodMode reports "approve" for every group - it never falls through to "shadow"
	// in that state, since the approve check runs first and always wins.
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", "")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")

	assert.Equal(t, "approve", AutomodMode(14))
}
