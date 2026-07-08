package test

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// TestGetChatMessages_ModSeesDeletedUserMessages verifies that a moderator viewing
// a chat via GET /chat/{id}/message can see messages from soft-deleted users.
//
// Scenario: phisher sends a scam message then deletes their account. The moderator
// must still see the message when clicking "View Chat" in the ModTools review queue.
// V1 PHP did NOT filter on users.deleted in this path; V2 incorrectly excluded them.
func TestGetChatMessages_ModSeesDeletedUserMessages(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("modviewdel")

	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	victimID := CreateTestUser(t, prefix+"_victim", "User")
	phisherID := CreateTestUser(t, prefix+"_phisher", "User")

	CreateTestMembership(t, modID, groupID, "Moderator")
	CreateTestMembership(t, victimID, groupID, "Member")
	// phisher has no membership (already unsubscribed)

	// User2User chat: victim initiated, phisher replied with scam
	chatID := CreateTestChatRoom(t, victimID, &phisherID, nil, "User2User")

	db.Exec(
		"INSERT INTO chat_messages (chatid, userid, message, date, reviewrequired, reviewrejected, processingsuccessful) "+
			"VALUES (?, ?, 'Click here for free prize!', NOW(), 1, 0, 1)",
		chatID, phisherID,
	)
	var msgID uint64
	db.Raw(
		"SELECT id FROM chat_messages WHERE chatid = ? AND userid = ? ORDER BY id DESC LIMIT 1",
		chatID, phisherID,
	).Scan(&msgID)
	if msgID == 0 {
		t.Fatal("Failed to create phisher's chat message")
	}

	// Phisher immediately deletes their account after sending the scam message
	db.Exec("UPDATE users SET deleted = NOW() WHERE id = ?", phisherID)

	_, modToken := CreateTestSession(t, modID)
	req := httptest.NewRequest(
		"GET",
		fmt.Sprintf("/api/chat/%d/message?jwt=%s", chatID, modToken),
		nil,
	)
	resp, _ := getApp().Test(req, -1)

	assert.Equal(t, http.StatusOK, resp.StatusCode)

	var messages []map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&messages)

	// Mod MUST see the phisher's message even though the phisher's account is deleted.
	// Without the fix, FetchChatMessages filters out messages from deleted users
	// (AND users.deleted IS NULL) even for mods, returning an empty array.
	assert.Equal(t, 1, len(messages), "moderator should see the phisher's message even after account deletion")
	if len(messages) > 0 {
		id, _ := messages[0]["id"].(float64)
		assert.Equal(t, float64(msgID), id)
	}
}

// TestGetChatMessages_HeldReplyHiddenFromPoster verifies the rippling held-reply gate on the
// in-app chat fetch: an email/TN reply from outside a post's reach is held
// (rippling_held_replies, status='held') and must NOT reach the poster early. The PHP
// notification paths honour this; FetchChatMessages must too, or the poster reads the held
// reply here once chats:process-incoming flips processingsuccessful. The replier (sender)
// still sees their own message; releasing the hold reveals it to the poster.
func TestGetChatMessages_HeldReplyHiddenFromPoster(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("heldreply")

	// Self-sufficient: rippling_held_replies belongs to PR C. Minimal stand-in for isolation.
	db.Exec("CREATE TABLE IF NOT EXISTS rippling_held_replies (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, " +
		"chatid BIGINT UNSIGNED, chatmsgid BIGINT UNSIGNED, msgid BIGINT UNSIGNED, replieruserid BIGINT UNSIGNED, " +
		"source ENUM('email','tn','web') NOT NULL DEFAULT 'email', " +
		"lat DOUBLE NULL, lng DOUBLE NULL, status VARCHAR(20) DEFAULT 'held', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)")

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	replierID := CreateTestUser(t, prefix+"_replier", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	CreateTestMembership(t, replierID, groupID, "Member")

	// The original post (messages.id) that the chat is about — required by the FK on rippling_held_replies.msgid.
	postMsgID := CreateTestMessage(t, posterID, groupID, "OFFER: Test item (heldreply)", 51.5, -0.1)

	// Poster initiated the chat; replier replied (a fully-processed, normally-visible message).
	chatID := CreateTestChatRoom(t, posterID, &replierID, nil, "User2User")
	db.Exec(
		"INSERT INTO chat_messages (chatid, userid, message, date, reviewrequired, reviewrejected, processingsuccessful) "+
			"VALUES (?, ?, 'I can collect this please', NOW(), 0, 0, 1)",
		chatID, replierID,
	)
	var msgID uint64
	db.Raw("SELECT id FROM chat_messages WHERE chatid = ? AND userid = ? ORDER BY id DESC LIMIT 1", chatID, replierID).Scan(&msgID)
	if msgID == 0 {
		t.Fatal("Failed to create replier's chat message")
	}

	// The reply is held — replier is outside the post's current reach.
	// chatmsgid references chat_messages.id; msgid references messages.id (the original post).
	db.Exec("INSERT INTO rippling_held_replies (chatid, chatmsgid, msgid, replieruserid, status, created_at) "+
		"VALUES (?, ?, ?, ?, 'held', NOW())", chatID, msgID, postMsgID, replierID)
	defer db.Exec("DELETE FROM rippling_held_replies WHERE chatmsgid = ?", msgID)

	fetchAs := func(userID uint64) []map[string]interface{} {
		_, token := CreateTestSession(t, userID)
		req := httptest.NewRequest("GET", fmt.Sprintf("/api/chat/%d/message?jwt=%s", chatID, token), nil)
		resp, _ := getApp().Test(req, -1)
		assert.Equal(t, http.StatusOK, resp.StatusCode)
		var msgs []map[string]interface{}
		json.NewDecoder(resp.Body).Decode(&msgs)
		return msgs
	}

	contains := func(msgs []map[string]interface{}, id uint64) bool {
		for _, m := range msgs {
			if mid, ok := m["id"].(float64); ok && uint64(mid) == id {
				return true
			}
		}
		return false
	}

	// Poster must NOT see the held reply; the replier (sender) sees their own message.
	assert.False(t, contains(fetchAs(posterID), msgID), "poster must not see a held reply")
	assert.True(t, contains(fetchAs(replierID), msgID), "replier still sees their own held message")

	// Releasing the hold reveals it to the poster.
	db.Exec("UPDATE rippling_held_replies SET status = 'released' WHERE chatmsgid = ?", msgID)
	assert.True(t, contains(fetchAs(posterID), msgID), "poster sees the reply once the hold is released")
}
