package test

import (
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// Regression: an edit on a post that has rippled INTO a group must NOT appear in that
// receiving group's ModTools Edit queue. Rippling-out writes an Approved messages_groups
// row (rippled_in=1) per receiving group; the Edit-collection query in ListMessagesMT joins
// messages_edits -> messages_groups by msgid, so without a rippled_in=0 filter an edit on a
// rippled-in post leaked into every receiving group's Edit queue — and to ACTIVE mods there
// via the all-groups (groupid=0) path — even though the edit belongs to the post's ORIGIN
// group(s) only. Reporter: Torbrexbones/Derek (Fife) seeing the Edit queue for posts on
// groups they only receive rippled copies of. See finding_modtools_edits_backup_group_leak.
func TestListMessagesMTEditExcludesRippledInCopies(t *testing.T) {
	prefix := uniquePrefix("editripple")
	db := database.DBConn

	originGroup := CreateTestGroup(t, prefix+"_orig")
	rippledGroup := CreateTestGroup(t, prefix+"_rip")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	originModID := CreateTestUser(t, prefix+"_omod", "User")
	rippledModID := CreateTestUser(t, prefix+"_rmod", "User")

	CreateTestMembership(t, posterID, originGroup, "Member")
	CreateTestMembership(t, originModID, originGroup, "Moderator")
	CreateTestMembership(t, rippledModID, rippledGroup, "Moderator")

	_, originModToken := CreateTestSession(t, originModID)
	_, rippledModToken := CreateTestSession(t, rippledModID)

	// Post originates on originGroup (CreateTestMessage inserts the origin row, rippled_in=0).
	msgID := CreateTestMessage(t, posterID, originGroup, prefix+" OFFER: Rippled Edit Item", 51.5, -0.1)

	// Simulate rippling-out: an Approved messages_groups row (rippled_in=1) on the receiving group,
	// exactly as ExpandService::rippleIntoNewGroups does.
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in) "+
		"VALUES (?, ?, NOW(), 'Approved', 0, 1)", msgID, rippledGroup)

	// A pending edit needing mod review.
	db.Exec("INSERT INTO messages_edits (msgid, byuser, oldtext, newtext, reviewrequired, timestamp) "+
		"VALUES (?, ?, 'Old body text', 'New body text', 1, NOW())", msgID, posterID)

	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_edits WHERE msgid = ?", msgID)
		db.Exec("DELETE FROM messages_groups WHERE msgid = ?", msgID)
	})

	editQueueHasMsg := func(token string, groupid uint64) bool {
		url := fmt.Sprintf("/api/modtools/messages?collection=Edit&jwt=%s", token)
		if groupid != 0 {
			url = fmt.Sprintf("/api/modtools/messages?collection=Edit&groupid=%d&jwt=%s", groupid, token)
		}
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

	// The origin-group mod SEES the edit — it belongs to their group — on both routes.
	assert.True(t, editQueueHasMsg(originModToken, 0),
		"origin-group mod should see the edit via the all-groups path")
	assert.True(t, editQueueHasMsg(originModToken, originGroup),
		"origin-group mod should see the edit via the explicit-groupid path")

	// A mod ACTIVE only on the rippled-INTO group must NOT see it — on both routes.
	assert.False(t, editQueueHasMsg(rippledModToken, 0),
		"a mod of the rippled-into group must not see the edit via the all-groups path")
	assert.False(t, editQueueHasMsg(rippledModToken, rippledGroup),
		"a mod of the rippled-into group must not see the edit via the explicit-groupid path")
}
