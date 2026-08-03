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

// A moderator who covers several communities (common - Discourse 9481/642, "Derek")
// moderates BOTH group 10 (unheld, e.g. Fife) and group 20 (held by another team's
// mod, e.g. Livingston) on the same rippled message. The bundled-app compat field is
// message-wide, so it cannot say "held on 20, not on 10" - it can only say yes/no. The
// old effectiveHeldby answered "yes" as soon as it found ANY held group among the
// ones the viewer moderates, which shows a hold that belongs to a community the
// viewer isn't even looking at as if it were on the one they are - the exact report
// ("the held post on Fife is still showing, unsure if [the other mod] has released
// it") when the viewer never held anything on Fife at all. Because a compat client
// can't disambiguate WHICH of the viewer's groups the flag refers to, showing "held"
// here is actively misleading rather than merely imprecise, so the safe answer when
// the viewer's own groups disagree is nil - matching what an unheld post shows. The
// single-group case (by far the most common) is untouched: this only changes multi-
// group ambiguity.
func TestEffectiveHeldby_AmbiguousAcrossViewersOwnGroupsReturnsNil(t *testing.T) {
	groups := []MessageGroup{
		{Groupid: 10, Heldby: nil},            // e.g. Fife - not held
		{Groupid: 20, Heldby: heldbyPtr(999)}, // e.g. Livingston - held by another mod
	}

	// Viewer moderates BOTH groups. Group 10 (the one presumably being looked at) is
	// not held, so the compat field must not claim it is just because group 20 is.
	assert.Nil(t, effectiveHeldby(groups, map[uint64]bool{10: true, 20: true}),
		"a hold on one of the viewer's OTHER groups must not make an unheld one of their groups look held")

	// If every one of the viewer's own groups on the message agrees (all held), the
	// compat field can safely report it.
	allHeld := []MessageGroup{
		{Groupid: 10, Heldby: heldbyPtr(999)},
		{Groupid: 20, Heldby: heldbyPtr(999)},
	}
	got := effectiveHeldby(allHeld, map[uint64]bool{10: true, 20: true})
	if assert.NotNil(t, got) {
		assert.Equal(t, uint64(999), *got)
	}
}
