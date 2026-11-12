package test

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
)

// TestCreateChatMessage_ServerOwnedFlagsIgnored verifies that a new chat message is always
// created unprocessed and unreviewed, whatever the request body says. The flags that gate
// delivery (processingsuccessful, reviewrequired, reviewrejected) and notification
// (mailedtoall, seenbyall) belong to chats:process-incoming. If a sender could set them, the
// recipient would see the message before its spam check and before any confirm-reply hold.
func TestCreateChatMessage_ServerOwnedFlagsIgnored(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("srvowned")

	senderID := CreateTestUser(t, prefix+"_sender", utils.SYSTEMROLE_USER)
	recipientID := CreateTestUser(t, prefix+"_recipient", utils.SYSTEMROLE_USER)
	chatID := CreateTestChatRoom(t, senderID, &recipientID, nil, "User2User")
	_, senderToken := CreateTestSession(t, senderID)
	_, recipientToken := CreateTestSession(t, recipientID)

	body, _ := json.Marshal(map[string]interface{}{
		"message":              "hello there",
		"processingsuccessful": true,
		"reviewrequired":       false,
		"reviewrejected":       true,
		"mailedtoall":          true,
		"seenbyall":            true,
		"replyreceived":        true,
	})
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/chat/%d/message?jwt=%s", chatID, senderToken), bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)

	var row struct {
		ID                   uint64
		Processingrequired   bool
		Processingsuccessful bool
		Reviewrejected       bool
		Mailedtoall          bool
		Seenbyall            bool
		Replyreceived        bool
	}
	db.Raw("SELECT id, processingrequired, processingsuccessful, reviewrejected, mailedtoall, seenbyall, replyreceived "+
		"FROM chat_messages WHERE chatid = ? AND userid = ? ORDER BY id DESC LIMIT 1", chatID, senderID).Scan(&row)
	if row.ID == 0 {
		t.Fatal("message was not created")
	}

	assert.True(t, row.Processingrequired, "a new message waits for processing")
	assert.False(t, row.Processingsuccessful, "a sender cannot mark their own message as processed")
	assert.False(t, row.Reviewrejected, "a sender cannot set the review verdict")
	assert.False(t, row.Mailedtoall, "a sender cannot mark the notification email as sent")
	assert.False(t, row.Seenbyall, "a sender cannot mark the message as seen")
	assert.False(t, row.Replyreceived, "a sender cannot mark a reply as received")

	// The recipient must not see the message until it has been processed.
	req = httptest.NewRequest("GET", fmt.Sprintf("/api/chat/%d/message?jwt=%s", chatID, recipientToken), nil)
	resp, _ = getApp().Test(req, -1)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)

	var messages []map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&messages)
	assert.Equal(t, 0, len(messages), "the recipient does not see an unprocessed message")
}
