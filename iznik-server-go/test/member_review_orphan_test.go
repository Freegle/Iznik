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

func TestMemberReviewOrphanCountVsListing(t *testing.T) {
	// Regression: the Spammembers work-count query tallied membership rows without
	// JOINing to the users table, so orphaned memberships (user row deleted,
	// membership row remains) inflated the badge count to 1 while the listing
	// query (which INNER-JOINs users) returned 0 members.
	db := database.DBConn
	prefix := uniquePrefix("mrorph")

	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, token := CreateTestSession(t, modID)

	// Create a user, flag their membership for review, then hard-delete the
	// user row with FK checks disabled — leaving an orphaned membership row.
	// In production this can occur when a user is deleted from the users table
	// without cascading (e.g. before FK CASCADE was in place) while a
	// reviewrequestedat flag remains on their membership row.
	targetID := CreateTestUser(t, prefix+"_target", "User")
	db.Exec("INSERT INTO memberships (userid, groupid, role, collection, reviewrequestedat) "+
		"VALUES (?, ?, 'Member', 'Approved', NOW())", targetID, groupID)

	db.Exec("SET FOREIGN_KEY_CHECKS = 0")
	db.Exec("DELETE FROM users_emails WHERE userid = ?", targetID)
	db.Exec("DELETE FROM users WHERE id = ?", targetID)
	db.Exec("SET FOREIGN_KEY_CHECKS = 1")

	t.Cleanup(func() {
		db.Exec("SET FOREIGN_KEY_CHECKS = 0")
		db.Exec("DELETE FROM memberships WHERE userid = ? AND groupid = ?", targetID, groupID)
		db.Exec("SET FOREIGN_KEY_CHECKS = 1")
	})

	// Work-count call.
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/group/work?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var workResult []group.GroupWork
	json2.Unmarshal(rsp(resp), &workResult)

	var found *group.GroupWork
	for i := range workResult {
		if workResult[i].Groupid == groupID {
			found = &workResult[i]
			break
		}
	}
	assert.NotNil(t, found, "Expected group %d in work results", groupID)
	if found == nil {
		return
	}

	// Listing call.
	spamURL := fmt.Sprintf("/api/memberships?collection=Spam&groupid=%d&jwt=%s", groupID, token)
	spamResp, _ := getApp().Test(httptest.NewRequest("GET", spamURL, nil))
	assert.Equal(t, 200, spamResp.StatusCode)

	var spamMembers []map[string]interface{}
	json2.Unmarshal(rsp(spamResp), &spamMembers)

	assert.Equal(t, int64(len(spamMembers)), found.Spammembers,
		"work-count Spammembers (%d) must equal members returned by listing (%d); "+
			"orphaned membership (user deleted, membership row remains) inflates the count",
		found.Spammembers, len(spamMembers))
}
