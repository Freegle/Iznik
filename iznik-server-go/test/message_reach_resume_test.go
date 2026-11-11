package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/microvolunteering"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// A pull-back freezes a post's reach: status 'held', nothing scheduled. A moderator's Approve of
// the post's home copy lifts the freeze, so the reach goes back to 'expanding', due now, and the
// engine carries on from there. The exception is a post its home community sent back to pending,
// which is barred from rippling for good (rippling_blocked) and stays frozen however often it is
// approved. These tests pin who resumes it and who does not.

// reachDue reports whether the post's reach has a next step scheduled, and how many seconds ago
// that step fell due (negative while it is still in the future).
func reachDue(msgID uint64) (scheduled bool, secondsAgo int64) {
	database.DBConn.Raw("SELECT next_expansion_at IS NOT NULL, COALESCE(TIMESTAMPDIFF(SECOND, next_expansion_at, NOW()), 0) "+
		"FROM rippling_reach WHERE msgid = ?", msgID).Row().Scan(&scheduled, &secondsAgo)
	return
}

// scheduleReachIn gives the post's reach a next step the given SQL interval from now, as the
// engine leaves one on a reach that is expanding.
func scheduleReachIn(msgID uint64, interval string) {
	database.DBConn.Exec("UPDATE rippling_reach SET next_expansion_at = NOW() + INTERVAL "+interval+" WHERE msgid = ?", msgID)
}

func rippleBlockedCount(msgID uint64) int64 {
	var n int64
	database.DBConn.Raw("SELECT COUNT(*) FROM rippling_blocked WHERE msgid = ?", msgID).Scan(&n)
	return n
}

// blockRippling records the post as never to ripple again, as a home community's Back to
// pending does.
func blockRippling(t *testing.T, msgID uint64) {
	t.Helper()
	database.DBConn.Exec("INSERT IGNORE INTO rippling_blocked (msgid, byuser, reason) VALUES (?, NULL, 'reach resume test')", msgID)
	t.Cleanup(func() { database.DBConn.Exec("DELETE FROM rippling_blocked WHERE msgid = ?", msgID) })
}

// requireFrozen is the state a pull-back leaves the reach in.
func requireFrozen(t *testing.T, msgID uint64, why string) {
	t.Helper()
	require.Equal(t, "held", reachStatus(msgID), why)
	scheduled, _ := reachDue(msgID)
	require.False(t, scheduled, why+": nothing is scheduled")
}

func assertResumed(t *testing.T, msgID uint64, why string) {
	t.Helper()
	assert.Equal(t, "expanding", reachStatus(msgID), why)
	scheduled, ago := reachDue(msgID)
	assert.True(t, scheduled, why+": and it is due, so the engine's next run picks it up")
	assert.InDelta(t, 0, ago, 60, why+": due now, not when it had been scheduled before the freeze")
}

func assertStillFrozen(t *testing.T, msgID uint64, why string) {
	t.Helper()
	assert.Equal(t, "held", reachStatus(msgID), why)
	scheduled, _ := reachDue(msgID)
	assert.False(t, scheduled, why+": and nothing is scheduled")
}

// A receiving community sends the post back, which pulls the home copy back too and freezes the
// reach. The home moderator then approves the home copy: the reach spreads again. Nothing else
// changes: the receiving community's copy is still theirs to decide, and nothing is barred.
func TestHomeApproveAfterAReceivingBackToPendingResumesTheReach(t *testing.T) {
	f := newHomeLockFixture(t, "rr_recv")
	addExpandingReach(t, f.msgID)
	scheduleReachIn(f.msgID, "1 HOUR")

	status, body := postModAction(t, f.tokRecv, f.msgID, "BackToPending", f.recv)
	require.Equal(t, 200, status, body)
	require.Equal(t, "Pending", approvalOf(f.msgID, f.home).Collection, "the pull-back took the home copy off the board")
	requireFrozen(t, f.msgID, "the pull-back froze the reach")
	require.Equal(t, int64(0), rippleBlockedCount(f.msgID), "a receiving community's send-back does not bar the post")

	status, body = postModAction(t, f.tokHome, f.msgID, "Approve", f.home)
	require.Equal(t, 200, status, body)

	assert.Equal(t, "Approved", approvalOf(f.msgID, f.home).Collection)
	assertResumed(t, f.msgID, "approving the home copy lifts the freeze")
	assert.Equal(t, "Pending", approvalOf(f.msgID, f.recv).Collection, "the receiving community's copy is still theirs to decide")
	assert.Equal(t, int64(0), rippleBlockedCount(f.msgID))
}

// A home community sends the post back: it is withdrawn from the communities it rippled into and
// barred from rippling for good. Approving the home copy again brings that copy back and nothing
// else; the reach stays frozen.
func TestHomeApproveAfterAHomeBackToPendingLeavesTheReachFrozen(t *testing.T) {
	f := newHomeLockFixture(t, "rr_home")
	addExpandingReach(t, f.msgID)
	scheduleReachIn(f.msgID, "1 HOUR")

	status, body := postModAction(t, f.tokHome, f.msgID, "BackToPending", f.home)
	require.Equal(t, 200, status, body)
	requireFrozen(t, f.msgID, "the home send-back froze the reach")
	require.Equal(t, int64(1), rippleBlockedCount(f.msgID), "and barred the post from rippling")

	status, body = postModAction(t, f.tokHome, f.msgID, "Approve", f.home)
	require.Equal(t, 200, status, body)

	assert.Equal(t, "Approved", approvalOf(f.msgID, f.home).Collection, "the home copy is back")
	assertStillFrozen(t, f.msgID, "a post its home community sent back never spreads again")
	assert.Equal(t, int64(1), rippleBlockedCount(f.msgID), "the bar stays")
	assert.Equal(t, 1, approvalOf(f.msgID, f.recv).Deleted, "and the withdrawn copy stays withdrawn")
}

// A members' report quorum pulls every copy back and freezes the reach, the same freeze a
// moderator's send-back leaves. The home moderator approving the home copy resumes it.
func TestHomeApproveAfterAReportQuorumPullBackResumesTheReach(t *testing.T) {
	f := newHomeLockFixture(t, "rr_quorum")
	addExpandingReach(t, f.msgID)
	scheduleReachIn(f.msgID, "1 HOUR")

	db := database.DBConn
	flipped := microvolunteering.SendForReviewAllGroups(db, f.msgID, "Members think there is something wrong with this message.", nil, nil)
	require.ElementsMatch(t, []uint64{f.home, f.recv}, flipped)
	microvolunteering.FreezeReachIfOriginPending(db, f.msgID)
	requireFrozen(t, f.msgID, "the quorum froze the reach")

	status, body := postModAction(t, f.tokHome, f.msgID, "Approve", f.home)
	require.Equal(t, 200, status, body)

	assertResumed(t, f.msgID, "the moderator's approval of the home copy lifts the freeze")
}

// Approving a copy that rippled in says nothing about the post's own community: the reach stays
// frozen. Once with the home copy still under review, as a pull-back leaves it, and once with the
// home copy live and the reach still held, which shows it is the copies the Approve approved that
// decide, not the state the post happens to be in.
func TestApproveOfARippledInCopyAloneLeavesTheReachHeld(t *testing.T) {
	f := newHomeLockFixture(t, "rr_recvonly")
	addExpandingReach(t, f.msgID)
	scheduleReachIn(f.msgID, "1 HOUR")

	status, body := postModAction(t, f.tokRecv, f.msgID, "BackToPending", f.recv)
	require.Equal(t, 200, status, body)
	requireFrozen(t, f.msgID, "the pull-back froze the reach")

	approveByHand(t, f.msgID, f.recv, f.tokRecv)
	assert.Equal(t, "Approved", approvalOf(f.msgID, f.recv).Collection)
	assert.Equal(t, "Pending", approvalOf(f.msgID, f.home).Collection, "the home copy is still under review")
	assertStillFrozen(t, f.msgID, "the receiving community's approval does not resume the post")

	database.DBConn.Exec("UPDATE messages_groups SET collection = 'Approved' WHERE msgid = ? AND groupid = ?", f.msgID, f.home)
	approveByHand(t, f.msgID, f.recv, f.tokRecv)
	assertStillFrozen(t, f.msgID, "nor does it with the home copy live: only an approval of the home copy does")
}

// A moderator who runs several communities approves them all at once. The reach resumes when
// the home copy is one of those approved, and not when only rippled-in copies are.
func TestApproveOnSeveralCommunitiesResumesTheReachOnlyWithTheHomeCopy(t *testing.T) {
	t.Run("rippled-in copies alone", func(t *testing.T) {
		home, second, third, msgID, _, token := multiGroupPost(t, "rr_multi_recv")
		addExpandingReach(t, msgID)
		microvolunteering.FreezeReachIfOriginPending(database.DBConn, msgID)
		requireFrozen(t, msgID, "the home copy is under review, so the reach is frozen")

		status, result := postMultiAction(t, token, map[string]interface{}{
			"id": msgID, "action": "Approve", "groupids": []uint64{second, third},
		})
		require.Equal(t, 200, status)
		require.Equal(t, float64(0), result["ret"])
		assert.Equal(t, "Approved", collectionOn(t, msgID, second))
		assert.Equal(t, "Approved", collectionOn(t, msgID, third))
		assert.Equal(t, "Pending", collectionOn(t, msgID, home))
		assertStillFrozen(t, msgID, "the home copy was not approved")

		status, body := postModAction(t, token, msgID, "Approve", home)
		require.Equal(t, 200, status, body)
		assertResumed(t, msgID, "approving the home copy afterwards resumes it")
	})

	t.Run("home copy among them", func(t *testing.T) {
		home, second, third, msgID, _, token := multiGroupPost(t, "rr_multi_home")
		addExpandingReach(t, msgID)
		microvolunteering.FreezeReachIfOriginPending(database.DBConn, msgID)
		requireFrozen(t, msgID, "the home copy is under review, so the reach is frozen")

		status, result := postMultiAction(t, token, map[string]interface{}{
			"id": msgID, "action": "Approve", "groupids": []uint64{home, second, third},
		})
		require.Equal(t, 200, status)
		require.Equal(t, float64(0), result["ret"])
		assert.Equal(t, "Approved", collectionOn(t, msgID, home))
		assertResumed(t, msgID, "the home copy was approved with the others")
	})
}

// ResumeReachIfHeld lifts a freeze and nothing else. A reach that is expanding keeps its
// schedule, and one that finished or was stopped is left as it is.
func TestResumeReachIfHeld(t *testing.T) {
	cases := []struct {
		name           string
		status         string // the reach row's status when the resume is asked for
		due            string // SQL interval for next_expansion_at, "" for none
		blocked        bool   // the post's home community sent it back
		homeCopy       string // the home copy's collection
		homeDeleted    bool   // the home copy has been withdrawn
		noReach        bool   // there is no reach row at all
		wantResumed    bool
		wantStatus     string
		wantScheduled  bool
		wantSecondsAgo int64 // when scheduled: roughly how long ago it fell due
	}{
		{name: "held, home copy live, nothing barred", status: "held", homeCopy: "Approved",
			wantResumed: true, wantStatus: "expanding", wantScheduled: true, wantSecondsAgo: 0},
		{name: "held, but the home community sent it back", status: "held", homeCopy: "Approved", blocked: true,
			wantStatus: "held"},
		{name: "held, home copy still under review", status: "held", homeCopy: "Pending",
			wantStatus: "held"},
		{name: "held, home copy withdrawn", status: "held", homeCopy: "Approved", homeDeleted: true,
			wantStatus: "held"},
		{name: "expanding keeps its schedule", status: "expanding", due: "1 HOUR", homeCopy: "Approved",
			wantStatus: "expanding", wantScheduled: true, wantSecondsAgo: -3600},
		{name: "done is left alone", status: "done", homeCopy: "Approved",
			wantStatus: "done"},
		{name: "stopped is left alone", status: "stopped", homeCopy: "Approved",
			wantStatus: "stopped"},
		{name: "no reach row, none is made", noReach: true, homeCopy: "Approved"},
	}

	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			f := newHomeLockFixture(t, "rr_fn")
			db := database.DBConn
			if c.noReach {
				db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", f.msgID)
			} else {
				addExpandingReach(t, f.msgID)
				db.Exec("UPDATE rippling_reach SET status = ?, next_expansion_at = NULL WHERE msgid = ?", c.status, f.msgID)
				if c.due != "" {
					scheduleReachIn(f.msgID, c.due)
				}
			}
			if c.blocked {
				blockRippling(t, f.msgID)
			}
			db.Exec("UPDATE messages_groups SET collection = ?, deleted = ? WHERE msgid = ? AND groupid = ?",
				c.homeCopy, c.homeDeleted, f.msgID, f.home)

			assert.Equal(t, c.wantResumed, microvolunteering.ResumeReachIfHeld(db, f.msgID))

			if c.noReach {
				var rows int64
				db.Raw("SELECT COUNT(*) FROM rippling_reach WHERE msgid = ?", f.msgID).Scan(&rows)
				assert.Equal(t, int64(0), rows, "a post with no reach row does not get one from a resume")
				return
			}

			assert.Equal(t, c.wantStatus, reachStatus(f.msgID))
			scheduled, ago := reachDue(f.msgID)
			assert.Equal(t, c.wantScheduled, scheduled)
			if c.wantScheduled {
				assert.InDelta(t, c.wantSecondsAgo, ago, 120)
			}
		})
	}

	assert.False(t, microvolunteering.ResumeReachIfHeld(database.DBConn, 0), "no post, nothing to resume")
}
