package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// Delete as Spam is a recorded moderator action: the member's log names the moderator and the
// community, and a post with no copy left no longer holds its RFC822 id, so the same message
// arriving again is not read as a duplicate of a dead row.
func TestSpamLeavesLogEntryAndReleasesMessageId(t *testing.T) {
	prefix := uniquePrefix("spam_log")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", utils.SYSTEMROLE_USER)
	mod := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, poster, groupID, utils.ROLE_MEMBER)
	CreateTestMembership(t, mod, groupID, utils.ROLE_MODERATOR)
	_, token := CreateTestSession(t, mod)

	msgID := createPendingMessage(t, poster, groupID, prefix)
	db.Exec("UPDATE messages SET messageid = ? WHERE id = ?", prefix+"@test.invalid", msgID)

	status, body := postModAction(t, token, msgID, "Spam", groupID)
	require.Equal(t, 200, status, body)

	var logs int64
	db.Raw("SELECT COUNT(*) FROM logs WHERE type = 'Message' AND msgid = ? AND groupid = ? AND byuser = ? AND user = ?",
		msgID, groupID, mod, poster).Scan(&logs)
	assert.Equal(t, int64(1), logs, "the moderator's Delete as Spam is logged against the member")

	var messageid *string
	db.Raw("SELECT messageid FROM messages WHERE id = ?", msgID).Scan(&messageid)
	assert.Nil(t, messageid, "the last copy is gone, so the post lets go of its message id")
}
