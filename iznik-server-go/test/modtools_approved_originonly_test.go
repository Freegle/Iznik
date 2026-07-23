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

// 9808/638: the ModTools Approved-messages list is dominated by posts that rippled INTO a
// group, which makes post-moderating a group's OWN members hard. ListMessagesMT with
// ?collection=Approved&originonly=true adds a rippled_in=0 filter so only posts that
// originated on the group are returned. Same messages_groups.rippled_in marker the Edit
// queue already uses (9808/633 / PR #1144); see modtools_edits_rippled_in_test.go.
func TestListMessagesMTApprovedOriginOnly(t *testing.T) {
	prefix := uniquePrefix("mtApprovedRipple")
	db := database.DBConn

	originGroup := CreateTestGroup(t, prefix+"_orig")
	rippledGroup := CreateTestGroup(t, prefix+"_rip")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, posterID, originGroup, "Member")
	CreateTestMembership(t, modID, originGroup, "Moderator")
	CreateTestMembership(t, modID, rippledGroup, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	// Approved on its origin group (rippled_in=0 from CreateTestMessage), then rippled
	// (rippled_in=1) into the other group.
	msgID := CreateTestMessage(t, posterID, originGroup, prefix+" OFFER: MT Ripple Item", 51.5, -0.1)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in) "+
		"VALUES (?, ?, NOW(), 'Approved', 0, 1)", msgID, rippledGroup)

	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, rippledGroup)
	})

	listHasMsg := func(groupid uint64, originOnly bool) bool {
		url := fmt.Sprintf("/api/modtools/messages?collection=Approved&groupid=%d&jwt=%s", groupid, modToken)
		if originOnly {
			url += "&originonly=true"
		}
		resp, err := getApp().Test(httptest.NewRequest("GET", url, nil))
		require.NoError(t, err)
		require.Equal(t, 200, resp.StatusCode)
		var result map[string]interface{}
		require.NoError(t, json2.NewDecoder(resp.Body).Decode(&result))
		msgs, _ := result["messages"].([]interface{})
		for _, m := range msgs {
			if id, ok := m.(float64); ok && uint64(id) == msgID {
				return true
			}
		}
		return false
	}

	// Default: the rippled-in copy appears in the receiving group's Approved list.
	assert.True(t, listHasMsg(rippledGroup, false),
		"without originonly, a rippled-in post appears in the receiving group's Approved list")
	// originonly=true: excluded from the receiving group.
	assert.False(t, listHasMsg(rippledGroup, true),
		"with originonly, a rippled-in post is excluded from the receiving group's Approved list")
	// originonly=true on the ORIGIN group: still present (rippled_in=0 there).
	assert.True(t, listHasMsg(originGroup, true),
		"with originonly, the post still appears in its origin group's Approved list")
}
