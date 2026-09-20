package rippling

// A post is not live until its reach has been calculated. Rippling starts a
// post small and grows it, so a post with no rippling_reach row yet has no
// audience at all - showing it anyway meant browse offered a post that the
// reply gate then refused, which is what the "this hasn't reached your area
// yet" notice looked like from the member's side.
//
// Reach normally lands within a minute (of 1,347 posts over 24 hours, 788 had
// a row inside 60s and 1,204 inside two minutes), but 132 browsable posts have
// no row and never will: their origin cannot snap to the road graph, the Isle
// of Man being the clearest case. Those posts must still be shown. So the gate
// is a grace period, not a requirement: hide a reach-less post while it is
// young, show it once the wait is longer than the reach engine ever takes.

import (
	"os"
	"strconv"
	"strings"
)

// How long a post may be missing its reach row before we show it regardless.
const ReachPendingGraceMinutes = 10

// HidePendingReach reports whether feeds should hide posts whose reach has not
// been calculated yet. On unless switched off: rippling governs every group, so
// a post without reach is genuinely not live anywhere.
//
// RIPPLE_HIDE_PENDING=0 turns it off without a deploy if the grace period ever
// hides more than it should.
func HidePendingReach() bool {
	v := strings.ToLower(strings.TrimSpace(os.Getenv("RIPPLE_HIDE_PENDING")))
	return v != "0" && v != "false" && v != "no" && v != "off"
}

// ReachPendingFilter returns a SQL predicate that keeps a post only once its
// reach exists, or once it has waited longer than the reach engine takes.
// msgidExpr is the caller's own column expression for the message id, such as
// "ms.msgid"; it is SQL the caller writes, never anything a member supplies.
// viewerID is the member reading the feed, 0 when logged out.
//
// Returns an always-true predicate when the gate is off, so callers can splice
// it in unconditionally.
//
// The clock is messages.arrival, the original post time. messages_spatial's
// arrival is bumped by the reach engine on every tick, so a post gated on that
// would restart its own grace period.
//
// A member's own post is never hidden from them. They watch for it to appear
// and would report it missing; and the gate exists to stop OTHER people being
// offered a post they cannot reply to, which their own post is not.
func ReachPendingFilter(msgidExpr string, viewerID uint64) string {
	if !HidePendingReach() {
		return "1 = 1"
	}

	return "(EXISTS (SELECT 1 FROM rippling_reach rr_pending WHERE rr_pending.msgid = " + msgidExpr + ")" +
		" OR NOT EXISTS (SELECT 1 FROM messages m_pending WHERE m_pending.id = " + msgidExpr +
		" AND m_pending.arrival >= NOW() - INTERVAL " + strconv.Itoa(ReachPendingGraceMinutes) + " MINUTE" +
		" AND m_pending.fromuser <> " + strconv.FormatUint(viewerID, 10) + "))"
}
