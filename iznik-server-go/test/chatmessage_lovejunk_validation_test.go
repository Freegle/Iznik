package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// A LoveJunk reply that is refused changes nothing. Validating the message and the photo comes before the group
// membership, the chat room and the roster rows, or each refused retry leaves a joined member and an empty chat.
func TestCreateChatMessageLoveJunkRefusedReplyLeavesNothingBehind(t *testing.T) {
	db := database.DBConn

	leftBehind := func(posterID uint64, ljuserid uint64) (rooms int64, memberships int64) {
		var ljid uint64
		db.Raw("SELECT id FROM users WHERE ljuserid = ?", ljuserid).Scan(&ljid)
		db.Raw("SELECT COUNT(*) FROM chat_rooms WHERE user1 IN (?, ?) AND user2 IN (?, ?) AND user1 != user2",
			posterID, ljid, posterID, ljid).Scan(&rooms)
		db.Raw("SELECT COUNT(*) FROM memberships WHERE userid = ?", ljid).Scan(&memberships)

		return rooms, memberships
	}

	t.Run("an empty message", func(t *testing.T) {
		posterID, _, _, payload := loveJunkFixture(t, "ljnomsg")
		payload.Message = ""

		assert.Equal(t, fiber.StatusBadRequest, postLoveJunk(t, payload).StatusCode)

		rooms, memberships := leftBehind(posterID, *payload.Ljuserid)
		assert.Equal(t, int64(0), rooms, "no chat is created for a refused reply")
		assert.Equal(t, int64(0), memberships, "the replier is not joined to the group by a refused reply")
	})

	t.Run("a photo that is already used", func(t *testing.T) {
		posterID, _, _, payload := loveJunkFixture(t, "ljusedimg")

		// A photo that already belongs to a message.
		usedBy := CreateTestUser(t, uniquePrefix("ljusedimg_owner"), utils.SYSTEMROLE_USER)
		otherChat := CreateTestChatRoom(t, usedBy, &posterID, nil, "User2User")
		used := CreateTestChatMessage(t, otherChat, usedBy, "Here it is")
		db.Exec("INSERT INTO chat_images (chatmsgid, contenttype) VALUES (?, 'image/jpeg')", used)
		var imageID uint64
		db.Raw("SELECT id FROM chat_images WHERE chatmsgid = ? ORDER BY id DESC LIMIT 1", used).Scan(&imageID)
		require.NotZero(t, imageID)
		payload.Imageid = &imageID

		assert.Equal(t, fiber.StatusConflict, postLoveJunk(t, payload).StatusCode)

		var ljid uint64
		db.Raw("SELECT id FROM users WHERE ljuserid = ?", *payload.Ljuserid).Scan(&ljid)
		var rooms, memberships int64
		db.Raw("SELECT COUNT(*) FROM chat_rooms WHERE (user1 = ? OR user2 = ?)", ljid, ljid).Scan(&rooms)
		db.Raw("SELECT COUNT(*) FROM memberships WHERE userid = ?", ljid).Scan(&memberships)
		assert.Equal(t, int64(0), rooms, "no chat is created for a refused reply")
		assert.Equal(t, int64(0), memberships, "the replier is not joined to the group by a refused reply")
	})
}
