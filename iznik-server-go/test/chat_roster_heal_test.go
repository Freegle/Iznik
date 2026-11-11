package test

// A participant of a chat with no chat_roster row is never emailed: the chat
// mailer only reads people from the roster, and marking the chat read only
// updates an existing row. Old account merges moved rooms without their roster
// rows, and the member then wrote in one and never heard the volunteers' reply
// (Sentry 7797206888, chat 3274245). Opening or writing in a room you are a
// participant of must give you a row, whatever left it missing.

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func rosterRowCount(chatID uint64, userID uint64) int64 {
	var n int64
	database.DBConn.Table("chat_roster").Where("chatid = ? AND userid = ?", chatID, userID).Count(&n)
	return n
}

func postChatMessageAs(t *testing.T, token string, chatID uint64, message string) int {
	t.Helper()
	s, _ := json.Marshal(map[string]interface{}{"message": message})
	req := httptest.NewRequest("POST", "/api/chat/"+fmt.Sprint(chatID)+"/message?jwt="+token, bytes.NewBuffer(s))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	return resp.StatusCode
}

func TestOpeningExistingUser2ModChatRestoresMemberRosterRow(t *testing.T) {
	prefix := uniquePrefix("roster_open")
	memberID := CreateTestUser(t, prefix+"_member", utils.SYSTEMROLE_USER)
	chatID := CreateTestChatRoom(t, memberID, nil, "User2Mod")
	_, token := CreateTestSession(t, memberID)
	require.Equal(t, int64(0), rosterRowCount(chatID, memberID))

	resp := putChatRoom(t, token, map[string]interface{}{"chattype": "User2Mod"})
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)

	assert.Equal(t, int64(1), rosterRowCount(chatID, memberID),
		"opening their own room must give the member a roster row, or replies never reach them")
}

func TestWritingInUser2ModChatRestoresMemberRosterRow(t *testing.T) {
	prefix := uniquePrefix("roster_u2m")
	memberID := CreateTestUser(t, prefix+"_member", utils.SYSTEMROLE_USER)
	chatID := CreateTestChatRoom(t, memberID, nil, "User2Mod")
	_, token := CreateTestSession(t, memberID)

	assert.Equal(t, fiber.StatusOK, postChatMessageAs(t, token, chatID, "Why has my post not appeared?"))

	assert.Equal(t, int64(1), rosterRowCount(chatID, memberID),
		"a member who writes to the volunteers must be on the roster to be emailed their reply")
}

func TestWritingInUser2UserChatRestoresSenderRosterRow(t *testing.T) {
	prefix := uniquePrefix("roster_u2u")
	user1 := CreateTestUser(t, prefix+"_u1", utils.SYSTEMROLE_USER)
	user2 := CreateTestUser(t, prefix+"_u2", utils.SYSTEMROLE_USER)
	chatID := CreateTestChatRoom(t, user1, &user2, "User2User")
	CreateTestChatRoster(t, chatID, user1)
	_, token := CreateTestSession(t, user2)

	assert.Equal(t, fiber.StatusOK, postChatMessageAs(t, token, chatID, "Is it still available?"))

	assert.Equal(t, int64(1), rosterRowCount(chatID, user2))
	assert.Equal(t, int64(1), rosterRowCount(chatID, user1), "the other side's row is left alone")
}

func TestModWritingInMembersUser2UserChatGetsNoRosterRow(t *testing.T) {
	prefix := uniquePrefix("roster_modu2u")
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	user1 := CreateTestUser(t, prefix+"_u1", utils.SYSTEMROLE_USER)
	user2 := CreateTestUser(t, prefix+"_u2", utils.SYSTEMROLE_USER)
	chatID := CreateTestChatRoom(t, user1, &user2, "User2User")
	_, token := CreateTestSession(t, modID)

	assert.Equal(t, fiber.StatusOK, postChatMessageAs(t, token, chatID, "A note from the volunteers"))

	assert.Equal(t, int64(0), rosterRowCount(chatID, modID),
		"a moderator is not a participant of a members' chat, so must not be mailed its messages")
}
