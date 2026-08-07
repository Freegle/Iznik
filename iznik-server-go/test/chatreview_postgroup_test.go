package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// The chat review queue tells a moderator which group lets them ACT on a chat -
// the group the other member belongs to. It never said which community the POST
// is on, and those are routinely different. A moderator of many communities had
// to open each chat to work out whether it was theirs to handle:
//
//	"the problem chat might refer to an item that isn't on any of my groups and
//	 I usually prefer to leave that for the mods on the group for the post. But
//	 I need to do a lot of clicking to work that out." - Discourse #10004
//
// So each reviewable message that refers to a post now carries refmsggroup.
func TestChatReview_CarriesTheGroupThePostIsOn(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("reviewpostgroup")

	// The moderator's own group, and a DIFFERENT community hosting the post.
	modGroup := CreateTestGroup(t, prefix+"_mod")
	postGroup := CreateTestGroup(t, prefix+"_post")

	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, modGroup, "Moderator")
	_, token := CreateTestSession(t, modID)

	// The replier is on the moderator's group - that is what puts this chat in
	// their queue at all.
	replierID := CreateTestUser(t, prefix+"_replier", "User")
	CreateTestMembership(t, replierID, modGroup, "Member")

	// The post lives on the OTHER community.
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	CreateTestMembership(t, posterID, postGroup, "Member")
	msgID := CreateTestMessage(t, posterID, postGroup, "OFFER: sofa (review group test)", 51.5, -0.1)

	chatID := CreateTestChatRoom(t, posterID, &replierID, nil, "User2User")
	res := db.Exec(
		"INSERT INTO chat_messages (chatid, userid, message, type, date, refmsgid, "+
			"reviewrequired, reviewrejected, processingrequired, processingsuccessful) "+
			"VALUES (?, ?, 'Is this still available?', 'Default', NOW(), ?, 1, 0, 0, 1)",
		chatID, posterID, msgID,
	)
	if res.Error != nil {
		t.Fatalf("could not create the reviewable message: %v", res.Error)
	}
	var chatMsgID uint64
	db.Raw("SELECT id FROM chat_messages WHERE chatid = ? ORDER BY id DESC LIMIT 1", chatID).Scan(&chatMsgID)
	if chatMsgID == 0 {
		t.Fatal("reviewable message created but id not found")
	}
	defer db.Exec("DELETE FROM chat_messages WHERE id = ?", chatMsgID)

	resp, err := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/chatmessages?jwt=%s", token), nil), 20000)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var body map[string]interface{}
	json.Unmarshal(rsp(resp), &body)
	msgs, _ := body["chatmessages"].([]interface{})

	var mine map[string]interface{}
	for _, raw := range msgs {
		if m, ok := raw.(map[string]interface{}); ok {
			if id, ok := m["id"].(float64); ok && uint64(id) == chatMsgID {
				mine = m
			}
		}
	}
	if mine == nil {
		t.Fatalf("message %d not in the review queue - the fixture is wrong, so the "+
			"assertion below would prove nothing", chatMsgID)
	}

	group, ok := mine["refmsggroup"].(map[string]interface{})
	assert.True(t, ok, "a reviewable message about a post carries the post's group")
	if ok {
		assert.Equal(t, float64(postGroup), group["id"],
			"reports the community the POST is on, not the one that grants the mod their buttons")
		assert.NotEmpty(t, group["namedisplay"], "carries a name the UI can show")
	}
}

// A chat with no post behind it - a direct message rather than a reply to an
// offer - has no group to report, and must not invent one.
func TestChatReview_NoPostMeansNoGroup(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("reviewnopost")

	modGroup := CreateTestGroup(t, prefix+"_mod")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, modGroup, "Moderator")
	_, token := CreateTestSession(t, modID)

	senderID := CreateTestUser(t, prefix+"_sender", "User")
	recipientID := CreateTestUser(t, prefix+"_recipient", "User")
	CreateTestMembership(t, recipientID, modGroup, "Member")

	chatID := CreateTestChatRoom(t, senderID, &recipientID, nil, "User2User")
	db.Exec(
		"INSERT INTO chat_messages (chatid, userid, message, type, date, "+
			"reviewrequired, reviewrejected, processingrequired, processingsuccessful) "+
			"VALUES (?, ?, 'Hello there', 'Default', NOW(), 1, 0, 0, 1)",
		chatID, senderID,
	)
	var chatMsgID uint64
	db.Raw("SELECT id FROM chat_messages WHERE chatid = ? ORDER BY id DESC LIMIT 1", chatID).Scan(&chatMsgID)
	defer db.Exec("DELETE FROM chat_messages WHERE id = ?", chatMsgID)

	resp, _ := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/chatmessages?jwt=%s", token), nil), 20000)
	var body map[string]interface{}
	json.Unmarshal(rsp(resp), &body)
	msgs, _ := body["chatmessages"].([]interface{})

	for _, raw := range msgs {
		if m, ok := raw.(map[string]interface{}); ok {
			if id, ok := m["id"].(float64); ok && uint64(id) == chatMsgID {
				_, present := m["refmsggroup"]
				assert.False(t, present, "a chat about no post carries no post group")
			}
		}
	}
}
