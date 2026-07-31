package test

import (
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/group"
	"github.com/stretchr/testify/assert"
)

func TestGetGroupWork_Unauthenticated(t *testing.T) {
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/group/work", nil))
	assert.Equal(t, 401, resp.StatusCode)
}

func TestGetGroupWork_NoGroups(t *testing.T) {
	prefix := uniquePrefix("gwnogrp")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/group/work?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result []group.GroupWork
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, 0, len(result))
}

func TestGetGroupWork_ActiveMod(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("gwactive")

	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "User")

	// Create active mod membership (default settings = active).
	CreateTestMembership(t, userID, groupID, "Moderator")
	_, token := CreateTestSession(t, userID)

	// Insert a pending message.
	senderID := CreateTestUser(t, prefix+"_sender", "User")
	var msgID uint64
	db.Exec("INSERT INTO messages (fromuser, type, subject, textbody, message) VALUES (?, 'Offer', 'Test pending', 'Test body', 'Test body')", senderID)
	db.Raw("SELECT id FROM messages WHERE fromuser = ? ORDER BY id DESC LIMIT 1", senderID).Scan(&msgID)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, collection, deleted, contentcheck_checked_at) VALUES (?, ?, 'Pending', 0, NOW())", msgID, groupID)

	// Insert a spam message.
	var spamMsgID uint64
	db.Exec("INSERT INTO messages (fromuser, type, subject, textbody, message) VALUES (?, 'Offer', 'Test spam', 'Test body', 'Test body')", senderID)
	db.Raw("SELECT id FROM messages WHERE fromuser = ? ORDER BY id DESC LIMIT 1", senderID).Scan(&spamMsgID)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, collection, deleted) VALUES (?, ?, 'Spam', 0)", spamMsgID, groupID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/group/work?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result []group.GroupWork
	json2.Unmarshal(rsp(resp), &result)
	assert.GreaterOrEqual(t, len(result), 1)

	// Find our group in the results.
	var found *group.GroupWork
	for i := range result {
		if result[i].Groupid == groupID {
			found = &result[i]
			break
		}
	}
	assert.NotNil(t, found, "Expected group %d in work results", groupID)
	assert.GreaterOrEqual(t, found.Pending, int64(1), "Expected pending >= 1")
	assert.GreaterOrEqual(t, found.Spam, int64(1), "Expected spam >= 1")
	// Since this is an active group, pendingother should be 0 for unheld messages.
	assert.Equal(t, int64(0), found.Pendingother, "Unheld pending on active group should be in 'pending', not 'pendingother'")

	// Clean up.
	db.Exec("DELETE FROM messages_groups WHERE msgid IN (?, ?)", msgID, spamMsgID)
	db.Exec("DELETE FROM messages WHERE id IN (?, ?)", msgID, spamMsgID)
}

func TestGetGroupWork_BackupMod(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("gwbackup")

	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "User")

	// Create backup mod membership (active=0 in settings).
	db.Exec("INSERT INTO memberships (userid, groupid, role, settings) VALUES (?, ?, 'Moderator', ?)",
		userID, groupID, `{"active":0}`)
	_, token := CreateTestSession(t, userID)

	// Insert a pending message.
	senderID := CreateTestUser(t, prefix+"_sender", "User")
	var msgID uint64
	db.Exec("INSERT INTO messages (fromuser, type, subject, textbody, message) VALUES (?, 'Offer', 'Test backup pending', 'Test body', 'Test body')", senderID)
	db.Raw("SELECT id FROM messages WHERE fromuser = ? ORDER BY id DESC LIMIT 1", senderID).Scan(&msgID)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, collection, deleted, contentcheck_checked_at) VALUES (?, ?, 'Pending', 0, NOW())", msgID, groupID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/group/work?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result []group.GroupWork
	json2.Unmarshal(rsp(resp), &result)

	var found *group.GroupWork
	for i := range result {
		if result[i].Groupid == groupID {
			found = &result[i]
			break
		}
	}
	assert.NotNil(t, found, "Expected group %d in work results", groupID)
	// Backup group: all pending → pendingother.
	assert.Equal(t, int64(0), found.Pending, "Backup group pending should be in 'pendingother'")
	assert.GreaterOrEqual(t, found.Pendingother, int64(1), "Expected pendingother >= 1 for backup group")
	// Spam should be 0 for inactive groups.
	assert.Equal(t, int64(0), found.Spam, "Backup group should not have spam count")

	// Clean up.
	db.Exec("DELETE FROM messages_groups WHERE msgid = ?", msgID)
	db.Exec("DELETE FROM messages WHERE id = ?", msgID)
}

func TestGetGroupWork_HeldPending(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("gwheld")

	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	holderID := CreateTestUser(t, prefix+"_holder", "User")

	CreateTestMembership(t, modID, groupID, "Moderator")
	_, token := CreateTestSession(t, modID)

	// Insert a held pending message — held is tracked per-group on
	// messages_groups.heldby (not the global messages.heldby).
	senderID := CreateTestUser(t, prefix+"_sender", "User")
	var msgID uint64
	db.Exec("INSERT INTO messages (fromuser, type, subject, textbody, message) VALUES (?, 'Offer', 'Test held', 'Test body', 'Test body')", senderID)
	db.Raw("SELECT id FROM messages WHERE fromuser = ? ORDER BY id DESC LIMIT 1", senderID).Scan(&msgID)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, collection, deleted, heldby) VALUES (?, ?, 'Pending', 0, ?)", msgID, groupID, holderID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/group/work?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result []group.GroupWork
	json2.Unmarshal(rsp(resp), &result)

	var found *group.GroupWork
	for i := range result {
		if result[i].Groupid == groupID {
			found = &result[i]
			break
		}
	}
	assert.NotNil(t, found, "Expected group %d in work results", groupID)
	// Held message on active group → pendingother.
	assert.Equal(t, int64(0), found.Pending, "Held pending should not be in 'pending'")
	assert.GreaterOrEqual(t, found.Pendingother, int64(1), "Held pending should be in 'pendingother'")

	// Clean up.
	db.Exec("DELETE FROM messages_groups WHERE msgid = ?", msgID)
	db.Exec("DELETE FROM messages WHERE id = ?", msgID)
}

// TestGetGroupWork_HeldPerGroup verifies that holding a cross-posted message on
// one group does not make it count as held on another group it is also pending on.
// Held status lives on messages_groups.heldby (per-group), not messages.heldby.
func TestGetGroupWork_HeldPerGroup(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("gwheldpg")

	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	holderID := CreateTestUser(t, prefix+"_holder", "User")

	// Active mod on both groups.
	CreateTestMembership(t, modID, groupA, "Moderator")
	CreateTestMembership(t, modID, groupB, "Moderator")
	_, token := CreateTestSession(t, modID)

	// One message pending on both groups, held on group A only.
	senderID := CreateTestUser(t, prefix+"_sender", "User")
	var msgID uint64
	db.Exec("INSERT INTO messages (fromuser, type, subject, textbody, message) VALUES (?, 'Offer', 'Test held per group', 'Test body', 'Test body')", senderID)
	db.Raw("SELECT id FROM messages WHERE fromuser = ? ORDER BY id DESC LIMIT 1", senderID).Scan(&msgID)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, collection, deleted, heldby) VALUES (?, ?, 'Pending', 0, ?)", msgID, groupA, holderID)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, collection, deleted, contentcheck_checked_at) VALUES (?, ?, 'Pending', 0, NOW())", msgID, groupB)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/group/work?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result []group.GroupWork
	json2.Unmarshal(rsp(resp), &result)

	var gA, gB *group.GroupWork
	for i := range result {
		if result[i].Groupid == groupA {
			gA = &result[i]
		}
		if result[i].Groupid == groupB {
			gB = &result[i]
		}
	}
	assert.NotNil(t, gA, "Expected group A in results")
	assert.NotNil(t, gB, "Expected group B in results")

	// Held on A → counted as held (pendingother), not unheld (pending).
	assert.Equal(t, int64(0), gA.Pending, "Held-on-A copy must not be in group A 'pending'")
	assert.GreaterOrEqual(t, gA.Pendingother, int64(1), "Held-on-A copy must be in group A 'pendingother'")

	// Not held on B → counted as unheld (pending), not held (pendingother).
	assert.GreaterOrEqual(t, gB.Pending, int64(1), "Unheld-on-B copy must be in group B 'pending'")
	assert.Equal(t, int64(0), gB.Pendingother, "Unheld-on-B copy must not be in group B 'pendingother'")

	// Clean up.
	db.Exec("DELETE FROM messages_groups WHERE msgid = ?", msgID)
	db.Exec("DELETE FROM messages WHERE id = ?", msgID)
}

func TestGetGroupWork_SpamMembers(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("gwspam")

	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")

	CreateTestMembership(t, modID, groupID, "Moderator")
	_, token := CreateTestSession(t, modID)

	// Insert a spam member (reviewrequestedat set, reviewedat NULL).
	spamUserID := CreateTestUser(t, prefix+"_spam", "User")
	db.Exec("INSERT INTO memberships (userid, groupid, role, collection, reviewrequestedat) VALUES (?, ?, 'Member', 'Approved', NOW())",
		spamUserID, groupID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/group/work?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result []group.GroupWork
	json2.Unmarshal(rsp(resp), &result)

	var found *group.GroupWork
	for i := range result {
		if result[i].Groupid == groupID {
			found = &result[i]
			break
		}
	}
	assert.NotNil(t, found)
	assert.GreaterOrEqual(t, found.Spammembers, int64(1), "Expected spammembers >= 1")
}

func TestGetGroupWork_SpamMembersReFlaggedAfterRecentReview(t *testing.T) {
	// Regression: commit 4749246f6 changed the membership list query to use
	// reviewrequestedat > reviewedat (member re-flagged after a recent review).
	// The badge count query in groupWork.go must use the same condition so the
	// count matches what the Member Review page actually shows.
	db := database.DBConn
	prefix := uniquePrefix("gwreflg")

	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, token := CreateTestSession(t, modID)

	// Create a member who was reviewed recently (within 31 days) but then
	// re-flagged AFTER that review. The old 31-day window would not count
	// this member; the correct reviewrequestedat > reviewedat condition does.
	spamUserID := CreateTestUser(t, prefix+"_spam", "User")
	db.Exec(`INSERT INTO memberships (userid, groupid, role, collection, reviewedat, reviewrequestedat)
		VALUES (?, ?, 'Member', 'Approved',
		DATE_SUB(NOW(), INTERVAL 5 DAY),
		NOW())`,
		spamUserID, groupID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/group/work?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result []group.GroupWork
	json2.Unmarshal(rsp(resp), &result)

	var found *group.GroupWork
	for i := range result {
		if result[i].Groupid == groupID {
			found = &result[i]
			break
		}
	}
	assert.NotNil(t, found)
	assert.GreaterOrEqual(t, found.Spammembers, int64(1),
		"Re-flagged member (reviewrequestedat > reviewedat) must appear in spammembers count")
}

func TestGetGroupWork_MultipleGroups(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("gwmulti")

	groupID1 := CreateTestGroup(t, prefix + "_g1")
	groupID2 := CreateTestGroup(t, prefix + "_g2")
	modID := CreateTestUser(t, prefix+"_mod", "User")

	// Active on group 1, backup on group 2.
	CreateTestMembership(t, modID, groupID1, "Moderator")
	db.Exec("INSERT INTO memberships (userid, groupid, role, settings) VALUES (?, ?, 'Moderator', ?)",
		modID, groupID2, `{"active":0}`)
	_, token := CreateTestSession(t, modID)

	// Pending message in each group.
	senderID := CreateTestUser(t, prefix+"_sender", "User")
	var msgID1, msgID2 uint64
	db.Exec("INSERT INTO messages (fromuser, type, subject, textbody, message) VALUES (?, 'Offer', 'Test multi 1', 'Test body', 'Test body')", senderID)
	db.Raw("SELECT id FROM messages WHERE fromuser = ? ORDER BY id DESC LIMIT 1", senderID).Scan(&msgID1)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, collection, deleted, contentcheck_checked_at) VALUES (?, ?, 'Pending', 0, NOW())", msgID1, groupID1)

	db.Exec("INSERT INTO messages (fromuser, type, subject, textbody, message) VALUES (?, 'Offer', 'Test multi 2', 'Test body', 'Test body')", senderID)
	db.Raw("SELECT id FROM messages WHERE fromuser = ? ORDER BY id DESC LIMIT 1", senderID).Scan(&msgID2)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, collection, deleted, contentcheck_checked_at) VALUES (?, ?, 'Pending', 0, NOW())", msgID2, groupID2)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/group/work?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result []group.GroupWork
	json2.Unmarshal(rsp(resp), &result)

	var g1, g2 *group.GroupWork
	for i := range result {
		if result[i].Groupid == groupID1 {
			g1 = &result[i]
		}
		if result[i].Groupid == groupID2 {
			g2 = &result[i]
		}
	}

	assert.NotNil(t, g1, "Expected group %d (active) in results", groupID1)
	assert.NotNil(t, g2, "Expected group %d (backup) in results", groupID2)

	// Active group: pending in primary field.
	assert.GreaterOrEqual(t, g1.Pending, int64(1))
	assert.Equal(t, int64(0), g1.Pendingother)

	// Backup group: pending in other field.
	assert.Equal(t, int64(0), g2.Pending)
	assert.GreaterOrEqual(t, g2.Pendingother, int64(1))

	// Results should be sorted by groupid.
	for i := 1; i < len(result); i++ {
		assert.Less(t, result[i-1].Groupid, result[i].Groupid, "Results should be sorted by groupid")
	}

	// Clean up.
	db.Exec("DELETE FROM messages_groups WHERE msgid IN (?, ?)", msgID1, msgID2)
	db.Exec("DELETE FROM messages WHERE id IN (?, ?)", msgID1, msgID2)
}

func TestGetGroupWork_AllFieldsPresent(t *testing.T) {
	// Verify the JSON response includes all expected fields.
	prefix := uniquePrefix("gwfields")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, token := CreateTestSession(t, modID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/group/work?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	body := rsp(resp)
	var rawResult []map[string]interface{}
	json2.Unmarshal(body, &rawResult)
	assert.GreaterOrEqual(t, len(rawResult), 1)

	// Find our group.
	var found map[string]interface{}
	for _, r := range rawResult {
		if uint64(r["groupid"].(float64)) == groupID {
			found = r
			break
		}
	}
	assert.NotNil(t, found, "Expected group %d in results", groupID)

	// Verify all 16 fields are present in JSON.
	expectedFields := []string{
		"groupid", "pending", "pendingother", "spam",
		"pendingmembers", "pendingmembersother", "spammembers", "spammembersother",
		"pendingevents", "pendingvolunteering", "editreview", "pendingadmins",
		"happiness", "relatedmembers", "chatreview", "chatreviewother",
	}
	for _, field := range expectedFields {
		_, ok := found[field]
		assert.True(t, ok, "Expected field %q in response", field)
	}
}

func TestGetGroupWork_PendingMembers(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("gwpendmem")

	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, token := CreateTestSession(t, modID)

	// Create a pending member.
	pendingUserID := CreateTestUser(t, prefix+"_pending", "User")
	db.Exec("INSERT INTO memberships (userid, groupid, role, collection) VALUES (?, ?, 'Member', 'Pending')",
		pendingUserID, groupID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/group/work?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result []group.GroupWork
	json2.Unmarshal(rsp(resp), &result)

	var found *group.GroupWork
	for i := range result {
		if result[i].Groupid == groupID {
			found = &result[i]
			break
		}
	}
	assert.NotNil(t, found)
	assert.GreaterOrEqual(t, found.Pendingmembers, int64(1), "Expected pendingmembers >= 1")
}

func TestGetGroupWork_EditReview(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("gwedit")

	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, token := CreateTestSession(t, modID)

	// Create a message with an edit needing review.
	senderID := CreateTestUser(t, prefix+"_sender", "User")
	var msgID uint64
	db.Exec("INSERT INTO messages (fromuser, type, subject, textbody, message) VALUES (?, 'Offer', 'Test edit review', 'Test body', 'Test body')", senderID)
	db.Raw("SELECT id FROM messages WHERE fromuser = ? ORDER BY id DESC LIMIT 1", senderID).Scan(&msgID)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, collection, deleted) VALUES (?, ?, 'Approved', 0)", msgID, groupID)
	db.Exec("INSERT INTO messages_edits (msgid, timestamp, reviewrequired, oldtext, newtext) VALUES (?, NOW(), 1, 'old', 'new')", msgID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/group/work?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result []group.GroupWork
	json2.Unmarshal(rsp(resp), &result)

	var found *group.GroupWork
	for i := range result {
		if result[i].Groupid == groupID {
			found = &result[i]
			break
		}
	}
	assert.NotNil(t, found)
	assert.GreaterOrEqual(t, found.Editreview, int64(1), "Expected editreview >= 1")

	// Clean up.
	db.Exec("DELETE FROM messages_edits WHERE msgid = ?", msgID)
	db.Exec("DELETE FROM messages_groups WHERE msgid = ?", msgID)
	db.Exec("DELETE FROM messages WHERE id = ?", msgID)
}

// Regression (Discourse 9839): an edit on a post that rippled INTO a group must
// NOT inflate that group's Edit work count. The rippled-in copy is Approved with
// rippled_in=1; the Edit list filters rippled_in=0, so a count that ignored that
// showed a "ghost" badge against a group whose Edit list is empty. The count must
// match the list — edits belong to the post's ORIGIN group only.
func TestGetGroupWork_EditReviewExcludesRippledIn(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("gweditrip")

	originGroup := CreateTestGroup(t, prefix+"_orig")
	rippledGroup := CreateTestGroup(t, prefix+"_rip")
	originMod := CreateTestUser(t, prefix+"_omod", "User")
	rippledMod := CreateTestUser(t, prefix+"_rmod", "User")
	CreateTestMembership(t, originMod, originGroup, "Moderator")
	CreateTestMembership(t, rippledMod, rippledGroup, "Moderator")
	_, originToken := CreateTestSession(t, originMod)
	_, rippledToken := CreateTestSession(t, rippledMod)

	senderID := CreateTestUser(t, prefix+"_sender", "User")
	var msgID uint64
	db.Exec("INSERT INTO messages (fromuser, type, subject, textbody, message) VALUES (?, 'Offer', 'Rippled edit count', 'b', 'b')", senderID)
	db.Raw("SELECT id FROM messages WHERE fromuser = ? ORDER BY id DESC LIMIT 1", senderID).Scan(&msgID)
	// Origin row (rippled_in=0) plus a rippled-in copy (rippled_in=1).
	db.Exec("INSERT INTO messages_groups (msgid, groupid, collection, deleted, rippled_in) VALUES (?, ?, 'Approved', 0, 0)", msgID, originGroup)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, collection, deleted, rippled_in) VALUES (?, ?, 'Approved', 0, 1)", msgID, rippledGroup)
	db.Exec("INSERT INTO messages_edits (msgid, timestamp, reviewrequired, oldtext, newtext) VALUES (?, NOW(), 1, 'old', 'new')", msgID)

	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_edits WHERE msgid = ?", msgID)
		db.Exec("DELETE FROM messages_groups WHERE msgid = ?", msgID)
		db.Exec("DELETE FROM messages WHERE id = ?", msgID)
	})

	editCountFor := func(token string, gid uint64) int64 {
		resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/group/work?jwt="+token, nil))
		assert.Equal(t, 200, resp.StatusCode)
		var result []group.GroupWork
		json2.Unmarshal(rsp(resp), &result)
		for i := range result {
			if result[i].Groupid == gid {
				return result[i].Editreview
			}
		}
		return 0
	}

	assert.GreaterOrEqual(t, editCountFor(originToken, originGroup), int64(1),
		"origin group's Edit count should include the edit")
	assert.Equal(t, int64(0), editCountFor(rippledToken, rippledGroup),
		"rippled-into group's Edit count must NOT include the rippled-in copy's edit (Discourse 9839)")
}

func TestGetGroupWork_OwnerRole(t *testing.T) {
	// Owners should also see work counts.
	prefix := uniquePrefix("gwowner")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, ownerID, groupID, "Owner")
	_, token := CreateTestSession(t, ownerID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/group/work?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result []group.GroupWork
	json2.Unmarshal(rsp(resp), &result)

	var found *group.GroupWork
	for i := range result {
		if result[i].Groupid == groupID {
			found = &result[i]
			break
		}
	}
	assert.NotNil(t, found, "Owner should see group %d in work results", groupID)
	assert.Equal(t, groupID, found.Groupid)
}

func TestGetGroupWork_RegularMemberNoResults(t *testing.T) {
	// Regular members (not mod/owner) should not see work counts for that group.
	prefix := uniquePrefix("gwmember")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix+"_user", "User")
	CreateTestMembership(t, userID, groupID, "Member")
	_, token := CreateTestSession(t, userID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/group/work?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result []group.GroupWork
	json2.Unmarshal(rsp(resp), &result)

	// Regular member should not have this group in results.
	for _, r := range result {
		assert.NotEqual(t, groupID, r.Groupid, "Regular member should not see group work for %d", groupID)
	}
}

func TestGetGroupWork_PendingAdmins(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("gwadmin")

	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, token := CreateTestSession(t, modID)

	// Create a pending admin.
	db.Exec("INSERT INTO admins (groupid, subject, text, pending, created) VALUES (?, 'Test Admin', 'Test Admin Text', 1, NOW())", groupID)
	var adminID uint64
	db.Raw("SELECT id FROM admins WHERE groupid = ? ORDER BY id DESC LIMIT 1", groupID).Scan(&adminID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/group/work?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result []group.GroupWork
	json2.Unmarshal(rsp(resp), &result)

	var found *group.GroupWork
	for i := range result {
		if result[i].Groupid == groupID {
			found = &result[i]
			break
		}
	}
	assert.NotNil(t, found)
	assert.GreaterOrEqual(t, found.Pendingadmins, int64(1), "Expected pendingadmins >= 1")

	// Clean up.
	if adminID > 0 {
		db.Exec("DELETE FROM admins WHERE id = ?", adminID)
	}
}

func TestGetGroupWork_PendingAdmins_BackupGroupIgnored(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("gwadmbk")

	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")

	// Backup mod.
	db.Exec("INSERT INTO memberships (userid, groupid, role, settings) VALUES (?, ?, 'Moderator', ?)",
		modID, groupID, `{"active":0}`)
	_, token := CreateTestSession(t, modID)

	// Create a pending admin.
	db.Exec("INSERT INTO admins (groupid, subject, text, pending, created) VALUES (?, 'Test Admin', 'Test Admin Text', 1, NOW())", groupID)
	var adminID uint64
	db.Raw("SELECT id FROM admins WHERE groupid = ? ORDER BY id DESC LIMIT 1", groupID).Scan(&adminID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/group/work?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result []group.GroupWork
	json2.Unmarshal(rsp(resp), &result)

	var found *group.GroupWork
	for i := range result {
		if result[i].Groupid == groupID {
			found = &result[i]
			break
		}
	}
	assert.NotNil(t, found)
	// Pending admins only counted for active groups.
	assert.Equal(t, int64(0), found.Pendingadmins, "Backup group should not count pending admins")

	// Clean up.
	if adminID > 0 {
		db.Exec("DELETE FROM admins WHERE id = ?", adminID)
	}
}

func TestGetGroupWork_DeletedMessageNotCounted(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("gwdelmsg")

	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, token := CreateTestSession(t, modID)

	senderID := CreateTestUser(t, prefix+"_sender", "User")
	var msgID uint64
	db.Exec("INSERT INTO messages (fromuser, type, subject, textbody, message, deleted) VALUES (?, 'Offer', 'Test deleted pending', 'Test body', 'Test body', NOW())", senderID)
	db.Raw("SELECT id FROM messages WHERE fromuser = ? ORDER BY id DESC LIMIT 1", senderID).Scan(&msgID)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, collection, deleted, contentcheck_checked_at) VALUES (?, ?, 'Pending', 0, NOW())", msgID, groupID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/group/work?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result []group.GroupWork
	json2.Unmarshal(rsp(resp), &result)

	var found *group.GroupWork
	for i := range result {
		if result[i].Groupid == groupID {
			found = &result[i]
			break
		}
	}
	assert.NotNil(t, found, "Expected group %d in work results", groupID)
	assert.Equal(t, int64(0), found.Pending, "Deleted message should not be counted in pending")
	assert.Equal(t, int64(0), found.Pendingother, "Deleted message should not be counted in pendingother")

	// Clean up.
	db.Exec("DELETE FROM messages_groups WHERE msgid = ?", msgID)
	db.Exec("DELETE FROM messages WHERE id = ?", msgID)
}

func TestGetGroupWork_DeletedUserNotCounted(t *testing.T) {
	// Regression: when a user self-deletes (limbo), users.deleted is set but
	// messages_groups rows remain. Per-group count queries must exclude these.
	db := database.DBConn
	prefix := uniquePrefix("gwdelusr")

	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, token := CreateTestSession(t, modID)

	senderID := CreateTestUser(t, prefix+"_sender", "User")
	msgID := CreateTestMessage(t, senderID, groupID, "OFFER: Limbo pending", 55.9533, -3.1883)
	db.Exec("UPDATE messages_groups SET collection = 'Pending' WHERE msgid = ?", msgID)

	// Soft-delete the user.
	db.Exec("UPDATE users SET deleted = NOW() WHERE id = ?", senderID)
	defer db.Exec("UPDATE users SET deleted = NULL WHERE id = ?", senderID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/group/work?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result []group.GroupWork
	json2.Unmarshal(rsp(resp), &result)

	var found *group.GroupWork
	for i := range result {
		if result[i].Groupid == groupID {
			found = &result[i]
			break
		}
	}
	assert.NotNil(t, found, "Expected group %d in work results", groupID)
	assert.Equal(t, int64(0), found.Pending, "Deleted user's message should not be counted in pending")
	assert.Equal(t, int64(0), found.Pendingother, "Deleted user's message should not be counted in pendingother")
}

func TestGetGroupWork_SortedByGroupid(t *testing.T) {
	prefix := uniquePrefix("gwsort")

	// Create 3 groups.
	gids := make([]uint64, 3)
	for i := 0; i < 3; i++ {
		gids[i] = CreateTestGroup(t, fmt.Sprintf("%s_%d", prefix, i))
	}

	modID := CreateTestUser(t, prefix+"_mod", "User")
	for _, gid := range gids {
		CreateTestMembership(t, modID, gid, "Moderator")
	}
	_, token := CreateTestSession(t, modID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/group/work?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result []group.GroupWork
	json2.Unmarshal(rsp(resp), &result)

	// Verify sorted.
	for i := 1; i < len(result); i++ {
		assert.Less(t, result[i-1].Groupid, result[i].Groupid)
	}
}

func TestGetGroupWork_HappinessExcludesEmptyComments(t *testing.T) {
	// Ratings without comments (empty string) should not count in the happiness badge.
	prefix := uniquePrefix("gwhapempty")
	db := database.DBConn
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, token := CreateTestSession(t, modID)

	userID := CreateTestUser(t, prefix+"_user", "User")
	msgID := CreateTestMessage(t, userID, groupID, prefix+" offer item", 52.5, -1.8)

	// Insert outcome with empty-string comment (simulating a rating-only click).
	db.Exec("INSERT INTO messages_outcomes (msgid, outcome, happiness, comments, reviewed) VALUES (?, 'Taken', 'Happy', '', 0)", msgID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/group/work?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result []group.GroupWork
	json2.Unmarshal(rsp(resp), &result)

	var found *group.GroupWork
	for i := range result {
		if result[i].Groupid == groupID {
			found = &result[i]
			break
		}
	}
	assert.NotNil(t, found)
	assert.Equal(t, int64(0), found.Happiness, "Empty-string comments should not count in happiness badge")

	// Now insert one with a real comment — it should count.
	msgID2 := CreateTestMessage(t, userID, groupID, prefix+" offer item2", 52.5, -1.8)
	db.Exec("INSERT INTO messages_outcomes (msgid, outcome, happiness, comments, reviewed) VALUES (?, 'Taken', 'Happy', 'Great!', 0)", msgID2)

	resp2, _ := getApp().Test(httptest.NewRequest("GET", "/api/group/work?jwt="+token, nil))
	assert.Equal(t, 200, resp2.StatusCode)

	var result2 []group.GroupWork
	json2.Unmarshal(rsp(resp2), &result2)

	var found2 *group.GroupWork
	for i := range result2 {
		if result2[i].Groupid == groupID {
			found2 = &result2[i]
			break
		}
	}
	assert.NotNil(t, found2)
	assert.Equal(t, int64(1), found2.Happiness, "Real comments should count in happiness badge")
}

// The Feedback (Happiness) badge count must match the Feedback list: a post that
// rippled INTO a group counts only for its ORIGIN group, not the rippled-into
// one. Regression guard for the badge/list mismatch that got PR #1144 rejected
// (only the list was scoped). Discourse 9808/633.
func TestGetGroupWork_HappinessExcludesRippledIn(t *testing.T) {
	prefix := uniquePrefix("gwhapripple")
	db := database.DBConn
	originGroup := CreateTestGroup(t, prefix+"_origin")
	rippledGroup := CreateTestGroup(t, prefix+"_rippled")

	// One moderator of BOTH groups, so /api/group/work returns both counts.
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, originGroup, "Moderator")
	CreateTestMembership(t, modID, rippledGroup, "Moderator")
	_, token := CreateTestSession(t, modID)

	userID := CreateTestUser(t, prefix+"_user", "User")
	msgID := CreateTestMessage(t, userID, originGroup, prefix+" offer item", 52.5, -1.8)
	db.Exec("INSERT INTO messages_outcomes (msgid, outcome, happiness, comments, reviewed) VALUES (?, 'Taken', 'Happy', 'Great!', 0)", msgID)
	// Ripple the same post into rippledGroup (Approved copy, rippled_in = 1).
	db.Exec("INSERT INTO messages_groups (msgid, groupid, collection, arrival, rippled_in) VALUES (?, ?, 'Approved', NOW(), 1)", msgID, rippledGroup)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/group/work?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)
	var result []group.GroupWork
	json2.Unmarshal(rsp(resp), &result)

	var origin, rippled *group.GroupWork
	for i := range result {
		if result[i].Groupid == originGroup {
			origin = &result[i]
		} else if result[i].Groupid == rippledGroup {
			rippled = &result[i]
		}
	}
	assert.NotNil(t, origin)
	assert.Equal(t, int64(1), origin.Happiness, "origin group's badge counts the item")
	if rippled != nil {
		assert.Equal(t, int64(0), rippled.Happiness, "rippled-into group's badge must not count the rippled-in copy")
	}
}

// group/work only counts a pending post once its content check has run - until then it may
// still be auto-approved, so counting it shows a number the moderator cannot act on
// (Discourse 9481/563). session.go applies the same filter for the ModTools badge; without
// it here the two disagreed about the same queue.
//
// A HELD post is exempt: a moderator has claimed it, it will never auto-approve, and it is
// already sitting in their list. Holding also used to stop the content check running at
// all, so a held post could stay unchecked indefinitely and vanish from the count entirely
// (Discourse 9481/635).
func TestGroupWorkPendingContentCheckFilter(t *testing.T) {
	prefix := uniquePrefix("gw_cccheck")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	holderID := CreateTestUser(t, prefix+"_holder", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, token := CreateTestSession(t, modID)

	senderID := CreateTestUser(t, prefix+"_sender", "User")
	mk := func(subject string) uint64 {
		var id uint64
		db.Exec("INSERT INTO messages (fromuser, type, subject, textbody, message) VALUES (?, 'Offer', ?, 'Test body', 'Test body')", senderID, subject)
		db.Raw("SELECT id FROM messages WHERE fromuser = ? AND subject = ? ORDER BY id DESC LIMIT 1", senderID, subject).Scan(&id)
		return id
	}

	uncheckedID := mk(prefix + " unchecked unheld")
	heldUncheckedID := mk(prefix + " unchecked HELD")
	defer db.Exec("DELETE FROM messages_groups WHERE msgid IN (?, ?)", uncheckedID, heldUncheckedID)
	defer db.Exec("DELETE FROM messages WHERE id IN (?, ?)", uncheckedID, heldUncheckedID)

	// Unheld and not yet content-checked: must NOT count - it may still auto-approve.
	db.Exec("INSERT INTO messages_groups (msgid, groupid, collection, deleted, contentcheck_checked_at) VALUES (?, ?, 'Pending', 0, NULL)", uncheckedID, groupID)
	// Held and not yet content-checked: must still count as held work.
	db.Exec("INSERT INTO messages_groups (msgid, groupid, collection, deleted, heldby, contentcheck_checked_at) VALUES (?, ?, 'Pending', 0, ?, NULL)", heldUncheckedID, groupID, holderID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/group/work?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result []group.GroupWork
	json2.Unmarshal(rsp(resp), &result)

	var g *group.GroupWork
	for i := range result {
		if result[i].Groupid == groupID {
			g = &result[i]
		}
	}
	assert.NotNil(t, g, "Expected the group in results")

	assert.Equal(t, int64(0), g.Pending,
		"an unheld post with no content check yet must not be counted")
	assert.Equal(t, int64(1), g.Pendingother,
		"a held post counts even before the content check has run")
}
