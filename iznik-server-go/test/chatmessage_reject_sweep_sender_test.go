package test

import (
	"net/http"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// The reject sweep is spam flood prevention: it clears repeats of the same sender's text. A
// different member who happens to send the same words, in a chat the moderator could also
// reject from, is not a repeat and keeps their message waiting for review.
func TestRejectDuplicatesLeavesOtherMembersIdenticalText(t *testing.T) {
	_, _, groupID, _, msgID, modToken := setupModerationData(t)
	db := database.DBConn

	otherUserID := CreateTestUser(t, uniquePrefix(t.Name())+"_other", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, otherUserID, groupID, utils.ROLE_MEMBER)
	chatID2 := CreateTestChatRoom(t, otherUserID, nil, &groupID, "User2Mod")
	db.Exec(
		"INSERT INTO chat_messages (chatid, userid, message, date, reviewrequired, processingsuccessful) VALUES (?, ?, ?, NOW(), 1, 1)",
		chatID2, otherUserID, "Test message needing review")
	var otherMsgID uint64
	db.Raw("SELECT id FROM chat_messages WHERE chatid = ? ORDER BY id DESC LIMIT 1", chatID2).Scan(&otherMsgID)

	resp := postChatmessages(t, "/api/chatmessages", map[string]interface{}{
		"id":     msgID,
		"action": "Reject",
	}, modToken)
	assert.Equal(t, http.StatusOK, resp.StatusCode)

	var reviewRequired, reviewRejected int
	db.Raw("SELECT reviewrequired, reviewrejected FROM chat_messages WHERE id = ?", otherMsgID).Row().Scan(&reviewRequired, &reviewRejected)
	assert.Equal(t, 1, reviewRequired, "another member's identical message stays pending")
	assert.Equal(t, 0, reviewRejected, "and is not rejected")
}
