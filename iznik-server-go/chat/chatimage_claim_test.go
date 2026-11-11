package chat

import (
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/freegle/iznik-server-go/database"
)

// The API test TestCreateChatMessageImageConcurrentSendsOnlyOneWins races five sends of one
// image. Whether a loser reaches linkChatImage's "no row claimed" branch, or stops at the
// checkChatImageUnused pre-check, depends on the race, so that branch was covered on some runs
// and not others. This pins it directly: the second claim of a linked image affects no row.
func TestLinkChatImageSecondClaimLosesDeterministically(t *testing.T) {
	db := database.DBConn
	require.NotNil(t, db)

	// Two chat messages to claim with, through the FK chain: a user, a room, two messages.
	require.NoError(t, db.Exec("INSERT INTO users (fullname) VALUES ('chat image claim test')").Error)
	var userid uint64
	db.Raw("SELECT MAX(id) FROM users").Scan(&userid)
	require.NotZero(t, userid)

	require.NoError(t, db.Exec("INSERT INTO chat_rooms (chattype, user1, user2) VALUES ('User2User', ?, ?)", userid, userid).Error)
	var chatid uint64
	db.Raw("SELECT MAX(id) FROM chat_rooms").Scan(&chatid)
	require.NotZero(t, chatid)

	msgids := make([]uint64, 2)
	for i := range msgids {
		require.NoError(t, db.Exec("INSERT INTO chat_messages (chatid, userid, message) VALUES (?, ?, 'claim test')", chatid, userid).Error)
		db.Raw("SELECT MAX(id) FROM chat_messages").Scan(&msgids[i])
		require.NotZero(t, msgids[i])
	}

	require.NoError(t, db.Exec("INSERT INTO chat_images (contenttype) VALUES ('image/jpeg')").Error)
	var imageid uint64
	db.Raw("SELECT MAX(id) FROM chat_images").Scan(&imageid)
	require.NotZero(t, imageid)

	assert.NoError(t, linkChatImage(db, imageid, msgids[0]), "the first claim takes the row")
	assert.Equal(t, errChatImageTaken, linkChatImage(db, imageid, msgids[1]), "the second claim affects no row")

	var linked uint64
	db.Raw("SELECT chatmsgid FROM chat_images WHERE id = ?", imageid).Scan(&linked)
	assert.Equal(t, msgids[0], linked, "the first message keeps the image")

	assert.Equal(t, errChatImageTaken, linkChatImage(db, imageid+1000000, msgids[1]), "an image that does not exist claims nothing")

	db.Exec("DELETE FROM chat_images WHERE id = ?", imageid)
	db.Exec("DELETE FROM chat_messages WHERE id IN (?, ?)", msgids[0], msgids[1])
	db.Exec("DELETE FROM chat_rooms WHERE id = ?", chatid)
	db.Exec("DELETE FROM users WHERE id = ?", userid)
}
