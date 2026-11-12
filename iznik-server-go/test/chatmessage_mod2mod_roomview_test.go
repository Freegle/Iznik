package test

import (
	"fmt"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/stretchr/testify/assert"
)

func getChatMessagesByRoom(t *testing.T, chatID, userID uint64) *http.Response {
	t.Helper()
	_, token := CreateTestSession(t, userID)
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/chatmessages?roomid=%d&jwt=%s", chatID, token), nil)
	resp, _ := getApp().Test(req, -1)

	return resp
}

// A Mod2Mod room is for the group's active moderators. The room view that ModTools uses (GET /chatmessages?roomid=)
// must apply the same rule as the chat list and GET /chat/:id/message, so a backup moderator gets nothing from it.
func TestGetChatMessagesByRoomMod2ModBackupModeratorIsRefused(t *testing.T) {
	_, chatID, modID, backupID, _ := mod2modFixture(t, uniquePrefix("m2mroombk"))

	assert.Equal(t, http.StatusOK, getChatMessagesByRoom(t, chatID, modID).StatusCode, "an active moderator reads the room")
	assert.Equal(t, http.StatusForbidden, getChatMessagesByRoom(t, chatID, backupID).StatusCode,
		"a backup moderator is left out of a Mod2Mod room, as everywhere else")
}
