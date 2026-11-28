package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

func messageGroupRows(msgid uint64, groupid uint64) int64 {
	var count int64
	database.DBConn.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid = ? AND groupid = ?", msgid, groupid).Scan(&count)
	return count
}

// A post on community A that rippled into community B. The moderator of B only has standing over
// B's copy. Sending the post back to draft names A explicitly: it must be refused and leave A's
// copy alone.
func TestRejectToDraftByModeratorOfRippledCopyCannotTouchAnotherCommunity(t *testing.T) {
	prefix := uniquePrefix("r2d_rip_grp")
	db := database.DBConn

	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")
	poster := CreateTestUser(t, prefix+"_poster", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, poster, groupA, utils.ROLE_MEMBER)
	modB := CreateTestUser(t, prefix+"_modb", utils.SYSTEMROLE_MODERATOR)
	CreateTestMembership(t, modB, groupB, utils.ROLE_MODERATOR)
	_, tokenB := CreateTestSession(t, modB)

	msgID := CreateTestMessage(t, poster, groupA, prefix+" item", 52.5, -1.8)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, collection, arrival, rippled_in) VALUES (?, ?, 'Approved', NOW(), 1)", msgID, groupB)

	status := postMessageAction(t, tokenB, map[string]interface{}{
		"id": msgID, "action": "RejectToDraft", "groupid": groupA,
	})
	assert.Equal(t, 403, status, "a moderator of B may not send A's copy back to draft")
	assert.Equal(t, int64(1), messageGroupRows(msgID, groupA), "A's copy must stay")
}

// With no community named, a moderator of the rippled copy acts on the communities they
// moderate only, so the post stays on the community it was posted on.
func TestRejectToDraftByModeratorOfRippledCopyLeavesTheHomeCommunity(t *testing.T) {
	prefix := uniquePrefix("r2d_rip_all")
	db := database.DBConn

	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")
	poster := CreateTestUser(t, prefix+"_poster", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, poster, groupA, utils.ROLE_MEMBER)
	modB := CreateTestUser(t, prefix+"_modb", utils.SYSTEMROLE_MODERATOR)
	CreateTestMembership(t, modB, groupB, utils.ROLE_MODERATOR)
	_, tokenB := CreateTestSession(t, modB)

	msgID := CreateTestMessage(t, poster, groupA, prefix+" item", 52.5, -1.8)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, collection, arrival, rippled_in) VALUES (?, ?, 'Approved', NOW(), 1)", msgID, groupB)
	db.Exec("INSERT INTO messages_outcomes (msgid, outcome, timestamp) VALUES (?, 'Taken', NOW())", msgID)

	postMessageAction(t, tokenB, map[string]interface{}{"id": msgID, "action": "RejectToDraft"})

	assert.Equal(t, int64(1), messageGroupRows(msgID, groupA), "the home community's copy must stay")
	var outcomes int64
	db.Raw("SELECT COUNT(*) FROM messages_outcomes WHERE msgid = ?", msgID).Scan(&outcomes)
	assert.Equal(t, int64(1), outcomes, "the post's outcome is not cleared while it is live on its home community")
}

// Move takes the post off the communities the moderator runs and puts it on the target. A copy on
// a community the moderator does not run, with its approval, stays.
func TestMoveLeavesCopiesOnCommunitiesTheModeratorDoesNotRun(t *testing.T) {
	prefix := uniquePrefix("move_scope")
	db := database.DBConn

	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")
	groupC := CreateTestGroup(t, prefix+"_c")
	poster := CreateTestUser(t, prefix+"_poster", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, poster, groupA, utils.ROLE_MEMBER)
	mod := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	CreateTestMembership(t, mod, groupA, utils.ROLE_MODERATOR)
	CreateTestMembership(t, mod, groupB, utils.ROLE_MODERATOR)
	_, token := CreateTestSession(t, mod)

	msgID := CreateTestMessage(t, poster, groupA, prefix+" item", 52.5, -1.8)
	other := CreateTestUser(t, prefix+"_other", utils.SYSTEMROLE_MODERATOR)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, collection, arrival, approvedby) VALUES (?, ?, 'Approved', NOW(), ?)", msgID, groupC, other)

	status := postMessageAction(t, token, map[string]interface{}{"id": msgID, "action": "Move", "groupid": groupB})
	assert.Equal(t, 200, status)

	var collection string
	db.Raw("SELECT collection FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupB).Scan(&collection)
	assert.Equal(t, "Pending", collection, "the post is pending on the target")
	assert.Equal(t, int64(0), messageGroupRows(msgID, groupA), "the moderator's own copy moves")

	var approvedby uint64
	db.Raw("SELECT COALESCE(approvedby, 0) FROM messages_groups WHERE msgid = ? AND groupid = ? AND collection = 'Approved'", msgID, groupC).Scan(&approvedby)
	assert.Equal(t, other, approvedby, "another community's approved copy must survive the move")
}
