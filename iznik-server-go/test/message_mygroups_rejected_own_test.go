package test

import (
	json2 "encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// A post a moderator rejected with an explanation is not live. The "All my communities" feed
// serves a member's own posts that are not in messages_spatial yet, which is meant for Pending
// posts, so it must not return their Rejected one.
func TestMyGroupsFeedHidesOwnRejectedPost(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("mygroups_rejected")
	posterID, token := CreateFullTestUser(t, prefix+"_poster")
	group := CreateTestGroup(t, prefix)
	CreateTestMembership(t, posterID, group, utils.ROLE_MEMBER)

	rejectedID := CreateTestMessage(t, posterID, group, prefix+" rejected offer", 51.5, -0.1)
	pendingID := CreateTestMessage(t, posterID, group, prefix+" pending offer", 51.5, -0.1)
	db.Exec("UPDATE messages_groups SET collection = ? WHERE msgid = ?", utils.COLLECTION_REJECTED, rejectedID)
	db.Exec("UPDATE messages_groups SET collection = ? WHERE msgid = ?", utils.COLLECTION_PENDING, pendingID)
	db.Exec("DELETE FROM messages_spatial WHERE msgid IN (?, ?)", rejectedID, pendingID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/mygroups?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)

	found := map[uint64]bool{}
	for _, m := range msgs {
		found[m.ID] = true
	}
	assert.True(t, found[pendingID], "the member's own Pending post is still shown back to them")
	assert.False(t, found[rejectedID], "the member's own Rejected post is not shown as a live post")
}
