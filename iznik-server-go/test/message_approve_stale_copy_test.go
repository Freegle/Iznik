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

// approveWithStdmsg sends an Approve with a standard message on one community and returns the
// decoded answer.
func approveWithStdmsg(t *testing.T, token string, msgID uint64, groupID uint64) map[string]interface{} {
	body, _ := json.Marshal(map[string]interface{}{
		"id": msgID, "action": "Approve", "groupid": groupID,
		"subject": "Welcome", "body": "Thanks for your post.", "stdmsgid": 42,
	})
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/message?jwt=%s", token), bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	require.NoError(t, json.NewDecoder(resp.Body).Decode(&result))
	return result
}

func approvedEmailTasks(msgID uint64) int64 {
	var count int64
	database.DBConn.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type = 'email_message_approved' AND JSON_EXTRACT(data, '$.msgid') = ?",
		msgID).Scan(&count)
	return count
}

func approveStaleCopyFixture(t *testing.T, name string) (uint64, uint64, string) {
	prefix := uniquePrefix(name)
	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", utils.SYSTEMROLE_USER)
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, posterID, groupID, utils.ROLE_MEMBER)
	CreateTestMembership(t, modID, groupID, utils.ROLE_MODERATOR)
	_, modToken := CreateTestSession(t, modID)
	msgID := createPendingMessage(t, posterID, groupID, prefix)
	return msgID, groupID, modToken
}

// A second Approve of a copy that has gone live in the meantime (two moderators, a double
// click, a stale tab) is a no-op that answers ret=1. It must not queue a second approval
// email to the poster.
func TestApproveOnOneCommunityTwiceQueuesOneApprovalEmail(t *testing.T) {
	msgID, groupID, modToken := approveStaleCopyFixture(t, "appr_twice")

	first := approveWithStdmsg(t, modToken, msgID, groupID)
	assert.Equal(t, float64(0), first["ret"])
	assert.Equal(t, int64(1), approvedEmailTasks(msgID))

	second := approveWithStdmsg(t, modToken, msgID, groupID)
	assert.Equal(t, float64(1), second["ret"], "approving a copy that is no longer pending is refused")
	assert.Contains(t, second["status"], "no longer pending")
	assert.Equal(t, int64(1), approvedEmailTasks(msgID), "the poster is not told a second time")
}

// A copy the poster withdrew (messages_groups.deleted = 1) must not be put live by an Approve,
// and the poster must not be told it was approved.
func TestApproveOnOneCommunityIgnoresAWithdrawnCopy(t *testing.T) {
	msgID, groupID, modToken := approveStaleCopyFixture(t, "appr_withdrawn")
	database.DBConn.Exec("UPDATE messages_groups SET deleted = 1 WHERE msgid = ? AND groupid = ?", msgID, groupID)

	result := approveWithStdmsg(t, modToken, msgID, groupID)
	assert.Equal(t, float64(1), result["ret"])

	var collection string
	database.DBConn.Raw("SELECT collection FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupID).Scan(&collection)
	assert.Equal(t, "Pending", collection, "a withdrawn copy stays where it was")
	assert.Equal(t, int64(0), approvedEmailTasks(msgID))
}

// A copy a moderator has already rejected must not be put live by a later Approve from a
// stale view, and the poster must not be told it was approved.
func TestApproveOnOneCommunityIgnoresARejectedCopy(t *testing.T) {
	msgID, groupID, modToken := approveStaleCopyFixture(t, "appr_rejected")
	database.DBConn.Exec("UPDATE messages_groups SET collection = 'Rejected' WHERE msgid = ? AND groupid = ?", msgID, groupID)

	result := approveWithStdmsg(t, modToken, msgID, groupID)
	assert.Equal(t, float64(1), result["ret"])

	var collection string
	database.DBConn.Raw("SELECT collection FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupID).Scan(&collection)
	assert.Equal(t, "Rejected", collection, "a rejected copy stays rejected")
	assert.Equal(t, int64(0), approvedEmailTasks(msgID))
}
