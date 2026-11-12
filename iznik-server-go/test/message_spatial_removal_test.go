package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func spatialRowCount(msgID uint64) int64 {
	var n int64
	database.DBConn.Raw("SELECT COUNT(*) FROM messages_spatial WHERE msgid = ?", msgID).Scan(&n)
	return n
}

// A post a moderator takes out of Approved is out of browse at once, not up to five minutes
// later when the reconciler runs. Browse reads messages_spatial, so the row goes with the copy.
func TestModeratorActionsRemoveTheSpatialRowWhenNoCopyIsApproved(t *testing.T) {
	for _, action := range []string{"Spam", "Delete", "BackToPending"} {
		t.Run(action, func(t *testing.T) {
			prefix := uniquePrefix("spatial_rm_" + action)
			groupID := CreateTestGroup(t, prefix)
			poster := CreateTestUser(t, prefix+"_poster", utils.SYSTEMROLE_USER)
			mod := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_USER)
			CreateTestMembership(t, poster, groupID, utils.ROLE_MEMBER)
			CreateTestMembership(t, mod, groupID, utils.ROLE_MODERATOR)
			_, token := CreateTestSession(t, mod)

			msgID := CreateTestMessage(t, poster, groupID, "OFFER: Table "+prefix, 51.5, -0.1)
			require.Equal(t, int64(1), spatialRowCount(msgID))

			status, body := postModAction(t, token, msgID, action, groupID)
			require.Equal(t, 200, status, body)

			assert.Equal(t, int64(0), spatialRowCount(msgID), "the post no longer shows in browse")
		})
	}
}
