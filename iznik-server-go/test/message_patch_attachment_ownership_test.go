package test

import (
	"bytes"
	"encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// A member's edit may only link photos that are unattached or already on their own post.
// Naming another post's photo in the attachments list must leave that photo where it is.
func TestPatchMessageAttachmentsCannotTakeAnotherPostsPhoto(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("patch_att_own")

	groupID := CreateTestGroup(t, prefix)
	userA := CreateTestUser(t, prefix+"_a", utils.SYSTEMROLE_USER)
	userB := CreateTestUser(t, prefix+"_b", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, userA, groupID, utils.ROLE_MEMBER)
	CreateTestMembership(t, userB, groupID, utils.ROLE_MEMBER)
	_, tokenA := CreateTestSession(t, userA)

	msgA := CreateTestMessage(t, userA, groupID, "OFFER: patch att own A (Test)", 51.5, -1.0)
	msgB := CreateTestMessage(t, userB, groupID, "OFFER: patch att own B (Test)", 51.5, -1.0)
	attB := CreateTestAttachment(t, msgB)

	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_attachments WHERE id = ?", attB)
	})

	body := map[string]interface{}{
		"id":          msgA,
		"attachments": []uint64{attB},
	}
	bodyBytes, _ := json.Marshal(body)
	req := httptest.NewRequest("PATCH", "/api/message?jwt="+tokenA, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	_, err := getApp().Test(req)
	assert.NoError(t, err)

	var owner uint64
	db.Raw("SELECT COALESCE(msgid, 0) FROM messages_attachments WHERE id = ?", attB).Scan(&owner)
	assert.Equal(t, msgB, owner, "another member's photo must stay on their own post")
}
