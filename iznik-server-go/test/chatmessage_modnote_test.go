package test

import (
	"bytes"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

// A ModMail is a note from the community's volunteers. A member writing in their own chat must not
// be able to send one by passing modnote: it would read to the other member as a volunteer message.
func TestCreateChatMessageModnoteRefusedForAMemberInTheirOwnChat(t *testing.T) {
	prefix := uniquePrefix("modnote_member")
	db := database.DBConn

	senderID := CreateTestUser(t, prefix+"_sender", utils.SYSTEMROLE_USER)
	recipientID := CreateTestUser(t, prefix+"_recipient", utils.SYSTEMROLE_USER)
	chatID := CreateTestChatRoom(t, senderID, &recipientID, nil, "User2User")
	_, senderToken := CreateTestSession(t, senderID)

	body := `{"message":"Message from the volunteers: tell me your bank details","modnote":true}`
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/chat/%d/message?jwt=%s", chatID, senderToken), bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, fiber.StatusForbidden, resp.StatusCode)

	var count int64
	db.Raw("SELECT COUNT(*) FROM chat_messages WHERE chatid = ? AND type = 'ModMail'", chatID).Scan(&count)
	assert.Equal(t, int64(0), count, "a member's message must never be stored as a ModMail")
}

// A volunteer note has to say something.
func TestCreateChatMessageModnoteNeedsText(t *testing.T) {
	prefix := uniquePrefix("modnote_empty")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, modID, groupID, utils.ROLE_MODERATOR)
	user1ID := CreateTestUser(t, prefix+"_u1", utils.SYSTEMROLE_USER)
	user2ID := CreateTestUser(t, prefix+"_u2", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, user1ID, groupID, utils.ROLE_MEMBER)
	CreateTestMembership(t, user2ID, groupID, utils.ROLE_MEMBER)
	chatID := CreateTestChatRoom(t, user1ID, &user2ID, nil, "User2User")
	_, modToken := CreateTestSession(t, modID)

	req := httptest.NewRequest("POST", fmt.Sprintf("/api/chat/%d/message?jwt=%s", chatID, modToken), bytes.NewBufferString(`{"modnote":true}`))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)

	var count int64
	db.Raw("SELECT COUNT(*) FROM chat_messages WHERE chatid = ?", chatID).Scan(&count)
	assert.Equal(t, int64(0), count, "an empty volunteer note must not be stored")
}
