package test

import (
	"bytes"
	"encoding/json"
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/aiimage"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/log"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func TestBounds(t *testing.T) {
	// Create a message in specific bounds for this test
	prefix := uniquePrefix("bounds")
	userID := CreateTestUser(t, prefix, "User")
	CreateTestMessage(t, userID, "Test Bounds Item", 55.9533, -3.1883)

	// Get within the bounds
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/inbounds?swlat=55&swlng=-3.5&nelat=56&nelng=-3", nil))
	assert.Equal(t, 200, resp.StatusCode)

	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)
	assert.Greater(t, len(msgs), 0)

	// Repeat but logged in
	_, token := CreateFullTestUser(t, prefix+"_auth")
	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/message/inbounds?swlat=55&swlng=-3.5&nelat=56&nelng=-3&jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)
	json2.Unmarshal(rsp(resp), &msgs)
	assert.Greater(t, len(msgs), 0)

	// Get outside bounds
	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/message/inbounds?swlng=55&swlat=-3.5&nelng=56&nelat=-3", nil))
	assert.Equal(t, 200, resp.StatusCode)
	json2.Unmarshal(rsp(resp), &msgs)
	assert.Equal(t, len(msgs), 0)
}

func TestMyGroups(t *testing.T) {
	// Get logged out - should return 401
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/mygroups", nil))
	assert.Equal(t, 401, resp.StatusCode)

	// Create a full test user with group membership and message
	prefix := uniquePrefix("mygroups")
	userID, token := CreateFullTestUser(t, prefix)

	// Create a group the user is in with a message
	CreateTestMessage(t, userID, "Test MyGroups Item", 55.9533, -3.1883)

	// Should be able to fetch messages in our groups
	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/message/mygroups?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)
	// We expect at least some messages (could be from other tests too)
}

func TestMessagesByUser(t *testing.T) {
	// Create a user with a message
	prefix := uniquePrefix("usermsg")
	userID := CreateTestUser(t, prefix, "User")
	CreateTestMessage(t, userID, "Test User Message", 55.9533, -3.1883)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/user/"+fmt.Sprint(userID)+"/message", nil))
	assert.Equal(t, 200, resp.StatusCode)

	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)
	assert.Greater(t, len(msgs), 0)

	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/user/"+fmt.Sprint(userID)+"/message?active=true", nil))
	assert.Equal(t, 200, resp.StatusCode)

	json2.Unmarshal(rsp(resp), &msgs)
	assert.Greater(t, len(msgs), 0)

	// Invalid user
	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/user/z/message", nil))
	assert.Equal(t, 404, resp.StatusCode)
}

func TestActiveQueryExcludesExpiredMessages(t *testing.T) {
	prefix := uniquePrefix("expire")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	// Recent message (1 day old) — should appear in active.
	recentID := CreateTestMessageWithArrival(t, userID, "OFFER: Fresh Sofa", 55.9533, -3.1883, 1)

	// Old message (200 days old, well past default 90-day Offer expiry) — should NOT appear in active.
	oldID := CreateTestMessageWithArrival(t, userID, "OFFER: Ancient Chair", 55.9533, -3.1883, 200)

	// Active query should include recent, exclude old.
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/user/"+fmt.Sprint(userID)+"/message?active=true&jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)

	foundRecent := false
	foundOld := false
	for _, m := range msgs {
		if m.ID == recentID {
			foundRecent = true
		}
		if m.ID == oldID {
			foundOld = true
		}
	}
	assert.True(t, foundRecent, "Recent message should appear in active query")
	assert.False(t, foundOld, "Expired message should be excluded from active query")

	// Non-active query should return both, with old marked as hasoutcome=true.
	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/user/"+fmt.Sprint(userID)+"/message?active=false&jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	json2.Unmarshal(rsp(resp), &msgs)

	for _, m := range msgs {
		if m.ID == recentID {
			assert.False(t, m.Hasoutcome, "Recent message should not have hasoutcome set")
		}
		if m.ID == oldID {
			assert.True(t, m.Hasoutcome, "Expired message should have hasoutcome=true in non-active query")
		}
	}
}

func TestExpiredPromisedMessageExcludedFromActive(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("exprms")
	userID := CreateTestUser(t, prefix, "User")
	promiserID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	// Old message (200 days) with a promise — should be excluded from active
	// because it's past the expiry age. Promises don't prevent expiry.
	msgID := CreateTestMessageWithArrival(t, userID, "OFFER: Promised Table", 55.9533, -3.1883, 200)
	db.Exec("INSERT INTO messages_promises (msgid, userid) VALUES (?, ?)", msgID, promiserID)
	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_promises WHERE msgid = ?", msgID)
	})

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/user/"+fmt.Sprint(userID)+"/message?active=true&jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)

	found := false
	for _, m := range msgs {
		if m.ID == msgID {
			found = true
		}
	}
	assert.False(t, found, "Expired promised message should NOT appear in active query")
}

func TestExpiredMessageWithRecentChatKeptActive(t *testing.T) {
	// An old message past expiry age should remain active if there's recent
	// chat activity referencing it (ongoing conversation).
	db := database.DBConn
	prefix := uniquePrefix("exprchat")
	userID := CreateTestUser(t, prefix, "User")
	otherID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	// Old message (200 days) — would normally expire.
	msgID := CreateTestMessageWithArrival(t, userID, "OFFER: "+prefix+" chat item", 55.9533, -3.1883, 200)

	// Create a chat room between the two users and a recent chat message
	// referencing the old message.
	var chatID uint64
	db.Exec("INSERT INTO chat_rooms (user1, user2, chattype, latestmessage) VALUES (?, ?, 'User2User', NOW())", userID, otherID)
	db.Raw("SELECT id FROM chat_rooms WHERE user1 = ? AND user2 = ? AND chattype = 'User2User'", userID, otherID).Scan(&chatID)
	db.Exec("INSERT INTO chat_messages (chatid, userid, message, type, refmsgid, date, processingsuccessful, reviewrequired, reviewrejected) VALUES (?, ?, 'Is this still available?', 'Default', ?, NOW(), 1, 0, 0)",
		chatID, otherID, msgID)
	t.Cleanup(func() {
		db.Exec("DELETE FROM chat_messages WHERE chatid = ?", chatID)
		db.Exec("DELETE FROM chat_rooms WHERE id = ?", chatID)
	})

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/user/"+fmt.Sprint(userID)+"/message?active=true&jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)

	found := false
	for _, m := range msgs {
		if m.ID == msgID {
			found = true
		}
	}
	assert.True(t, found, "Old message with recent chat should remain active")
}

func TestExpiredMessageHeldActiveByUnrelatedRoomChatAgedOut(t *testing.T) {
	// An old post must NOT be kept in active My Posts merely because the chat ROOM
	// that once referenced it has had recent UNRELATED activity. Freegle user-to-user
	// rooms are one long-lived room per pair of people, so chat_rooms.latestmessage
	// tracks any conversation between them. Recency must be judged by the chat message
	// that actually references the post (refmsgid), not the room's overall last
	// message — otherwise old posts get pinned active whenever the two users chat
	// about anything else (Discourse 9481/583). Companion to
	// TestExpiredMessageWithRecentChatKeptActive: same shape, but the reference is OLD
	// while the room is recent, so this one must age out.
	db := database.DBConn
	prefix := uniquePrefix("roomchat")
	userID := CreateTestUser(t, prefix, "User")
	otherID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	// Old message (200 days) — past expiry.
	msgID := CreateTestMessageWithArrival(t, userID, "OFFER: "+prefix+" stale shared-room item", 55.9533, -3.1883, 200)

	// Long-lived room whose LATEST message is recent (unrelated chat), but the chat
	// message that references THIS post is old — the discussion of this post ended
	// long ago.
	var chatID uint64
	db.Exec("INSERT INTO chat_rooms (user1, user2, chattype, latestmessage) VALUES (?, ?, 'User2User', NOW())", userID, otherID)
	db.Raw("SELECT id FROM chat_rooms WHERE user1 = ? AND user2 = ? AND chattype = 'User2User'", userID, otherID).Scan(&chatID)
	db.Exec("INSERT INTO chat_messages (chatid, userid, message, type, refmsgid, date, processingsuccessful, reviewrequired, reviewrejected) VALUES (?, ?, 'interested long ago', 'Default', ?, DATE_SUB(NOW(), INTERVAL 200 DAY), 1, 0, 0)",
		chatID, otherID, msgID)
	t.Cleanup(func() {
		db.Exec("DELETE FROM chat_messages WHERE chatid = ?", chatID)
		db.Exec("DELETE FROM chat_rooms WHERE id = ?", chatID)
	})

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/user/"+fmt.Sprint(userID)+"/message?active=true&jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)

	found := false
	for _, m := range msgs {
		if m.ID == msgID {
			found = true
		}
	}
	assert.False(t, found, "Old post must age out of active My Posts despite recent UNRELATED chat in the shared room (Discourse 9481/583)")
}

func TestNonSpatialMessageMarkedOldInInactiveQuery(t *testing.T) {
	// Messages without a spatial entry (not publicly visible) should be marked
	// hasoutcome=true in the active=false response so the client's old/active
	// split matches the active=true HAVING clause.
	db := database.DBConn
	prefix := uniquePrefix("nonspatial")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	// Create a message and remove its spatial entry to simulate a post that
	// was removed from the index (e.g. by the V1 expiry cron).
	msgID := CreateTestMessageWithArrival(t, userID, "OFFER: No Spatial", 55.9533, -3.1883, 10)
	db.Exec("DELETE FROM messages_spatial WHERE msgid = ?", msgID)

	// active=true: should NOT include it (HAVING requires spatialid IS NOT NULL).
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/user/"+fmt.Sprint(userID)+"/message?active=true&jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)
	var activeMsgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &activeMsgs)
	for _, m := range activeMsgs {
		assert.NotEqual(t, msgID, m.ID, "Non-spatial message should not appear in active=true")
	}

	// active=false: should include it with hasoutcome=true.
	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/user/"+fmt.Sprint(userID)+"/message?active=false&jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)
	var allMsgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &allMsgs)
	found := false
	for _, m := range allMsgs {
		if m.ID == msgID {
			found = true
			assert.True(t, m.Hasoutcome, "Non-spatial message should have hasoutcome=true in active=false response")
		}
	}
	assert.True(t, found, "Non-spatial message should appear in active=false response")
}

func TestCount(t *testing.T) {
	// Create a full test user for count endpoint
	prefix := uniquePrefix("count")
	_, token := CreateFullTestUser(t, prefix)

	var count int

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/count?browseView=mygroups&jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)
	json2.Unmarshal(rsp(resp), &count)
	// Count can be 0 for a new user

	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/message/count?browseView=nearby&jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)
	json2.Unmarshal(rsp(resp), &count)
	// Count can be 0 for a new user
}

func TestMessageUnseenStatus(t *testing.T) {
	// Test that messages are correctly marked as unseen/seen based on messages_likes View entries
	prefix := uniquePrefix("unseen")

	// Create message owner
	ownerID := CreateTestUser(t, prefix+"_owner", "User")

	// Create a viewer who will mark the message as seen
	viewerID := CreateTestUser(t, prefix+"_viewer", "User")
	_, viewerToken := CreateTestSession(t, viewerID)

	// Create a message
	msgID := CreateTestMessage(t, ownerID, "Test Unseen Item", 55.9533, -3.1883)

	// Get owner's messages as viewer - should show unseen=true (no View record exists)
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/user/"+fmt.Sprint(ownerID)+"/message?jwt="+viewerToken, nil))
	assert.Equal(t, 200, resp.StatusCode)

	type MessageWithUnseen struct {
		ID     uint64 `json:"id"`
		Unseen bool   `json:"unseen"`
	}

	var msgs []MessageWithUnseen
	json2.Unmarshal(rsp(resp), &msgs)

	// Find our message
	var foundMsg *MessageWithUnseen
	for i, m := range msgs {
		if m.ID == msgID {
			foundMsg = &msgs[i]
			break
		}
	}
	assert.NotNil(t, foundMsg, "Message should be found in user's messages")
	assert.True(t, foundMsg.Unseen, "Message should be unseen before viewing")

	// Mark the message as viewed by the viewer
	MarkMessageAsViewed(t, viewerID, msgID)

	// Get owner's messages again as viewer - should now show unseen=false
	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/user/"+fmt.Sprint(ownerID)+"/message?jwt="+viewerToken, nil))
	assert.Equal(t, 200, resp.StatusCode)

	json2.Unmarshal(rsp(resp), &msgs)

	// Find our message again
	foundMsg = nil
	for i, m := range msgs {
		if m.ID == msgID {
			foundMsg = &msgs[i]
			break
		}
	}
	assert.NotNil(t, foundMsg, "Message should still be found in user's messages")
	assert.False(t, foundMsg.Unseen, "Message should be seen after viewing")
}

// =============================================================================
// Additional auth & error tests for partial-coverage endpoints
// =============================================================================

func TestBounds_MissingParams(t *testing.T) {
	// Missing all required bounds params - should return empty (defaults to 0,0,0,0)
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/inbounds", nil))
	assert.Equal(t, 200, resp.StatusCode)

	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)
	assert.Equal(t, 0, len(msgs))
}

func TestBounds_PartialParams(t *testing.T) {
	// Only some bounds params provided
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/inbounds?swlat=55", nil))
	assert.Equal(t, 200, resp.StatusCode)
}

func TestMessagesByUser_NonExistentUser(t *testing.T) {
	// User ID that doesn't exist should return 200 with empty array
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/user/999999999/message", nil))
	assert.Equal(t, 200, resp.StatusCode)
}

func TestMessageModOnlyFields(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("msg_modfields")

	// Create group, regular user, and mod user.
	regularUserID := CreateTestUser(t, prefix+"_reg", "User")
	modUserID := CreateTestUser(t, prefix+"_mod", "Moderator")
	PromoteTestUserToModerator(t, modUserID)
	_, regularToken := CreateTestSession(t, regularUserID)
	_, modToken := CreateTestSession(t, modUserID)

	// Create a message with source/fromip/fromcountry set.
	msgID := CreateTestMessage(t, regularUserID, "Test Mod Fields Item", 55.9533, -3.1883)
	db.Exec("UPDATE messages SET source = 'Platform', sourceheader = 'Freegle App', fromaddr = 'test@users.ilovefreegle.org', fromip = '1.2.3.4', fromcountry = 'GB' WHERE id = ?", msgID)

	// Fetch as mod — should see source/fromip/fromcountry.
	resp, err := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d?jwt=%s", msgID, modToken), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var modMsg message.Message
	json2.Unmarshal(rsp(resp), &modMsg)
	assert.NotNil(t, modMsg.Source, "Mod should see source")
	assert.Equal(t, "Platform", *modMsg.Source)
	assert.NotNil(t, modMsg.Fromip, "Mod should see fromip")
	assert.Equal(t, "1.2.3.4", *modMsg.Fromip)
	assert.NotNil(t, modMsg.Fromcountry, "Mod should see fromcountry")

	// Fetch as regular user — should NOT see source/fromip/fromcountry.
	resp, err = getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d?jwt=%s", msgID, regularToken), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var regMsg message.Message
	json2.Unmarshal(rsp(resp), &regMsg)
	assert.Nil(t, regMsg.Source, "Regular user should NOT see source")
	assert.Nil(t, regMsg.Fromip, "Regular user should NOT see fromip")
	assert.Nil(t, regMsg.Fromcountry, "Regular user should NOT see fromcountry")
	assert.Nil(t, regMsg.Fromaddr, "Regular user should NOT see fromaddr")

	// Fetch without auth — should NOT see source/fromip/fromcountry.
	resp, err = getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d", msgID), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var anonMsg message.Message
	json2.Unmarshal(rsp(resp), &anonMsg)
	assert.Nil(t, anonMsg.Source, "Anonymous user should NOT see source")
	assert.Nil(t, anonMsg.Fromip, "Anonymous user should NOT see fromip")
	assert.Nil(t, anonMsg.Fromcountry, "Anonymous user should NOT see fromcountry")
}

// Why a post was held names the keyword that flagged it, which tells a spammer
// exactly what to avoid next time. It is moderator information (Discourse #9988).
func TestMessageContentCheckReasonsAreModOnly(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("msg_ccreasons")

	regularUserID := CreateTestUser(t, prefix+"_reg", "User")
	modUserID := CreateTestUser(t, prefix+"_mod", "Moderator")
	PromoteTestUserToModerator(t, modUserID)
	_, regularToken := CreateTestSession(t, regularUserID)
	_, modToken := CreateTestSession(t, modUserID)

	msgID := CreateTestMessage(t, regularUserID, "Test ContentCheck Reasons Item", 55.9533, -3.1883)
	db.Exec("UPDATE messages SET contentcheck_checked_at = NOW(), contentcheck_reasons = ? WHERE id = ?",
		`[{"check":"AIJudgement","category":"substance_medicine","action":"flag","keyword":"mineral","detail":"Matched concern keyword 'mineral'"}]`,
		msgID)

	resp, err := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d?jwt=%s", msgID, modToken), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var modMsg message.Message
	json2.Unmarshal(rsp(resp), &modMsg)
	assert.NotNil(t, modMsg.ContentcheckReasons, "mod should see contentcheck_reasons")
	if modMsg.ContentcheckReasons != nil {
		assert.Contains(t, string(*modMsg.ContentcheckReasons), "mineral",
			"a mod needs the word that flagged the post")
	}

	resp, err = getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d?jwt=%s", msgID, regularToken), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var regMsg message.Message
	json2.Unmarshal(rsp(resp), &regMsg)
	assert.Nil(t, regMsg.ContentcheckReasons, "a member must NOT see why their post was held")
	assert.Nil(t, regMsg.ContentcheckCheckedAt, "nor when it was checked")

	resp, err = getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d", msgID), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var anonMsg2 message.Message
	json2.Unmarshal(rsp(resp), &anonMsg2)
	assert.Nil(t, anonMsg2.ContentcheckReasons, "logged out must NOT see why a post was held")
}

// --- Mod action helpers ---

// --- Test: Approve ---

// --- Test: Reject ---

// --- Test: Delete (mod action) ---

// TestPostMessageDeleteNoDuplicateLog asserts that POST /message?action=Delete does NOT
// synchronously write a Message/Deleted row to the logs table.  The batch processor
// (ProcessBackgroundTasksCommand) is the sole writer: it inserts the row when it picks up
// the email_message_rejected background task.  Adding a second synchronous write in the Go
// handler creates an identical duplicate in production (one from Go, one from PHP).
//
// handleDeleteMessage was temporarily broken to add a logAndNotifyMods() call (bug: duplicate
// logs).  The fix removed that call; this test guards against regression by asserting count==0.
func TestPostMessageDeleteNoDuplicateLog(t *testing.T) {
	prefix := uniquePrefix("msgmod_del_duplog")
	db := database.DBConn

	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	PromoteTestUserToModerator(t, modID)
	_, modToken := CreateTestSession(t, modID)

	msgID := CreateTestMessage(t, posterID, prefix+" offer item", 52.5, -1.8)

	body := map[string]interface{}{
		"id":     msgID,
		"action": "Delete",
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", modToken)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// The Go handler must NOT write a Message/Deleted log entry directly.
	// The batch processor writes it when processing the email_message_rejected task.
	// A sync write here produces a duplicate in production (Go + PHP = 2 identical rows).
	var logCount int64
	db.Raw("SELECT COUNT(*) FROM logs WHERE type = ? AND subtype = ? AND msgid = ?",
		log.LOG_TYPE_MESSAGE, log.LOG_SUBTYPE_DELETED, msgID).Scan(&logCount)
	assert.Equal(t, int64(0), logCount,
		"handleDeleteMessage must not sync-write a logs row: count expected 0, batch processor is the sole writer")
}

// --- Test: Spam ---

// --- Test: Hold ---

// --- Test: Release ---

// --- Test: ApproveEdits ---

func TestPostMessageApproveEdits(t *testing.T) {
	prefix := uniquePrefix("msgmod_aped")
	db := database.DBConn

	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	PromoteTestUserToModerator(t, modID)
	_, modToken := CreateTestSession(t, modID)

	msgID := CreateTestMessage(t, posterID, prefix+" offer item", 52.5, -1.8)

	// Mark as edited.
	db.Exec("UPDATE messages SET editedby = ? WHERE id = ?", posterID, msgID)

	// Create a pending edit.
	newSubject := prefix + " updated subject"
	newText := "Updated body text"
	db.Exec("INSERT INTO messages_edits (msgid, byuser, oldsubject, newsubject, oldtext, newtext, reviewrequired) VALUES (?, ?, ?, ?, 'Old text', ?, 1)",
		msgID, posterID, prefix+" offer item", newSubject, newText)

	body := map[string]interface{}{
		"id":     msgID,
		"action": "ApproveEdits",
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", modToken)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify editedby cleared.
	var editedby *uint64
	db.Raw("SELECT editedby FROM messages WHERE id = ?", msgID).Scan(&editedby)
	assert.Nil(t, editedby)

	// Verify subject and textbody updated.
	var subject, textbody string
	db.Raw("SELECT subject, COALESCE(textbody, '') FROM messages WHERE id = ?", msgID).Row().Scan(&subject, &textbody)
	assert.Equal(t, newSubject, subject)
	assert.Equal(t, newText, textbody)

	// Verify edit marked as approved with reviewrequired = 0.
	var approvedCount int64
	db.Raw("SELECT COUNT(*) FROM messages_edits WHERE msgid = ? AND approvedat IS NOT NULL AND reviewrequired = 0", msgID).Scan(&approvedCount)
	assert.Equal(t, int64(1), approvedCount)

	// Verify it no longer appears in the V1-style count query (which only checks reviewrequired).
	var pendingEditCount int64
	db.Raw("SELECT COUNT(*) FROM messages_edits WHERE msgid = ? AND reviewrequired = 1", msgID).Scan(&pendingEditCount)
	assert.Equal(t, int64(0), pendingEditCount, "Approved edit should not appear in V1 count query")
}

// --- Test: RevertEdits ---

func TestPostMessageRevertEdits(t *testing.T) {
	prefix := uniquePrefix("msgmod_rved")
	db := database.DBConn

	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	PromoteTestUserToModerator(t, modID)
	_, modToken := CreateTestSession(t, modID)

	msgID := CreateTestMessage(t, posterID, prefix+" offer item", 52.5, -1.8)

	// Simulate the real edit flow: PATCH immediately updates messages with the new text,
	// then records old/new in messages_edits for mod review.
	db.Exec("UPDATE messages SET subject = ?, textbody = ?, editedby = ? WHERE id = ?",
		prefix+" changed subject", "New text", posterID, msgID)
	db.Exec("INSERT INTO messages_edits (msgid, byuser, oldsubject, newsubject, oldtext, newtext, reviewrequired) VALUES (?, ?, ?, ?, 'Old text', 'New text', 1)",
		msgID, posterID, prefix+" offer item", prefix+" changed subject")

	body := map[string]interface{}{
		"id":     msgID,
		"action": "RevertEdits",
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", modToken)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify editedby cleared.
	var editedby *uint64
	db.Raw("SELECT editedby FROM messages WHERE id = ?", msgID).Scan(&editedby)
	assert.Nil(t, editedby)

	// Verify subject restored to original (not the edited value).
	var subject string
	db.Raw("SELECT subject FROM messages WHERE id = ?", msgID).Scan(&subject)
	assert.Equal(t, prefix+" offer item", subject)

	// Verify textbody restored to original.
	var textbody string
	db.Raw("SELECT COALESCE(textbody, '') FROM messages WHERE id = ?", msgID).Scan(&textbody)
	assert.Equal(t, "Old text", textbody)

	// Verify edit marked as reverted with reviewrequired = 0.
	var revertedCount int64
	db.Raw("SELECT COUNT(*) FROM messages_edits WHERE msgid = ? AND revertedat IS NOT NULL AND reviewrequired = 0", msgID).Scan(&revertedCount)
	assert.Equal(t, int64(1), revertedCount)

	// Verify it no longer appears in the V1-style count query.
	var pendingEditCount int64
	db.Raw("SELECT COUNT(*) FROM messages_edits WHERE msgid = ? AND reviewrequired = 1", msgID).Scan(&pendingEditCount)
	assert.Equal(t, int64(0), pendingEditCount, "Reverted edit should not appear in V1 count query")
}

// --- Test: PartnerConsent ---

func TestPostMessagePartnerConsent(t *testing.T) {
	prefix := uniquePrefix("msgmod_pc")
	db := database.DBConn

	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	PromoteTestUserToModerator(t, modID)
	_, modToken := CreateTestSession(t, modID)

	msgID := CreateTestMessage(t, posterID, prefix+" offer item", 52.5, -1.8)

	// Create a test partner.
	partnerName := prefix + "_partner"
	db.Exec("INSERT INTO partners_keys (partner, `key`) VALUES (?, ?)", partnerName, prefix+"_key")
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", partnerName)

	body := map[string]interface{}{
		"id":      msgID,
		"action":  "PartnerConsent",
		"partner": partnerName,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", modToken)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify partners_messages record created.
	var pmCount int64
	db.Raw("SELECT COUNT(*) FROM partners_messages WHERE msgid = ?", msgID).Scan(&pmCount)
	assert.Equal(t, int64(1), pmCount)
	defer db.Exec("DELETE FROM partners_messages WHERE msgid = ?", msgID)
}

// --- Test: Reply ---

// --- Test: JoinAndPost ---

// A location-only edit (client sends locationid but no lat/lng) must denormalise
// lat/lng from the chosen location, not leave them stale/NULL. Otherwise the post
// becomes undiscoverable - browse/search read messages.lat/lng directly. This is
// the live msg 119554658 shape: a valid locationid, but NULL lat/lng (Discourse 9865).
func TestPatchMessageLocationOnlyEditDenormalisesLatLng(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("patch_locdenorm")
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	token := getToken(t, ownerID)

	msgID := CreateTestMessage(t, ownerID, prefix+" chair", 55.95, -3.18)

	// A real location (postcode) with its own lat/lng.
	var locID uint64
	var locLat, locLng float64
	db.Raw("SELECT id, lat, lng FROM locations WHERE lat IS NOT NULL AND lng IS NOT NULL AND lat <> 0 LIMIT 1").
		Row().Scan(&locID, &locLat, &locLng)
	require.NotZero(t, locID, "test DB needs a location with lat/lng")

	// Edit ONLY the location - send locationid, deliberately no lat/lng.
	body := map[string]interface{}{"id": msgID, "locationid": locID}
	bb, _ := json.Marshal(body)
	req := httptest.NewRequest("PATCH", "/api/message?jwt="+token, bytes.NewBuffer(bb))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, 10000)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)

	// lat/lng must now match the chosen location, derived server-side.
	var gotLat, gotLng *float64
	var gotLoc *uint64
	db.Raw("SELECT lat, lng, locationid FROM messages WHERE id = ?", msgID).Row().Scan(&gotLat, &gotLng, &gotLoc)
	require.NotNil(t, gotLoc)
	assert.Equal(t, locID, *gotLoc)
	require.NotNil(t, gotLat, "location-only edit must derive lat from the location, not leave it NULL (9865)")
	require.NotNil(t, gotLng, "location-only edit must derive lng from the location, not leave it NULL (9865)")
	assert.InDelta(t, locLat, *gotLat, 0.0001)
	assert.InDelta(t, locLng, *gotLng, 0.0001)
}

// --- Test: PatchMessage ---

func TestGetMessageReturnsEditsForMod(t *testing.T) {
	prefix := uniquePrefix("msg_get_edits")
	db := database.DBConn

	posterID := CreateTestUser(t, prefix+"_poster", "User")

	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	PromoteTestUserToModerator(t, modID)
	_, modToken := CreateTestSession(t, modID)

	// Non-mod user (systemrole User, group role Member)
	otherID := CreateTestUser(t, prefix+"_other", "User")
	_, otherToken := CreateTestSession(t, otherID)

	msgID := CreateTestMessage(t, posterID, prefix+" item", 52.5, -1.8)

	// Create a pending edit with oldtext and newtext.
	db.Exec("INSERT INTO messages_edits (msgid, byuser, oldtext, newtext, reviewrequired, timestamp) VALUES (?, ?, 'Old body text', 'New body text', 1, NOW())",
		msgID, posterID)

	// Fetch as mod — should see edits with oldtext/newtext.
	resp, _ := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/message/%d?jwt=%s", msgID, modToken), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var msg map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&msg)

	edits, hasEdits := msg["edits"]
	assert.True(t, hasEdits, "Mod should see edits field")

	editList := edits.([]interface{})
	assert.Equal(t, 1, len(editList), "Should have 1 pending edit")

	edit := editList[0].(map[string]interface{})
	assert.Equal(t, "Old body text", edit["oldtext"])
	assert.Equal(t, "New body text", edit["newtext"])

	// Fetch as non-mod — should NOT see edits.
	resp2, _ := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/message/%d?jwt=%s", msgID, otherToken), nil))
	assert.Equal(t, 200, resp2.StatusCode)

	var msg2 map[string]interface{}
	json.NewDecoder(resp2.Body).Decode(&msg2)

	_, hasEdits2 := msg2["edits"]
	assert.False(t, hasEdits2, "Non-mod should NOT see edits field")

	// Cleanup
	db.Exec("DELETE FROM messages_edits WHERE msgid = ?", msgID)
}

func TestGetMessageReturnsLocationForMod(t *testing.T) {
	prefix := uniquePrefix("msg_get_loc")
	db := database.DBConn

	posterID := CreateTestUser(t, prefix+"_poster", "User")

	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	PromoteTestUserToModerator(t, modID)
	_, modToken := CreateTestSession(t, modID)

	msgID := CreateTestMessage(t, posterID, prefix+" item", 52.5, -1.8)

	// Create a location and assign it to the message.
	db.Exec("INSERT INTO locations (name, type, lat, lng) VALUES (?, 'Postcode', 52.5, -1.8)", prefix+"_PC")
	var locID uint64
	db.Raw("SELECT id FROM locations WHERE name = ? ORDER BY id DESC LIMIT 1", prefix+"_PC").Scan(&locID)
	assert.Greater(t, locID, uint64(0), "Location should be created")
	db.Exec("UPDATE messages SET locationid = ? WHERE id = ?", locID, msgID)

	// Fetch as mod — location should have correct lat/lng from the location record.
	resp, _ := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/message/%d?jwt=%s", msgID, modToken), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var msg map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&msg)

	loc, hasLoc := msg["location"]
	assert.True(t, hasLoc, "Mod should see location")

	locMap := loc.(map[string]interface{})
	assert.NotEqual(t, float64(0), locMap["lat"], "Location lat should not be 0")
	assert.NotEqual(t, float64(0), locMap["lng"], "Location lng should not be 0")
	assert.InDelta(t, 52.5, locMap["lat"].(float64), 0.01, "Location lat should match")
	assert.InDelta(t, -1.8, locMap["lng"].(float64), 0.01, "Location lng should match")

	// Fetch as non-mod — should NOT see precise location (privacy).
	otherID := CreateTestUser(t, prefix+"_other", "User")
	_, otherToken := CreateTestSession(t, otherID)

	resp2, _ := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/message/%d?jwt=%s", msgID, otherToken), nil))
	assert.Equal(t, 200, resp2.StatusCode)

	var msg2 map[string]interface{}
	json.NewDecoder(resp2.Body).Decode(&msg2)

	_, hasLoc2 := msg2["location"]
	assert.False(t, hasLoc2, "Non-mod should NOT see precise location")
}

// --- Test: DELETE /message/:id ---

func TestPatchMessageLocationName(t *testing.T) {
	prefix := uniquePrefix("msgmod_patchloc")
	db := database.DBConn

	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	PromoteTestUserToModerator(t, modID)
	_, modToken := CreateTestSession(t, modID)

	userID := CreateTestUser(t, prefix+"_user", "User")
	msgID := CreateTestMessage(t, userID, prefix+" Test Item", 53.0, -1.0)

	// Find a location name to use.
	var locName string
	var locID uint64
	db.Raw("SELECT id, name FROM locations WHERE name LIKE '% %' LIMIT 1").Row().Scan(&locID, &locName)
	if locID == 0 {
		t.Fatal("No locations in test database")
	}

	// PATCH with location name (not locationid) — should resolve to locationid.
	body, _ := json.Marshal(map[string]interface{}{
		"id":       msgID,
		"subject":  prefix + " Edited Subject",
		"location": locName,
	})
	req := httptest.NewRequest("PATCH", "/api/message?jwt="+modToken, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify subject was updated.
	var subject string
	db.Raw("SELECT subject FROM messages WHERE id = ?", msgID).Scan(&subject)
	assert.Equal(t, prefix+" Edited Subject", subject)

	// Verify locationid was set from the location name.
	var msgLocID uint64
	db.Raw("SELECT COALESCE(locationid, 0) FROM messages WHERE id = ?", msgID).Scan(&msgLocID)
	assert.Equal(t, locID, msgLocID, "locationid should be resolved from location name")
}

func TestPatchMessageExtendDeadlineClearsExpiredOutcome(t *testing.T) {
	prefix := uniquePrefix("msgpatch_extend")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	_, ownerToken := CreateTestSession(t, ownerID)

	msgID := CreateTestMessage(t, ownerID, prefix+" Test Item", 53.0, -1.0)

	// Simulate batch job: set a past deadline and insert an Expired outcome.
	db.Exec("UPDATE messages SET deadline = '2026-01-01' WHERE id = ?", msgID)
	db.Exec("INSERT INTO messages_outcomes (msgid, outcome, comments, timestamp) VALUES (?, 'Expired', 'Reached deadline', NOW())", msgID)
	// Simulate an in-progress intended outcome (e.g. user started marking post Taken).
	db.Exec("INSERT INTO messages_outcomes_intended (msgid, outcome) VALUES (?, 'Taken') ON DUPLICATE KEY UPDATE outcome = VALUES(outcome)", msgID)

	// Confirm message currently has an Expired outcome.
	var outcomeCount int64
	db.Raw("SELECT COUNT(*) FROM messages_outcomes WHERE msgid = ? AND outcome = 'Expired'", msgID).Scan(&outcomeCount)
	assert.Equal(t, int64(1), outcomeCount, "message should have Expired outcome before patch")
	var intendedCount int64
	db.Raw("SELECT COUNT(*) FROM messages_outcomes_intended WHERE msgid = ?", msgID).Scan(&intendedCount)
	assert.Equal(t, int64(1), intendedCount, "intended outcome should exist before patch")

	// PATCH with a future deadline — should clear only the Expired outcome.
	futureDeadline := "2027-01-01"
	body, _ := json.Marshal(map[string]interface{}{
		"id":       msgID,
		"deadline": futureDeadline,
	})
	req := httptest.NewRequest("PATCH", "/api/message?jwt="+ownerToken, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Expired outcome should be cleared so the post becomes active again.
	db.Raw("SELECT COUNT(*) FROM messages_outcomes WHERE msgid = ? AND outcome = 'Expired'", msgID).Scan(&outcomeCount)
	assert.Equal(t, int64(0), outcomeCount, "Expired outcome should be cleared after patching with future deadline")
	// In-progress intended outcome must NOT be cleared — it is unrelated to deadline extension.
	db.Raw("SELECT COUNT(*) FROM messages_outcomes_intended WHERE msgid = ?", msgID).Scan(&intendedCount)
	assert.Equal(t, int64(1), intendedCount, "intended outcome should be preserved after deadline extension")
}

// --- Test: PUT /message ---

// --- Test: System Admin can act as mod ---

// --- Test: BackToPending ---

func TestPostMessageNotLoggedIn(t *testing.T) {
	body := map[string]interface{}{"id": 1, "action": "Promise"}
	bodyBytes, _ := json.Marshal(body)
	req := httptest.NewRequest("POST", "/api/message", bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)
}

func TestPostMessageNoID(t *testing.T) {
	prefix := uniquePrefix("msgw_noid")
	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)

	body := map[string]interface{}{"action": "Promise"}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", token)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestPostMessageUnknownAction(t *testing.T) {
	prefix := uniquePrefix("msgw_unk")
	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)

	body := map[string]interface{}{"id": 1, "action": "Bogus"}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", token)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestPostMessagePromise(t *testing.T) {
	prefix := uniquePrefix("msgw_promise")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	_, ownerToken := CreateTestSession(t, ownerID)
	otherID := CreateTestUser(t, prefix+"_other", "User")
	msgID := CreateTestMessage(t, ownerID, prefix+" offer item", 52.5, -1.8)

	// Create a chat room between the users for the system message.
	CreateTestChatRoom(t, ownerID, &otherID, "User2User")

	// Promise the item to the other user.
	body := map[string]interface{}{
		"id":     msgID,
		"action": "Promise",
		"userid": otherID,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", ownerToken)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)
	assert.Equal(t, float64(0), result["ret"])

	// Verify promise recorded in DB.
	var count int64
	db.Raw("SELECT COUNT(*) FROM messages_promises WHERE msgid = ? AND userid = ?", msgID, otherID).Scan(&count)
	assert.Equal(t, int64(1), count)

	// Verify chat message created.
	var chatMsgCount int64
	db.Raw("SELECT COUNT(*) FROM chat_messages WHERE refmsgid = ? AND type = 'Promised'", msgID).Scan(&chatMsgCount)
	assert.Equal(t, int64(1), chatMsgCount)
}

func TestPostMessagePromiseNotYourMessage(t *testing.T) {
	prefix := uniquePrefix("msgw_prm_notmine")

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	otherID := CreateTestUser(t, prefix+"_other", "User")
	_, otherToken := CreateTestSession(t, otherID)
	msgID := CreateTestMessage(t, ownerID, prefix+" offer item", 52.5, -1.8)

	body := map[string]interface{}{
		"id":     msgID,
		"action": "Promise",
		"userid": otherID,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", otherToken)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 403, resp.StatusCode)
}

func TestPostMessagePromiseMessageNotFound(t *testing.T) {
	prefix := uniquePrefix("msgw_prm_nf")

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)

	body := map[string]interface{}{
		"id":     999999999,
		"action": "Promise",
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", token)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 404, resp.StatusCode)
}

func TestPostMessageRenege(t *testing.T) {
	prefix := uniquePrefix("msgw_renege")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	_, ownerToken := CreateTestSession(t, ownerID)
	otherID := CreateTestUser(t, prefix+"_other", "User")
	msgID := CreateTestMessage(t, ownerID, prefix+" offer item", 52.5, -1.8)

	// Create a chat room and a promise first.
	CreateTestChatRoom(t, ownerID, &otherID, "User2User")
	db.Exec("REPLACE INTO messages_promises (msgid, userid) VALUES (?, ?)", msgID, otherID)

	// Renege on the promise.
	body := map[string]interface{}{
		"id":     msgID,
		"action": "Renege",
		"userid": otherID,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", ownerToken)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify promise deleted.
	var promiseCount int64
	db.Raw("SELECT COUNT(*) FROM messages_promises WHERE msgid = ? AND userid = ?", msgID, otherID).Scan(&promiseCount)
	assert.Equal(t, int64(0), promiseCount)

	// Verify renege recorded.
	var renegeCount int64
	db.Raw("SELECT COUNT(*) FROM messages_reneged WHERE msgid = ? AND userid = ?", msgID, otherID).Scan(&renegeCount)
	assert.Equal(t, int64(1), renegeCount)

	// Verify chat message created.
	var chatMsgCount int64
	db.Raw("SELECT COUNT(*) FROM chat_messages WHERE refmsgid = ? AND type = 'Reneged'", msgID).Scan(&chatMsgCount)
	assert.Equal(t, int64(1), chatMsgCount)
}

func TestPostMessageOutcomeIntended(t *testing.T) {
	prefix := uniquePrefix("msgw_intended")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)
	msgID := CreateTestMessage(t, userID, prefix+" offer item", 52.5, -1.8)

	body := map[string]interface{}{
		"id":      msgID,
		"action":  "OutcomeIntended",
		"outcome": "Taken",
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", token)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify intended outcome recorded.
	var outcome string
	db.Raw("SELECT outcome FROM messages_outcomes_intended WHERE msgid = ?", msgID).Scan(&outcome)
	assert.Equal(t, "Taken", outcome)
}

// TestPostMessageOutcomeIntendedRepost verifies that "Repost" is a valid
// intended outcome. The frontend sends this when a user clicks the repost
// link from the notification email.
func TestPostMessageOutcomeIntendedRepost(t *testing.T) {
	prefix := uniquePrefix("msgw_int_rep")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)
	msgID := CreateTestMessage(t, userID, prefix+" offer item", 52.5, -1.8)

	body := map[string]interface{}{
		"id":      msgID,
		"action":  "OutcomeIntended",
		"outcome": "Repost",
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", token)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify intended outcome recorded.
	var outcome string
	db.Raw("SELECT outcome FROM messages_outcomes_intended WHERE msgid = ?", msgID).Scan(&outcome)
	assert.Equal(t, "Repost", outcome)
}

func TestPostMessageOutcomeIntendedInvalid(t *testing.T) {
	prefix := uniquePrefix("msgw_int_inv")

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)
	msgID := CreateTestMessage(t, userID, prefix+" offer item", 52.5, -1.8)

	body := map[string]interface{}{
		"id":      msgID,
		"action":  "OutcomeIntended",
		"outcome": "Invalid",
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", token)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestPostMessageOutcome(t *testing.T) {
	prefix := uniquePrefix("msgw_outcome")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)
	msgID := CreateTestMessage(t, userID, prefix+" offer item", 52.5, -1.8)

	happiness := "Happy"
	comment := "Great transaction"
	body := map[string]interface{}{
		"id":        msgID,
		"action":    "Outcome",
		"outcome":   "Taken",
		"happiness": happiness,
		"comment":   comment,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", token)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify outcome recorded.
	var dbOutcome string
	var dbHappiness string
	var dbComments string
	db.Raw("SELECT outcome, happiness, comments FROM messages_outcomes WHERE msgid = ?", msgID).Row().Scan(&dbOutcome, &dbHappiness, &dbComments)
	assert.Equal(t, "Taken", dbOutcome)
	assert.Equal(t, "Happy", dbHappiness)
	assert.Equal(t, "Great transaction", dbComments)
}

func TestPostMessageOutcomeDuplicate(t *testing.T) {
	prefix := uniquePrefix("msgw_out_dup")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)
	msgID := CreateTestMessage(t, userID, prefix+" offer item", 52.5, -1.8)

	// Insert an existing outcome.
	db.Exec("INSERT INTO messages_outcomes (msgid, outcome) VALUES (?, 'Taken')", msgID)

	body := map[string]interface{}{
		"id":      msgID,
		"action":  "Outcome",
		"outcome": "Withdrawn",
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", token)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 409, resp.StatusCode)
}

// Regression: a message with multiple rows in messages_outcomes (Expired left
// by the deadline-expiry batch plus Withdrawn "Auto-expired" left by the
// spatial-index expiry batch, which historically appended instead of
// replacing) must still allow the owner to mark Taken — the user-visible
// failure that took the owner to the "something went wrong" page.
func TestPostMessageOutcomeAllowsTakenOverExpiredPlusAutoWithdrawn(t *testing.T) {
	prefix := uniquePrefix("msgw_out_dup_expauto")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)
	msgID := CreateTestMessage(t, userID, prefix+" offer item", 52.5, -1.8)

	// Stale duplicate rows from before the batch fix: same shape as the prod
	// data that produced the 409.
	db.Exec("INSERT INTO messages_outcomes (msgid, outcome, comments) VALUES (?, 'Expired', 'Reached deadline')", msgID)
	db.Exec("INSERT INTO messages_outcomes (msgid, outcome, comments) VALUES (?, 'Withdrawn', 'Auto-expired')", msgID)

	body := map[string]interface{}{
		"id":      msgID,
		"action":  "Outcome",
		"outcome": "Taken",
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", token)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode, "owner must be able to mark Taken even when stale duplicate auto-expiry rows exist")

	// Existing rows replaced with a single Taken row.
	var count int64
	db.Raw("SELECT COUNT(*) FROM messages_outcomes WHERE msgid = ?", msgID).Scan(&count)
	assert.Equal(t, int64(1), count, "stale outcome rows should be replaced, not stacked")
	var dbOutcome string
	db.Raw("SELECT outcome FROM messages_outcomes WHERE msgid = ?", msgID).Scan(&dbOutcome)
	assert.Equal(t, "Taken", dbOutcome)
}

// The owner clicks a chase-up-email "Taken" link after the spatial-index
// expiry batch has already auto-withdrawn the post. The Auto-expired
// Withdrawn row is system-generated, so the deliberate user action should
// override it.
func TestPostMessageOutcomeAllowsTakenOverAutoExpiredWithdrawn(t *testing.T) {
	prefix := uniquePrefix("msgw_out_autoexp")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)
	msgID := CreateTestMessage(t, userID, prefix+" offer item", 52.5, -1.8)

	db.Exec("INSERT INTO messages_outcomes (msgid, outcome, comments) VALUES (?, 'Withdrawn', 'Auto-expired')", msgID)

	body := map[string]interface{}{
		"id":      msgID,
		"action":  "Outcome",
		"outcome": "Taken",
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", token)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode, "owner clicking Taken from an earlier chase-up after auto-expiry must succeed")

	var count int64
	db.Raw("SELECT COUNT(*) FROM messages_outcomes WHERE msgid = ?", msgID).Scan(&count)
	assert.Equal(t, int64(1), count)
	var dbOutcome string
	db.Raw("SELECT outcome FROM messages_outcomes WHERE msgid = ?", msgID).Scan(&dbOutcome)
	assert.Equal(t, "Taken", dbOutcome)
}

// Real Withdrawn (no Auto-expired marker, no Expired sibling) is still a
// genuine conflict — the user already deliberately withdrew the post.
func TestPostMessageOutcomeRejectsTakenOverRealWithdrawn(t *testing.T) {
	prefix := uniquePrefix("msgw_out_dup_realw")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)
	msgID := CreateTestMessage(t, userID, prefix+" offer item", 52.5, -1.8)

	db.Exec("INSERT INTO messages_outcomes (msgid, outcome) VALUES (?, 'Withdrawn')", msgID)

	body := map[string]interface{}{
		"id":      msgID,
		"action":  "Outcome",
		"outcome": "Taken",
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", token)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 409, resp.StatusCode)
}

func TestPostMessageOutcomeMessageNotFound(t *testing.T) {
	prefix := uniquePrefix("msgw_out_nf")

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)

	body := map[string]interface{}{
		"id":      999999999,
		"action":  "Outcome",
		"outcome": "Taken",
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", token)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 404, resp.StatusCode)
}

func TestPostMessageAddBy(t *testing.T) {
	prefix := uniquePrefix("msgw_addby")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	_, ownerToken := CreateTestSession(t, ownerID)
	takerID := CreateTestUser(t, prefix+"_taker", "User")
	msgID := CreateTestMessage(t, ownerID, prefix+" offer item", 52.5, -1.8)

	// Set initial availability.
	db.Exec("UPDATE messages SET availableinitially = 5, availablenow = 5 WHERE id = ?", msgID)

	body := map[string]interface{}{
		"id":     msgID,
		"action": "AddBy",
		"userid": takerID,
		"count":  2,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", ownerToken)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify messages_by entry.
	var byCount int
	db.Raw("SELECT count FROM messages_by WHERE msgid = ? AND userid = ?", msgID, takerID).Scan(&byCount)
	assert.Equal(t, 2, byCount)

	// Verify available count reduced.
	var availNow int
	db.Raw("SELECT availablenow FROM messages WHERE id = ?", msgID).Scan(&availNow)
	assert.Equal(t, 3, availNow)
}

func TestPostMessageAddByUpdate(t *testing.T) {
	prefix := uniquePrefix("msgw_addby_upd")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	_, ownerToken := CreateTestSession(t, ownerID)
	takerID := CreateTestUser(t, prefix+"_taker", "User")
	msgID := CreateTestMessage(t, ownerID, prefix+" offer item", 52.5, -1.8)

	// Set initial availability and add an existing entry.
	db.Exec("UPDATE messages SET availableinitially = 5, availablenow = 3 WHERE id = ?", msgID)
	db.Exec("INSERT INTO messages_by (userid, msgid, count) VALUES (?, ?, 2)", takerID, msgID)

	// Update the count to 3.
	body := map[string]interface{}{
		"id":     msgID,
		"action": "AddBy",
		"userid": takerID,
		"count":  3,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", ownerToken)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify updated count.
	var byCount int
	db.Raw("SELECT count FROM messages_by WHERE msgid = ? AND userid = ?", msgID, takerID).Scan(&byCount)
	assert.Equal(t, 3, byCount)

	// Old count was 2, restored to 5, then reduced by 3 = 2.
	var availNow int
	db.Raw("SELECT availablenow FROM messages WHERE id = ?", msgID).Scan(&availNow)
	assert.Equal(t, 2, availNow)
}

func TestPostMessageRemoveBy(t *testing.T) {
	prefix := uniquePrefix("msgw_rmby")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	_, ownerToken := CreateTestSession(t, ownerID)
	takerID := CreateTestUser(t, prefix+"_taker", "User")
	msgID := CreateTestMessage(t, ownerID, prefix+" offer item", 52.5, -1.8)

	// Set availability and add an entry.
	db.Exec("UPDATE messages SET availableinitially = 5, availablenow = 3 WHERE id = ?", msgID)
	db.Exec("INSERT INTO messages_by (userid, msgid, count) VALUES (?, ?, 2)", takerID, msgID)

	body := map[string]interface{}{
		"id":     msgID,
		"action": "RemoveBy",
		"userid": takerID,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", ownerToken)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify entry removed.
	var byCount int64
	db.Raw("SELECT COUNT(*) FROM messages_by WHERE msgid = ? AND userid = ?", msgID, takerID).Scan(&byCount)
	assert.Equal(t, int64(0), byCount)

	// Verify availability restored.
	var availNow int
	db.Raw("SELECT availablenow FROM messages WHERE id = ?", msgID).Scan(&availNow)
	assert.Equal(t, 5, availNow)
}

func TestPostMessageOutcomeTakenOnWanted(t *testing.T) {
	prefix := uniquePrefix("msgw_tak_wnt")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)
	msgID := CreateTestMessage(t, userID, prefix+" wanted item", 52.5, -1.8)

	// Change type to Wanted.
	db.Exec("UPDATE messages SET type = 'Wanted' WHERE id = ?", msgID)

	body := map[string]interface{}{
		"id":      msgID,
		"action":  "Outcome",
		"outcome": "Taken",
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", token)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 400, resp.StatusCode, "Taken outcome should be rejected on Wanted message")
}

func TestPostMessageOutcomeReceivedOnOffer(t *testing.T) {
	prefix := uniquePrefix("msgw_rcv_ofr")

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)
	msgID := CreateTestMessage(t, userID, prefix+" offer item", 52.5, -1.8)

	// Message is already Offer type from CreateTestMessage.
	body := map[string]interface{}{
		"id":      msgID,
		"action":  "Outcome",
		"outcome": "Received",
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", token)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 400, resp.StatusCode, "Received outcome should be rejected on Offer message")
}

func TestPostMessageAddByNotYourMessage(t *testing.T) {
	prefix := uniquePrefix("msgw_addby_ny")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	otherID := CreateTestUser(t, prefix+"_other", "User")
	_, otherToken := CreateTestSession(t, otherID)
	msgID := CreateTestMessage(t, ownerID, prefix+" offer item", 52.5, -1.8)

	db.Exec("UPDATE messages SET availableinitially = 5, availablenow = 5 WHERE id = ?", msgID)

	body := map[string]interface{}{
		"id":     msgID,
		"action": "AddBy",
		"userid": otherID,
		"count":  1,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", otherToken)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 403, resp.StatusCode, "Non-owner should not be able to AddBy")
}

func TestPostMessageRemoveByNotYourMessage(t *testing.T) {
	prefix := uniquePrefix("msgw_rmby_ny")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	otherID := CreateTestUser(t, prefix+"_other", "User")
	_, otherToken := CreateTestSession(t, otherID)
	msgID := CreateTestMessage(t, ownerID, prefix+" offer item", 52.5, -1.8)

	db.Exec("UPDATE messages SET availableinitially = 5, availablenow = 3 WHERE id = ?", msgID)
	db.Exec("INSERT INTO messages_by (userid, msgid, count) VALUES (?, ?, 2)", otherID, msgID)

	body := map[string]interface{}{
		"id":     msgID,
		"action": "RemoveBy",
		"userid": otherID,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", otherToken)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 403, resp.StatusCode, "Non-owner should not be able to RemoveBy")
}

func TestPostMessagePromiseCreatesChat(t *testing.T) {
	// H1: Promise should create a chat room if none exists between the users.
	prefix := uniquePrefix("msgw_prm_cc")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	_, ownerToken := CreateTestSession(t, ownerID)
	otherID := CreateTestUser(t, prefix+"_other", "User")
	msgID := CreateTestMessage(t, ownerID, prefix+" offer item", 52.5, -1.8)

	// Verify no chat room exists between these users.
	var chatCount int64
	db.Raw("SELECT COUNT(*) FROM chat_rooms WHERE (user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?)",
		ownerID, otherID, otherID, ownerID).Scan(&chatCount)
	assert.Equal(t, int64(0), chatCount)

	// Promise the item - should create a chat room.
	body := map[string]interface{}{
		"id":     msgID,
		"action": "Promise",
		"userid": otherID,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", ownerToken)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify chat room was created.
	db.Raw("SELECT COUNT(*) FROM chat_rooms WHERE (user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?)",
		ownerID, otherID, otherID, ownerID).Scan(&chatCount)
	assert.Equal(t, int64(1), chatCount)

	// Verify chat message was created.
	var chatMsgCount int64
	db.Raw("SELECT COUNT(*) FROM chat_messages WHERE refmsgid = ? AND type = 'Promised'", msgID).Scan(&chatMsgCount)
	assert.Equal(t, int64(1), chatMsgCount)
}

func TestPostMessageOutcomeTakenWithUserRecordsBy(t *testing.T) {
	// H3: Outcome Taken/Received with userid should insert into messages_by.
	prefix := uniquePrefix("msgw_out_by")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)
	takerID := CreateTestUser(t, prefix+"_taker", "User")
	msgID := CreateTestMessage(t, userID, prefix+" offer item", 52.5, -1.8)

	// Set availability.
	db.Exec("UPDATE messages SET availableinitially = 3, availablenow = 3 WHERE id = ?", msgID)

	body := map[string]interface{}{
		"id":      msgID,
		"action":  "Outcome",
		"outcome": "Taken",
		"userid":  takerID,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", token)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify messages_by entry created with availablenow count.
	var byCount int
	db.Raw("SELECT count FROM messages_by WHERE msgid = ? AND userid = ?", msgID, takerID).Scan(&byCount)
	assert.Equal(t, 3, byCount, "messages_by should record availablenow count for the taker")
}

func TestPostMessageOutcomeMarksSpatialSuccessful(t *testing.T) {
	// When a message is marked Taken or Received, messages_spatial.successful
	// must be set to 1 so that:
	// - isochrone queries exclude it (they filter on successful = 0)
	// - dashboard heatmap includes it (it filters on successful = 1)
	// This is V1 parity with markSuccessfulInSpatial().
	prefix := uniquePrefix("msgw_out_sp")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)
	msgID := CreateTestMessage(t, userID, prefix+" offer item", 52.5, -1.8)

	// CreateTestMessage sets successful=1 for convenience; reset to 0 to
	// simulate a real message that hasn't had an outcome yet.
	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid = ?", msgID)

	// Verify it really is 0 before the outcome.
	var beforeSuccessful int
	db.Raw("SELECT successful FROM messages_spatial WHERE msgid = ?", msgID).Scan(&beforeSuccessful)
	assert.Equal(t, 0, beforeSuccessful, "spatial successful should be 0 before outcome")

	body := map[string]interface{}{
		"id":      msgID,
		"action":  "Outcome",
		"outcome": "Taken",
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", token)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify messages_spatial.successful was set to 1.
	var afterSuccessful int
	db.Raw("SELECT successful FROM messages_spatial WHERE msgid = ?", msgID).Scan(&afterSuccessful)
	assert.Equal(t, 1, afterSuccessful, "spatial successful should be 1 after Taken outcome")
}

func TestPostMessageOutcomeWithdrawnDoesNotMarkSpatialSuccessful(t *testing.T) {
	// Withdrawn should NOT set successful=1 — only Taken/Received are "successful".
	prefix := uniquePrefix("msgw_out_sp_w")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)
	msgID := CreateTestMessage(t, userID, prefix+" offer item", 52.5, -1.8)

	// Reset spatial successful to 0.
	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid = ?", msgID)

	body := map[string]interface{}{
		"id":      msgID,
		"action":  "Outcome",
		"outcome": "Withdrawn",
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", token)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify messages_spatial.successful is still 0.
	var afterSuccessful int
	db.Raw("SELECT successful FROM messages_spatial WHERE msgid = ?", msgID).Scan(&afterSuccessful)
	assert.Equal(t, 0, afterSuccessful, "spatial successful should remain 0 after Withdrawn outcome")
}

func TestPostMessageWithdrawnApproved(t *testing.T) {
	// Withdrawn on an approved message should record the outcome normally (not delete).
	prefix := uniquePrefix("msgw_wdr_app")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)
	msgID := CreateTestMessage(t, userID, prefix+" offer item", 52.5, -1.8)

	// Message is already Approved by default from CreateTestMessage.
	body := map[string]interface{}{
		"id":      msgID,
		"action":  "Outcome",
		"outcome": "Withdrawn",
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", token)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify outcome was recorded (not deleted).
	var dbOutcome string
	db.Raw("SELECT outcome FROM messages_outcomes WHERE msgid = ?", msgID).Scan(&dbOutcome)
	assert.Equal(t, "Withdrawn", dbOutcome)

	// Verify message still exists.
	var msgCount int64
	db.Raw("SELECT COUNT(*) FROM messages WHERE id = ?", msgID).Scan(&msgCount)
	assert.Equal(t, int64(1), msgCount, "Approved message should NOT be deleted")
}

func TestPostMessageOutcomeQueuesFreebieAlertsRemove(t *testing.T) {
	// When a message gets a Taken outcome, a freebie_alerts_remove background task should be queued.
	prefix := uniquePrefix("msgw_fa_rem")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)
	msgID := CreateTestMessage(t, userID, prefix+" offer item", 52.5, -1.8)

	// Clean any pre-existing tasks for this message.
	db.Exec("DELETE FROM background_tasks WHERE task_type = 'freebie_alerts_remove' AND JSON_EXTRACT(data, '$.msgid') = ?", msgID)

	body := map[string]interface{}{
		"id":      msgID,
		"action":  "Outcome",
		"outcome": "Taken",
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", token)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify freebie_alerts_remove task was queued.
	var taskCount int64
	db.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type = 'freebie_alerts_remove' AND JSON_EXTRACT(data, '$.msgid') = ?", msgID).Scan(&taskCount)
	assert.Equal(t, int64(1), taskCount, "freebie_alerts_remove task should be queued on Taken outcome")
}

func TestPostMessageOutcomeWithdrawnQueuesFreebieAlertsRemove(t *testing.T) {
	// Withdrawn outcomes should also queue freebie_alerts_remove.
	prefix := uniquePrefix("msgw_fa_rem_w")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)
	msgID := CreateTestMessage(t, userID, prefix+" offer item", 52.5, -1.8)

	db.Exec("DELETE FROM background_tasks WHERE task_type = 'freebie_alerts_remove' AND JSON_EXTRACT(data, '$.msgid') = ?", msgID)

	body := map[string]interface{}{
		"id":      msgID,
		"action":  "Outcome",
		"outcome": "Withdrawn",
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", token)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var taskCount int64
	db.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type = 'freebie_alerts_remove' AND JSON_EXTRACT(data, '$.msgid') = ?", msgID).Scan(&taskCount)
	assert.Equal(t, int64(1), taskCount, "freebie_alerts_remove task should be queued on Withdrawn outcome")
}

func TestDeleteMessageQueuesFreebieAlertsRemove(t *testing.T) {
	// User-deleting a message should queue freebie_alerts_remove.
	prefix := uniquePrefix("msgw_fa_del")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)
	msgID := CreateTestMessage(t, userID, prefix+" offer item", 52.5, -1.8)

	db.Exec("DELETE FROM background_tasks WHERE task_type = 'freebie_alerts_remove' AND JSON_EXTRACT(data, '$.msgid') = ?", msgID)

	url := fmt.Sprintf("/api/message/%d?jwt=%s", msgID, token)
	req := httptest.NewRequest("DELETE", url, nil)
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var taskCount int64
	db.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type = 'freebie_alerts_remove' AND JSON_EXTRACT(data, '$.msgid') = ?", msgID).Scan(&taskCount)
	assert.Equal(t, int64(1), taskCount, "freebie_alerts_remove task should be queued when message is deleted")
}

func TestPostMessageView(t *testing.T) {
	prefix := uniquePrefix("msgw_view")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)
	msgID := CreateTestMessage(t, userID, prefix+" offer item", 52.5, -1.8)

	body := map[string]interface{}{
		"id":     msgID,
		"action": "View",
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", token)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify view recorded.
	var viewCount int64
	db.Raw("SELECT COUNT(*) FROM messages_likes WHERE msgid = ? AND userid = ? AND type = 'View'", msgID, userID).Scan(&viewCount)
	assert.Equal(t, int64(1), viewCount)
}

func TestPostMessageViewDedup(t *testing.T) {
	prefix := uniquePrefix("msgw_view_dup")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)
	msgID := CreateTestMessage(t, userID, prefix+" offer item", 52.5, -1.8)

	// Insert a recent view.
	db.Exec("INSERT INTO messages_likes (msgid, userid, type) VALUES (?, ?, 'View')", msgID, userID)

	// View again - should be de-duplicated (count stays at 1).
	body := map[string]interface{}{
		"id":     msgID,
		"action": "View",
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", token)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Should still be just 1 view (de-duplicated within 30 min).
	var viewCount int
	db.Raw("SELECT count FROM messages_likes WHERE msgid = ? AND userid = ? AND type = 'View'", msgID, userID).Scan(&viewCount)
	assert.Equal(t, 1, viewCount)
}

// TestMessagePageviewSemantics verifies the pageview flag distinguishes a genuine
// page-open (handleView => 1) from a list-scroll impression (MarkSeen => 0), that a
// scroll-then-open is upgraded to 1, and that an open-then-scroll is never downgraded.
func TestMessagePageviewSemantics(t *testing.T) {
	db := database.DBConn

	// Returns (exists, pageview) where pageview is -1 for NULL (legacy/unknown).
	getPageview := func(msgID, userID uint64) (bool, int) {
		var cnt int
		db.Raw("SELECT COUNT(*) FROM messages_likes WHERE msgid = ? AND userid = ? AND type = 'View'", msgID, userID).Scan(&cnt)
		if cnt == 0 {
			return false, -1
		}
		var pv int
		db.Raw("SELECT COALESCE(pageview, -1) FROM messages_likes WHERE msgid = ? AND userid = ? AND type = 'View'", msgID, userID).Scan(&pv)
		return true, pv
	}
	doView := func(token string, msgID uint64) {
		body, _ := json.Marshal(map[string]interface{}{"id": msgID, "action": "View"})
		req := httptest.NewRequest("POST", "/api/message?jwt="+token, bytes.NewBuffer(body))
		req.Header.Set("Content-Type", "application/json")
		resp, err := getApp().Test(req)
		assert.NoError(t, err)
		assert.Equal(t, 200, resp.StatusCode)
	}
	doMarkSeen := func(token string, msgID uint64) {
		req := httptest.NewRequest("POST", "/api/messages/markseen?jwt="+token, bytes.NewBufferString(fmt.Sprintf(`{"ids": [%d]}`, msgID)))
		req.Header.Set("Content-Type", "application/json")
		resp, err := getApp().Test(req)
		assert.NoError(t, err)
		assert.Equal(t, 200, resp.StatusCode)
	}
	// Distinct viewer (not the owner) so MarkSeen records against a real recipient.
	setup := func(label string) (msgID uint64, token string, viewerID uint64) {
		prefix := uniquePrefix(label)
		ownerID := CreateTestUser(t, prefix+"_owner", "User")
		viewerID = CreateTestUser(t, prefix+"_viewer", "User")
		_, token = CreateTestSession(t, viewerID)
		msgID = CreateTestMessage(t, ownerID, prefix+" item", 55.9533, -3.1883)
		return
	}

	// genuine page-open => pageview = 1
	msgID, token, viewerID := setup("pv_open")
	doView(token, msgID)
	exists, pv := getPageview(msgID, viewerID)
	assert.True(t, exists, "page-open should create a View row")
	assert.Equal(t, 1, pv, "page-open => pageview=1")

	// list-scroll impression => pageview = 0
	msgID, token, viewerID = setup("pv_seen")
	doMarkSeen(token, msgID)
	exists, pv = getPageview(msgID, viewerID)
	assert.True(t, exists, "impression should create a View row")
	assert.Equal(t, 0, pv, "scroll impression => pageview=0")

	// scroll THEN open => upgraded to 1
	msgID, token, viewerID = setup("pv_seen_open")
	doMarkSeen(token, msgID)
	doView(token, msgID)
	_, pv = getPageview(msgID, viewerID)
	assert.Equal(t, 1, pv, "open after scroll => upgraded to pageview=1")

	// open THEN scroll => stays 1 (never downgraded)
	msgID, token, viewerID = setup("pv_open_seen")
	doView(token, msgID)
	doMarkSeen(token, msgID)
	_, pv = getPageview(msgID, viewerID)
	assert.Equal(t, 1, pv, "scroll after open must not downgrade pageview")
}

// TestMessagePageviewSource verifies a notification-tagged page-open records its source
// (e.g. ripple_notify), and that a later untagged (organic) open never clears that
// attribution - the key property for distinguishing notification-click from organic browse.
func TestMessagePageviewSource(t *testing.T) {
	db := database.DBConn

	getSource := func(msgID, userID uint64) string {
		var s string
		db.Raw("SELECT COALESCE(source, '') FROM messages_likes WHERE msgid = ? AND userid = ? AND type = 'View'", msgID, userID).Scan(&s)
		return s
	}
	doView := func(token string, msgID uint64, source string) {
		body := map[string]interface{}{"id": msgID, "action": "View"}
		if source != "" {
			body["source"] = source
		}
		b, _ := json.Marshal(body)
		req := httptest.NewRequest("POST", "/api/message?jwt="+token, bytes.NewBuffer(b))
		req.Header.Set("Content-Type", "application/json")
		resp, err := getApp().Test(req)
		assert.NoError(t, err)
		assert.Equal(t, 200, resp.StatusCode)
	}

	// notification-tagged open records the source; a later organic open does not clear it
	{
		prefix := uniquePrefix("pv_src_notify")
		userID := CreateTestUser(t, prefix+"_user", "User")
		_, token := CreateTestSession(t, userID)
		msgID := CreateTestMessage(t, userID, prefix+" item", 52.5, -1.8)

		doView(token, msgID, "ripple_notify")
		assert.Equal(t, "ripple_notify", getSource(msgID, userID), "tagged open records source")
		doView(token, msgID, "") // organic, within dedup window -> UPDATE path
		assert.Equal(t, "ripple_notify", getSource(msgID, userID), "organic open must not clear notification source")
	}

	// organic-only open leaves source unset (NULL)
	{
		prefix := uniquePrefix("pv_src_organic")
		userID := CreateTestUser(t, prefix+"_user", "User")
		_, token := CreateTestSession(t, userID)
		msgID := CreateTestMessage(t, userID, prefix+" item", 52.5, -1.8)

		doView(token, msgID, "")
		assert.Equal(t, "", getSource(msgID, userID), "organic open leaves source NULL")
	}
}

// TestMarkSeenSource verifies a source-tagged impression (MarkSeen with a source,
// e.g. from the similar-posts widget) records pageview=0 + the source on the
// fresh row, that a later genuine open upgrades pageview to 1 while PRESERVING
// the source, and that a subsequent differently-tagged impression does not
// overwrite the first-touch source.
func TestMarkSeenSource(t *testing.T) {
	db := database.DBConn

	get := func(msgID, userID uint64) (pv int, source string) {
		db.Raw("SELECT COALESCE(pageview, -1), COALESCE(source, '') FROM messages_likes "+
			"WHERE msgid = ? AND userid = ? AND type = 'View'", msgID, userID).Row().Scan(&pv, &source)
		return
	}
	doMarkSeen := func(token string, msgID uint64, source string) {
		var body string
		if source != "" {
			body = fmt.Sprintf(`{"ids": [%d], "source": "%s"}`, msgID, source)
		} else {
			body = fmt.Sprintf(`{"ids": [%d]}`, msgID)
		}
		req := httptest.NewRequest("POST", "/api/messages/markseen?jwt="+token, bytes.NewBufferString(body))
		req.Header.Set("Content-Type", "application/json")
		resp, err := getApp().Test(req)
		assert.NoError(t, err)
		assert.Equal(t, 200, resp.StatusCode)
	}
	doView := func(token string, msgID uint64) {
		b, _ := json.Marshal(map[string]interface{}{"id": msgID, "action": "View"})
		req := httptest.NewRequest("POST", "/api/message?jwt="+token, bytes.NewBuffer(b))
		req.Header.Set("Content-Type", "application/json")
		resp, err := getApp().Test(req)
		assert.NoError(t, err)
		assert.Equal(t, 200, resp.StatusCode)
	}

	prefix := uniquePrefix("markseen_src")
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	viewerID := CreateTestUser(t, prefix+"_viewer", "User")
	_, token := CreateTestSession(t, viewerID)
	msgID := CreateTestMessage(t, ownerID, prefix+" item", 55.9533, -3.1883)

	// Source-tagged impression: pageview=0 + source recorded.
	doMarkSeen(token, msgID, "similar_posts")
	pv, src := get(msgID, viewerID)
	assert.Equal(t, 0, pv, "impression => pageview=0")
	assert.Equal(t, "similar_posts", src, "impression records its source")

	// Genuine open (untagged): pageview upgraded to 1, source preserved.
	doView(token, msgID)
	pv, src = get(msgID, viewerID)
	assert.Equal(t, 1, pv, "open after impression => pageview upgraded to 1")
	assert.Equal(t, "similar_posts", src, "genuine open must not clear the impression source")

	// A later differently-tagged impression must not overwrite first-touch source.
	doMarkSeen(token, msgID, "wanted_match")
	_, src = get(msgID, viewerID)
	assert.Equal(t, "similar_posts", src, "first-touch source wins; later impressions do not overwrite")
}

// --- Adversarial tests ---

func TestPostMessageAddByNegativeCount(t *testing.T) {
	// Negative count should not corrupt availablenow.
	prefix := uniquePrefix("msgw_addby_neg")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	_, ownerToken := CreateTestSession(t, ownerID)
	takerID := CreateTestUser(t, prefix+"_taker", "User")
	msgID := CreateTestMessage(t, ownerID, prefix+" offer item", 52.5, -1.8)

	db.Exec("UPDATE messages SET availableinitially = 5, availablenow = 5 WHERE id = ?", msgID)

	body := map[string]interface{}{
		"id":     msgID,
		"action": "AddBy",
		"userid": takerID,
		"count":  -3,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", ownerToken)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// availablenow should not exceed availableinitially (LEAST guard protects).
	var availNow int
	db.Raw("SELECT availablenow FROM messages WHERE id = ?", msgID).Scan(&availNow)
	assert.LessOrEqual(t, availNow, 5, "availablenow should not exceed availableinitially")
}

func TestPostMessageAddByHugeCount(t *testing.T) {
	// Very large count should not make availablenow negative (GREATEST guard protects).
	prefix := uniquePrefix("msgw_addby_huge")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	_, ownerToken := CreateTestSession(t, ownerID)
	takerID := CreateTestUser(t, prefix+"_taker", "User")
	msgID := CreateTestMessage(t, ownerID, prefix+" offer item", 52.5, -1.8)

	db.Exec("UPDATE messages SET availableinitially = 2, availablenow = 2 WHERE id = ?", msgID)

	body := map[string]interface{}{
		"id":     msgID,
		"action": "AddBy",
		"userid": takerID,
		"count":  99999,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", ownerToken)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// availablenow should be 0, not negative (GREATEST(0) guard).
	var availNow int
	db.Raw("SELECT availablenow FROM messages WHERE id = ?", msgID).Scan(&availNow)
	assert.GreaterOrEqual(t, availNow, 0, "availablenow should never go negative")
}

func TestPostMessageAddBySomeoneElse(t *testing.T) {
	// AddBy with no userid means "someone else" (not a known Freegle user).
	prefix := uniquePrefix("msgw_addby_else")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	_, ownerToken := CreateTestSession(t, ownerID)
	msgID := CreateTestMessage(t, ownerID, prefix+" offer item", 52.5, -1.8)

	db.Exec("UPDATE messages SET availableinitially = 3, availablenow = 3 WHERE id = ?", msgID)

	// AddBy with no userid — represents "someone else".
	body := map[string]interface{}{
		"id":     msgID,
		"action": "AddBy",
		"count":  1,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", ownerToken)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify messages_by entry with userid=NULL.
	var byCount int
	db.Raw("SELECT count FROM messages_by WHERE msgid = ? AND userid IS NULL", msgID).Scan(&byCount)
	assert.Equal(t, 1, byCount)

	// Verify available count reduced.
	var availNow int
	db.Raw("SELECT availablenow FROM messages WHERE id = ?", msgID).Scan(&availNow)
	assert.Equal(t, 2, availNow)
}

func TestPostMessagePromiseToSelfNoUserid(t *testing.T) {
	// Promise without userid should promise to self (no chat message).
	prefix := uniquePrefix("msgw_prm_self")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	_, ownerToken := CreateTestSession(t, ownerID)
	msgID := CreateTestMessage(t, ownerID, prefix+" offer item", 52.5, -1.8)

	body := map[string]interface{}{
		"id":     msgID,
		"action": "Promise",
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", ownerToken)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Promise should be recorded with self as userid.
	var count int64
	db.Raw("SELECT COUNT(*) FROM messages_promises WHERE msgid = ? AND userid = ?", msgID, ownerID).Scan(&count)
	assert.Equal(t, int64(1), count)

	// No chat message should be created (promising to self).
	var chatMsgCount int64
	db.Raw("SELECT COUNT(*) FROM chat_messages WHERE refmsgid = ? AND type = 'Promised'", msgID).Scan(&chatMsgCount)
	assert.Equal(t, int64(0), chatMsgCount)
}

func TestPostMessageDoublePromise(t *testing.T) {
	// Double Promise should be idempotent (REPLACE INTO).
	prefix := uniquePrefix("msgw_prm_dbl")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	_, ownerToken := CreateTestSession(t, ownerID)
	otherID := CreateTestUser(t, prefix+"_other", "User")
	msgID := CreateTestMessage(t, ownerID, prefix+" offer item", 52.5, -1.8)

	body := map[string]interface{}{
		"id":     msgID,
		"action": "Promise",
		"userid": otherID,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", ownerToken)

	// First promise.
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Second promise (same user, same message) - should not error.
	bodyBytes, _ = json.Marshal(body)
	req = httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err = getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Still only one promise record (REPLACE INTO is idempotent).
	var count int64
	db.Raw("SELECT COUNT(*) FROM messages_promises WHERE msgid = ? AND userid = ?", msgID, otherID).Scan(&count)
	assert.Equal(t, int64(1), count)
}

func TestPostMessageRenegeWithoutPromise(t *testing.T) {
	// Renege when no promise exists should succeed without error.
	prefix := uniquePrefix("msgw_rng_nop")

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	_, ownerToken := CreateTestSession(t, ownerID)
	otherID := CreateTestUser(t, prefix+"_other", "User")
	msgID := CreateTestMessage(t, ownerID, prefix+" offer item", 52.5, -1.8)

	body := map[string]interface{}{
		"id":     msgID,
		"action": "Renege",
		"userid": otherID,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", ownerToken)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode, "Renege without existing promise should succeed gracefully")
}

func TestPostMessageOutcomeNoHappiness(t *testing.T) {
	// Outcome without happiness should succeed (happiness is optional).
	prefix := uniquePrefix("msgw_out_noh")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)
	msgID := CreateTestMessage(t, userID, prefix+" offer item", 52.5, -1.8)

	body := map[string]interface{}{
		"id":      msgID,
		"action":  "Outcome",
		"outcome": "Taken",
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", token)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify outcome recorded without happiness.
	var dbOutcome string
	db.Raw("SELECT outcome FROM messages_outcomes WHERE msgid = ?", msgID).Scan(&dbOutcome)
	assert.Equal(t, "Taken", dbOutcome)
}

func TestPostMessageOutcomeHappyNoComment(t *testing.T) {
	// Rating without comment should store NULL comments, not empty string.
	// This ensures the feedback badge only counts outcomes with real comments.
	prefix := uniquePrefix("msgw_out_nocomm")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)
	msgID := CreateTestMessage(t, userID, prefix+" offer item", 52.5, -1.8)

	body := map[string]interface{}{
		"id":        msgID,
		"action":    "Outcome",
		"outcome":   "Taken",
		"happiness": "Happy",
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", token)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// comments should be NULL, not empty string.
	var dbComments *string
	db.Raw("SELECT comments FROM messages_outcomes WHERE msgid = ?", msgID).Scan(&dbComments)
	assert.Nil(t, dbComments, "comments should be NULL when no comment provided")
}

func TestPostMessageEmptyBody(t *testing.T) {
	prefix := uniquePrefix("msgw_empty")
	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)

	// Empty JSON body - should return 400 (missing id).
	url := fmt.Sprintf("/api/message?jwt=%s", token)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer([]byte("{}")))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestPostMessageInvalidJSON(t *testing.T) {
	prefix := uniquePrefix("msgw_badjson")
	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)

	url := fmt.Sprintf("/api/message?jwt=%s", token)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer([]byte("not json")))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 400, resp.StatusCode)
}

// =============================================================================
// Message List Tests (GET /messages)
// =============================================================================

func TestListMessagesNoGroupID(t *testing.T) {
	resp, err := getApp().Test(httptest.NewRequest("GET",
		"/api/messages?collection=Approved", nil))
	assert.NoError(t, err)
	// No groupid returns empty list (graceful degradation).
	assert.Equal(t, 200, resp.StatusCode)
}

func TestGetMessageWithoutHistory(t *testing.T) {
	// Verify that regular GET /message/:id still works without messagehistory param.
	prefix := uniquePrefix("msgnohist")

	posterID := CreateTestUser(t, prefix+"_poster", "User")

	msgID := CreateTestMessage(t, posterID, prefix+" Normal Message", 55.9533, -3.1883)

	resp, err := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/message/%d", msgID), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result message.Message
	json.NewDecoder(resp.Body).Decode(&result)
	assert.Equal(t, msgID, result.ID)
}

func TestGetMultipleMessagesStillWorks(t *testing.T) {
	// Verify that GET /message/id1,id2 still works with the new handler.
	prefix := uniquePrefix("msgmulti")

	posterID := CreateTestUser(t, prefix+"_poster", "User")

	mid1 := CreateTestMessage(t, posterID, prefix+" Multi 1", 55.9533, -3.1883)
	mid2 := CreateTestMessage(t, posterID, prefix+" Multi 2", 55.9533, -3.1883)

	resp, err := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/message/%d,%d", mid1, mid2), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var messages []message.Message
	json.NewDecoder(resp.Body).Decode(&messages)
	assert.Equal(t, 2, len(messages))
}

// =============================================================================
// Helper functions
// =============================================================================

func containsSubstring(s, substr string) bool {
	return strings.Contains(strings.ToLower(s), strings.ToLower(substr))
}

func TestMessagesMarkSeen(t *testing.T) {
	prefix := uniquePrefix("markseen")

	// Create message owner and viewer
	ownerID := CreateTestUser(t, prefix+"_owner", "User")

	viewerID := CreateTestUser(t, prefix+"_viewer", "User")
	_, viewerToken := CreateTestSession(t, viewerID)

	// Create messages
	msgID1 := CreateTestMessage(t, ownerID, "Test Item 1", 55.9533, -3.1883)
	msgID2 := CreateTestMessage(t, ownerID, "Test Item 2", 55.9533, -3.1883)

	// Mark both messages as seen via POST
	body := fmt.Sprintf(`{"ids": [%d, %d]}`, msgID1, msgID2)
	req := httptest.NewRequest("POST", "/api/messages/markseen?jwt="+viewerToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, true, result["success"])

	// Verify messages are now marked as seen by checking the user message endpoint
	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/user/"+fmt.Sprint(ownerID)+"/message?jwt="+viewerToken, nil))
	assert.Equal(t, 200, resp.StatusCode)

	type MessageWithUnseen struct {
		ID     uint64 `json:"id"`
		Unseen bool   `json:"unseen"`
	}

	var msgs []MessageWithUnseen
	json2.Unmarshal(rsp(resp), &msgs)

	for _, m := range msgs {
		if m.ID == msgID1 || m.ID == msgID2 {
			assert.False(t, m.Unseen, "Message %d should be seen after MarkSeen", m.ID)
		}
	}
}

func TestMessagesMarkSeenUnauthorized(t *testing.T) {
	// Test without token - should fail
	body := `{"ids": [1]}`
	req := httptest.NewRequest("POST", "/api/messages/markseen", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)
}

func TestMessagesMarkSeenEmptyIds(t *testing.T) {
	prefix := uniquePrefix("markseen_empty")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	// Test with empty IDs array
	body := `{"ids": []}`
	req := httptest.NewRequest("POST", "/api/messages/markseen?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestMessagesMarkSeenInvalidBody(t *testing.T) {
	prefix := uniquePrefix("markseen_invalid")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	// Test with missing IDs field
	body := `{}`
	req := httptest.NewRequest("POST", "/api/messages/markseen?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestMessagesMarkSeenInvalidJSON(t *testing.T) {
	prefix := uniquePrefix("markseen_json")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	req := httptest.NewRequest("POST", "/api/messages/markseen?jwt="+token, bytes.NewBufferString("not json"))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestMessagesMarkSeenNonExistentIDs(t *testing.T) {
	// Marking non-existent message IDs should succeed (inserts orphaned View records
	// but doesn't crash). This matches PHP behaviour.
	prefix := uniquePrefix("markseen_ghost")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	body := `{"ids": [999999998, 999999999]}`
	req := httptest.NewRequest("POST", "/api/messages/markseen?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
}

func TestMessagesMarkSeenIdempotent(t *testing.T) {
	// Marking the same message as seen twice should succeed (ON DUPLICATE KEY UPDATE)
	prefix := uniquePrefix("markseen_idem")

	ownerID := CreateTestUser(t, prefix+"_owner", "User")

	viewerID := CreateTestUser(t, prefix+"_viewer", "User")
	_, viewerToken := CreateTestSession(t, viewerID)

	msgID := CreateTestMessage(t, ownerID, "Test Idempotent", 55.9533, -3.1883)

	body := fmt.Sprintf(`{"ids": [%d]}`, msgID)

	// First mark
	req := httptest.NewRequest("POST", "/api/messages/markseen?jwt="+viewerToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	// Second mark (should also succeed)
	req = httptest.NewRequest("POST", "/api/messages/markseen?jwt="+viewerToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ = getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
}

// TestMessagesMarkSeenBatchPreservesSemantics marks MANY messages in a single call (the batched
// multi-row INSERT path that replaced the per-id loop) and verifies the per-message semantics are
// unchanged: every message ends up with a View row (seen), a brand-new one gets pageview=0 (a
// scroll impression), and a message that already has a genuine page-open (pageview=1) keeps
// pageview=1 and has its count incremented - i.e. batching never downgrades an existing view.
func TestMessagesMarkSeenBatchPreservesSemantics(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("markseen_batch")

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	viewerID := CreateTestUser(t, prefix+"_viewer", "User")
	_, viewerToken := CreateTestSession(t, viewerID)

	// A batch of messages (enough to exercise the multi-row INSERT).
	const n = 12
	ids := make([]uint64, n)
	for i := 0; i < n; i++ {
		ids[i] = CreateTestMessage(t, ownerID, fmt.Sprintf("%s item %d", prefix, i), 55.9533, -3.1883)
	}

	// Give the FIRST message a genuine page-open first (pageview=1), so we can prove the batch
	// mark-seen does not downgrade it.
	openBody, _ := json.Marshal(map[string]interface{}{"id": ids[0], "action": "View"})
	openReq := httptest.NewRequest("POST", "/api/message?jwt="+viewerToken, bytes.NewBuffer(openBody))
	openReq.Header.Set("Content-Type", "application/json")
	openResp, _ := getApp().Test(openReq)
	assert.Equal(t, 200, openResp.StatusCode)

	var pvBefore, countBefore int
	db.Raw("SELECT COALESCE(pageview, -1), count FROM messages_likes WHERE msgid = ? AND userid = ? AND type = 'View'",
		ids[0], viewerID).Row().Scan(&pvBefore, &countBefore)
	assert.Equal(t, 1, pvBefore, "page-open should set pageview=1 before the batch mark-seen")

	// Mark ALL of them seen in one batched call.
	idParts := make([]string, n)
	for i, id := range ids {
		idParts[i] = fmt.Sprint(id)
	}
	body := fmt.Sprintf(`{"ids": [%s]}`, strings.Join(idParts, ","))
	req := httptest.NewRequest("POST", "/api/messages/markseen?jwt="+viewerToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	// Every message now has a View row.
	var seenCount int
	placeholders := make([]string, n)
	args := make([]interface{}, 0, n+2)
	for i, id := range ids {
		placeholders[i] = "?"
		args = append(args, id)
	}
	args = append(args, viewerID)
	db.Raw("SELECT COUNT(*) FROM messages_likes WHERE msgid IN ("+strings.Join(placeholders, ",")+
		") AND userid = ? AND type = 'View'", args...).Scan(&seenCount)
	assert.Equal(t, n, seenCount, "every message in the batch has a View row")

	// A brand-new message (ids[1]) got pageview=0 (scroll impression).
	var pvNew int
	db.Raw("SELECT COALESCE(pageview, -1) FROM messages_likes WHERE msgid = ? AND userid = ? AND type = 'View'",
		ids[1], viewerID).Scan(&pvNew)
	assert.Equal(t, 0, pvNew, "a newly-seen message gets pageview=0")

	// The pre-opened message (ids[0]) keeps pageview=1 (NOT downgraded) and its count incremented.
	var pvAfter, countAfter int
	db.Raw("SELECT COALESCE(pageview, -1), count FROM messages_likes WHERE msgid = ? AND userid = ? AND type = 'View'",
		ids[0], viewerID).Row().Scan(&pvAfter, &countAfter)
	assert.Equal(t, 1, pvAfter, "batch mark-seen must not downgrade an existing genuine view")
	assert.Greater(t, countAfter, countBefore, "the ON DUPLICATE KEY UPDATE increments count on the existing row")
}

// --- Tests: Subject reconstruction from item + location ---

func TestPatchMessageReconstructsSubjectFromItemLocation(t *testing.T) {
	// Bug #209: When a mod edits item/location in ModTools, the Go PATCH handler
	// should reconstruct the subject using area + vague postcode.
	prefix := uniquePrefix("msgmod_subj_recon")
	db := database.DBConn

	modID := CreateTestUser(t, prefix+"_mod", "User")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	PromoteTestUserToModerator(t, modID)
	_, modToken := CreateTestSession(t, modID)

	// Create a message.
	msgID := CreateTestMessage(t, posterID, "OFFER: Old item (Old Location)", 52.5, -1.8)

	// Create an area location.
	db.Exec("INSERT INTO locations (name, type, lat, lng) VALUES (?, 'Point', 52.5, -1.8)", prefix+"_Village")
	var areaID uint64
	db.Raw("SELECT id FROM locations WHERE name = ? ORDER BY id DESC LIMIT 1", prefix+"_Village").Scan(&areaID)
	require.NotZero(t, areaID)

	// Create a postcode location with areaid pointing to the area.
	db.Exec("INSERT INTO locations (name, type, lat, lng, areaid) VALUES (?, 'Postcode', 52.5, -1.8, ?)", prefix+"_CB22 3AA", areaID)
	var pcID uint64
	db.Raw("SELECT id FROM locations WHERE name = ? ORDER BY id DESC LIMIT 1", prefix+"_CB22 3AA").Scan(&pcID)
	require.NotZero(t, pcID)

	// Assign the postcode location to the message.
	db.Exec("UPDATE messages SET locationid = ? WHERE id = ?", pcID, msgID)

	// Create an item for the message.
	db.Exec("INSERT INTO items (name) VALUES (?)", prefix+"_Kitchen table")
	var itemID uint64
	db.Raw("SELECT id FROM items WHERE name = ? ORDER BY id DESC LIMIT 1", prefix+"_Kitchen table").Scan(&itemID)
	require.NotZero(t, itemID)
	db.Exec("DELETE FROM messages_items WHERE msgid = ?", msgID)
	db.Exec("INSERT INTO messages_items (msgid, itemid) VALUES (?, ?)", msgID, itemID)

	// PATCH with a new item and location name.
	body, _ := json.Marshal(map[string]interface{}{
		"id":       msgID,
		"item":     prefix + "_Dining table",
		"location": prefix + "_CB22 3AA",
	})
	req := httptest.NewRequest("PATCH", "/api/message?jwt="+modToken, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify subject was reconstructed with area + vague postcode.
	var subject string
	db.Raw("SELECT subject FROM messages WHERE id = ?", msgID).Scan(&subject)

	// Should be "OFFER: <item> (<area> <vague_pc>)" — area name + outward code only.
	assert.Contains(t, subject, prefix+"_Dining table")
	assert.Contains(t, subject, prefix+"_Village")
	assert.Contains(t, subject, prefix+"_CB22")
	// Should NOT contain the full postcode (inward code).
	assert.NotContains(t, subject, "3AA")
}

func TestPatchMessageItemCaseCorrection(t *testing.T) {
	// Bug: Discourse #9518 post #18 — "Correct Case" standard message lowercases the
	// subject visually but the Go API finds the existing item via case-insensitive MySQL
	// lookup and reconstructs the subject using the original capitalized DB name, so the
	// lowercase edit never saves.
	prefix := uniquePrefix("msgmod_case")
	db := database.DBConn

	modID := CreateTestUser(t, prefix+"_mod", "User")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	PromoteTestUserToModerator(t, modID)
	_, modToken := CreateTestSession(t, modID)

	// Create an area and postcode location.
	db.Exec("INSERT INTO locations (name, type, lat, lng) VALUES (?, 'Point', 52.5, -1.8)", prefix+"_Town")
	var areaID uint64
	db.Raw("SELECT id FROM locations WHERE name = ? ORDER BY id DESC LIMIT 1", prefix+"_Town").Scan(&areaID)
	require.NotZero(t, areaID)
	db.Exec("INSERT INTO locations (name, type, lat, lng, areaid) VALUES (?, 'Postcode', 52.5, -1.8, ?)", prefix+"_CB22 3AA", areaID)
	var pcID uint64
	db.Raw("SELECT id FROM locations WHERE name = ? ORDER BY id DESC LIMIT 1", prefix+"_CB22 3AA").Scan(&pcID)
	require.NotZero(t, pcID)

	// Create a message with the item in UPPERCASE.
	msgID := CreateTestMessage(t, posterID, "OFFER: KITCHEN TABLE (Location)", 52.5, -1.8)
	db.Exec("UPDATE messages SET locationid = ? WHERE id = ?", pcID, msgID)

	// Create item with UPPERCASE name.
	db.Exec("INSERT INTO items (name) VALUES (?)", prefix+"_KITCHEN TABLE")
	var itemID uint64
	db.Raw("SELECT id FROM items WHERE name = ? ORDER BY id DESC LIMIT 1", prefix+"_KITCHEN TABLE").Scan(&itemID)
	require.NotZero(t, itemID)
	db.Exec("DELETE FROM messages_items WHERE msgid = ?", msgID)
	db.Exec("INSERT INTO messages_items (msgid, itemid) VALUES (?, ?)", msgID, itemID)

	// PATCH with lowercase item name (simulating "Correct Case" action).
	body, _ := json.Marshal(map[string]interface{}{
		"id":   msgID,
		"item": prefix + "_kitchen table",
	})
	req := httptest.NewRequest("PATCH", "/api/message?jwt="+modToken, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// The subject should use the lowercase item name the moderator submitted.
	var subject string
	db.Raw("SELECT subject FROM messages WHERE id = ?", msgID).Scan(&subject)
	assert.Contains(t, subject, prefix+"_kitchen table", "subject should contain the submitted lowercase item name")
	assert.NotContains(t, subject, prefix+"_KITCHEN TABLE", "subject should NOT contain the old uppercase name")

	// The items table canonical name must NOT be changed — it is shared across all
	// messages using this item. Modifying it from a single message edit would cause
	// flip-flopping if different mods use different casings.
	var storedName string
	db.Raw("SELECT name FROM items WHERE id = ?", itemID).Scan(&storedName)
	assert.Equal(t, prefix+"_KITCHEN TABLE", storedName, "items canonical name should NOT be mutated by a message edit")
}

// --- Tests: RejectToDraft / BackToDraft ---

func TestRejectToDraftClearsExpiredDeadline(t *testing.T) {
	prefix := uniquePrefix("msg_r2d_dl")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)

	msgID := CreateTestMessage(t, userID, prefix+" item", 52.5, -1.8)

	// Set an expired deadline.
	db.Exec("UPDATE messages SET deadline = '2020-01-01' WHERE id = ?", msgID)

	// Call RejectToDraft.
	body, _ := json.Marshal(map[string]interface{}{
		"id":     msgID,
		"action": "RejectToDraft",
	})
	req := httptest.NewRequest("POST", "/api/message?jwt="+token, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify deadline was cleared.
	var deadline *string
	db.Raw("SELECT deadline FROM messages WHERE id = ?", msgID).Scan(&deadline)
	assert.Nil(t, deadline, "Expired deadline should be cleared")
}

func TestRejectToDraftKeepsFutureDeadline(t *testing.T) {
	prefix := uniquePrefix("msg_r2d_futuredl")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)

	msgID := CreateTestMessage(t, userID, prefix+" item", 52.5, -1.8)

	// Set a future deadline — should be preserved.
	futureDeadline := time.Now().AddDate(0, 0, 30).Format("2006-01-02")
	db.Exec("UPDATE messages SET deadline = ? WHERE id = ?", futureDeadline, msgID)

	body, _ := json.Marshal(map[string]interface{}{
		"id":     msgID,
		"action": "RejectToDraft",
	})
	req := httptest.NewRequest("POST", "/api/message?jwt="+token, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var deadline *string
	db.Raw("SELECT DATE_FORMAT(deadline, '%Y-%m-%d') FROM messages WHERE id = ?", msgID).Scan(&deadline)
	require.NotNil(t, deadline, "Future deadline should be preserved")
	assert.Equal(t, futureDeadline, *deadline, "Future deadline value should be unchanged")
}

func TestBackToDraftAlias(t *testing.T) {
	prefix := uniquePrefix("msg_b2d_alias")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)

	msgID := CreateTestMessage(t, userID, prefix+" item", 52.5, -1.8)

	// BackToDraft should work the same as RejectToDraft.
	body, _ := json.Marshal(map[string]interface{}{
		"id":     msgID,
		"action": "BackToDraft",
	})
	req := httptest.NewRequest("POST", "/api/message?jwt="+token, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)
	assert.Equal(t, float64(0), result["ret"])

	// Verify message is now a draft.
	var draftCount int64
	db.Raw("SELECT COUNT(*) FROM messages_drafts WHERE msgid = ?", msgID).Scan(&draftCount)
	assert.Equal(t, int64(1), draftCount, "Message should be in messages_drafts")
}

func TestRejectToDraftClearsOutcome(t *testing.T) {
	// When a message is moved back to draft for reposting, any existing outcome
	// (e.g. "Withdrawn") must be cleared so the reposted message starts fresh.
	prefix := uniquePrefix("r2d_outcome")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)

	msgID := CreateTestMessage(t, userID, prefix+" item", 52.5, -1.8)

	// Set a previous outcome.
	db.Exec("INSERT INTO messages_outcomes (msgid, outcome) VALUES (?, 'Withdrawn')", msgID)
	db.Exec("INSERT INTO messages_outcomes_intended (msgid, outcome) VALUES (?, 'Repost') ON DUPLICATE KEY UPDATE outcome = VALUES(outcome)", msgID)

	var outcomeCount int64
	db.Raw("SELECT COUNT(*) FROM messages_outcomes WHERE msgid = ?", msgID).Scan(&outcomeCount)
	require.Equal(t, int64(1), outcomeCount, "Outcome should exist before RejectToDraft")

	// Call RejectToDraft.
	body, _ := json.Marshal(map[string]interface{}{
		"id":     msgID,
		"action": "RejectToDraft",
	})
	req := httptest.NewRequest("POST", "/api/message?jwt="+token, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Outcome should be cleared.
	db.Raw("SELECT COUNT(*) FROM messages_outcomes WHERE msgid = ?", msgID).Scan(&outcomeCount)
	assert.Equal(t, int64(0), outcomeCount, "Outcome should be cleared after RejectToDraft")

	var intendedCount int64
	db.Raw("SELECT COUNT(*) FROM messages_outcomes_intended WHERE msgid = ?", msgID).Scan(&intendedCount)
	assert.Equal(t, int64(0), intendedCount, "Intended outcome should be cleared after RejectToDraft")
}

func TestRejectToDraftResetsAvailablenow(t *testing.T) {
	// When a message is moved back to draft, availablenow is reset to
	// availableinitially and messages_by is cleared.
	prefix := uniquePrefix("r2d_avail")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	otherID := CreateTestUser(t, prefix+"_other", "User")
	_, token := CreateTestSession(t, userID)

	msgID := CreateTestMessage(t, userID, prefix+" item", 52.5, -1.8)

	// Simulate the item being promised: set availablenow=0 and add a messages_by row.
	db.Exec("UPDATE messages SET availableinitially = 2, availablenow = 0 WHERE id = ?", msgID)
	db.Exec("INSERT INTO messages_by (msgid, userid, count) VALUES (?, ?, 2)", msgID, otherID)

	// Call RejectToDraft.
	body, _ := json.Marshal(map[string]interface{}{
		"id":     msgID,
		"action": "RejectToDraft",
	})
	req := httptest.NewRequest("POST", "/api/message?jwt="+token, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// availablenow should be reset to availableinitially.
	var availnow int
	db.Raw("SELECT availablenow FROM messages WHERE id = ?", msgID).Scan(&availnow)
	assert.Equal(t, 2, availnow, "availablenow should be reset to availableinitially")

	// messages_by should be cleared.
	var byCount int64
	db.Raw("SELECT COUNT(*) FROM messages_by WHERE msgid = ?", msgID).Scan(&byCount)
	assert.Equal(t, int64(0), byCount, "messages_by should be cleared after RejectToDraft")
}

// TestGetMessageWorryWords and TestGetMessageWorryWordsGroupMod removed: the
// concern_keywords-backed "worry" field on GET /message/:id was word-list moderation.
// Rules are AI judgement now, not word lists (see briefs/ai-judgement.md); there is no
// production code left that reads concern_keywords or per-group worrywords settings.

// TestMessageAiDeclinedWritesTable is a gap documentation test.
// V1 line 3999: INSERT IGNORE INTO messages_ai_declined (msgid) when AI check declines a message.
// Go V2 has NO AI service integration in the web post path — this functionality is entirely absent.
// This test verifies the table exists and is writable (documents the gap without requiring AI).
func TestMessageAiDeclinedWritesTable(t *testing.T) {
	prefix := uniquePrefix("msg_ai_dec")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	msgID := CreateTestMessage(t, userID, prefix+" AI declined item", 55.9533, -3.1883)

	// Directly insert into messages_ai_declined to verify the table is writable.
	// In V1, this is done by the AI spam check when it declines a message.
	// In V2 Go, there is no AI check integration — this is a known parity gap.
	result := db.Exec("INSERT IGNORE INTO messages_ai_declined (msgid) VALUES (?)", msgID)
	assert.NoError(t, result.Error, "messages_ai_declined table should accept inserts")

	// Verify the row was written.
	var count int64
	db.Raw("SELECT COUNT(*) FROM messages_ai_declined WHERE msgid = ?", msgID).Scan(&count)
	assert.Equal(t, int64(1), count, "messages_ai_declined should have a row for the message")

	// NOTE: Gap — Go V2 never calls an AI service for web-posted messages.
	// V1 checks messages against an AI model and inserts into messages_ai_declined when declined.
	// The Go implementation would need to call an AI service and insert here when the AI declines.
}

// --- tnpostid and expiresat tests ---

func TestGetMessageTnpostid(t *testing.T) {
	prefix := uniquePrefix("msg_tnpostid")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)
	msgID := CreateTestMessage(t, userID, prefix+" Offer", 55.9533, -3.1883)

	// Set tnpostid.
	db.Exec("UPDATE messages SET tnpostid = ? WHERE id = ?", "tn-12345", msgID)

	url := fmt.Sprintf("/api/message/%d?jwt=%s", msgID, token)
	req := httptest.NewRequest("GET", url, nil)
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)
	assert.Equal(t, "tn-12345", result["tnpostid"])
}

func TestGetMessageTnpostidNull(t *testing.T) {
	prefix := uniquePrefix("msg_tnpostidnull")

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)
	msgID := CreateTestMessage(t, userID, prefix+" Offer", 55.9533, -3.1883)

	url := fmt.Sprintf("/api/message/%d?jwt=%s", msgID, token)
	req := httptest.NewRequest("GET", url, nil)
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)
	assert.Nil(t, result["tnpostid"])
}

// --- Partner auth PATCH /message tests ---

func insertTestPartnerKeyMsg(t *testing.T, prefix string, domain string) string {
	db := database.DBConn
	key := prefix + "_key"
	result := db.Exec("INSERT INTO partners_keys (partner, `key`, domain) VALUES (?, ?, ?)",
		prefix+"_partner", key, domain)
	if result.Error != nil {
		t.Fatalf("ERROR: Failed to insert partner key: %v", result.Error)
	}
	return key
}

func TestPatchMessagePartnerAuth(t *testing.T) {
	prefix := uniquePrefix("msg_partpatch")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", 44444, ownerID)

	msgID := CreateTestMessage(t, ownerID, prefix+" Offer", 55.9533, -3.1883)
	key := insertTestPartnerKeyMsg(t, prefix, "test.com")

	// Partner edits the message subject.
	body := map[string]interface{}{
		"id":      msgID,
		"subject": "Partner Updated Subject",
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?partner=%s&tnuserid=44444&email=%s@test.com", key, prefix)
	req := httptest.NewRequest("PATCH", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)
	assert.Equal(t, float64(0), result["ret"])

	// Verify subject was updated.
	var subject string
	db.Raw("SELECT subject FROM messages WHERE id = ?", msgID).Scan(&subject)
	assert.Equal(t, "Partner Updated Subject", subject)
}

func TestPatchMessagePartnerWrongDomain(t *testing.T) {
	prefix := uniquePrefix("msg_partpatchdom")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", 55555, ownerID)

	msgID := CreateTestMessage(t, ownerID, prefix+" Offer", 55.9533, -3.1883)
	key := insertTestPartnerKeyMsg(t, prefix, "partner.com")

	body := map[string]interface{}{
		"id":      msgID,
		"subject": "Should Not Work",
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?partner=%s&tnuserid=55555&email=user@wrong.com", key)
	req := httptest.NewRequest("PATCH", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 403, resp.StatusCode)
}

func TestPatchMessagePartnerInvalidKey(t *testing.T) {
	prefix := uniquePrefix("msg_partpatchbad")

	ownerID := CreateTestUser(t, prefix+"_owner", "User")

	msgID := CreateTestMessage(t, ownerID, prefix+" Offer", 55.9533, -3.1883)

	body := map[string]interface{}{
		"id":      msgID,
		"subject": "Should Not Work",
	}
	bodyBytes, _ := json.Marshal(body)
	req := httptest.NewRequest("PATCH", "/api/message?partner=bad_key&tnuserid=1&email=x@test.com", bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 403, resp.StatusCode)
}

func TestPatchMessagePartnerNotOwner(t *testing.T) {
	prefix := uniquePrefix("msg_partpatchnotown")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	otherID := CreateTestUser(t, prefix+"_other", "User")
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", 66666, otherID)

	msgID := CreateTestMessage(t, ownerID, prefix+" Offer", 55.9533, -3.1883)
	key := insertTestPartnerKeyMsg(t, prefix, "test.com")

	// Try to edit as a different user (not the message owner).
	body := map[string]interface{}{
		"id":      msgID,
		"subject": "Should Not Work",
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?partner=%s&tnuserid=66666&email=%s@test.com", key, prefix+"_other")
	req := httptest.NewRequest("PATCH", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 403, resp.StatusCode)
}

func TestPatchMessagePartnerLatLng(t *testing.T) {
	prefix := uniquePrefix("msg_partlatlng")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	// tnuserid is UNIQUE in production. 77710 is not used by any other test -
	// 77701-77704, 77801-77804 and 77901-77903 all are. Release it from any user
	// left by an earlier run before claiming it.
	db.Exec("UPDATE users SET tnuserid = NULL WHERE tnuserid = ?", 77710)
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", 77710, ownerID)

	// Create message at original lat/lng.
	msgID := CreateTestMessage(t, ownerID, prefix+" Offer", 55.9533, -3.1883)
	key := insertTestPartnerKeyMsg(t, prefix, "test.com")

	newLat := 56.953346
	newLng := -2.188375

	body := map[string]interface{}{
		"id":  msgID,
		"lat": newLat,
		"lng": newLng,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?partner=%s&tnuserid=77710&email=%s@test.com", key, prefix)
	req := httptest.NewRequest("PATCH", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)
	assert.Equal(t, float64(0), result["ret"])

	// Verify lat/lng were written to messages table.
	var lat, lng float64
	db.Raw("SELECT lat, lng FROM messages WHERE id = ?", msgID).Row().Scan(&lat, &lng)
	assert.InDelta(t, newLat, lat, 0.0001, "lat should be updated in messages table")
	assert.InDelta(t, newLng, lng, 0.0001, "lng should be updated in messages table")
}

// --- Per-Group Moderation Tests ---

// Cross-group authorization tests: a mod of group A must not be able to
// perform moderation actions on a message that's only on group B, even
// though the message is visible to them (because they mod one of its groups).

// =============================================================================
// GET /message/:id — postings visibility (V1 returns postings to all callers)
// =============================================================================

// --- Partner key auth for POST /message (actions) ---

func TestPostMessagePartnerAuthPromise(t *testing.T) {
	prefix := uniquePrefix("msg_postpartner")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	// tnuserid is UNIQUE in production, so release it from any user left by an
	// earlier run before claiming it.
	db.Exec("UPDATE users SET tnuserid = NULL WHERE tnuserid = ?", 77701)
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", 77701, ownerID)

	msgID := CreateTestMessage(t, ownerID, prefix+" Offer", 55.9533, -3.1883)
	key := insertTestPartnerKeyMsg(t, prefix, "tn.com")
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	body := map[string]interface{}{
		"id":     msgID,
		"action": "Promise",
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?partner=%s&tnuserid=77701&email=%s@tn.com", key, prefix+"_owner")
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
}

func TestPostMessagePartnerAuthByTnPostid(t *testing.T) {
	prefix := uniquePrefix("msg_postpartnertn")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	// tnuserid is UNIQUE in production, so release it from any user left by an
	// earlier run before claiming it.
	db.Exec("UPDATE users SET tnuserid = NULL WHERE tnuserid = ?", 77702)
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", 77702, ownerID)

	msgID := CreateTestMessage(t, ownerID, prefix+" Offer", 55.9533, -3.1883)
	tnpostid := fmt.Sprintf("tn-%d", msgID)
	db.Exec("UPDATE messages SET tnpostid = ? WHERE id = ?", tnpostid, msgID)

	key := insertTestPartnerKeyMsg(t, prefix, "tn.com")
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	// Send action with tnpostid instead of id.
	body := map[string]interface{}{
		"tnpostid": tnpostid,
		"action":   "Promise",
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?partner=%s&tnuserid=77702&email=%s@tn.com", key, prefix+"_owner")
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
}

func TestPostMessagePartnerInvalidKey(t *testing.T) {
	prefix := uniquePrefix("msg_postpartbad")

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	msgID := CreateTestMessage(t, ownerID, prefix+" Offer", 55.9533, -3.1883)

	body := map[string]interface{}{
		"id":     msgID,
		"action": "Promise",
	}
	bodyBytes, _ := json.Marshal(body)
	req := httptest.NewRequest("POST", "/api/message?partner=bad_key&tnuserid=1&email=x@tn.com", bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 403, resp.StatusCode)
}

// --- PATCH /message/tn/:tnpostid (edit by TN post ID) ---

func TestPatchMessageByTnPostid(t *testing.T) {
	prefix := uniquePrefix("msg_patchtn")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", 77703, ownerID)

	msgID := CreateTestMessage(t, ownerID, prefix+" Offer", 55.9533, -3.1883)
	tnpostid := fmt.Sprintf("tn-%d", msgID)
	db.Exec("UPDATE messages SET tnpostid = ? WHERE id = ?", tnpostid, msgID)

	key := insertTestPartnerKeyMsg(t, prefix, "tn.com")
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	body := map[string]interface{}{
		"subject": "TN Updated Subject",
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message/tn/%s?partner=%s&tnuserid=77703&email=%s@tn.com", tnpostid, key, prefix+"_owner")
	req := httptest.NewRequest("PATCH", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var subject string
	db.Raw("SELECT subject FROM messages WHERE id = ?", msgID).Scan(&subject)
	assert.Equal(t, "TN Updated Subject", subject)
}

func TestPatchMessageByTnPostidNotFound(t *testing.T) {
	prefix := uniquePrefix("msg_patchtn404")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", 77704, ownerID)

	key := insertTestPartnerKeyMsg(t, prefix, "tn.com")
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	body := map[string]interface{}{
		"subject": "Should Not Work",
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message/tn/nonexistent-tn-id?partner=%s&tnuserid=77704&email=%s@tn.com", key, prefix+"_owner")
	req := httptest.NewRequest("PATCH", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 404, resp.StatusCode)
}

// TestPatchMessageByTnPostidUpdatesLocationFromCoordinates verifies that a TN edit
// carrying fresh lat/lng but no Freegle location/locationid (TN only ever knows GPS
// coordinates for a post, never Freegle's internal location rows) re-derives
// locationid from the new coordinates - and therefore the subject's derived "vague
// postcode" - instead of leaving it pinned to whatever it was before the edit.
// Regression test for Discourse 9908: a TN post's postcode could never be corrected
// because locationid never followed a coordinates-only edit.
func TestPatchMessageByTnPostidUpdatesLocationFromCoordinates(t *testing.T) {
	prefix := uniquePrefix("msg_patchtn_loc")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", 77705, ownerID)

	msgID := CreateTestMessage(t, ownerID, prefix+" Offer", 52.006292, -4.939858)
	tnpostid := fmt.Sprintf("tn-loc-%d", msgID)

	// Give the message a matched item (as every TN message has - confirmed against
	// production), and pin it to the OLD test-fixture postcode/coords (SA65 9ET) so
	// locationid and lat/lng agree before the edit, as a real pre-edit message would.
	itemName := prefix + " Item"
	db.Exec("INSERT INTO items (name) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)", itemName)
	var itemID uint64
	db.Raw("SELECT id FROM items WHERE name = ?", itemName).Scan(&itemID)
	db.Exec("INSERT INTO messages_items (msgid, itemid) VALUES (?, ?)", msgID, itemID)
	db.Exec("UPDATE messages SET tnpostid = ?, locationid = ?, lat = ?, lng = ? WHERE id = ?",
		tnpostid, 1687412, 52.006292, -4.939858, msgID)

	key := insertTestPartnerKeyMsg(t, prefix, "tn.com")
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	// TN edits the post with new coordinates only - no location/locationid, since TN
	// doesn't know Freegle's internal location rows.
	body := map[string]interface{}{
		"lat": 55.957571,
		"lng": -3.205333,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message/tn/%s?partner=%s&tnuserid=77705&email=%s@tn.com", tnpostid, key, prefix+"_owner")
	req := httptest.NewRequest("PATCH", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var locationID uint64
	var subject string
	db.Raw("SELECT locationid FROM messages WHERE id = ?", msgID).Scan(&locationID)
	db.Raw("SELECT subject FROM messages WHERE id = ?", msgID).Scan(&subject)

	assert.Equal(t, uint64(1000001), locationID, "locationid should follow the new coordinates, not stay pinned to the old postcode")
	assert.Equal(t, "OFFER: "+itemName+" (Edinburgh EH3)", subject, "subject should be rebuilt from the new location, not the stale one")
}

// TestPostMessageByTnPostidUpdatesAllMessages verifies that POST /message with tnpostid
// applies the action to ALL Freegle messages sharing the same tnpostid.
func TestPostMessageByTnPostidUpdatesAllMessages(t *testing.T) {
	prefix := uniquePrefix("posttn_multi")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", 88802, ownerID)

	tnpostid := fmt.Sprintf("tn-post-multi-%s", prefix)
	msg1ID := CreateTestMessage(t, ownerID, "OFFER: "+prefix+" Item1", 55.9533, -3.1883)
	msg2ID := CreateTestMessage(t, ownerID, "OFFER: "+prefix+" Item2", 55.9533, -3.1883)
	db.Exec("UPDATE messages SET tnpostid = ? WHERE id IN (?, ?)", tnpostid, msg1ID, msg2ID)

	key := insertTestPartnerKeyMsg(t, prefix, "tn.com")
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	body := map[string]interface{}{
		"tnpostid": tnpostid,
		"action":   "OutcomeIntended",
		"outcome":  "Taken",
	}
	bodyBytes, _ := json.Marshal(body)
	reqURL := fmt.Sprintf("/api/message?partner=%s&tnuserid=88802&email=%s@tn.com", key, prefix+"_owner")
	req := httptest.NewRequest("POST", reqURL, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var count1, count2 int64
	db.Raw("SELECT COUNT(*) FROM messages_outcomes_intended WHERE msgid = ? AND outcome = 'Taken'", msg1ID).Scan(&count1)
	db.Raw("SELECT COUNT(*) FROM messages_outcomes_intended WHERE msgid = ? AND outcome = 'Taken'", msg2ID).Scan(&count2)
	assert.Equal(t, int64(1), count1, "first message should have outcome intended")
	assert.Equal(t, int64(1), count2, "second message should also have outcome intended")
}

// TestMessageAttachmentHasAIField verifies that the API returns an "ai" boolean field
// on each attachment so that TN can determine which attachments were AI-generated.
func TestMessageAttachmentHasAIField(t *testing.T) {
	prefix := uniquePrefix("attach_ai_field")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)

	msgID := CreateTestMessage(t, userID, prefix+" AI Field Test", 55.0, -3.0)
	aiUID := "freegletusd-test-ai-field-" + prefix
	db.Exec("INSERT INTO messages_attachments (msgid, externaluid, externalmods, `primary`) VALUES (?, ?, '{\"ai\":true}', 1)", msgID, aiUID)

	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_attachments WHERE msgid = ?", msgID)
	})

	reqURL := fmt.Sprintf("/api/message/%d?jwt=%s", msgID, token)
	req := httptest.NewRequest("GET", reqURL, nil)
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)

	attachments, ok := result["attachments"].([]interface{})
	assert.True(t, ok, "response should have attachments array")
	assert.Equal(t, 1, len(attachments), "should have one attachment")
	firstAttach, ok := attachments[0].(map[string]interface{})
	assert.True(t, ok)
	assert.Equal(t, true, firstAttach["ai"], "AI attachment should have ai:true field")
}

// Anonymous GET /api/message/:id masks 4+ digit sequences and email addresses
// in the body (defence against scraping phone numbers / contacts). A valid
// partner key is a trusted integration (e.g. Trash Nothing) and must see the
// full body so messages can be round-tripped between platforms.
func TestMessagePartnerKeyBypassesBodyMasking(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("msg_partner_mask")

	userID := CreateTestUser(t, prefix+"_user", "User")
	msgID := CreateTestMessage(t, userID, prefix+" Mask Test", 55.0, -3.0)

	// Set a body that exercises both masking rules — a phone-like 11-digit
	// number and an email address.
	body := "Call me on 07700900123 or email someone@example.com"
	db.Exec("UPDATE messages SET textbody = ?, message = ? WHERE id = ?", body, body, msgID)

	// Register a partner key.
	partnerKey := prefix + "_key"
	db.Exec("INSERT INTO partners_keys (partner, `key`) VALUES (?, ?)", prefix+"_partner", partnerKey)
	t.Cleanup(func() {
		db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")
	})

	// 1. Anonymous request: body must be masked.
	resp, err := getApp().Test(httptest.NewRequest("GET", "/api/message/"+fmt.Sprint(msgID), nil), -1)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)
	var anon map[string]interface{}
	require.NoError(t, json.NewDecoder(resp.Body).Decode(&anon))
	anonBody, _ := anon["textbody"].(string)
	assert.Contains(t, anonBody, "***",
		"Anonymous caller must see masked digits/emails")
	assert.NotContains(t, anonBody, "07700900123",
		"Anonymous caller must not see raw phone number")
	assert.NotContains(t, anonBody, "someone@example.com",
		"Anonymous caller must not see raw email address")

	// 2. Partner request: body must be returned unmasked.
	resp, err = getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/message/%d?partner=%s", msgID, partnerKey), nil), -1)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)
	var partner map[string]interface{}
	require.NoError(t, json.NewDecoder(resp.Body).Decode(&partner))
	partnerBody, _ := partner["textbody"].(string)
	assert.Equal(t, body, partnerBody,
		"Valid partner key must return the textbody verbatim — no masking")

	// 3. Invalid partner key: must still mask (we don't fail the request,
	// we just treat the caller as anonymous).
	resp, err = getApp().Test(httptest.NewRequest("GET",
		"/api/message/"+fmt.Sprint(msgID)+"?partner=not_a_real_key_xyz", nil), -1)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)
	var bogus map[string]interface{}
	require.NoError(t, json.NewDecoder(resp.Body).Decode(&bogus))
	bogusBody, _ := bogus["textbody"].(string)
	assert.NotContains(t, bogusBody, "07700900123",
		"Invalid partner key must not bypass masking")
}

// --- Content check pipeline tests ---

// TestPatchMessageByTnPostid_RemovesAIPhotoWhenTextbodyHasNoPhotoLinks verifies that
// when a TN user edits their post to remove all photos (textbody has no TN photo links),
// the AI-generated attachment is deleted and the message is flagged as AI-declined so the
// illustrations cron cannot re-inject it.
func TestPatchMessageByTnPostid_RemovesAIPhotoWhenTextbodyHasNoPhotoLinks(t *testing.T) {
	prefix := uniquePrefix("patchtn_ai_photo")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", 77801, ownerID)

	msgID := CreateTestMessage(t, ownerID, prefix+" Offer", 55.9533, -3.1883)
	tnpostid := fmt.Sprintf("tn-ai-%d", msgID)
	db.Exec("UPDATE messages SET tnpostid = ? WHERE id = ?", tnpostid, msgID)

	// Insert an AI-generated attachment on the message.
	externalUID := fmt.Sprintf("ai-uid-%d", msgID)
	db.Exec("INSERT INTO messages_attachments (msgid, externaluid, externalmods, `primary`) VALUES (?, ?, ?, 1)",
		msgID, externalUID, `{"ai":true}`)

	key := insertTestPartnerKeyMsg(t, prefix, "tn.com")
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	// PATCH with a textbody that contains no TN photo links — simulates TN user deleting their photo.
	body := map[string]interface{}{
		"textbody": "I have a sofa to give away. No photos.",
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message/tn/%s?partner=%s&tnuserid=77801&email=%s@tn.com", tnpostid, key, prefix+"_owner")
	req := httptest.NewRequest("PATCH", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// AI attachment must be gone.
	var aiCount int64
	db.Raw("SELECT COUNT(*) FROM messages_attachments WHERE msgid = ? AND externalmods LIKE ?", msgID, `%"ai":true%`).Scan(&aiCount)
	assert.Equal(t, int64(0), aiCount, "AI attachment should be removed when TN textbody has no photo links")

	// messages_ai_declined must be set so the cron cannot re-inject.
	var declinedCount int64
	db.Raw("SELECT COUNT(*) FROM messages_ai_declined WHERE msgid = ?", msgID).Scan(&declinedCount)
	assert.Equal(t, int64(1), declinedCount, "messages_ai_declined must be set to prevent cron re-injection")

	// Cleanup.
	db.Exec("DELETE FROM messages_ai_declined WHERE msgid = ?", msgID)
	db.Exec("DELETE FROM messages_attachments WHERE msgid = ?", msgID)
}

// TestPatchMessageByTnPostid_RemovesAIAndScrapesTNPhotosWhenLinksPresent verifies that
// when a TN user edits their post and the textbody contains trashnothing.com/pics/ links:
//   - The AI-generated attachment is removed and messages_ai_declined is set
//   - The pic-link block is stripped from the stored textbody
//   - The TN page fetcher is invoked and its returned image URLs are stored as attachments
func TestPatchMessageByTnPostid_RemovesAIAndScrapesTNPhotosWhenLinksPresent(t *testing.T) {
	prefix := uniquePrefix("patchtn_scrape")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", 77802, ownerID)

	msgID := CreateTestMessage(t, ownerID, prefix+" Offer", 55.9533, -3.1883)
	tnpostid := fmt.Sprintf("tn-scrape-%d", msgID)
	db.Exec("UPDATE messages SET tnpostid = ? WHERE id = ?", tnpostid, msgID)

	// Insert an AI-generated attachment.
	aiExternalUID := fmt.Sprintf("ai-uid-scrape-%d", msgID)
	db.Exec("INSERT INTO messages_attachments (msgid, externaluid, externalmods, `primary`) VALUES (?, ?, ?, 1)",
		msgID, aiExternalUID, `{"ai":true}`)

	key := insertTestPartnerKeyMsg(t, prefix, "tn.com")
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	// Inject a fake TNPageFetcher that returns a stable image URL without real HTTP.
	fakeImageURL := "https://img.trashnothing.com/fake/photo.jpg"
	origFetcher := message.TNPageFetcher
	message.TNPageFetcher = func(pageURL string) []string {
		return []string{fakeImageURL}
	}
	defer func() { message.TNPageFetcher = origFetcher }()

	// Inject a fake TNImageFetcher that returns stub image data without real HTTP.
	origImageFetcher := message.TNImageFetcher
	message.TNImageFetcher = func(imageURL string) ([]byte, string, error) {
		return []byte("fake-image-data"), "image/jpeg", nil
	}
	defer func() { message.TNImageFetcher = origImageFetcher }()

	// Inject a fake ImageUploader that returns a stable externaluid without real TUS.
	fakeExternalUID := fmt.Sprintf("freegletusd-tn-fake-%d", msgID)
	origUploader := aiimage.ImageUploader
	aiimage.ImageUploader = func(data []byte, mime string) (string, error) {
		return fakeExternalUID, nil
	}
	defer func() { aiimage.ImageUploader = origUploader }()

	// Run scraping synchronously so the test doesn't need to sleep.
	origScrapeRunner := message.TNPhotoScrapeRunner
	message.TNPhotoScrapeRunner = message.ScrapeTNPhotosSync
	defer func() { message.TNPhotoScrapeRunner = origScrapeRunner }()

	// PATCH with a textbody containing a TN photo link block.
	textbody := "I have a sofa to give away.\n\nCheck out the pictures at:\nhttps://trashnothing.com/pics/abc123\n"
	body := map[string]interface{}{
		"textbody": textbody,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message/tn/%s?partner=%s&tnuserid=77802&email=%s@tn.com", tnpostid, key, prefix+"_owner")
	req := httptest.NewRequest("PATCH", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// AI attachment must be gone.
	var aiCount int64
	db.Raw("SELECT COUNT(*) FROM messages_attachments WHERE msgid = ? AND externalmods LIKE ?", msgID, `%"ai":true%`).Scan(&aiCount)
	assert.Equal(t, int64(0), aiCount, "AI attachment should be removed when TN textbody has photo links")

	// messages_ai_declined must be set.
	var declinedCount int64
	db.Raw("SELECT COUNT(*) FROM messages_ai_declined WHERE msgid = ?", msgID).Scan(&declinedCount)
	assert.Equal(t, int64(1), declinedCount, "messages_ai_declined must be set to prevent cron re-injection")

	// The pic-link block should be stripped from the stored textbody.
	var storedTextbody string
	db.Raw("SELECT textbody FROM messages WHERE id = ?", msgID).Scan(&storedTextbody)
	assert.NotContains(t, storedTextbody, "trashnothing.com/pics/", "pic links should be stripped from stored textbody")
	assert.NotContains(t, storedTextbody, "Check out the pictures", "pic block header should be stripped from stored textbody")
	assert.Contains(t, storedTextbody, "sofa", "non-photo content should be preserved in textbody")

	// Verify the scraped TN photo was stored as an attachment.
	var tnCount int64
	db.Raw("SELECT COUNT(*) FROM messages_attachments WHERE msgid = ? AND externaluid = ?", msgID, fakeExternalUID).Scan(&tnCount)
	assert.Equal(t, int64(1), tnCount, "scraped TN photo should be stored as attachment")

	// Cleanup.
	db.Exec("DELETE FROM messages_ai_declined WHERE msgid = ?", msgID)
	db.Exec("DELETE FROM messages_attachments WHERE msgid = ?", msgID)
}

// TestPatchMessageByTN_ChangeSignalNotWrittenUntilPhotosScraped verifies the fix for the
// TN multi-photo race.  TN polls /api/changes, which reports a message as "Edited" based
// on the existence of a messages_edits row.  TN then fetches the full message to read its
// attachments.  If the messages_edits row (the change signal) is written BEFORE the TN
// photos have been scraped into messages_attachments, TN can fetch in the gap and get a
// partial photo set — the reported "only 1 photo back, all of them on a forced re-fetch" bug.
//
// The fix scrapes TN photos SYNCHRONOUSLY and BEFORE applyPatchMessageCore (which writes
// messages_edits), matching V1 (http/api/message.php scrapes + saves attachments before
// calling Message::edit()).  So the change signal only becomes visible once every photo is in.
//
// This test deliberately does NOT swap TNPhotoScrapeRunner: it exercises the real production
// runner, so an asynchronous or wrong-order implementation fails it.
func TestPatchMessageByTN_ChangeSignalNotWrittenUntilPhotosScraped(t *testing.T) {
	prefix := uniquePrefix("patchtn_signalorder")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", 77804, ownerID)

	msgID := CreateTestMessage(t, ownerID, prefix+" Offer", 55.9533, -3.1883)
	tnpostid := fmt.Sprintf("tn-signalorder-%d", msgID)
	db.Exec("UPDATE messages SET tnpostid = ? WHERE id = ?", tnpostid, msgID)

	// Set up a clean slate for the change signal and attachments we assert on.
	db.Exec("DELETE FROM messages_edits WHERE msgid = ?", msgID)
	db.Exec("DELETE FROM messages_attachments WHERE msgid = ?", msgID)

	key := insertTestPartnerKeyMsg(t, prefix, "tn.com")
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	// Fake the TN fetch chain so no real HTTP happens.
	fakeImageURL := "https://img.trashnothing.com/fake/signalorder.jpg"
	origFetcher := message.TNPageFetcher
	message.TNPageFetcher = func(pageURL string) []string { return []string{fakeImageURL} }
	defer func() { message.TNPageFetcher = origFetcher }()

	origImageFetcher := message.TNImageFetcher
	message.TNImageFetcher = func(imageURL string) ([]byte, string, error) {
		return []byte("fake-image-data"), "image/jpeg", nil
	}
	defer func() { message.TNImageFetcher = origImageFetcher }()

	// The uploader runs at the moment a photo is about to be stored.  Capture how many
	// messages_edits rows (the /api/changes signal) exist AT THAT MOMENT.  With the fix
	// this must be 0: the signal is only written after every photo is in.
	fakeExternalUID := fmt.Sprintf("freegletusd-tn-signalorder-%d", msgID)
	editsAtUploadTime := int64(-1)
	uploaded := make(chan struct{}, 1)
	origUploader := aiimage.ImageUploader
	aiimage.ImageUploader = func(data []byte, mime string) (string, error) {
		db.Raw("SELECT COUNT(*) FROM messages_edits WHERE msgid = ?", msgID).Scan(&editsAtUploadTime)
		select {
		case uploaded <- struct{}{}:
		default:
		}
		return fakeExternalUID, nil
	}
	defer func() { aiimage.ImageUploader = origUploader }()

	textbody := "Sofa for collection.\n\nCheck out the pictures at:\nhttps://trashnothing.com/pics/sigorder1\n"
	bodyBytes, _ := json.Marshal(map[string]interface{}{"textbody": textbody})
	reqURL := fmt.Sprintf("/api/message/tn/%s?partner=%s&tnuserid=77804&email=%s@tn.com", tnpostid, key, prefix+"_owner")
	req := httptest.NewRequest("PATCH", reqURL, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Wait for the upload to have happened (covers a still-asynchronous implementation).
	// The channel receive synchronises with the uploader goroutine, so the subsequent read
	// of editsAtUploadTime is race-free.
	select {
	case <-uploaded:
	case <-time.After(10 * time.Second):
		t.Fatal("TN photo was never uploaded/scraped")
	}

	// Core assertion: the change signal must NOT have existed while the photo was being stored.
	assert.Equal(t, int64(0), editsAtUploadTime,
		"messages_edits (the /api/changes signal) must not be written until all TN photos are scraped — otherwise TN can fetch a partial photo set")

	// After the handler completes, both the photo and the change signal must exist.
	var tnCount int64
	db.Raw("SELECT COUNT(*) FROM messages_attachments WHERE msgid = ? AND externaluid = ?", msgID, fakeExternalUID).Scan(&tnCount)
	assert.Equal(t, int64(1), tnCount, "scraped TN photo should be stored as attachment")

	var editsCount int64
	db.Raw("SELECT COUNT(*) FROM messages_edits WHERE msgid = ?", msgID).Scan(&editsCount)
	assert.True(t, editsCount >= 1, "an edit (change signal) should be recorded after the photos are in")

	// Cleanup.
	db.Exec("DELETE FROM messages_edits WHERE msgid = ?", msgID)
	db.Exec("DELETE FROM messages_attachments WHERE msgid = ?", msgID)
	db.Exec("DELETE FROM messages_ai_declined WHERE msgid = ?", msgID)
}

// TestPatchMessageByTnPostid_NonAIAttachmentRemovedOnTextEdit verifies that when a
// TN user edits a post by sending a textbody (even with no pic links), ALL existing
// attachments — including non-AI ones — are deleted.  TN's textbody is the authoritative
// photo set: if TN sends no pic links, the correct state is zero attachments.
//
// This test was formerly named TestPatchMessageByTnPostid_NonAIAttachmentPreservedOnTextOnlyEdit
// and asserted the opposite (preservation).  That was the bug: fix #2 corrects it.
func TestPatchMessageByTnPostid_NonAIAttachmentRemovedOnTextEdit(t *testing.T) {
	prefix := uniquePrefix("patchtn_noai")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", 77803, ownerID)

	msgID := CreateTestMessage(t, ownerID, prefix+" Offer", 55.9533, -3.1883)
	tnpostid := fmt.Sprintf("tn-noai-%d", msgID)
	db.Exec("UPDATE messages SET tnpostid = ? WHERE id = ?", tnpostid, msgID)

	// Insert a regular (non-AI) attachment — the one that must be removed.
	realAttachID := CreateTestAttachment(t, msgID)

	key := insertTestPartnerKeyMsg(t, prefix, "tn.com")
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	// PATCH with textbody containing no TN pic links.
	body := map[string]interface{}{
		"textbody": "Updated description with no photos.",
	}
	bodyBytes, _ := json.Marshal(body)
	reqURL := fmt.Sprintf("/api/message/tn/%s?partner=%s&tnuserid=77803&email=%s@tn.com", tnpostid, key, prefix+"_owner")
	req := httptest.NewRequest("PATCH", reqURL, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// BUG #2 FIX: the non-AI attachment must be gone — textbody with no pic links
	// means TN is asserting "no photos", so we clear all existing attachments.
	var attachCount int64
	db.Raw("SELECT COUNT(*) FROM messages_attachments WHERE id = ?", realAttachID).Scan(&attachCount)
	assert.Equal(t, int64(0), attachCount, "non-AI attachment must be deleted when TN sends textbody with no pic links (fix #2)")

	// Cleanup.
	db.Exec("DELETE FROM messages_attachments WHERE msgid = ?", msgID)
}

// --- BUG FIX REGRESSION TESTS (must FAIL on master, PASS with fix) ---

// TestPatchMessageByTN_ExplicitSubjectWinsOverMsgtype verifies that when a caller sends
// both an explicit subject AND a msgtype, the explicit subject is persisted and NOT
// overwritten by the "reconstruct from type+item+location" block.
// Bug #1: passing msgtype set req.Type, which triggered reconstruction that clobbered
// the caller's subject.
func TestPatchMessageByTN_ExplicitSubjectWinsOverMsgtype(t *testing.T) {
	prefix := uniquePrefix("patchtn_subjfix")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", 77901, ownerID)

	// Create a message that already has an item and location so reconstruction is possible.
	msgID := CreateTestMessage(t, ownerID, "OFFER: old item (old location)", 55.9533, -3.1883)
	tnpostid := fmt.Sprintf("tn-subjfix-%d", msgID)
	db.Exec("UPDATE messages SET tnpostid = ?, type = 'Offer' WHERE id = ?", tnpostid, msgID)

	// Wire up an item and a location so that subject reconstruction would produce a
	// different string if it fires.
	db.Exec("INSERT INTO items (name) VALUES (?) ON DUPLICATE KEY UPDATE name=name", prefix+"_widget")
	var itemID uint64
	db.Raw("SELECT id FROM items WHERE name = ?", prefix+"_widget").Scan(&itemID)
	if itemID > 0 {
		db.Exec("DELETE FROM messages_items WHERE msgid = ?", msgID)
		db.Exec("INSERT INTO messages_items (msgid, itemid) VALUES (?, ?)", msgID, itemID)
	}

	key := insertTestPartnerKeyMsg(t, prefix, "tn.com")
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	// PATCH with explicit subject AND msgtype — the explicit subject must win.
	wantSubject := "WANTED: new title 3 (location 3)"
	body := map[string]interface{}{
		"subject": wantSubject,
		"msgtype": "Wanted",
	}
	bodyBytes, _ := json.Marshal(body)
	reqURL := fmt.Sprintf("/api/message/tn/%s?partner=%s&tnuserid=77901&email=%s@tn.com", tnpostid, key, prefix+"_owner")
	httpReq := httptest.NewRequest("PATCH", reqURL, bytes.NewBuffer(bodyBytes))
	httpReq.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(httpReq, -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var gotSubject string
	db.Raw("SELECT subject FROM messages WHERE id = ?", msgID).Scan(&gotSubject)
	assert.Equal(t, wantSubject, gotSubject,
		"explicit subject must not be overwritten by reconstruct-from-type block (bug #1)")
}

// TestPatchMessageByTN_EmptyTextbodyRemovesAllAttachments verifies that PATCH with
// textbody="" (empty string, i.e. a non-nil *string pointer to "") deletes all
// existing attachments (including non-AI ones) because TN is asserting "no photos".
// Bug #2: only AI attachments were deleted; non-AI TN-scraped photos survived.
func TestPatchMessageByTN_EmptyTextbodyRemovesAllAttachments(t *testing.T) {
	prefix := uniquePrefix("patchtn_emptytb")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", 77902, ownerID)

	msgID := CreateTestMessage(t, ownerID, prefix+" Offer", 55.9533, -3.1883)
	tnpostid := fmt.Sprintf("tn-emptytb-%d", msgID)
	db.Exec("UPDATE messages SET tnpostid = ? WHERE id = ?", tnpostid, msgID)

	// Insert a regular (non-AI) attachment to simulate a previously-scraped TN photo.
	attach1ID := CreateTestAttachment(t, msgID)
	attach2ID := CreateTestAttachment(t, msgID)

	key := insertTestPartnerKeyMsg(t, prefix, "tn.com")
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	// PATCH with textbody="" — no pic links, TN is asserting "no photos".
	emptyBody := ""
	body := map[string]interface{}{
		"textbody": emptyBody,
	}
	bodyBytes, _ := json.Marshal(body)
	reqURL := fmt.Sprintf("/api/message/tn/%s?partner=%s&tnuserid=77902&email=%s@tn.com", tnpostid, key, prefix+"_owner")
	httpReq := httptest.NewRequest("PATCH", reqURL, bytes.NewBuffer(bodyBytes))
	httpReq.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(httpReq, -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Both non-AI attachments must be gone (fix #2).
	var count1, count2 int64
	db.Raw("SELECT COUNT(*) FROM messages_attachments WHERE id = ?", attach1ID).Scan(&count1)
	db.Raw("SELECT COUNT(*) FROM messages_attachments WHERE id = ?", attach2ID).Scan(&count2)
	assert.Equal(t, int64(0), count1, "first non-AI attachment must be deleted when textbody='' (fix #2)")
	assert.Equal(t, int64(0), count2, "second non-AI attachment must be deleted when textbody='' (fix #2)")

	// Cleanup.
	db.Exec("DELETE FROM messages_attachments WHERE msgid = ?", msgID)
}

// TestPatchMessageByTN_NewPicLinkReplacesOldAttachments verifies that PATCH with a
// textbody containing new trashnothing.com/pics/ links removes OLD non-AI attachments
// before adding the newly-scraped ones.
// Bug #3: old non-AI photos survived alongside newly-scraped ones.
func TestPatchMessageByTN_NewPicLinkReplacesOldAttachments(t *testing.T) {
	prefix := uniquePrefix("patchtn_replace")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", 77903, ownerID)

	msgID := CreateTestMessage(t, ownerID, prefix+" Offer", 55.9533, -3.1883)
	tnpostid := fmt.Sprintf("tn-replace-%d", msgID)
	db.Exec("UPDATE messages SET tnpostid = ? WHERE id = ?", tnpostid, msgID)

	// Insert two old non-AI attachments (simulating previously-scraped TN photos).
	oldAttach1 := CreateTestAttachment(t, msgID)
	oldAttach2 := CreateTestAttachment(t, msgID)

	key := insertTestPartnerKeyMsg(t, prefix, "tn.com")
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	// Inject fake fetchers so we don't do real HTTP.
	origFetcher := message.TNPageFetcher
	message.TNPageFetcher = func(pageURL string) []string {
		return []string{"https://img.trashnothing.com/fake/new-photo.jpg"}
	}
	defer func() { message.TNPageFetcher = origFetcher }()

	origImageFetcher := message.TNImageFetcher
	message.TNImageFetcher = func(imageURL string) ([]byte, string, error) {
		return []byte("fake-image-data"), "image/jpeg", nil
	}
	defer func() { message.TNImageFetcher = origImageFetcher }()

	fakeExternalUID := fmt.Sprintf("freegletusd-tn-replace-%d", msgID)
	origUploader := aiimage.ImageUploader
	aiimage.ImageUploader = func(data []byte, mime string) (string, error) {
		return fakeExternalUID, nil
	}
	defer func() { aiimage.ImageUploader = origUploader }()

	origScrapeRunner := message.TNPhotoScrapeRunner
	message.TNPhotoScrapeRunner = message.ScrapeTNPhotosSync
	defer func() { message.TNPhotoScrapeRunner = origScrapeRunner }()

	// PATCH with textbody containing one new TN pic link.
	textbody := "Nice sofa.\n\nCheck out the pictures at:\nhttps://trashnothing.com/pics/newxyz\n"
	body := map[string]interface{}{
		"textbody": textbody,
	}
	bodyBytes, _ := json.Marshal(body)
	reqURL := fmt.Sprintf("/api/message/tn/%s?partner=%s&tnuserid=77903&email=%s@tn.com", tnpostid, key, prefix+"_owner")
	httpReq := httptest.NewRequest("PATCH", reqURL, bytes.NewBuffer(bodyBytes))
	httpReq.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(httpReq, -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Both OLD non-AI attachments must be gone (fix #3).
	var old1, old2 int64
	db.Raw("SELECT COUNT(*) FROM messages_attachments WHERE id = ?", oldAttach1).Scan(&old1)
	db.Raw("SELECT COUNT(*) FROM messages_attachments WHERE id = ?", oldAttach2).Scan(&old2)
	assert.Equal(t, int64(0), old1, "first old non-AI attachment must be deleted when new pic links are present (fix #3)")
	assert.Equal(t, int64(0), old2, "second old non-AI attachment must be deleted when new pic links are present (fix #3)")

	// The new scraped attachment must be present.
	var newCount int64
	db.Raw("SELECT COUNT(*) FROM messages_attachments WHERE msgid = ? AND externaluid = ?", msgID, fakeExternalUID).Scan(&newCount)
	assert.Equal(t, int64(1), newCount, "newly-scraped TN photo must be stored as attachment (fix #3)")

	// Cleanup.
	db.Exec("DELETE FROM messages_ai_declined WHERE msgid = ?", msgID)
	db.Exec("DELETE FROM messages_attachments WHERE msgid = ?", msgID)
}

func TestPostMessagePromisePartner(t *testing.T) {
	// Partner Promise should work with only a partner key (no email/tnuserid),
	// acting as the message's fromuser when fromaddr is in the partner domain.
	prefix := uniquePrefix("msgp_prm_ptnr")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	otherID := CreateTestUser(t, prefix+"_other", "User")
	msgID := CreateTestMessage(t, ownerID, prefix+" offer item", 52.5, -1.8)
	db.Exec("UPDATE messages SET fromaddr = ? WHERE id = ?", prefix+"_owner@test.com", msgID)

	key := insertTestPartnerKeyMsg(t, prefix, "test.com")
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	body := map[string]interface{}{
		"id":     msgID,
		"action": "Promise",
		"userid": otherID,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?partner=%s", key)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode, "Partner Promise should succeed without email/tnuserid")

	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)
	assert.Equal(t, float64(0), result["ret"])

	var count int64
	db.Raw("SELECT COUNT(*) FROM messages_promises WHERE msgid = ? AND userid = ?", msgID, otherID).Scan(&count)
	assert.Equal(t, int64(1), count)
}

func TestPostMessageRenegePartner(t *testing.T) {
	// Partner Renege should work with only a partner key (no email/tnuserid).
	prefix := uniquePrefix("msgp_rng_ptnr")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	otherID := CreateTestUser(t, prefix+"_other", "User")
	msgID := CreateTestMessage(t, ownerID, prefix+" offer item", 52.5, -1.8)
	db.Exec("UPDATE messages SET fromaddr = ? WHERE id = ?", prefix+"_owner@test.com", msgID)
	db.Exec("REPLACE INTO messages_promises (msgid, userid) VALUES (?, ?)", msgID, otherID)

	key := insertTestPartnerKeyMsg(t, prefix, "test.com")
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	body := map[string]interface{}{
		"id":     msgID,
		"action": "Renege",
		"userid": otherID,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?partner=%s", key)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode, "Partner Renege should succeed without email/tnuserid")

	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)
	assert.Equal(t, float64(0), result["ret"])

	var count int64
	db.Raw("SELECT COUNT(*) FROM messages_promises WHERE msgid = ? AND userid = ?", msgID, otherID).Scan(&count)
	assert.Equal(t, int64(0), count, "Promise should be deleted after Renege")
}

func TestPostMessagePromiseRenegePromisePartner(t *testing.T) {
	// Reproduces the TN bug: Promise -> Renege -> Promise should all succeed.
	// The 2nd Promise was previously failing with 403 when using only a partner key.
	prefix := uniquePrefix("msgp_prp_ptnr")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	otherID := CreateTestUser(t, prefix+"_other", "User")
	msgID := CreateTestMessage(t, ownerID, prefix+" offer item", 52.5, -1.8)
	db.Exec("UPDATE messages SET fromaddr = ? WHERE id = ?", prefix+"_owner@test.com", msgID)

	key := insertTestPartnerKeyMsg(t, prefix, "test.com")
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	postAction := func(action string) int {
		body := map[string]interface{}{
			"id":     msgID,
			"action": action,
			"userid": otherID,
		}
		bodyBytes, _ := json.Marshal(body)
		postURL := fmt.Sprintf("/api/message?partner=%s", key)
		postReq := httptest.NewRequest("POST", postURL, bytes.NewBuffer(bodyBytes))
		postReq.Header.Set("Content-Type", "application/json")
		postResp, postErr := getApp().Test(postReq, -1)
		assert.NoError(t, postErr)
		return postResp.StatusCode
	}

	assert.Equal(t, 200, postAction("Promise"), "1st Promise should succeed")
	assert.Equal(t, 200, postAction("Renege"), "Renege should succeed")
	assert.Equal(t, 200, postAction("Promise"), "2nd Promise after Renege should succeed")

	var count int64
	db.Raw("SELECT COUNT(*) FROM messages_promises WHERE msgid = ? AND userid = ?", msgID, otherID).Scan(&count)
	assert.Equal(t, int64(1), count, "Promise should be recorded after 2nd Promise")
}

func TestPostMessagePromisePartnerWrongDomain(t *testing.T) {
	// Partner Promise should fail if message fromaddr is not in the partner domain.
	prefix := uniquePrefix("msgp_prm_wdom")
	db := database.DBConn

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	otherID := CreateTestUser(t, prefix+"_other", "User")
	msgID := CreateTestMessage(t, ownerID, prefix+" offer item", 52.5, -1.8)
	db.Exec("UPDATE messages SET fromaddr = ? WHERE id = ?", prefix+"_owner@other-domain.com", msgID)

	key := insertTestPartnerKeyMsg(t, prefix, "test.com")
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	body := map[string]interface{}{
		"id":     msgID,
		"action": "Promise",
		"userid": otherID,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?partner=%s", key)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 403, resp.StatusCode, "Partner Promise should fail if fromaddr not in partner domain")
}

func postMessageAction(t *testing.T, token string, body map[string]interface{}) int {
	bodyBytes, _ := json.Marshal(body)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/message?jwt=%s", token), bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	return resp.StatusCode
}
