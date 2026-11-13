package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func postOutcomeAction(t *testing.T, token string, msgID uint64, outcome string) int {
	body, _ := json.Marshal(map[string]interface{}{"id": msgID, "action": "Outcome", "outcome": outcome})
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/message?jwt=%s", token), bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	return resp.StatusCode
}

// Withdrawing a post that is still Pending on one community but live on another records a
// Withdrawn outcome and keeps the post, as Taken and Received do. It is not deleted outright.
func assertWithdrawKeepsPostLiveElsewhere(t *testing.T, msgID, pendingGroup uint64, poster uint64) {
	db := database.DBConn
	token := getToken(t, poster)

	require.Equal(t, 200, postOutcomeAction(t, token, msgID, utils.OUTCOME_WITHDRAWN))

	var outcomes int64
	db.Raw("SELECT COUNT(*) FROM messages_outcomes WHERE msgid = ? AND outcome = ?", msgID, utils.OUTCOME_WITHDRAWN).Scan(&outcomes)
	assert.Equal(t, int64(1), outcomes, "a Withdrawn outcome is recorded")

	var deleted *string
	db.Raw("SELECT deleted FROM messages WHERE id = ?", msgID).Scan(&deleted)
	assert.Nil(t, deleted, "the post is not deleted outright while it is live on another community")

	var pendingDeleted int
	db.Raw("SELECT deleted FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, pendingGroup).Scan(&pendingDeleted)
	assert.Equal(t, 1, pendingDeleted, "the copy still waiting for a moderator is retired")
}

func TestWithdrawCrossPostPendingOnOneApprovedOnAnother(t *testing.T) {
	prefix := uniquePrefix("wd_cross")
	db := database.DBConn
	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")
	poster := CreateTestUser(t, prefix+"_poster", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, poster, groupA, utils.ROLE_MEMBER)
	CreateTestMembership(t, poster, groupB, utils.ROLE_MEMBER)

	msgID := createPendingMessage(t, poster, groupA, prefix)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts) VALUES (?, ?, NOW(), 'Approved', 0)", msgID, groupB)

	assertWithdrawKeepsPostLiveElsewhere(t, msgID, groupA, poster)
}

func TestWithdrawPendingAtHomeWithHandApprovedCopyElsewhere(t *testing.T) {
	f := newHomeLockFixture(t, "wd_hand")
	db := database.DBConn

	var poster uint64
	db.Raw("SELECT fromuser FROM messages WHERE id = ?", f.msgID).Scan(&poster)
	// The neighbouring community's moderator approved their copy by hand, then the home
	// moderator sent the post back to pending.
	approveByHand(t, f.msgID, f.recv, f.tokRecv)
	status, body := postModAction(t, f.tokHome, f.msgID, "BackToPending", f.home)
	require.Equal(t, 200, status, body)
	require.Equal(t, "Approved", rowOf(f.msgID, f.recv).Collection)

	assertWithdrawKeepsPostLiveElsewhere(t, f.msgID, f.home, poster)
}
