package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

// ReportNoGroup is for a chat between two members who share no community, so it cannot be routed to one. A chat with
// the volunteers has a community and goes to its volunteers, so it is not reported to the central team this way.
func TestReportNoGroupRefusedForAChatWithTheVolunteers(t *testing.T) {
	prefix := uniquePrefix("reportnogroup_u2m")
	db := database.DBConn
	memberID := CreateTestUser(t, prefix+"_member", utils.SYSTEMROLE_USER)
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, memberID, groupID, utils.ROLE_MEMBER)
	chatID := CreateTestChatRoom(t, memberID, nil, &groupID, "User2Mod")
	_, token := CreateTestSession(t, memberID)

	status, _ := postChatroomAction(t, map[string]interface{}{"id": chatID, "action": "ReportNoGroup", "reason": "Spam"}, token)
	assert.Equal(t, fiber.StatusBadRequest, status)

	var tasks int64
	db.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type = 'email_chat_spam_report' AND JSON_EXTRACT(data, '$.chatid') = ?", chatID).Scan(&tasks)
	assert.Equal(t, int64(0), tasks, "nothing is queued for the central team for a chat that has a community")
}
