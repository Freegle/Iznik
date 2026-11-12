package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

// Each ReportNoGroup call queues an email to the central team, so the same report sent again is not sent twice.
func TestReportNoGroupRepeatedReportIsQueuedOnce(t *testing.T) {
	prefix := uniquePrefix("reportnogroup_twice")
	db := database.DBConn
	user1ID := CreateTestUser(t, prefix+"_u1", utils.SYSTEMROLE_USER)
	user2ID := CreateTestUser(t, prefix+"_u2", utils.SYSTEMROLE_USER)
	chatID := CreateTestChatRoom(t, user1ID, &user2ID, nil, "User2User")
	CreateTestChatMessage(t, chatID, user2ID, "Want a girlfriend?")
	_, token := CreateTestSession(t, user1ID)

	for i := 0; i < 2; i++ {
		status, _ := postChatroomAction(t, map[string]interface{}{"id": chatID, "action": "ReportNoGroup", "reason": "Spam", "comment": "creepy"}, token)
		assert.Equal(t, fiber.StatusOK, status)
	}

	var tasks int64
	db.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type = 'email_chat_spam_report' AND JSON_EXTRACT(data, '$.chatid') = ?", chatID).Scan(&tasks)
	assert.Equal(t, int64(1), tasks, "the same report is queued once")
}
