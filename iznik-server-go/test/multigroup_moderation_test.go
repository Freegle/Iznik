package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// A moderator running several neighbouring communities sees one card per post in
// ModTools, and Approve, Reject and Delete on that card act on every community the post
// is pending on that they moderate, by sending them as groupids. These tests pin what that
// does: every copy that can be acted on is, the member hears once, and copies another
// moderator holds or the home community has locked are left alone and reported.

// multiGroupPost is a post pending on its home community and rippled, still pending, into
// two more, with one moderator running all three. Returns (home, second, third, msgid, mod,
// token).
func multiGroupPost(t *testing.T, name string) (uint64, uint64, uint64, uint64, uint64, string) {
	t.Helper()
	db := database.DBConn
	prefix := uniquePrefix(name)

	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	home := CreateTestGroup(t, prefix+"_home")
	second := CreateTestGroup(t, prefix+"_second")
	third := CreateTestGroup(t, prefix+"_third")

	msgID := CreateTestMessage(t, posterID, home, "OFFER: "+prefix+" item", 51.5, -0.1)
	require.NoError(t, db.Exec("UPDATE messages_groups SET collection = 'Pending' WHERE msgid = ? AND groupid = ?", msgID, home).Error)
	for _, gid := range []uint64{second, third} {
		require.NoError(t, db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in) "+
			"VALUES (?, ?, NOW(), 'Pending', 0, 1)", msgID, gid).Error)
	}

	for _, gid := range []uint64{home, second, third} {
		CreateTestMembership(t, modID, gid, "Moderator")
	}
	_, token := CreateTestSession(t, modID)

	return home, second, third, msgID, modID, token
}

// postMultiAction sends a moderation action naming several communities and returns the
// status and decoded body.
func postMultiAction(t *testing.T, token string, body map[string]interface{}) (int, map[string]interface{}) {
	t.Helper()
	payload, _ := json.Marshal(body)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/message?jwt=%s", token), bytes.NewBuffer(payload))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	require.NoError(t, err)

	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)
	return resp.StatusCode, result
}

func collectionOn(t *testing.T, msgID, gid uint64) string {
	t.Helper()
	var collection string
	database.DBConn.Raw("SELECT collection FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, gid).Scan(&collection)
	return collection
}

func taskCount(t *testing.T, taskType string, msgID uint64) int64 {
	t.Helper()
	var n int64
	database.DBConn.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type = ? AND JSON_EXTRACT(data, '$.msgid') = ?",
		taskType, msgID).Scan(&n)
	return n
}

func skippedList(result map[string]interface{}, key string) []uint64 {
	var out []uint64
	skipped, _ := result["skipped"].(map[string]interface{})
	list, _ := skipped[key].([]interface{})
	for _, v := range list {
		out = append(out, uint64(v.(float64)))
	}
	return out
}

func TestApproveOnSeveralCommunitiesApprovesEveryCopy(t *testing.T) {
	home, second, third, msgID, _, token := multiGroupPost(t, "mg_approve")

	status, result := postMultiAction(t, token, map[string]interface{}{
		"id": msgID, "action": "Approve", "groupids": []uint64{home, second, third},
		"subject": "Welcome", "body": "Approved with a note.",
	})
	assert.Equal(t, 200, status)
	assert.Equal(t, float64(0), result["ret"])

	for _, gid := range []uint64{home, second, third} {
		assert.Equal(t, "Approved", collectionOn(t, msgID, gid), "every copy is approved by the one action")
	}

	assert.Equal(t, int64(3), taskCount(t, "email_message_approved", msgID),
		"each community still gets its own log entry and moderator push")
	assert.Equal(t, 1, notifyPosterFlag(t, "email_message_approved", msgID, home),
		"the member hears from their home community")
	assert.Equal(t, 0, notifyPosterFlag(t, "email_message_approved", msgID, second))
	assert.Equal(t, 0, notifyPosterFlag(t, "email_message_approved", msgID, third))
}

func TestApproveOnSeveralCommunitiesSkipsCopyHeldByAnother(t *testing.T) {
	db := database.DBConn
	home, second, third, msgID, _, token := multiGroupPost(t, "mg_held")

	otherMod := CreateTestUser(t, uniquePrefix("mg_held_other"), "User")
	CreateTestMembership(t, otherMod, third, "Moderator")
	db.Exec("UPDATE messages_groups SET heldby = ? WHERE msgid = ? AND groupid = ?", otherMod, msgID, third)

	status, result := postMultiAction(t, token, map[string]interface{}{
		"id": msgID, "action": "Approve", "groupids": []uint64{home, second, third},
	})
	assert.Equal(t, 200, status, "a held copy does not stop the others being approved")

	assert.Equal(t, "Approved", collectionOn(t, msgID, home))
	assert.Equal(t, "Approved", collectionOn(t, msgID, second))
	assert.Equal(t, "Pending", collectionOn(t, msgID, third), "the held copy is left alone")

	var heldby uint64
	db.Raw("SELECT COALESCE(heldby, 0) FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, third).Scan(&heldby)
	assert.Equal(t, otherMod, heldby, "and keeps its hold")

	assert.Equal(t, []uint64{third}, skippedList(result, "heldbyother"))
}

func TestApproveOnSeveralCommunitiesAllHeldIsRefused(t *testing.T) {
	db := database.DBConn
	home, second, third, msgID, _, token := multiGroupPost(t, "mg_allheld")

	otherMod := CreateTestUser(t, uniquePrefix("mg_allheld_other"), "User")
	db.Exec("UPDATE messages_groups SET heldby = ? WHERE msgid = ?", otherMod, msgID)

	status, _ := postMultiAction(t, token, map[string]interface{}{
		"id": msgID, "action": "Approve", "groupids": []uint64{home, second, third},
	})
	assert.Equal(t, 409, status, "with every copy held there is nothing to act on")

	for _, gid := range []uint64{home, second, third} {
		assert.Equal(t, "Pending", collectionOn(t, msgID, gid))
	}
}

func TestApproveOnSeveralCommunitiesSkipsCopyLockedByHome(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("mg_locked")

	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	home := CreateTestGroup(t, prefix+"_home")
	locked := CreateTestGroup(t, prefix+"_locked")
	open := CreateTestGroup(t, prefix+"_open")

	msgID := CreateTestMessage(t, posterID, home, "OFFER: "+prefix+" item", 51.5, -0.1)
	db.Exec("UPDATE messages_groups SET collection = 'Pending' WHERE msgid = ? AND groupid = ?", msgID, home)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in, locked_by_home) "+
		"VALUES (?, ?, NOW(), 'Pending', 0, 1, 1)", msgID, locked)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in) "+
		"VALUES (?, ?, NOW(), 'Pending', 0, 1)", msgID, open)

	// This moderator runs the two receiving communities, not the home one.
	CreateTestMembership(t, modID, locked, "Moderator")
	CreateTestMembership(t, modID, open, "Moderator")
	_, token := CreateTestSession(t, modID)

	status, result := postMultiAction(t, token, map[string]interface{}{
		"id": msgID, "action": "Approve", "groupids": []uint64{locked, open},
	})
	assert.Equal(t, 200, status)
	assert.Equal(t, "Approved", collectionOn(t, msgID, open))
	assert.Equal(t, "Pending", collectionOn(t, msgID, locked), "the home community's lock is respected")
	assert.Equal(t, []uint64{locked}, skippedList(result, "lockedbyhome"))

	// On the locked community alone it is still refused outright.
	status, _ = postModAction(t, token, msgID, "Approve", locked)
	assert.Equal(t, 403, status)
}

func TestApproveCrossPostOnSeveralCommunitiesSendsOneMessage(t *testing.T) {
	prefix := uniquePrefix("mg_xpost")

	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	first, second, _, msgID := crossPostedPost(t, prefix, posterID, "Pending")
	// crossPostedPost leaves the first direct copy Approved; this one is still waiting.
	database.DBConn.Exec("UPDATE messages_groups SET collection = 'Pending' WHERE msgid = ? AND groupid = ?", msgID, first)
	CreateTestMembership(t, modID, first, "Moderator")
	CreateTestMembership(t, modID, second, "Moderator")
	_, token := CreateTestSession(t, modID)

	// The member posted to both directly, so either could write to them. The community
	// sent first - the one the moderator was looking at - is the one that does.
	status, _ := postMultiAction(t, token, map[string]interface{}{
		"id": msgID, "action": "Approve", "groupids": []uint64{second, first},
		"subject": "Welcome", "body": "Approved with a note.",
	})
	assert.Equal(t, 200, status)

	assert.Equal(t, 1, notifyPosterFlag(t, "email_message_approved", msgID, second))
	assert.Equal(t, 0, notifyPosterFlag(t, "email_message_approved", msgID, first),
		"one action on two communities is one message to the member")
}

func TestRejectOnSeveralCommunitiesSendsOneMessage(t *testing.T) {
	prefix := uniquePrefix("mg_reject")

	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	first, second, rippled, msgID := crossPostedPost(t, prefix, posterID, "Pending")
	// crossPostedPost leaves the first direct copy Approved; this one is still waiting.
	database.DBConn.Exec("UPDATE messages_groups SET collection = 'Pending' WHERE msgid = ? AND groupid = ?", msgID, first)
	for _, gid := range []uint64{first, second, rippled} {
		CreateTestMembership(t, modID, gid, "Moderator")
	}
	_, token := CreateTestSession(t, modID)

	status, result := postMultiAction(t, token, map[string]interface{}{
		"id": msgID, "action": "Reject", "groupids": []uint64{first, second, rippled},
		"subject": "Sorry", "body": "We can't accept this.",
	})
	assert.Equal(t, 200, status)
	assert.Equal(t, float64(0), result["ret"])

	for _, gid := range []uint64{first, second, rippled} {
		assert.Equal(t, "Rejected", collectionOn(t, msgID, gid))
	}

	assert.Equal(t, int64(3), taskCount(t, "email_message_rejected", msgID))
	assert.Equal(t, 1, notifyPosterFlag(t, "email_message_rejected", msgID, first))
	assert.Equal(t, 0, notifyPosterFlag(t, "email_message_rejected", msgID, second),
		"the second home community's rejection is logged but not sent again")
	assert.Equal(t, 0, notifyPosterFlag(t, "email_message_rejected", msgID, rippled))
}

func TestRejectOnSeveralCommunitiesSkipsCopyHeldByAnother(t *testing.T) {
	db := database.DBConn
	home, second, third, msgID, _, token := multiGroupPost(t, "mg_rejheld")

	otherMod := CreateTestUser(t, uniquePrefix("mg_rejheld_other"), "User")
	db.Exec("UPDATE messages_groups SET heldby = ? WHERE msgid = ? AND groupid = ?", otherMod, msgID, second)

	status, result := postMultiAction(t, token, map[string]interface{}{
		"id": msgID, "action": "Reject", "groupids": []uint64{home, second, third},
		"subject": "Sorry", "body": "We can't accept this.",
	})
	assert.Equal(t, 200, status)
	assert.Equal(t, "Rejected", collectionOn(t, msgID, home))
	assert.Equal(t, "Pending", collectionOn(t, msgID, second))
	assert.Equal(t, "Rejected", collectionOn(t, msgID, third))
	assert.Equal(t, []uint64{second}, skippedList(result, "heldbyother"))
}

func TestDeleteOnSeveralCommunitiesLeavesCopiesNoLongerPending(t *testing.T) {
	db := database.DBConn
	home, second, third, msgID, _, token := multiGroupPost(t, "mg_delete")

	// Another moderator approved the third copy after the card was drawn.
	db.Exec("UPDATE messages_groups SET collection = 'Approved' WHERE msgid = ? AND groupid = ?", msgID, third)

	status, _ := postMultiAction(t, token, map[string]interface{}{
		"id": msgID, "action": "Delete", "groupids": []uint64{home, second, third},
	})
	assert.Equal(t, 200, status)

	var remaining []uint64
	db.Raw("SELECT groupid FROM messages_groups WHERE msgid = ?", msgID).Scan(&remaining)
	assert.Equal(t, []uint64{third}, remaining, "only the copies still pending are deleted")
	assert.Equal(t, int64(2), taskCount(t, "email_message_rejected", msgID))
	assert.Equal(t, 1, notifyPosterFlag(t, "email_message_rejected", msgID, home))
}

func TestSeveralCommunitiesMustAllBeModerated(t *testing.T) {
	home, second, _, msgID, _, token := multiGroupPost(t, "mg_notmine")

	notMine := CreateTestGroup(t, uniquePrefix("mg_notmine_other"))
	database.DBConn.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in) "+
		"VALUES (?, ?, NOW(), 'Pending', 0, 1)", msgID, notMine)

	status, _ := postMultiAction(t, token, map[string]interface{}{
		"id": msgID, "action": "Approve", "groupids": []uint64{home, second, notMine},
	})
	assert.Equal(t, 403, status, "naming a community you do not moderate refuses the whole action")

	for _, gid := range []uint64{home, second, notMine} {
		assert.Equal(t, "Pending", collectionOn(t, msgID, gid))
	}
}

func TestPosterNotifyFlagsWritesOnce(t *testing.T) {
	home := map[uint64]bool{1: true, 2: true}

	assert.Equal(t, map[uint64]int{3: 0, 2: 1, 1: 0}, message.PosterNotifyFlags(home, []uint64{3, 2, 1}),
		"the first home community in the order given writes, no other does")
	assert.Equal(t, map[uint64]int{5: 1, 6: 0}, message.PosterNotifyFlags(map[uint64]bool{}, []uint64{5, 6}),
		"with no home known one community still writes, as NotifyPosterFlag fails open")
	assert.Equal(t, map[uint64]int{3: 0}, message.PosterNotifyFlags(home, []uint64{3}),
		"a rippled-in community alone never writes")
}
