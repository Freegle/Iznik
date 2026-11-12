package test

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// A member's own PENDING post is deliberately shown back to them in browse (the
// "fromuser" branch of GetGroupMessages) so they can't tell it is still awaiting
// moderation. A REJECTED post, however, must NOT leak back into their browse feed
// — it has been removed by the moderators.
func TestBrowseHidesOwnRejectedPost(t *testing.T) {
	db := database.DBConn
	groupID := CreateTestGroup(t, "brownrej")
	userID := CreateTestUser(t, "brownrej", utils.SYSTEMROLE_USER)
	token := getToken(t, userID)

	approvedMsg := CreateTestMessage(t, userID, groupID, "OFFER: approved brownrej", 51.5, -0.1)
	pendingMsg := CreateTestMessage(t, userID, groupID, "OFFER: pending brownrej", 51.5, -0.1)
	rejectedMsg := CreateTestMessage(t, userID, groupID, "OFFER: rejected brownrej", 51.5, -0.1)

	// CreateTestMessage defaults the collection to Approved; set the other two.
	db.Exec("UPDATE messages_groups SET collection = ? WHERE msgid = ?", utils.COLLECTION_PENDING, pendingMsg)
	db.Exec("UPDATE messages_groups SET collection = ? WHERE msgid = ?", utils.COLLECTION_REJECTED, rejectedMsg)

	resp, err := getApp().Test(httptest.NewRequest("GET", "/api/group/"+fmt.Sprint(groupID)+"/message?jwt="+token, nil))
	assert.NoError(t, err)
	body, _ := io.ReadAll(resp.Body)
	var ids []uint64
	assert.NoError(t, json.Unmarshal(body, &ids))

	has := func(id uint64) bool {
		for _, x := range ids {
			if x == id {
				return true
			}
		}
		return false
	}

	assert.True(t, has(approvedMsg), "own approved post should appear in browse")
	assert.True(t, has(pendingMsg), "own pending post should appear in browse (can't tell it's pending)")
	assert.False(t, has(rejectedMsg), "own rejected post must NOT appear in browse")
}

// Another member's profile post list holds public, live posts only. Pending, Rejected and Spam
// copies are moderation state, for the owner and the moderators. The reader here is anonymous and
// then another member.
func TestOtherMembersPostListHidesPendingRejectedAndSpamCopies(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("profile_hide")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", utils.SYSTEMROLE_USER)
	viewerID := CreateTestUser(t, prefix+"_viewer", utils.SYSTEMROLE_USER)
	_, viewerToken := CreateTestSession(t, viewerID)

	approvedMsg := CreateTestMessage(t, ownerID, groupID, "OFFER: approved "+prefix, 51.5, -0.1)
	pendingMsg := CreateTestMessage(t, ownerID, groupID, "OFFER: pending "+prefix, 51.5, -0.1)
	rejectedMsg := CreateTestMessage(t, ownerID, groupID, "OFFER: rejected "+prefix, 51.5, -0.1)
	spamMsg := CreateTestMessage(t, ownerID, groupID, "OFFER: spam "+prefix, 51.5, -0.1)
	db.Exec("UPDATE messages_groups SET collection = ? WHERE msgid = ?", utils.COLLECTION_PENDING, pendingMsg)
	db.Exec("UPDATE messages_groups SET collection = ? WHERE msgid = ?", utils.COLLECTION_REJECTED, rejectedMsg)
	db.Exec("UPDATE messages_groups SET collection = ? WHERE msgid = ?", utils.COLLECTION_SPAM, spamMsg)

	for name, suffix := range map[string]string{"anonymous": "", "member": "?jwt=" + viewerToken} {
		resp, err := getApp().Test(httptest.NewRequest("GET", "/api/user/"+fmt.Sprint(ownerID)+"/message"+suffix, nil))
		assert.NoError(t, err)
		body, _ := io.ReadAll(resp.Body)
		var msgs []struct {
			ID uint64 `json:"id"`
		}
		assert.NoError(t, json.Unmarshal(body, &msgs))

		has := func(id uint64) bool {
			for _, m := range msgs {
				if m.ID == id {
					return true
				}
			}
			return false
		}

		assert.True(t, has(approvedMsg), name+": the approved post is public")
		assert.False(t, has(pendingMsg), name+": a pending copy must not be listed")
		assert.False(t, has(rejectedMsg), name+": a rejected copy must not be listed")
		assert.False(t, has(spamMsg), name+": a spam copy must not be listed")
	}
}
