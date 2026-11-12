package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// A copy the spam check flagged waits in the same queue as a Pending one and ModTools offers
// Hold on it, so Hold places the hold and logs it.
func TestHoldPlacesHoldOnSpamFlaggedCopy(t *testing.T) {
	prefix := uniquePrefix("hold_spam")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", utils.SYSTEMROLE_USER)
	mod := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, poster, groupID, utils.ROLE_MEMBER)
	CreateTestMembership(t, mod, groupID, utils.ROLE_MODERATOR)
	_, token := CreateTestSession(t, mod)

	msgID := createPendingMessage(t, poster, groupID, prefix)
	db.Exec("UPDATE messages_groups SET collection = ? WHERE msgid = ?", utils.COLLECTION_SPAM, msgID)

	status, body := postModAction(t, token, msgID, "Hold", groupID)
	require.Equal(t, 200, status, body)

	var heldby *uint64
	db.Raw("SELECT heldby FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupID).Scan(&heldby)
	if assert.NotNil(t, heldby, "the spam-flagged copy is held") {
		assert.Equal(t, mod, *heldby)
	}
	assert.Equal(t, int64(1), logCount(msgID, groupID, "Hold"), "and the hold is logged")
}
