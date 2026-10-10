package test

import (
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/group"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// A member deleting their post leaves its origin messages_groups row live (deleted = 0) and
// stamps messages.deleted instead. An edit still waiting for review on that post must not stay
// in the Edit queue or its counts: the post can no longer be fetched, so the queue showed an
// entry that answered 404 every time ModTools loaded it, and a badge nobody could clear.
func TestEditReviewSkipsDeletedPost(t *testing.T) {
	prefix := uniquePrefix("editdeleted")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, token := CreateTestSession(t, modID)

	msgID := CreateTestMessage(t, posterID, groupID, prefix+" WANTED: Edited then deleted", 51.5, -0.1)
	db.Exec("INSERT INTO messages_edits (msgid, byuser, oldtext, newtext, reviewrequired, timestamp) "+
		"VALUES (?, ?, 'Old body text', 'New body text', 1, NOW())", msgID, posterID)
	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_edits WHERE msgid = ?", msgID)
		db.Exec("DELETE FROM messages_groups WHERE msgid = ?", msgID)
	})

	inEditQueue := func() bool {
		url := fmt.Sprintf("/api/modtools/messages?collection=Edit&groupid=%d&jwt=%s", groupID, token)
		resp, err := getApp().Test(httptest.NewRequest("GET", url, nil))
		require.NoError(t, err)
		require.Equal(t, 200, resp.StatusCode)
		var result map[string]interface{}
		json2.NewDecoder(resp.Body).Decode(&result)
		msgs, _ := result["messages"].([]interface{})
		for _, id := range msgs {
			if uint64(id.(float64)) == msgID {
				return true
			}
		}
		return false
	}

	groupEditCount := func() int64 {
		resp, err := getApp().Test(httptest.NewRequest("GET", "/api/group/work?jwt="+token, nil))
		require.NoError(t, err)
		require.Equal(t, 200, resp.StatusCode)
		var result []group.GroupWork
		json2.Unmarshal(rsp(resp), &result)
		for _, w := range result {
			if w.Groupid == groupID {
				return w.Editreview
			}
		}
		return 0
	}

	// While the post is live, the edit is in the queue and both counts.
	assert.True(t, inEditQueue(), "a live post's edit should be in the Edit queue")
	assert.Equal(t, int64(1), groupEditCount())
	assert.Equal(t, float64(1), getSessionWork(t, token)["editreview"].(float64))

	// The member deletes the post: messages.deleted is stamped, the group row stays live.
	db.Exec("UPDATE messages SET deleted = NOW() WHERE id = ?", msgID)

	assert.False(t, inEditQueue(), "a deleted post's edit must leave the Edit queue")
	assert.Equal(t, int64(0), groupEditCount(), "a deleted post's edit must leave the group Edit count")
	assert.Equal(t, float64(0), getSessionWork(t, token)["editreview"].(float64),
		"a deleted post's edit must leave the Edit badge")
}
