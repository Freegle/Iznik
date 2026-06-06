package test

import (
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/group"
	"github.com/stretchr/testify/assert"
)

// TestPendingMembersOrphanCountVsListing is a regression test for the phantom
// pending-members badge (Discourse #9654/12).
//
// When a user whose membership row has collection='Pending' is hard-deleted
// (user row gone, membership row remains), the pendingmembers count query —
// which does NOT join the users table — returns 1 while the listing query
// (which INNER-JOINs users) returns 0. This produces a persistent +1 badge
// count that the moderator can never clear ("count stays at 1 after doing all tasks").
//
// The same pattern was fixed for Spammembers in PR #590. This test covers
// both the session work total (GET /api/session) and the per-group count
// (GET /api/group/work).
func TestPendingMembersOrphanCountVsListing(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("pmorphan")

	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, token := CreateTestSession(t, modID)

	// Create a user with a Pending membership, then hard-delete the user row with
	// FK checks off — leaving an orphaned Pending membership row.
	// In production this occurs when a user is deleted before FK CASCADE was in place.
	targetID := CreateTestUser(t, prefix+"_target", "User")
	db.Exec("INSERT INTO memberships (userid, groupid, role, collection) "+
		"VALUES (?, ?, 'Member', 'Pending')", targetID, groupID)

	db.Exec("SET FOREIGN_KEY_CHECKS = 0")
	db.Exec("DELETE FROM users_emails WHERE userid = ?", targetID)
	db.Exec("DELETE FROM users WHERE id = ?", targetID)
	db.Exec("SET FOREIGN_KEY_CHECKS = 1")

	t.Cleanup(func() {
		db.Exec("SET FOREIGN_KEY_CHECKS = 0")
		db.Exec("DELETE FROM memberships WHERE userid = ? AND groupid = ?", targetID, groupID)
		db.Exec("SET FOREIGN_KEY_CHECKS = 1")
	})

	// --- 1. GET /api/group/work: per-group Pendingmembers must be 0 ---
	workResp, _ := getApp().Test(httptest.NewRequest("GET", "/api/group/work?jwt="+token, nil))
	assert.Equal(t, 200, workResp.StatusCode)

	var workResult []group.GroupWork
	json2.Unmarshal(rsp(workResp), &workResult)

	var found *group.GroupWork
	for i := range workResult {
		if workResult[i].Groupid == groupID {
			found = &workResult[i]
			break
		}
	}
	assert.NotNil(t, found, "Expected group %d in work results", groupID)
	if found != nil {
		assert.Equal(t, int64(0), found.Pendingmembers,
			"group/work Pendingmembers must be 0 when the only pending member's user row is deleted; "+
				"orphaned membership row must not inflate the count (Discourse #9654/12)")
	}

	// --- 2. GET /api/session work.pendingmembers must be 0 ---
	work := getSessionWork(t, token)
	pendingmembers := work["pendingmembers"].(float64)
	assert.Equal(t, float64(0), pendingmembers,
		"session work.pendingmembers must be 0 when the only pending member's user row is deleted; "+
			"orphaned membership row must not inflate the count (Discourse #9654/12)")

	// --- 3. GET /api/memberships?collection=Pending: listing must return 0 ---
	listURL := fmt.Sprintf("/api/memberships?collection=Pending&groupid=%d&jwt=%s", groupID, token)
	listResp, _ := getApp().Test(httptest.NewRequest("GET", listURL, nil))
	assert.Equal(t, 200, listResp.StatusCode)

	// The listing response is a JSON object with a "members" key (see GetMemberships context
	// extraction) or a plain array — handle both.
	var rawList interface{}
	json2.Unmarshal(rsp(listResp), &rawList)
	var listLen int
	switch v := rawList.(type) {
	case []interface{}:
		listLen = len(v)
	case map[string]interface{}:
		if members, ok := v["members"].([]interface{}); ok {
			listLen = len(members)
		}
	}
	assert.Equal(t, 0, listLen,
		"pending-members listing must return 0 rows when the only pending member's user row is deleted")
}
