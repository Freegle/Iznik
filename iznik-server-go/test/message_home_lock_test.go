package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/freegle/iznik-server-go/microvolunteering"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// postModAction sends a moderation action and returns the status and body.
func postModAction(t *testing.T, token string, msgID uint64, action string, groupID uint64) (int, string) {
	body, _ := json.Marshal(map[string]interface{}{"id": msgID, "action": action, "groupid": groupID})
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/message?jwt=%s", token), bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	b, _ := io.ReadAll(resp.Body)
	return resp.StatusCode, string(b)
}

type homeLockFixture struct {
	home, recv       uint64
	msgID            uint64
	modHome, modRecv uint64
	tokHome, tokRecv string
}

// A post approved on its home group and rippled into a second group, each with its own
// moderator.
func newHomeLockFixture(t *testing.T, name string) homeLockFixture {
	prefix := uniquePrefix(name)
	db := database.DBConn
	f := homeLockFixture{}
	f.home = CreateTestGroup(t, prefix+"_home")
	f.recv = CreateTestGroup(t, prefix+"_recv")
	poster := CreateTestUser(t, prefix+"_poster", "User")
	f.modHome = CreateTestUser(t, prefix+"_modh", "User")
	f.modRecv = CreateTestUser(t, prefix+"_modr", "User")
	CreateTestMembership(t, poster, f.home, "Member")
	CreateTestMembership(t, f.modHome, f.home, "Moderator")
	CreateTestMembership(t, f.modRecv, f.recv, "Moderator")
	_, f.tokHome = CreateTestSession(t, f.modHome)
	_, f.tokRecv = CreateTestSession(t, f.modRecv)

	f.msgID = createPendingMessage(t, poster, f.home, prefix)
	db.Exec("UPDATE messages_groups SET collection = 'Approved' WHERE msgid = ? AND groupid = ?", f.msgID, f.home)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in) VALUES (?, ?, NOW(), 'Approved', 0, 1)", f.msgID, f.recv)
	return f
}

type rowState struct {
	Collection     string
	LockedByHome   int
	NeedsModerator int
	Spamreason     *string
}

func rowOf(msgID, gid uint64) rowState {
	var r rowState
	database.DBConn.Raw("SELECT collection, locked_by_home, needs_moderator, spamreason FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, gid).Scan(&r)
	return r
}

// A home send-back withdraws the post from every community it rippled into, instead of
// putting a copy in each of their pending queues, and blocks it from rippling again
// (Discourse 9808/849).
func TestHomeBackToPendingWithdrawsRippledCopies(t *testing.T) {
	f := newHomeLockFixture(t, "hl_withdraw")
	db := database.DBConn

	var poster uint64
	db.Raw("SELECT fromuser FROM messages WHERE id = ?", f.msgID).Scan(&poster)
	// The ripple joined the poster to the receiving community to carry the post.
	db.Exec("INSERT INTO memberships (userid, groupid, role, rippled) VALUES (?, ?, 'Member', 1)", poster, f.recv)

	status, _ := postModAction(t, f.tokHome, f.msgID, "BackToPending", f.home)
	require.Equal(t, 200, status)

	var recv struct {
		Collection string
		Deleted    int
	}
	db.Raw("SELECT collection, deleted FROM messages_groups WHERE msgid = ? AND groupid = ?", f.msgID, f.recv).Scan(&recv)
	assert.Equal(t, 1, recv.Deleted, "the rippled copy is withdrawn")
	assert.NotEqual(t, "Pending", recv.Collection, "the receiving community gets nothing to moderate")
	assert.Equal(t, 0, rowOf(f.msgID, f.recv).LockedByHome)

	assert.Equal(t, "Pending", rowOf(f.msgID, f.home).Collection, "the home copy goes back to pending as before")

	var deletedLogs, holdLogs int64
	db.Raw("SELECT COUNT(*) FROM logs WHERE msgid = ? AND groupid = ? AND type = 'Message' AND subtype = 'Deleted'", f.msgID, f.recv).Scan(&deletedLogs)
	db.Raw("SELECT COUNT(*) FROM logs WHERE msgid = ? AND groupid = ? AND type = 'Message' AND subtype = 'Hold'", f.msgID, f.recv).Scan(&holdLogs)
	assert.Equal(t, int64(1), deletedLogs, "the receiving community's log says it was withdrawn")
	assert.Equal(t, int64(0), holdLogs, "and does not say it is back in their queue")

	var memberships int64
	db.Raw("SELECT COUNT(*) FROM memberships WHERE userid = ? AND groupid = ?", poster, f.recv).Scan(&memberships)
	assert.Equal(t, int64(0), memberships, "the ripple-join membership goes with the only post it carried")

	var blocked int64
	db.Raw("SELECT COUNT(*) FROM rippling_blocked WHERE msgid = ?", f.msgID).Scan(&blocked)
	assert.Equal(t, int64(1), blocked, "the post is recorded as never to ripple again")

	// Re-approval at home brings back the home copy only.
	status, _ = postModAction(t, f.tokHome, f.msgID, "Approve", f.home)
	assert.Equal(t, 200, status)
	assert.Equal(t, "Approved", rowOf(f.msgID, f.home).Collection)
	db.Raw("SELECT deleted FROM messages_groups WHERE msgid = ? AND groupid = ?", f.msgID, f.recv).Scan(&recv.Deleted)
	assert.Equal(t, 1, recv.Deleted, "the withdrawn copy stays withdrawn")
}

// A copy the receiving community's poster also holds organically is not touched beyond the
// withdrawal: only a ripple-join membership is removed.
func TestHomeBackToPendingKeepsOrganicMembership(t *testing.T) {
	f := newHomeLockFixture(t, "hl_organic")
	db := database.DBConn

	var poster uint64
	db.Raw("SELECT fromuser FROM messages WHERE id = ?", f.msgID).Scan(&poster)
	CreateTestMembership(t, poster, f.recv, "Member")

	status, _ := postModAction(t, f.tokHome, f.msgID, "BackToPending", f.home)
	require.Equal(t, 200, status)

	var memberships int64
	db.Raw("SELECT COUNT(*) FROM memberships WHERE userid = ? AND groupid = ?", poster, f.recv).Scan(&memberships)
	assert.Equal(t, int64(1), memberships)
}

// Copies locked by a home send-back before withdrawal replaced locking still behave: the lock
// only bites while the home copy is not approved.
func TestHomeLockOnlyBitesWhileHomeNotApproved(t *testing.T) {
	f := newHomeLockFixture(t, "hl_leftover")
	db := database.DBConn

	// The state an older home send-back left: home Pending, the rippled copy Pending and locked.
	db.Exec("UPDATE messages_groups SET collection = 'Pending' WHERE msgid = ? AND groupid = ?", f.msgID, f.home)
	db.Exec("UPDATE messages_groups SET collection = 'Pending', locked_by_home = 1, needs_moderator = 1 WHERE msgid = ? AND groupid = ?", f.msgID, f.recv)

	status, body := postModAction(t, f.tokRecv, f.msgID, "Approve", f.recv)
	assert.Equal(t, 403, status, "while the home copy is pending the lock holds")
	assert.Contains(t, body, "home community is reviewing")

	// Home copy approved some other way, leaving a stale flag: it must not block.
	db.Exec("UPDATE messages_groups SET collection = 'Approved' WHERE msgid = ? AND groupid = ?", f.msgID, f.home)
	assert.Equal(t, 1, rowOf(f.msgID, f.recv).LockedByHome)

	status, _ = postModAction(t, f.tokRecv, f.msgID, "Approve", f.recv)
	assert.Equal(t, 200, status)
}

func TestHomeLockNotSetByReceivingModerator(t *testing.T) {
	f := newHomeLockFixture(t, "hl_recvmod")

	status, _ := postModAction(t, f.tokRecv, f.msgID, "BackToPending", f.recv)
	require.Equal(t, 200, status)

	assert.Equal(t, 0, rowOf(f.msgID, f.recv).LockedByHome)
	assert.Equal(t, 0, rowOf(f.msgID, f.home).LockedByHome)
	assert.Equal(t, "Pending", rowOf(f.msgID, f.recv).Collection)

	// The receiving moderator can approve their own copy again: copies stay independent.
	status, _ = postModAction(t, f.tokRecv, f.msgID, "Approve", f.recv)
	assert.Equal(t, 200, status)
	assert.Equal(t, "Approved", rowOf(f.msgID, f.recv).Collection)
}

func TestHomeLockNotSetByReportQuorum(t *testing.T) {
	f := newHomeLockFixture(t, "hl_quorum")

	flipped := microvolunteering.SendForReviewAllGroups(database.DBConn, f.msgID, "Members think there is something wrong with this message.", nil, nil)
	assert.Len(t, flipped, 2)

	assert.Equal(t, 0, rowOf(f.msgID, f.recv).LockedByHome)
	assert.Equal(t, "Pending", rowOf(f.msgID, f.recv).Collection)

	status, _ := postModAction(t, f.tokRecv, f.msgID, "Approve", f.recv)
	assert.Equal(t, 200, status, "a report quorum leaves each group independent")
}

// The hold has to be checked in the write itself: a hold that lands after the dispatch
// check but before the update must not be overridden or released.
func TestApprovePendingCopiesRespectsAnotherModeratorsHold(t *testing.T) {
	prefix := uniquePrefix("hl_race")
	db := database.DBConn
	group := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_p", "User")
	modA := CreateTestUser(t, prefix+"_a", "User")
	modB := CreateTestUser(t, prefix+"_b", "User")
	CreateTestMembership(t, modA, group, "Moderator")
	CreateTestMembership(t, modB, group, "Moderator")
	msgID := createPendingMessage(t, poster, group, prefix)

	// modB's hold has landed; modA's approval goes ahead having already passed the check.
	db.Exec("UPDATE messages_groups SET heldby = ? WHERE msgid = ? AND groupid = ?", modB, msgID, group)

	approvable, heldByOther := message.ApprovePendingCopies(db, msgID, modA, []uint64{group})
	assert.Empty(t, approvable)
	assert.Equal(t, []uint64{group}, heldByOther)
	assert.Equal(t, "Pending", rowOf(msgID, group).Collection, "a held copy is not approved")

	var heldby uint64
	db.Raw("SELECT heldby FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, group).Scan(&heldby)
	assert.Equal(t, modB, heldby, "the other moderator's hold is untouched")

	// The holder themselves can approve.
	approvable, heldByOther = message.ApprovePendingCopies(db, msgID, modB, []uint64{group})
	assert.Equal(t, []uint64{group}, approvable)
	assert.Empty(t, heldByOther)
	assert.Equal(t, "Approved", rowOf(msgID, group).Collection)
}

func TestApprovePendingCopiesApprovesUnheldGroupsOnly(t *testing.T) {
	prefix := uniquePrefix("hl_race2")
	db := database.DBConn
	g1 := CreateTestGroup(t, prefix+"_1")
	g2 := CreateTestGroup(t, prefix+"_2")
	poster := CreateTestUser(t, prefix+"_p", "User")
	modA := CreateTestUser(t, prefix+"_a", "User")
	modB := CreateTestUser(t, prefix+"_b", "User")
	msgID := createPendingMessage(t, poster, g1, prefix)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts) VALUES (?, ?, NOW(), 'Pending', 0)", msgID, g2)
	db.Exec("UPDATE messages_groups SET heldby = ? WHERE msgid = ? AND groupid = ?", modB, msgID, g2)

	approvable, heldByOther := message.ApprovePendingCopies(db, msgID, modA, []uint64{g1, g2})
	assert.Equal(t, []uint64{g1}, approvable)
	assert.Equal(t, []uint64{g2}, heldByOther)
	assert.Equal(t, "Approved", rowOf(msgID, g1).Collection)
	assert.Equal(t, "Pending", rowOf(msgID, g2).Collection)
}
