package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

// Any message in a chat the other person has closed reopens it for them, so it reappears in their list. A nudge is
// recorded as a message, and a blocked chat is left blocked.
func TestNudgeReopensAChatTheOtherPersonClosed(t *testing.T) {
	prefix := uniquePrefix("nudge_reopen")
	sender := CreateTestUser(t, prefix+"_sender", utils.SYSTEMROLE_USER)
	other := CreateTestUser(t, prefix+"_other", utils.SYSTEMROLE_USER)
	chatID := CreateTestChatRoom(t, sender, &other, nil, "User2User")
	CreateTestChatRoster(t, chatID, sender)
	CreateTestChatRoster(t, chatID, other)
	database.DBConn.Exec("UPDATE chat_roster SET status = ? WHERE chatid = ? AND userid = ?", utils.CHAT_STATUS_CLOSED, chatID, other)
	_, token := CreateTestSession(t, sender)

	status, _ := postChatroomAction(t, map[string]interface{}{"id": chatID, "action": "Nudge"}, token)
	assert.Equal(t, fiber.StatusOK, status)

	var rosterStatus string
	database.DBConn.Raw("SELECT status FROM chat_roster WHERE chatid = ? AND userid = ?", chatID, other).Scan(&rosterStatus)
	assert.NotEqual(t, utils.CHAT_STATUS_CLOSED, rosterStatus, "the chat the other person closed comes back for them")
}
