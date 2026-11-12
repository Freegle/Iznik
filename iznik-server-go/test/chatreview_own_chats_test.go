package test

import (
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/group"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// A moderator may not act on a chat they are one of the two people in, so the review queue must not
// list those messages to them, and the work count behind the badge must agree with the list. Another
// moderator of the same community still sees the message.
func TestChatReviewLeavesAModeratorsOwnChatsOutOfTheirOwnQueue(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("reviewown")

	groupID := CreateTestGroup(t, prefix)
	modA := CreateTestUser(t, prefix+"_moda", utils.SYSTEMROLE_USER)
	modB := CreateTestUser(t, prefix+"_modb", utils.SYSTEMROLE_USER)
	sender := CreateTestUser(t, prefix+"_sender", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, modA, groupID, utils.ROLE_MODERATOR)
	CreateTestMembership(t, modB, groupID, utils.ROLE_MODERATOR)
	CreateTestMembership(t, sender, groupID, utils.ROLE_MEMBER)
	_, tokenA := CreateTestSession(t, modA)
	_, tokenB := CreateTestSession(t, modB)

	// A member writes to moderator A, and the message is held for review.
	chatID := CreateTestChatRoom(t, sender, &modA, nil, "User2User")
	db.Exec("INSERT INTO chat_messages (chatid, userid, message, type, date, reviewrequired, reviewrejected, "+
		"processingrequired, processingsuccessful) VALUES (?, ?, 'Write to me at someone@example.com', 'Default', NOW(), 1, 0, 0, 1)",
		chatID, sender)
	var msgID uint64
	db.Raw("SELECT id FROM chat_messages WHERE chatid = ? ORDER BY id DESC LIMIT 1", chatID).Scan(&msgID)
	if msgID == 0 {
		t.Fatal("could not create the reviewable message")
	}
	defer db.Exec("DELETE FROM chat_messages WHERE id = ?", msgID)

	inQueue := func(token string) bool {
		resp, err := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/chatmessages?jwt=%s", token), nil), 20000)
		assert.NoError(t, err)
		assert.Equal(t, 200, resp.StatusCode)
		var body map[string]interface{}
		json2.Unmarshal(rsp(resp), &body)
		msgs, _ := body["chatmessages"].([]interface{})
		for _, raw := range msgs {
			if m, ok := raw.(map[string]interface{}); ok {
				if id, ok := m["id"].(float64); ok && uint64(id) == msgID {
					return true
				}
			}
		}
		return false
	}
	reviewCount := func(token string) int64 {
		resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/group/work?jwt="+token, nil))
		assert.Equal(t, 200, resp.StatusCode)
		var result []group.GroupWork
		json2.Unmarshal(rsp(resp), &result)
		for _, w := range result {
			if w.Groupid == groupID {
				return w.Chatreview + w.Chatreviewother
			}
		}
		return 0
	}

	assert.True(t, inQueue(tokenB), "the other moderator sees the message")
	assert.EqualValues(t, 1, reviewCount(tokenB), "and counts it")

	assert.False(t, inQueue(tokenA), "the recipient moderator does not see a message in their own chat")
	assert.EqualValues(t, 0, reviewCount(tokenA), "and does not count it")
}
