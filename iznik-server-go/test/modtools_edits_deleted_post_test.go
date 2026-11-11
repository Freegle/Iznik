package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// A member deleting their post stamps messages.deleted. An edit still waiting for review on that
// post must not stay in the Edit count: the post can no longer be fetched, so the count was a
// badge nobody could clear. Edits are national and the test database is shared, so compare the
// count before and after rather than asserting an absolute number.
func TestEditReviewSkipsDeletedPost(t *testing.T) {
	prefix := uniquePrefix("editdeleted")
	db := database.DBConn

	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	_, token := CreateTestSession(t, modID)

	before := getSessionWork(t, token)["editreview"].(float64)

	msgID := CreateTestMessage(t, posterID, prefix+" WANTED: Edited then deleted", 51.5, -0.1)
	db.Exec("INSERT INTO messages_edits (msgid, byuser, oldtext, newtext, reviewrequired, timestamp) "+
		"VALUES (?, ?, 'Old body text', 'New body text', 1, NOW())", msgID, posterID)
	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_edits WHERE msgid = ?", msgID)
	})

	// While the post is live, the edit is in the count.
	assert.Equal(t, before+1, getSessionWork(t, token)["editreview"].(float64))

	// The member deletes the post: messages.deleted is stamped.
	db.Exec("UPDATE messages SET deleted = NOW() WHERE id = ?", msgID)

	assert.Equal(t, before, getSessionWork(t, token)["editreview"].(float64),
		"a deleted post's edit must leave the Edit badge")
}
