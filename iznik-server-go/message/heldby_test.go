package message

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

func heldbyPtr(v uint64) *uint64 { return &v }

// A hold is per-group: a viewer must only see a hold placed on one of THEIR OWN
// moderated groups. A hold another team placed on a rippled-out copy (a group
// the viewer doesn't moderate) must NOT leak to them — that was the bug behind
// "posts held by mods not on my team".
func TestEffectiveHeldby_OnlyViewersOwnGroups(t *testing.T) {
	// Message on group 10 (the viewer moderates it) and group 20 (another team),
	// held by mod 999 on group 20 only.
	groups := []MessageGroup{
		{Groupid: 10, Heldby: nil},
		{Groupid: 20, Heldby: heldbyPtr(999)},
	}

	// The mod of group 10 must NOT see the hold another team placed on group 20.
	assert.Nil(t, effectiveHeldby(groups, map[uint64]bool{10: true}),
		"another team's hold on a rippled-out copy must not leak to this viewer")

	// The mod of group 20 sees their own hold.
	got := effectiveHeldby(groups, map[uint64]bool{20: true})
	if assert.NotNil(t, got) {
		assert.Equal(t, uint64(999), *got)
	}

	// A hold on the viewer's own group is shown.
	own := []MessageGroup{{Groupid: 10, Heldby: heldbyPtr(777)}}
	got = effectiveHeldby(own, map[uint64]bool{10: true})
	if assert.NotNil(t, got) {
		assert.Equal(t, uint64(777), *got)
	}

	// Not held on any group -> nil.
	assert.Nil(t, effectiveHeldby([]MessageGroup{{Groupid: 10, Heldby: nil}}, map[uint64]bool{10: true}))

	// No groups / no viewer groups -> nil (never panics).
	assert.Nil(t, effectiveHeldby(nil, map[uint64]bool{10: true}))
	assert.Nil(t, effectiveHeldby(groups, nil))
}
