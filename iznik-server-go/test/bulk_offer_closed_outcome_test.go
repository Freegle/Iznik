package test

import (
	"fmt"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// A clearance its owner has already closed keeps the one outcome it has. The external owner
// marking the last item gone afterwards must not add a Taken outcome to it.
func TestBulkLastItemGoneDoesNotAddTakenToAWithdrawnPost(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("bulkclosed")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, ownerID, groupID, utils.ROLE_MEMBER)

	msgID := CreateTestMessage(t, ownerID, groupID, prefix+" Clearance", 55.95, -3.18)
	deskID := addBulkItem(t, msgID, "Desk", 1, "Good")
	db.Exec("UPDATE messages SET availablenow = 1 WHERE id = ?", msgID)
	db.Exec("INSERT INTO messages_outcomes (msgid, outcome) VALUES (?, ?)", msgID, utils.OUTCOME_WITHDRAWN)

	token := fmt.Sprintf("edittok_%s_0123456789abcdef", prefix)
	setEditToken(t, msgID, token)
	res := postUpdate(t, token, map[string]interface{}{"itemid": deskID, "available": false})
	require.Equal(t, 200, res.StatusCode)

	var taken, total int64
	db.Raw("SELECT COUNT(*) FROM messages_outcomes WHERE msgid = ? AND outcome = ?", msgID, utils.OUTCOME_TAKEN).Scan(&taken)
	db.Raw("SELECT COUNT(*) FROM messages_outcomes WHERE msgid = ?", msgID).Scan(&total)
	assert.Equal(t, int64(0), taken, "no Taken outcome is added to a withdrawn post")
	assert.Equal(t, int64(1), total, "it keeps its one outcome")
}
