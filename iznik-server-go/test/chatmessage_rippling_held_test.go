package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// TestFetchChatMessages_HeldByRippling verifies that when a mod views a chat via GET /api/chat/:id/message,
// a message held by the rippling reply-hold engine (status='held' in rippling_held_replies) has
// heldbyrippling=true in the JSON response, and a message with no rippling row (or a released row) does not.
func TestFetchChatMessages_HeldByRippling(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("rippleheld")

	// Ensure the rippling_held_replies table exists (same schema as the migration).
	// CREATE TABLE IF NOT EXISTS so it coexists with any other rippling tests.
	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_held_replies (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
		chatid BIGINT UNSIGNED NOT NULL,
		chatmsgid BIGINT UNSIGNED NOT NULL,
		msgid BIGINT UNSIGNED NOT NULL,
		replieruserid BIGINT UNSIGNED NOT NULL,
		source ENUM('email','tn','web') NOT NULL DEFAULT 'email',
		lat DOUBLE,
		lng DOUBLE,
		status ENUM('held','released','dropped','taken-gone') NOT NULL DEFAULT 'held',
		created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
		releasedat TIMESTAMP NULL,
		INDEX (msgid),
		INDEX (chatid),
		INDEX (status)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	replierID := CreateTestUser(t, prefix+"_replier", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")

	CreateTestMembership(t, posterID, groupID, "Member")
	CreateTestMembership(t, replierID, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")

	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: rippling hold test", 51.5, -0.1)
	chatID := CreateTestChatRoom(t, replierID, &posterID, nil, "User2User")

	// Create a chat reply from the replier — pre-processed (processingrequired=0,
	// processingsuccessful=1) so it passes the normal review filter.
	var heldMsgID, normalMsgID uint64

	res := db.Exec(
		"INSERT INTO chat_messages (chatid, userid, message, date, reviewrequired, processingrequired, processingsuccessful) "+
			"VALUES (?, ?, ?, NOW(), 0, 0, 1)",
		chatID, replierID, "I would like this please",
	)
	if res.Error != nil {
		t.Fatalf("failed to insert held chat message: %v", res.Error)
	}
	db.Raw("SELECT id FROM chat_messages WHERE chatid = ? ORDER BY id DESC LIMIT 1", chatID).Scan(&heldMsgID)
	if heldMsgID == 0 {
		t.Fatal("failed to get held message ID")
	}

	// Insert a second normal message (no rippling row) for contrast.
	db.Exec(
		"INSERT INTO chat_messages (chatid, userid, message, date, reviewrequired, processingrequired, processingsuccessful) "+
			"VALUES (?, ?, ?, NOW(), 0, 0, 1)",
		chatID, replierID, "Another message, not rippling-held",
	)
	db.Raw("SELECT id FROM chat_messages WHERE chatid = ? AND id <> ? ORDER BY id DESC LIMIT 1",
		chatID, heldMsgID).Scan(&normalMsgID)
	if normalMsgID == 0 {
		t.Fatal("failed to get normal message ID")
	}

	// Insert the rippling hold row for heldMsgID (status='held').
	db.Exec(
		"INSERT INTO rippling_held_replies (chatid, chatmsgid, msgid, replieruserid, lat, lng, status) "+
			"VALUES (?, ?, ?, ?, 51.5, -0.1, 'held')",
		chatID, heldMsgID, msgID, replierID,
	)
	defer db.Exec("DELETE FROM rippling_held_replies WHERE chatmsgid IN (?, ?)", heldMsgID, normalMsgID)
	defer db.Exec("DELETE FROM chat_messages WHERE id IN (?, ?)", heldMsgID, normalMsgID)

	_, modToken := CreateTestSession(t, modID)

	// Fetch messages as mod (GET /api/chat/:chatID/message).
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/chat/%d/message?jwt=%s", chatID, modToken), nil)
	resp, err := getApp().Test(req, -1)
	if err != nil {
		t.Fatalf("request failed: %v", err)
	}
	assert.Equal(t, 200, resp.StatusCode)

	var body []map[string]interface{}
	if err := json.NewDecoder(resp.Body).Decode(&body); err != nil {
		t.Fatalf("failed to decode response: %v", err)
	}

	// Find heldMsgID and normalMsgID in the response.
	foundHeld := false
	foundNormal := false
	for _, m := range body {
		idFloat, ok := m["id"].(float64)
		if !ok {
			continue
		}
		id := uint64(idFloat)
		held, _ := m["heldbyrippling"].(bool)
		if id == heldMsgID {
			foundHeld = true
			assert.True(t, held, "message with active rippling hold should have heldbyrippling=true (id=%d)", heldMsgID)
		}
		if id == normalMsgID {
			foundNormal = true
			assert.False(t, held, "message without rippling hold should not have heldbyrippling=true (id=%d)", normalMsgID)
		}
	}
	assert.True(t, foundHeld, "held message should appear in mod response (id=%d)", heldMsgID)
	assert.True(t, foundNormal, "normal message should appear in mod response (id=%d)", normalMsgID)
}

// TestFetchChatMessages_HeldByRippling_ReleasedNotFlagged verifies that a message whose
// rippling_held_replies row is 'released' is NOT flagged as heldbyrippling.
func TestFetchChatMessages_HeldByRippling_ReleasedNotFlagged(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("ripplereleased")

	// Table created by the first test via IF NOT EXISTS.
	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	replierID := CreateTestUser(t, prefix+"_replier", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")

	CreateTestMembership(t, posterID, groupID, "Member")
	CreateTestMembership(t, replierID, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")

	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: rippling released test", 51.5, -0.1)
	chatID := CreateTestChatRoom(t, replierID, &posterID, nil, "User2User")

	db.Exec(
		"INSERT INTO chat_messages (chatid, userid, message, date, reviewrequired, processingrequired, processingsuccessful) "+
			"VALUES (?, ?, ?, NOW(), 0, 0, 1)",
		chatID, replierID, "Already released reply",
	)
	var releasedMsgID uint64
	db.Raw("SELECT id FROM chat_messages WHERE chatid = ? ORDER BY id DESC LIMIT 1", chatID).Scan(&releasedMsgID)
	if releasedMsgID == 0 {
		t.Fatal("failed to get released message ID")
	}

	// Insert row with status='released'.
	db.Exec(
		"INSERT INTO rippling_held_replies (chatid, chatmsgid, msgid, replieruserid, lat, lng, status, releasedat) "+
			"VALUES (?, ?, ?, ?, 51.5, -0.1, 'released', NOW())",
		chatID, releasedMsgID, msgID, replierID,
	)
	defer db.Exec("DELETE FROM rippling_held_replies WHERE chatmsgid = ?", releasedMsgID)
	defer db.Exec("DELETE FROM chat_messages WHERE id = ?", releasedMsgID)

	_, modToken := CreateTestSession(t, modID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/chat/%d/message?jwt=%s", chatID, modToken), nil)
	resp, err := getApp().Test(req, -1)
	if err != nil {
		t.Fatalf("request failed: %v", err)
	}
	assert.Equal(t, 200, resp.StatusCode)

	var body []map[string]interface{}
	if err := json.NewDecoder(resp.Body).Decode(&body); err != nil {
		t.Fatalf("failed to decode response: %v", err)
	}

	for _, m := range body {
		idFloat, ok := m["id"].(float64)
		if !ok {
			continue
		}
		if uint64(idFloat) == releasedMsgID {
			held, _ := m["heldbyrippling"].(bool)
			assert.False(t, held, "message with released rippling row should NOT have heldbyrippling=true")
			return
		}
	}
	t.Errorf("released message id=%d not found in response", releasedMsgID)
}

// TestGetChatMessagesForRoom_ParticipantHeldReplyExcluded verifies that the
// GET /api/chatmessages?roomid=X endpoint (getChatMessagesForRoom) enforces
// the same rippling hold gate as GET /api/chat/:id/message when the caller is
// a chat participant, not a moderator.  Before the fix, a participant could
// call this endpoint to read a held-but-not-released reply early.
func TestGetChatMessagesForRoom_ParticipantHeldReplyExcluded(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("roomheldpart")

	// Ensure the rippling_held_replies table exists.
	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_held_replies (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
		chatid BIGINT UNSIGNED NOT NULL,
		chatmsgid BIGINT UNSIGNED NOT NULL,
		msgid BIGINT UNSIGNED NOT NULL,
		replieruserid BIGINT UNSIGNED NOT NULL,
		lat DOUBLE,
		lng DOUBLE,
		status ENUM('held','released','dropped','taken-gone') NOT NULL DEFAULT 'held',
		created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
		releasedat TIMESTAMP NULL,
		INDEX (msgid),
		INDEX (chatid),
		INDEX (status)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	replierID := CreateTestUser(t, prefix+"_replier", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	CreateTestMembership(t, replierID, groupID, "Member")

	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: room held reply test", 51.5, -0.1)
	chatID := CreateTestChatRoom(t, replierID, &posterID, nil, "User2User")

	// Insert a held message from the replier (processingrequired=0, processingsuccessful=1
	// so it passes the basic review filter but should be blocked by the rippling hold gate).
	var heldMsgID, normalMsgID uint64
	db.Exec(
		"INSERT INTO chat_messages (chatid, userid, message, date, reviewrequired, processingrequired, processingsuccessful) "+
			"VALUES (?, ?, ?, NOW(), 0, 0, 1)",
		chatID, replierID, "Held reply via roomid path",
	)
	db.Raw("SELECT id FROM chat_messages WHERE chatid = ? ORDER BY id DESC LIMIT 1", chatID).Scan(&heldMsgID)
	if heldMsgID == 0 {
		t.Fatal("failed to get held message ID")
	}

	// Insert a normal message (no rippling row) that should still be visible.
	db.Exec(
		"INSERT INTO chat_messages (chatid, userid, message, date, reviewrequired, processingrequired, processingsuccessful) "+
			"VALUES (?, ?, ?, NOW(), 0, 0, 1)",
		chatID, replierID, "Normal reply via roomid path",
	)
	db.Raw("SELECT id FROM chat_messages WHERE chatid = ? AND id <> ? ORDER BY id DESC LIMIT 1",
		chatID, heldMsgID).Scan(&normalMsgID)
	if normalMsgID == 0 {
		t.Fatal("failed to get normal message ID")
	}

	// Insert the rippling hold row for heldMsgID (status='held').
	db.Exec(
		"INSERT INTO rippling_held_replies (chatid, chatmsgid, msgid, replieruserid, lat, lng, status) "+
			"VALUES (?, ?, ?, ?, 51.5, -0.1, 'held')",
		chatID, heldMsgID, msgID, replierID,
	)
	defer db.Exec("DELETE FROM rippling_held_replies WHERE chatmsgid IN (?, ?)", heldMsgID, normalMsgID)
	defer db.Exec("DELETE FROM chat_messages WHERE id IN (?, ?)", heldMsgID, normalMsgID)

	// Fetch as the POSTER (participant, not the sender of the held message).
	_, posterToken := CreateTestSession(t, posterID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/chatmessages?roomid=%d&jwt=%s", chatID, posterToken), nil)
	resp, err := getApp().Test(req, -1)
	if err != nil {
		t.Fatalf("request failed: %v", err)
	}
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	if err := json.NewDecoder(resp.Body).Decode(&result); err != nil {
		t.Fatalf("failed to decode response: %v", err)
	}
	assert.Equal(t, float64(0), result["ret"])

	msgs, ok := result["chatmessages"].([]interface{})
	if !ok {
		msgs = []interface{}{}
	}

	foundHeld := false
	foundNormal := false
	for _, raw := range msgs {
		m, ok := raw.(map[string]interface{})
		if !ok {
			continue
		}
		idFloat, ok := m["id"].(float64)
		if !ok {
			continue
		}
		id := uint64(idFloat)
		if id == heldMsgID {
			foundHeld = true
		}
		if id == normalMsgID {
			foundNormal = true
		}
	}

	assert.False(t, foundHeld, "held rippling reply should be hidden from participant via roomid path (id=%d)", heldMsgID)
	assert.True(t, foundNormal, "normal message should be visible to participant via roomid path (id=%d)", normalMsgID)
}
