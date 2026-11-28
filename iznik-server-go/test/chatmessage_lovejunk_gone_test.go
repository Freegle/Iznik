package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// An in-app reply to a deleted post is refused with refmsg_gone, and a LoveJunk reply is the same reply: it must not
// start a chat about a post that has been removed.
func TestCreateChatMessageLoveJunkReplyToDeletedPostIsRefused(t *testing.T) {
	db := database.DBConn
	_, _, msgID, payload := loveJunkFixture(t, "ljgone")
	db.Exec("UPDATE messages SET deleted = NOW() WHERE id = ?", msgID)

	assert.Equal(t, fiber.StatusNotFound, postLoveJunk(t, payload).StatusCode)

	var ljid uint64
	db.Raw("SELECT id FROM users WHERE ljuserid = ?", *payload.Ljuserid).Scan(&ljid)
	var rooms int64
	db.Raw("SELECT COUNT(*) FROM chat_rooms WHERE user1 = ? OR user2 = ?", ljid, ljid).Scan(&rooms)
	assert.Equal(t, int64(0), rooms, "no chat is started about a deleted post")
}

// A poster who closed the chat sees a new reply, as for any other message: the closed roster row is reopened.
func TestCreateChatMessageLoveJunkReplyReopensAClosedChat(t *testing.T) {
	db := database.DBConn
	posterID, _, _, payload := loveJunkFixture(t, "ljreopen")

	resp := postLoveJunk(t, payload)
	require.Equal(t, fiber.StatusOK, resp.StatusCode)

	var chatID uint64
	db.Raw("SELECT chatid FROM chat_messages WHERE userid = (SELECT id FROM users WHERE ljuserid = ?) ORDER BY id DESC LIMIT 1",
		*payload.Ljuserid).Scan(&chatID)
	require.NotZero(t, chatID)
	db.Exec("UPDATE chat_roster SET status = ? WHERE chatid = ? AND userid = ?", utils.CHAT_STATUS_CLOSED, chatID, posterID)

	payload.Message = "Are you still there?"
	require.Equal(t, fiber.StatusOK, postLoveJunk(t, payload).StatusCode)

	var status string
	db.Raw("SELECT status FROM chat_roster WHERE chatid = ? AND userid = ?", chatID, posterID).Scan(&status)
	assert.Equal(t, utils.CHAT_STATUS_OFFLINE, status, "a reply reopens the chat the poster closed, so it reappears in their list")
}
