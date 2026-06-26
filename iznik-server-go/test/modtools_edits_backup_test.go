package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// TestModtoolsEditsHiddenFromBackupMod covers the leak (Discourse: mod Torbrexbones/Derek, Fife)
// where GET /modtools/messages?collection=Edit returned a group's edit-review queue to a BACKUP
// moderator. The all-groups path (groupid=0) was already safe via GetActiveModGroupIDs; the leak
// was the explicit-groupid path, which only checked role (IsModOfGroup) with no active check, so a
// backup mod whose ModTools group selector re-sent a remembered backup group's id saw its edits.
// Both active=0 and the legacy showmessages=0 backup markers must hide the queue.
func TestModtoolsEditsHiddenFromBackupMod(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("editbackup")

	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix, "Moderator")
	memID := CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	// A poster's message on the group, with a pending (reviewrequired=1) edit awaiting review.
	posterID := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	msgID := CreateTestMessage(t, posterID, groupID, prefix+" edit", 53.0, -2.0)
	db.Exec("INSERT INTO messages_edits (msgid, byuser, oldtext, newtext, reviewrequired, timestamp) VALUES (?, ?, 'old', 'new', 1, NOW())", msgID, posterID)
	defer db.Exec("DELETE FROM messages_edits WHERE msgid = ?", msgID)

	editVisible := func(urlStr string) bool {
		resp, err := getApp().Test(httptest.NewRequest("GET", urlStr, nil))
		require.NoError(t, err)
		require.Equal(t, 200, resp.StatusCode)
		var body map[string]interface{}
		require.NoError(t, json.NewDecoder(resp.Body).Decode(&body))
		ids, _ := body["messages"].([]interface{})
		for _, id := range ids {
			if uint64(id.(float64)) == msgID {
				return true
			}
		}
		return false
	}
	byGroup := fmt.Sprintf("/api/modtools/messages?collection=Edit&groupid=%d&jwt=%s", groupID, modToken)
	allGroups := fmt.Sprintf("/api/modtools/messages?collection=Edit&jwt=%s", modToken)

	setSettings := func(s string) {
		db.Exec("UPDATE memberships SET collection = 'Approved', settings = ? WHERE id = ?", s, memID)
	}

	// Active mod sees the edit on both routes - guards against over-blocking.
	setSettings(`{"active":1}`)
	assert.True(t, editVisible(byGroup), "active mod must see the group's edit via explicit groupid")
	assert.True(t, editVisible(allGroups), "active mod must see the group's edit in the all-groups view")

	// Backup via active=0: hidden on BOTH routes (explicit groupid was the leak).
	setSettings(`{"active":0}`)
	assert.False(t, editVisible(byGroup), "backup mod (active=0) must NOT see the edit via explicit groupid")
	assert.False(t, editVisible(allGroups), "backup mod (active=0) must NOT see the edit in the all-groups view")

	// Backup via legacy showmessages=0 (no active key): also hidden - GetActiveModGroupIDs and
	// IsActiveModOfGroup honour the showmessages fallback, matching the isActiveModForGroup helper.
	setSettings(`{"showmessages":0}`)
	assert.False(t, editVisible(byGroup), "backup mod (showmessages=0) must NOT see the edit via explicit groupid")
	assert.False(t, editVisible(allGroups), "backup mod (showmessages=0) must NOT see the edit in the all-groups view")
}
