package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func freebieRemoveTasks(msgID uint64) int64 {
	var n int64
	database.DBConn.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type = 'freebie_alerts_remove' AND JSON_EXTRACT(data, '$.msgid') = ?", msgID).Scan(&n)
	return n
}

// A post that is no longer Approved anywhere is not publicly available, so it is taken off
// freebiealerts.app, as Delete, Spam and Withdrawn already do.
func TestBackToPendingQueuesFreebieAlertsRemove(t *testing.T) {
	prefix := uniquePrefix("fb_btp")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", utils.SYSTEMROLE_USER)
	mod := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, poster, groupID, utils.ROLE_MEMBER)
	CreateTestMembership(t, mod, groupID, utils.ROLE_MODERATOR)
	_, token := CreateTestSession(t, mod)

	msgID := CreateTestMessage(t, poster, groupID, prefix+" offer", 51.5, -0.1)
	db.Exec("DELETE FROM background_tasks WHERE task_type = 'freebie_alerts_remove' AND JSON_EXTRACT(data, '$.msgid') = ?", msgID)

	status, body := postModAction(t, token, msgID, "BackToPending", groupID)
	require.Equal(t, 200, status, body)

	assert.Equal(t, int64(1), freebieRemoveTasks(msgID), "pulling the only live copy back to pending takes the post off the external site")
}

func TestRejectToDraftQueuesFreebieAlertsRemove(t *testing.T) {
	prefix := uniquePrefix("fb_rtd")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", utils.SYSTEMROLE_USER)
	mod := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, poster, groupID, utils.ROLE_MEMBER)
	CreateTestMembership(t, mod, groupID, utils.ROLE_MODERATOR)
	_, token := CreateTestSession(t, mod)

	msgID := CreateTestMessage(t, poster, groupID, prefix+" offer", 51.5, -0.1)
	db.Exec("DELETE FROM background_tasks WHERE task_type = 'freebie_alerts_remove' AND JSON_EXTRACT(data, '$.msgid') = ?", msgID)

	status, body := postModAction(t, token, msgID, "RejectToDraft", groupID)
	require.Equal(t, 200, status, body)

	assert.Equal(t, int64(1), freebieRemoveTasks(msgID), "sending the only live copy back to draft takes the post off the external site")
}
