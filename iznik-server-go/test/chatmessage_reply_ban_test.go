package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

// A member banned from the only community a post is on cannot reply to it in the app, as they cannot through a
// LoveJunk link. A ban removes their membership, so the batch's banned-in-common-groups check has no common group to
// look at and delivers the reply.
func TestCreateChatMessage_ReplyRefusedWhenBannedFromTheOnlyGroupOfThePost(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("replybanone")

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, posterID, groupID, utils.ROLE_MEMBER)
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: reply-banned-one test item", 51.5, -0.1)

	bannedID := CreateTestUser(t, prefix+"_banned", utils.SYSTEMROLE_USER)
	db.Exec("INSERT INTO users_banned (userid, groupid, byuser, date) VALUES (?, ?, ?, NOW())", bannedID, groupID, posterID)
	t.Cleanup(func() { db.Exec("DELETE FROM users_banned WHERE userid = ?", bannedID) })

	assert.Equal(t, fiber.StatusForbidden, postInterestedReply(t, bannedID, posterID, msgID, ""),
		"a replier banned from the only community of a post is refused")

	var written int64
	db.Raw("SELECT COUNT(*) FROM chat_messages WHERE userid = ? AND refmsgid = ?", bannedID, msgID).Scan(&written)
	assert.Equal(t, int64(0), written, "a refused reply writes no chat message")
}
