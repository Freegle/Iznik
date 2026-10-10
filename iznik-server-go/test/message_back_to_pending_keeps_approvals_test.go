package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/microvolunteering"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// A moderator's Back to pending leaves a copy on another community Approved when that
// community's own moderator approved it by hand. Copies nobody has reviewed, and the acting
// moderator's own copies, are pulled back as before. A report quorum still pulls every copy.

type modCommunity struct {
	gid uint64
	mod uint64
	tok string
}

// addModCommunity creates a community with its own moderator.
func addModCommunity(t *testing.T, name string) modCommunity {
	prefix := uniquePrefix(name)
	c := modCommunity{gid: CreateTestGroup(t, prefix)}
	c.mod = CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, c.mod, c.gid, "Moderator")
	_, c.tok = CreateTestSession(t, c.mod)
	return c
}

// addCopy puts the post on a further community, Approved without a moderator approval, the
// way the ripple engine inserts it.
func addCopy(msgID, gid uint64, rippledIn int) {
	database.DBConn.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in) VALUES (?, ?, NOW(), 'Approved', 0, ?)",
		msgID, gid, rippledIn)
}

// approveByHand has the copy's own moderator approve it through the moderation endpoint,
// which is what sets approvedby.
func approveByHand(t *testing.T, msgID, gid uint64, tok string) {
	database.DBConn.Exec("UPDATE messages_groups SET collection = 'Pending' WHERE msgid = ? AND groupid = ?", msgID, gid)
	status, body := postModAction(t, tok, msgID, "Approve", gid)
	require.Equal(t, 200, status, body)
	require.Equal(t, "Approved", rowOf(msgID, gid).Collection)
}

type approvalState struct {
	Collection     string
	Deleted        int
	Approvedby     *uint64
	HasApprovedat  int
	NeedsModerator int
	Heldby         *uint64
}

func approvalOf(msgID, gid uint64) approvalState {
	var s approvalState
	database.DBConn.Raw("SELECT collection, deleted, approvedby, approvedat IS NOT NULL AS has_approvedat, needs_moderator, heldby FROM messages_groups WHERE msgid = ? AND groupid = ?",
		msgID, gid).Scan(&s)
	return s
}

func logCount(msgID, gid uint64, subtype string) int64 {
	var n int64
	database.DBConn.Raw("SELECT COUNT(*) FROM logs WHERE msgid = ? AND groupid = ? AND type = 'Message' AND subtype = ?",
		msgID, gid, subtype).Scan(&n)
	return n
}

func addExpandingReach(t *testing.T, msgID uint64) {
	db := database.DBConn
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, outer_bound, status) VALUES (?, 51.5, -0.1, "+
		"ST_Envelope(ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857)), 'expanding') "+
		"ON DUPLICATE KEY UPDATE status = VALUES(status)", msgID)
	t.Cleanup(func() { db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID) })
}

func reachStatus(msgID uint64) string {
	var s string
	database.DBConn.Raw("SELECT status FROM rippling_reach WHERE msgid = ?", msgID).Scan(&s)
	return s
}

// The home community sends a post back: a rippled-in copy its receiving community's moderator
// approved by hand stays live, with its approval; an unreviewed rippled-in copy is withdrawn.
func TestHomeBackToPendingKeepsHandApprovedRippledCopy(t *testing.T) {
	f := newHomeLockFixture(t, "keep_home")
	other := addModCommunity(t, "keep_home_other")
	addCopy(f.msgID, other.gid, 1)
	addExpandingReach(t, f.msgID)
	db := database.DBConn

	var poster uint64
	db.Raw("SELECT fromuser FROM messages WHERE id = ?", f.msgID).Scan(&poster)
	db.Exec("INSERT INTO memberships (userid, groupid, role, rippled) VALUES (?, ?, 'Member', 1)", poster, f.recv)
	db.Exec("INSERT INTO memberships (userid, groupid, role, rippled) VALUES (?, ?, 'Member', 1)", poster, other.gid)

	approveByHand(t, f.msgID, f.recv, f.tokRecv)
	before := approvalOf(f.msgID, f.recv)
	require.NotNil(t, before.Approvedby)
	require.Equal(t, f.modRecv, *before.Approvedby)

	status, body := postModAction(t, f.tokHome, f.msgID, "BackToPending", f.home)
	require.Equal(t, 200, status, body)

	kept := approvalOf(f.msgID, f.recv)
	assert.Equal(t, "Approved", kept.Collection, "a copy its own moderator approved stays approved")
	assert.Equal(t, 0, kept.Deleted, "and is not withdrawn")
	require.NotNil(t, kept.Approvedby, "the approval is kept")
	assert.Equal(t, f.modRecv, *kept.Approvedby)
	assert.Equal(t, 1, kept.HasApprovedat)
	assert.Equal(t, 0, kept.NeedsModerator, "nobody is asked to review it again")
	assert.Nil(t, kept.Heldby)
	assert.Equal(t, int64(0), logCount(f.msgID, f.recv, "Hold"), "the kept copy's community gets no Hold log")
	assert.Equal(t, int64(0), logCount(f.msgID, f.recv, "Deleted"), "nor a withdrawal")

	withdrawn := approvalOf(f.msgID, other.gid)
	assert.Equal(t, 1, withdrawn.Deleted, "an unreviewed rippled copy is withdrawn")
	assert.Equal(t, int64(1), logCount(f.msgID, other.gid, "Deleted"))
	assert.Equal(t, int64(0), logCount(f.msgID, other.gid, "Hold"))

	assert.Equal(t, "Pending", approvalOf(f.msgID, f.home).Collection, "the home copy goes back to pending")

	var recvMembership, otherMembership int64
	db.Raw("SELECT COUNT(*) FROM memberships WHERE userid = ? AND groupid = ?", poster, f.recv).Scan(&recvMembership)
	db.Raw("SELECT COUNT(*) FROM memberships WHERE userid = ? AND groupid = ?", poster, other.gid).Scan(&otherMembership)
	assert.Equal(t, int64(1), recvMembership, "the ripple-join carrying the kept copy stays")
	assert.Equal(t, int64(0), otherMembership, "the ripple-join carrying only the withdrawn copy goes")

	var blocked int64
	db.Raw("SELECT COUNT(*) FROM rippling_blocked WHERE msgid = ?", f.msgID).Scan(&blocked)
	assert.Equal(t, int64(1), blocked, "the post still never ripples further")
	assert.Equal(t, "held", reachStatus(f.msgID), "and its reach is frozen")
}

// A post cross-posted to a second home community whose moderator approved it by hand keeps
// that copy too. The origin is then still Approved, so the reach is frozen outright: a home
// send-back must stop the post spreading whatever the other home copy's state.
func TestHomeBackToPendingFreezesReachWhenSecondHomeCopyKept(t *testing.T) {
	f := newHomeLockFixture(t, "keep_home2")
	second := addModCommunity(t, "keep_home2_second")
	addCopy(f.msgID, second.gid, 0)
	addExpandingReach(t, f.msgID)

	approveByHand(t, f.msgID, second.gid, second.tok)

	status, body := postModAction(t, f.tokHome, f.msgID, "BackToPending", f.home)
	require.Equal(t, 200, status, body)

	kept := approvalOf(f.msgID, second.gid)
	assert.Equal(t, "Approved", kept.Collection)
	require.NotNil(t, kept.Approvedby)
	assert.Equal(t, second.mod, *kept.Approvedby)
	assert.Equal(t, "Pending", approvalOf(f.msgID, f.home).Collection)
	assert.Equal(t, 1, approvalOf(f.msgID, f.recv).Deleted, "the unreviewed rippled copy is withdrawn")
	assert.Equal(t, "held", reachStatus(f.msgID))
}

// A receiving community sends a post back. The home copy a home moderator approved stays
// live, as does a further community's hand-approved copy. The acting moderator's own copy
// goes back even though they approved it by hand, and an unreviewed copy goes back too.
func TestReceivingBackToPendingKeepsHandApprovedCopies(t *testing.T) {
	f := newHomeLockFixture(t, "keep_recv")
	reviewed := addModCommunity(t, "keep_recv_reviewed")
	unreviewed := addModCommunity(t, "keep_recv_unreviewed")
	addCopy(f.msgID, reviewed.gid, 1)
	addCopy(f.msgID, unreviewed.gid, 1)
	addExpandingReach(t, f.msgID)

	approveByHand(t, f.msgID, f.home, f.tokHome)
	approveByHand(t, f.msgID, reviewed.gid, reviewed.tok)
	approveByHand(t, f.msgID, f.recv, f.tokRecv)

	status, body := postModAction(t, f.tokRecv, f.msgID, "BackToPending", f.recv)
	require.Equal(t, 200, status, body)

	home := approvalOf(f.msgID, f.home)
	assert.Equal(t, "Approved", home.Collection, "the home copy a home moderator approved stays live")
	require.NotNil(t, home.Approvedby)
	assert.Equal(t, f.modHome, *home.Approvedby)
	assert.Equal(t, 1, home.HasApprovedat)
	assert.Equal(t, 0, home.NeedsModerator)
	assert.Equal(t, int64(0), logCount(f.msgID, f.home, "Hold"), "the home community gets no Hold log")

	other := approvalOf(f.msgID, reviewed.gid)
	assert.Equal(t, "Approved", other.Collection, "another hand-approved copy stays live")
	require.NotNil(t, other.Approvedby)
	assert.Equal(t, reviewed.mod, *other.Approvedby)
	assert.Equal(t, int64(0), logCount(f.msgID, reviewed.gid, "Hold"))

	own := approvalOf(f.msgID, f.recv)
	assert.Equal(t, "Pending", own.Collection, "the acting moderator's own copy always goes back")
	assert.Nil(t, own.Approvedby)
	assert.Equal(t, 1, own.NeedsModerator)
	require.NotNil(t, own.Heldby)
	assert.Equal(t, f.modRecv, *own.Heldby)

	pulled := approvalOf(f.msgID, unreviewed.gid)
	assert.Equal(t, "Pending", pulled.Collection, "an unreviewed copy goes back")
	assert.Equal(t, 0, pulled.Deleted, "and is not withdrawn: only a home send-back withdraws")
	assert.Equal(t, 1, pulled.NeedsModerator)
	assert.Equal(t, int64(1), logCount(f.msgID, unreviewed.gid, "Hold"), "its community is told why")

	var blocked int64
	database.DBConn.Raw("SELECT COUNT(*) FROM rippling_blocked WHERE msgid = ?", f.msgID).Scan(&blocked)
	assert.Equal(t, int64(0), blocked, "a receiving send-back does not block rippling")
	assert.Equal(t, "expanding", reachStatus(f.msgID), "the home copy is still live, so the reach is not frozen")
}

// A receiving send-back on a post whose home copy was approved automatically pulls the home
// copy back too, and freezes the reach.
func TestReceivingBackToPendingPullsUnreviewedHomeCopy(t *testing.T) {
	f := newHomeLockFixture(t, "keep_recv_auto")
	addExpandingReach(t, f.msgID)

	status, body := postModAction(t, f.tokRecv, f.msgID, "BackToPending", f.recv)
	require.Equal(t, 200, status, body)

	home := approvalOf(f.msgID, f.home)
	assert.Equal(t, "Pending", home.Collection)
	assert.Equal(t, 1, home.NeedsModerator)
	assert.Equal(t, int64(1), logCount(f.msgID, f.home, "Hold"))
	assert.Equal(t, "Pending", approvalOf(f.msgID, f.recv).Collection)
	assert.Equal(t, "held", reachStatus(f.msgID))
}

// A members' report quorum is not a moderator's review, so it pulls every copy back,
// hand-approved ones included.
func TestReportQuorumPullsHandApprovedCopies(t *testing.T) {
	f := newHomeLockFixture(t, "keep_quorum")

	approveByHand(t, f.msgID, f.home, f.tokHome)
	approveByHand(t, f.msgID, f.recv, f.tokRecv)

	flipped := microvolunteering.SendForReviewAllGroups(database.DBConn, f.msgID, "Members think there is something wrong with this message.", nil, nil)
	assert.ElementsMatch(t, []uint64{f.home, f.recv}, flipped)
	assert.Equal(t, "Pending", approvalOf(f.msgID, f.home).Collection)
	assert.Equal(t, "Pending", approvalOf(f.msgID, f.recv).Collection)
}
