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
// So each reviewable message that refers to a post now carries refmsggroups -
// every community the post is on, origin first.
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

	groups, ok := mine["refmsggroups"].([]interface{})
	assert.True(t, ok, "a reviewable message about a post carries the post's groups")
	if ok && assert.NotEmpty(t, groups, "at least the community it was posted to") {
		first, _ := groups[0].(map[string]interface{})
		assert.Equal(t, float64(postGroup), first["id"],
			"origin community first, not the one that grants the mod their buttons")
		assert.NotEmpty(t, first["namedisplay"], "carries a name the UI can show")
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
				_, present := m["refmsggroups"]
				assert.False(t, present, "a chat about no post carries no post group")
			}
		}
	}
}

// A rippled post is on more than one community, and the moderator scanning the
// queue needs to see all of them: the whole question is "is any of these mine?",
// and reporting only the origin hides exactly the case where it is theirs
// because the post rippled to them.
//
// Origin first, then where it spread - the community that knows the post reads
// first, and the UI joins the names with commas.
func TestChatReview_RippledPostListsEveryCommunityOriginFirst(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("reviewrippled")

	modGroup := CreateTestGroup(t, prefix+"_mod")
	originGroup := CreateTestGroup(t, prefix+"_origin")
	rippledGroup := CreateTestGroup(t, prefix+"_rippled")

	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, modGroup, "Moderator")
	_, token := CreateTestSession(t, modID)

	replierID := CreateTestUser(t, prefix+"_replier", "User")
	CreateTestMembership(t, replierID, modGroup, "Member")

	posterID := CreateTestUser(t, prefix+"_poster", "User")
	CreateTestMembership(t, posterID, originGroup, "Member")
	msgID := CreateTestMessage(t, posterID, originGroup, "OFFER: bookcase (rippled review test)", 51.5, -0.1)

	// The same post, reached by rippling. rippled_in sorts after the origin's
	// NULL, which is what makes the ordering meaningful rather than incidental.
	db.Exec("INSERT INTO messages_groups (msgid, groupid, collection, arrival, rippled_in, deleted) "+
		"VALUES (?, ?, 'Approved', NOW(), 1, 0)", msgID, rippledGroup)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, rippledGroup)

	chatID := CreateTestChatRoom(t, posterID, &replierID, nil, "User2User")
	db.Exec(
		"INSERT INTO chat_messages (chatid, userid, message, type, date, refmsgid, "+
			"reviewrequired, reviewrejected, processingrequired, processingsuccessful) "+
			"VALUES (?, ?, 'Still going?', 'Default', NOW(), ?, 1, 0, 0, 1)",
		chatID, posterID, msgID,
	)
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

	groups, ok := mine["refmsggroups"].([]interface{})
	assert.True(t, ok, "a rippled post carries its communities")
	if !ok {
		return
	}

	ids := make([]float64, 0, len(groups))
	for _, g := range groups {
		if m, ok := g.(map[string]interface{}); ok {
			if id, ok := m["id"].(float64); ok {
				ids = append(ids, id)
			}
		}
	}

	assert.Len(t, ids, 2, "both the origin and the community it rippled to")
	if len(ids) == 2 {
		assert.Equal(t, float64(originGroup), ids[0], "origin first")
		assert.Equal(t, float64(rippledGroup), ids[1], "then where it spread")
	}
}
