package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// ripplingHoldFixture builds a group/poster/replier/mod, a post, a chat and one chat message,
// then attaches a rippling_held_replies row in the given state. Returns the chat id, the chat
// message id and a mod JWT. Cleanup is registered on t.
//
// heldAgoHours/releasedAgoHours are relative to NOW so a test can assert a *duration* without
// depending on wall-clock; releasedAgoHours < 0 means leave releasedat NULL.
func ripplingHoldFixture(t *testing.T, tag string, status string, heldAgoHours int, releasedAgoHours int) (uint64, uint64, string) {
	t.Helper()
	db := database.DBConn
	prefix := uniquePrefix(tag)

	// Same CREATE TABLE IF NOT EXISTS as chatmessage_rippling_held_test.go so this file can run
	// standalone (test order is not guaranteed).
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

	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: "+tag, 51.5, -0.1)
	chatID := CreateTestChatRoom(t, replierID, &posterID, nil, "User2User")

	res := db.Exec(
		"INSERT INTO chat_messages (chatid, userid, message, date, reviewrequired, processingrequired, processingsuccessful) "+
			"VALUES (?, ?, ?, NOW(), 0, 0, 1)",
		chatID, replierID, "I would like this please",
	)
	if res.Error != nil {
		t.Fatalf("failed to insert chat message: %v", res.Error)
	}
	var chatMsgID uint64
	db.Raw("SELECT id FROM chat_messages WHERE chatid = ? ORDER BY id DESC LIMIT 1", chatID).Scan(&chatMsgID)
	if chatMsgID == 0 {
		t.Fatal("failed to get chat message ID")
	}

	if releasedAgoHours >= 0 {
		db.Exec(
			"INSERT INTO rippling_held_replies (chatid, chatmsgid, msgid, replieruserid, lat, lng, status, created_at, releasedat) "+
				"VALUES (?, ?, ?, ?, 51.5, -0.1, ?, DATE_SUB(NOW(), INTERVAL ? HOUR), DATE_SUB(NOW(), INTERVAL ? HOUR))",
			chatID, chatMsgID, msgID, replierID, status, heldAgoHours, releasedAgoHours,
		)
	} else {
		db.Exec(
			"INSERT INTO rippling_held_replies (chatid, chatmsgid, msgid, replieruserid, lat, lng, status, created_at) "+
				"VALUES (?, ?, ?, ?, 51.5, -0.1, ?, DATE_SUB(NOW(), INTERVAL ? HOUR))",
			chatID, chatMsgID, msgID, replierID, status, heldAgoHours,
		)
	}

	t.Cleanup(func() {
		db.Exec("DELETE FROM rippling_held_replies WHERE chatmsgid = ?", chatMsgID)
		db.Exec("DELETE FROM chat_messages WHERE id = ?", chatMsgID)
	})

	_, modToken := CreateTestSession(t, modID)
	return chatID, chatMsgID, modToken
}

// fetchChatMessage returns the JSON object for chatMsgID from GET /api/chat/:id/message.
func fetchChatMessage(t *testing.T, chatID uint64, chatMsgID uint64, token string) map[string]interface{} {
	t.Helper()
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/chat/%d/message?jwt=%s", chatID, token), nil)
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
		if idFloat, ok := m["id"].(float64); ok && uint64(idFloat) == chatMsgID {
			return m
		}
	}
	t.Fatalf("chat message id=%d not found in response", chatMsgID)
	return nil
}

// TestRipplingHold_ReleasedReportsDelay is the case behind the "delayed message between TN
// member and Freegle member" reports: the hold released long ago, so heldbyrippling is false
// and today a mod sees NOTHING - no way to tell the reply sat undelivered for two days. The
// ripplinghold object must survive release and carry how long the delay actually was.
func TestRipplingHold_ReleasedReportsDelay(t *testing.T) {
	chatID, chatMsgID, modToken := ripplingHoldFixture(t, "rhrelease", "released", 47, 0)

	m := fetchChatMessage(t, chatID, chatMsgID, modToken)

	// Back-compat: heldbyrippling stays false once released (the member-facing
	// "waiting to send" indicator must not reappear).
	held, _ := m["heldbyrippling"].(bool)
	assert.False(t, held, "released hold must not set heldbyrippling")

	hold, ok := m["ripplinghold"].(map[string]interface{})
	assert.True(t, ok, "released hold must still expose ripplinghold so the delay is visible")
	if !ok {
		return
	}
	assert.Equal(t, "released", hold["status"])
	delivered, _ := hold["delivered"].(bool)
	assert.True(t, delivered, "a released hold was eventually delivered")

	mins, _ := hold["heldminutes"].(float64)
	assert.InDelta(t, 47*60, mins, 90, "heldminutes should measure created_at -> releasedat (~47h)")
	assert.NotEmpty(t, hold["heldat"], "heldat should be present")
	assert.NotEmpty(t, hold["releasedat"], "releasedat should be present once released")
}

// TestRipplingHold_TakenGoneIsNotHeldAndNotDelivered pins the bug: 'taken-gone' means the item
// went while the reply was held, so it was NEVER delivered and never will be. It must not read
// as "held" (which tells a mod, and the sender, that it is still on its way).
func TestRipplingHold_TakenGoneIsNotHeldAndNotDelivered(t *testing.T) {
	chatID, chatMsgID, modToken := ripplingHoldFixture(t, "rhgone", "taken-gone", 30, 2)

	m := fetchChatMessage(t, chatID, chatMsgID, modToken)

	held, _ := m["heldbyrippling"].(bool)
	assert.False(t, held,
		"taken-gone is terminal, not held: heldbyrippling must be false or the UI promises delivery that never comes")

	hold, ok := m["ripplinghold"].(map[string]interface{})
	assert.True(t, ok, "taken-gone must expose ripplinghold")
	if !ok {
		return
	}
	assert.Equal(t, "taken-gone", hold["status"])
	delivered, _ := hold["delivered"].(bool)
	assert.False(t, delivered, "taken-gone was never delivered")
}

// TestRipplingHold_DroppedIsNotHeld - same for 'dropped'.
func TestRipplingHold_DroppedIsNotHeld(t *testing.T) {
	chatID, chatMsgID, modToken := ripplingHoldFixture(t, "rhdrop", "dropped", 5, 1)

	m := fetchChatMessage(t, chatID, chatMsgID, modToken)

	held, _ := m["heldbyrippling"].(bool)
	assert.False(t, held, "dropped is terminal, not held")

	hold, ok := m["ripplinghold"].(map[string]interface{})
	assert.True(t, ok, "dropped must expose ripplinghold")
	if !ok {
		return
	}
	assert.Equal(t, "dropped", hold["status"])
	delivered, _ := hold["delivered"].(bool)
	assert.False(t, delivered, "dropped was never delivered")
}

// TestRipplingHold_StillHeldCountsElapsed - a live hold reports how long it has been waiting
// so far, with releasedat absent.
func TestRipplingHold_StillHeldCountsElapsed(t *testing.T) {
	chatID, chatMsgID, modToken := ripplingHoldFixture(t, "rhheld", "held", 3, -1)

	m := fetchChatMessage(t, chatID, chatMsgID, modToken)

	held, _ := m["heldbyrippling"].(bool)
	assert.True(t, held, "a live hold still sets heldbyrippling")

	hold, ok := m["ripplinghold"].(map[string]interface{})
	assert.True(t, ok, "live hold must expose ripplinghold")
	if !ok {
		return
	}
	assert.Equal(t, "held", hold["status"])
	delivered, _ := hold["delivered"].(bool)
	assert.False(t, delivered, "a still-held reply has not been delivered")

	mins, _ := hold["heldminutes"].(float64)
	assert.InDelta(t, 3*60, mins, 30, "heldminutes should measure created_at -> now while still held")
	assert.Nil(t, hold["releasedat"], "releasedat must be absent while still held")
}

// TestRipplingHold_NotExposedToNonMod - hold internals (status names, durations) are a
// moderation tool, not member-facing. The sender keeps heldbyrippling for their own reply.
func TestRipplingHold_NotExposedToNonMod(t *testing.T) {
	db := database.DBConn
	chatID, chatMsgID, _ := ripplingHoldFixture(t, "rhnonmod", "held", 3, -1)

	// Fetch as the replier (the sender of the held message), who is not a mod.
	var replierID uint64
	db.Raw("SELECT userid FROM chat_messages WHERE id = ?", chatMsgID).Scan(&replierID)
	_, replierToken := CreateTestSession(t, replierID)

	m := fetchChatMessage(t, chatID, chatMsgID, replierToken)

	held, _ := m["heldbyrippling"].(bool)
	assert.True(t, held, "sender still sees heldbyrippling on their own held reply")

	_, ok := m["ripplinghold"].(map[string]interface{})
	assert.False(t, ok, "ripplinghold detail is mod-only")
}
