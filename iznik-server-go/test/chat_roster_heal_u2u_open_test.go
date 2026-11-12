package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// Opening an existing chat between members puts the opener back on its roster, as opening a chat with the volunteers
// does. The chat mailer only emails people on the roster, and an old account merge moved chats without their rows.
func TestOpeningExistingUser2UserChatRestoresOpenersRosterRow(t *testing.T) {
	prefix := uniquePrefix("roster_open_u2u")
	user1 := CreateTestUser(t, prefix+"_u1", utils.SYSTEMROLE_USER)
	user2 := CreateTestUser(t, prefix+"_u2", utils.SYSTEMROLE_USER)
	chatID := CreateTestChatRoom(t, user1, &user2, nil, "User2User")
	_, token := CreateTestSession(t, user2)
	require.Equal(t, int64(0), rosterRowCount(chatID, user2))

	resp := putChatRoom(t, token, map[string]interface{}{"userid": user1, "updateRoster": true})
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)

	assert.Equal(t, int64(1), rosterRowCount(chatID, user2),
		"opening the chat must give the opener a roster row, or replies never reach them")

	var status string
	database.DBConn.Raw("SELECT status FROM chat_roster WHERE chatid = ? AND userid = ?", chatID, user2).Scan(&status)
	assert.Equal(t, utils.CHAT_STATUS_ONLINE, status)
}
