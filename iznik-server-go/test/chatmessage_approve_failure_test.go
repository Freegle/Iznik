package test

import (
	"fmt"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
	"gorm.io/gorm"
)

// The batch chat mailer reads the "Chat message N approved" log row as the signal that a held reply was approved and
// is to be mailed. When the update that clears the hold fails, the moderator is told so and nothing says it was
// approved: no log row, no whitelisting of the text and no change to the sender's moderation status.
func TestApproveChatMessageThatCannotBeUpdatedIsNotLoggedAsApproved(t *testing.T) {
	db := database.DBConn
	_, senderID, _, _, msgID, modToken := setupModerationData(t)

	beforeStatement(t, "chat_messages", true, func(tx *gorm.DB) {
		_ = tx.AddError(fmt.Errorf("injected: lock wait timeout exceeded"))
	})

	resp := postChatmessages(t, "/api/chatmessages", map[string]interface{}{
		"id":     msgID,
		"action": "ApproveAllFuture",
	}, modToken)

	assert.NotEqual(t, 200, resp.StatusCode, "the moderator is told the approval did not happen")

	var logged int64
	db.Raw("SELECT COUNT(*) FROM logs WHERE type = 'Chat' AND subtype = 'Approved' AND text = ?",
		fmt.Sprintf("Chat message %d approved", msgID)).Scan(&logged)
	assert.Equal(t, int64(0), logged, "no approval is logged for a message that is still held")

	var required int
	db.Raw("SELECT reviewrequired FROM chat_messages WHERE id = ?", msgID).Scan(&required)
	assert.Equal(t, 1, required, "the message is still held")

	var status string
	db.Raw("SELECT COALESCE(chatmodstatus, '') FROM users WHERE id = ?", senderID).Scan(&status)
	assert.NotEqual(t, "Unmoderated", status, "the sender is not set to unmoderated by an approval that did not happen")
}
