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
