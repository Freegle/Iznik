package test

import (
	json2 "encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/chat"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// The member list shows a chat between two members only if it has had a message in the last CHAT_ACTIVE_LIMIT days
// (31), whichever chat types are asked for. The website always asks for User2User and User2Mod together, and the
// longer window is for the chats with the volunteers, so asking for both must not stretch the window for the chats
// between members.
func TestChatListWindowForUser2UserIsTheSameWhenUser2ModIsAskedFor(t *testing.T) {
	prefix := uniquePrefix("u2uwindow")
	db := database.DBConn

	memberID := CreateTestUser(t, prefix+"_member", utils.SYSTEMROLE_USER)
	_, token := CreateTestSession(t, memberID)
	otherID := CreateTestUser(t, prefix+"_other", utils.SYSTEMROLE_USER)
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, memberID, groupID, utils.ROLE_MEMBER)

	backdate := func(chatID uint64, userID uint64) {
		db.Exec("INSERT INTO chat_messages (chatid, userid, message, date, reviewrequired, processingrequired, processingsuccessful) "+
			"VALUES (?, ?, 'old message', DATE_SUB(NOW(), INTERVAL 90 DAY), 0, 0, 1)", chatID, userID)
		db.Exec("UPDATE chat_rooms SET latestmessage = DATE_SUB(NOW(), INTERVAL 90 DAY) WHERE id = ?", chatID)
	}

	u2uChat := CreateTestChatRoom(t, memberID, &otherID, nil, "User2User")
	backdate(u2uChat, memberID)
	u2mChat := CreateTestChatRoom(t, memberID, nil, &groupID, "User2Mod")
	backdate(u2mChat, memberID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/chat?jwt="+token+"&chattypes[]=User2User&chattypes[]=User2Mod", nil))
	assert.Equal(t, 200, resp.StatusCode)
	var chats []chat.ChatRoomListEntry
	json2.Unmarshal(rsp(resp), &chats)

	found := map[uint64]bool{}
	for _, c := range chats {
		found[c.ID] = true
	}

	assert.False(t, found[u2uChat], "a chat between members that is 90 days quiet is not listed, with or without User2Mod asked for")
	assert.True(t, found[u2mChat], "a chat with the volunteers keeps its longer window")
}
