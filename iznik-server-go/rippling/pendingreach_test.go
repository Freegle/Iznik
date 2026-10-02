package rippling

import (
	"strings"
	"testing"
)

// The gate is on by default: rippling governs every group, so a post with no
// reach row is genuinely not live anywhere.
func TestHidePendingReachIsOnByDefault(t *testing.T) {
	t.Setenv("RIPPLE_HIDE_PENDING", "")

	if !HidePendingReach() {
		t.Fatal("the pending-reach gate should be on when nothing switches it off")
	}
}

// RIPPLE_HIDE_PENDING switches the gate off without a deploy, which is the
// lever if the grace period ever hides more than it should.
func TestHidePendingReachRespectsTheOffSwitch(t *testing.T) {
	for _, off := range []string{"0", "false", "no", "off", "OFF", " off "} {
		t.Setenv("RIPPLE_HIDE_PENDING", off)

		if HidePendingReach() {
			t.Fatalf("RIPPLE_HIDE_PENDING=%q should switch the gate off", off)
		}
	}

	for _, on := range []string{"1", "true", "yes", "anything else"} {
		t.Setenv("RIPPLE_HIDE_PENDING", on)

		if !HidePendingReach() {
			t.Fatalf("RIPPLE_HIDE_PENDING=%q should leave the gate on", on)
		}
	}
}

// Switched off, the filter is a predicate callers can still splice in
// unconditionally.
func TestReachPendingFilterIsAlwaysTrueWhenOff(t *testing.T) {
	t.Setenv("RIPPLE_HIDE_PENDING", "0")

	if got := ReachPendingFilter("ms.msgid", 42); got != "1 = 1" {
		t.Fatalf("expected an always-true predicate when the gate is off, got %q", got)
	}
}

// On, the filter keeps a post that has reach, or that has waited out the grace
// period, and never hides the viewer's own post from them.
func TestReachPendingFilterGatesOnReachGraceAndAuthor(t *testing.T) {
	t.Setenv("RIPPLE_HIDE_PENDING", "1")

	got := ReachPendingFilter("ms.msgid", 42)

	for _, want := range []string{
		"rippling_reach rr_pending",
		"rr_pending.msgid = ms.msgid",
		"m_pending.arrival >= NOW() - INTERVAL 10 MINUTE",
		"m_pending.fromuser <> 42",
	} {
		if !strings.Contains(got, want) {
			t.Fatalf("filter %q is missing %q", got, want)
		}
	}
}
