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

func TestHomeBackToPendingLocksRippledCopies(t *testing.T) {
	f := newHomeLockFixture(t, "hl_lock")
	db := database.DBConn

	status, _ := postModAction(t, f.tokHome, f.msgID, "BackToPending", f.home)
	assert.Equal(t, 200, status)

	recv := rowOf(f.msgID, f.recv)
	assert.Equal(t, "Pending", recv.Collection)
	assert.Equal(t, 1, recv.LockedByHome, "the receiving copy is locked by the home community")
	assert.Equal(t, 1, recv.NeedsModerator)
	if assert.NotNil(t, recv.Spamreason) {
		assert.Contains(t, *recv.Spamreason, "home community")
	}
	assert.Equal(t, 0, rowOf(f.msgID, f.home).LockedByHome, "the home copy is never locked")

	// The receiving community's log says it was the home community.
	var logText string
	db.Raw("SELECT text FROM logs WHERE msgid = ? AND groupid = ? AND type = 'Message' AND subtype = 'Hold' ORDER BY id DESC LIMIT 1", f.msgID, f.recv).Scan(&logText)
	assert.Contains(t, logText, "home community")

	// Their moderator cannot approve it, and is told why.
	status, body := postModAction(t, f.tokRecv, f.msgID, "Approve", f.recv)
	assert.Equal(t, 403, status)
	assert.Contains(t, body, "home community is reviewing")
	assert.Equal(t, "Pending", rowOf(f.msgID, f.recv).Collection)

	// ModTools is told the copy is locked.
	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d?jwt=%s", f.msgID, f.tokRecv), nil))
	b, _ := io.ReadAll(resp.Body)
	var m message.Message
	require.NoError(t, json.Unmarshal(b, &m))
	locked := false
	for _, g := range m.MessageGroups {
		if g.Groupid == f.recv {
			locked = g.LockedByHome == 1
		}
	}
	assert.True(t, locked, "groups[].locked_by_home is exposed")

	// Approving the home copy lifts the lock but does not approve the other copy.
	status, _ = postModAction(t, f.tokHome, f.msgID, "Approve", f.home)
	assert.Equal(t, 200, status)
	recv = rowOf(f.msgID, f.recv)
	assert.Equal(t, 0, recv.LockedByHome)
	assert.Equal(t, "Pending", recv.Collection, "the copy returns to normal per-group moderation")

	status, _ = postModAction(t, f.tokRecv, f.msgID, "Approve", f.recv)
	assert.Equal(t, 200, status)
	assert.Equal(t, "Approved", rowOf(f.msgID, f.recv).Collection)
}

func TestHomeLockOnlyBitesWhileHomeNotApproved(t *testing.T) {
	f := newHomeLockFixture(t, "hl_leftover")
	db := database.DBConn

	status, _ := postModAction(t, f.tokHome, f.msgID, "BackToPending", f.home)
	require.Equal(t, 200, status)

	// Home copy approved some other way, leaving a stale flag: it must not block, and must
	// not be reported as a lock.
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
