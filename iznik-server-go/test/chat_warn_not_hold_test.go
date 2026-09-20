package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/chat"
	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// A chat message the content check has held (reviewrequired=1) is today invisible to the
// recipient until a moderator approves it. With CHAT_WARN_NOT_HOLD on, it is delivered with a
// member-facing `sensitive` reason so the client can show it behind a warning instead.

func insertHeldChatMessage(t *testing.T, chatID, userID uint64, text string, reportreason string, rejected int) uint64 {
	db := database.DBConn
	res := db.Exec(
		"INSERT INTO chat_messages (chatid, userid, message, date, reviewrequired, reviewrejected, reportreason, processingrequired, processingsuccessful) "+
			"VALUES (?, ?, ?, NOW(), 1, ?, ?, 0, 1)",
		chatID, userID, text, rejected, reportreason,
	)
	if res.Error != nil {
		t.Fatalf("failed to insert held chat message: %v", res.Error)
	}
	var id uint64
	db.Raw("SELECT id FROM chat_messages WHERE chatid = ? AND message = ? ORDER BY id DESC LIMIT 1", chatID, text).Scan(&id)
	if id == 0 {
		t.Fatal("failed to read back held chat message id")
	}
	return id
}

func fetchChatMessagesAs(t *testing.T, chatID uint64, token string) map[uint64]map[string]interface{} {
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/chat/%d/message?jwt=%s", chatID, token), nil)
	resp, err := getApp().Test(req, -1)
	if err != nil {
		t.Fatalf("request failed: %v", err)
	}
	assert.Equal(t, 200, resp.StatusCode)

	var body []map[string]interface{}
	if err := json.NewDecoder(resp.Body).Decode(&body); err != nil {
		t.Fatalf("failed to decode response: %v", err)
	}
	byID := map[uint64]map[string]interface{}{}
	for _, m := range body {
		if idf, ok := m["id"].(float64); ok {
			byID[uint64(idf)] = m
		}
	}
	return byID
}

func setupWarnNotHoldChat(t *testing.T, prefix string) (senderID, recipientID, chatID uint64) {
	groupID := CreateTestGroup(t, prefix)
	senderID = CreateTestUser(t, prefix+"_sender", "User")
	recipientID = CreateTestUser(t, prefix+"_recipient", "User")
	CreateTestMembership(t, senderID, groupID, "Member")
	CreateTestMembership(t, recipientID, groupID, "Member")
	chatID = CreateTestChatRoom(t, senderID, &recipientID, nil, "User2User")
	return
}

func TestChatWarnNotHold_OffHidesHeldMessage(t *testing.T) {
	t.Setenv("CHAT_WARN_NOT_HOLD", "0")
	db := database.DBConn
	prefix := uniquePrefix("warnoff")
	senderID, recipientID, chatID := setupWarnNotHoldChat(t, prefix)

	heldID := insertHeldChatMessage(t, chatID, senderID, "Send me £20 first", "Money", 0)
	defer db.Exec("DELETE FROM chat_messages WHERE id = ?", heldID)

	_, recipientToken := CreateTestSession(t, recipientID)
	got := fetchChatMessagesAs(t, chatID, recipientToken)
	_, seen := got[heldID]
	assert.False(t, seen, "with the flag off a held message must stay hidden from the recipient")
}

func TestChatWarnNotHold_OnDeliversWithReason(t *testing.T) {
	t.Setenv("CHAT_WARN_NOT_HOLD", "1")
	db := database.DBConn
	prefix := uniquePrefix("warnon")
	senderID, recipientID, chatID := setupWarnNotHoldChat(t, prefix)

	heldID := insertHeldChatMessage(t, chatID, senderID, "Send me £20 first", "Money", 0)
	rejectedID := insertHeldChatMessage(t, chatID, senderID, "Buy cheap pills", "Spam", 1)
	plainID := CreateTestChatMessage(t, chatID, senderID, "Is it still available?")
	db.Exec("UPDATE chat_messages SET processingrequired = 0, processingsuccessful = 1 WHERE id = ?", plainID)
	defer db.Exec("DELETE FROM chat_messages WHERE id IN (?, ?, ?)", heldID, rejectedID, plainID)

	_, recipientToken := CreateTestSession(t, recipientID)
	got := fetchChatMessagesAs(t, chatID, recipientToken)

	held, seen := got[heldID]
	if assert.True(t, seen, "with the flag on a held message is delivered to the recipient") {
		assert.Equal(t, "money", held["sensitive"], "the member-facing reason travels with the message")
		assert.Equal(t, "Send me £20 first", held["message"], "the text is delivered so the client can reveal it on tap")
	}

	_, rejectedSeen := got[rejectedID]
	assert.False(t, rejectedSeen, "a message a moderator rejected stays hidden whatever the flag")

	// A hold that comes from who the sender is, not what they wrote (a shadow ban is
	// recorded with the generic 'Spam' reason), is not a warning to tap through.
	shadowID := insertHeldChatMessage(t, chatID, senderID, "Cheap watches here", "Spam", 0)
	chainID := insertHeldChatMessage(t, chatID, senderID, "Still here", "Last", 0)
	defer db.Exec("DELETE FROM chat_messages WHERE id IN (?, ?)", shadowID, chainID)
	got = fetchChatMessagesAs(t, chatID, recipientToken)
	_, shadowSeen := got[shadowID]
	assert.False(t, shadowSeen, "a shadow-banned sender's message stays hidden")
	_, chainSeen := got[chainID]
	assert.False(t, chainSeen, "the hold that chains from it stays hidden too")

	plain, plainSeen := got[plainID]
	if assert.True(t, plainSeen) {
		_, hasSensitive := plain["sensitive"]
		assert.False(t, hasSensitive, "an ordinary message carries no sensitive reason")
	}

	_, senderToken := CreateTestSession(t, senderID)
	mine := fetchChatMessagesAs(t, chatID, senderToken)
	own, ownSeen := mine[heldID]
	if assert.True(t, ownSeen, "the sender always sees their own message") {
		_, hasSensitive := own["sensitive"]
		assert.False(t, hasSensitive, "the sender is not warned about their own message")
	}
}

func TestChatSensitiveReasonMapping(t *testing.T) {
	str := func(s string) *string { return &s }
	cases := map[string]string{
		"Money":                     "money",
		"Link":                      "link",
		"URL on DBL":                "link",
		"Email":                     "contact",
		"Language":                  "language",
		"WorryWord":                 "concern",
		"Referenced known spammer":  "scam",
		"Greetings spam":            "scam",
		"Known spam keyword":        "scam",
		"Spam":                      "checked",
		"Last":                      "checked",
		"Fully":                     "checked",
		"Something new and unknown": "checked",
	}
	for in, want := range cases {
		assert.Equal(t, want, chat.SensitiveReason(str(in)), "reportreason %q", in)
	}
	assert.Equal(t, "checked", chat.SensitiveReason(nil), "a held message with no recorded reason is still flagged")
}

func TestChatWarnNotHoldFlag(t *testing.T) {
	t.Setenv("CHAT_WARN_NOT_HOLD", "")
	assert.False(t, chat.WarnNotHold(), "off by default: this is an experiment, not the shipped behaviour")
	t.Setenv("CHAT_WARN_NOT_HOLD", "1")
	assert.True(t, chat.WarnNotHold())
	t.Setenv("CHAT_WARN_NOT_HOLD", "off")
	assert.False(t, chat.WarnNotHold())
}

func fetchChatListAs(t *testing.T, token string) map[uint64]map[string]interface{} {
	req := httptest.NewRequest("GET", "/api/chat?jwt="+token, nil)
	resp, err := getApp().Test(req, -1)
	if err != nil {
		t.Fatalf("request failed: %v", err)
	}
	assert.Equal(t, 200, resp.StatusCode)

	var body []map[string]interface{}
	if err := json.NewDecoder(resp.Body).Decode(&body); err != nil {
		t.Fatalf("failed to decode response: %v", err)
	}
	byID := map[uint64]map[string]interface{}{}
	for _, m := range body {
		if idf, ok := m["id"].(float64); ok {
			byID[uint64(idf)] = m
		}
	}
	return byID
}

// The chat list must tell the recipient there is something to read, without leaking the
// text the warning guards.
func TestChatWarnNotHold_ListCountsHeldButMasksSnippet(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("warnlist")
	senderID, recipientID, chatID := setupWarnNotHoldChat(t, prefix)

	heldID := insertHeldChatMessage(t, chatID, senderID, "Send me £20 first", "Money", 0)
	defer db.Exec("DELETE FROM chat_messages WHERE id = ?", heldID)

	_, recipientToken := CreateTestSession(t, recipientID)

	t.Setenv("CHAT_WARN_NOT_HOLD", "0")
	off := fetchChatListAs(t, recipientToken)
	_, listedOff := off[chatID]
	assert.False(t, listedOff, "with the flag off a room whose only message is held is not listed")

	t.Setenv("CHAT_WARN_NOT_HOLD", "1")
	on := fetchChatListAs(t, recipientToken)
	room, listedOn := on[chatID]
	if assert.True(t, listedOn, "with the flag on the room is listed") {
		assert.Equal(t, float64(1), room["unseen"], "the held message counts as unread")
		assert.Equal(t, chat.SensitiveSnippet, room["snippet"], "the preview does not show the held text")
	}

	_, senderToken := CreateTestSession(t, senderID)
	mine := fetchChatListAs(t, senderToken)
	own, ownListed := mine[chatID]
	if assert.True(t, ownListed) {
		assert.Equal(t, "Send me £20 first", own["snippet"], "the sender's own preview is not masked")
	}
}

// A moderator opening a member's chat for review reads the real preview, not the mask.
func TestChatWarnNotHold_ModeratorSeesRealPreview(t *testing.T) {
	t.Setenv("CHAT_WARN_NOT_HOLD", "1")
	db := database.DBConn
	prefix := uniquePrefix("warnmod")
	groupID := CreateTestGroup(t, prefix)
	senderID := CreateTestUser(t, prefix+"_sender", "User")
	recipientID := CreateTestUser(t, prefix+"_recipient", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, senderID, groupID, "Member")
	CreateTestMembership(t, recipientID, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	chatID := CreateTestChatRoom(t, senderID, &recipientID, nil, "User2User")

	heldID := insertHeldChatMessage(t, chatID, senderID, "Send me £20 first", "Money", 0)
	defer db.Exec("DELETE FROM chat_messages WHERE id = ?", heldID)

	_, modToken := CreateTestSession(t, modID)
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/chat/%d?jwt=%s", chatID, modToken), nil)
	resp, err := getApp().Test(req, -1)
	if err != nil {
		t.Fatalf("request failed: %v", err)
	}
	assert.Equal(t, 200, resp.StatusCode)
	var room map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&room)
	assert.Equal(t, "Send me £20 first", room["snippet"], "the moderator reads what was written")

	_, recipientToken := CreateTestSession(t, recipientID)
	mine := fetchChatListAs(t, recipientToken)
	if r, ok := mine[chatID]; assert.True(t, ok) {
		assert.Equal(t, chat.SensitiveSnippet, r["snippet"], "the member still gets the mask")
	}
}
