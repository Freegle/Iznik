package test

import (
	"errors"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
	"gorm.io/gorm"
)

// Concurrent sends of one image can deadlock on the image row, and the loser is told the image is
// taken. TestCreateChatMessageImageConcurrentSendsOnlyOneWins only reaches that branch when the
// database happens to pick a deadlock victim, so this test fails the claim with a deadlock every
// time. It registers a callback on the shared connection, so it must not run in parallel.
func TestCreateChatMessageImageDeadlockOnClaimIsAConflict(t *testing.T) {
	prefix := uniquePrefix("chatimgdead")
	groupID := CreateTestGroup(t, prefix)
	modUserID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	CreateTestMembership(t, modUserID, groupID, utils.ROLE_MODERATOR)
	chatid := CreateTestChatRoom(t, modUserID, nil, &groupID, "User2Mod")
	_, token := CreateTestSession(t, modUserID)
	imageID := newUnattachedChatImage(t)

	db := database.DBConn
	var before int64
	db.Raw("SELECT COUNT(*) FROM chat_messages WHERE chatid = ?", chatid).Scan(&before)

	claims := 0
	beforeStatement(t, "chat_images", true, func(tx *gorm.DB) {
		claims++
		_ = tx.AddError(errors.New("Error 1213 (40001): Deadlock found when trying to get lock; try restarting transaction"))
	})

	status, _ := postChatImage(t, chatid, token, imageID)
	assert.Equal(t, fiber.StatusConflict, status)
	assert.Equal(t, 1, claims, "the send is not retried: the loser has lost the image either way")

	// The insert shares the claim's transaction, so the message is rolled back with it.
	var after int64
	db.Raw("SELECT COUNT(*) FROM chat_messages WHERE chatid = ?", chatid).Scan(&after)
	assert.Equal(t, before, after)

	// The image is still free for whoever sends it next.
	var linked *uint64
	db.Raw("SELECT chatmsgid FROM chat_images WHERE id = ?", imageID).Scan(&linked)
	assert.Nil(t, linked)
}
