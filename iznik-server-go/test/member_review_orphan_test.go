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

// TestMemberReviewOrphanCountVsListing — AssertFlip test for the phantom member-review badge.
//
// Root cause: the work-count query (groupWork.go) queries the memberships table directly
// with no JOIN to users, so it counts orphaned memberships whose user row has been deleted.
// The listing query (membership.go getSpamMembers) INNER-JOINs users, so the orphan is
// excluded.  Result: count=1 but listing=0 — a phantom badge that survives browser/PC restart.
//
// AssertFlip protocol:
//
//	STEP 1 (BUGGY)  — assert the buggy behaviour IS present (count=1, list=0).  Passes today.
//	STEP 2 (FLIPPED) — assert count==len(listing).  Fails today, passes after the fix.
//
// Only the STEP 2 assertion is committed; it gates the fix.
func TestMemberReviewOrphanCountVsListing(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("mr_orphan")

	// Setup: one moderator, one group.
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, token := CreateTestSession(t, modID)

	// Create a member flagged for review (reviewrequestedat set, reviewedat NULL).
	spamUserID := CreateTestUser(t, prefix+"_spam", "User")
	db.Exec(
		"INSERT INTO memberships (userid, groupid, role, collection, reviewrequestedat) "+
			"VALUES (?, ?, 'Member', 'Approved', NOW())",
		spamUserID, groupID,
	)
	var memID uint64
	db.Raw(
		"SELECT id FROM memberships WHERE userid = ? AND groupid = ? ORDER BY id DESC LIMIT 1",
		spamUserID, groupID,
	).Scan(&memID)

	// Orphan the membership: hard-delete the user row while the membership row stays.
	// Disabling FK checks simulates the pre-Dec-2025 state where no FK existed;
	// orphaned rows from that era still exist in production and are the source of the
	// phantom badge.
	db.Exec("SET FOREIGN_KEY_CHECKS=0")
	db.Exec("DELETE FROM users WHERE id = ?", spamUserID)
	db.Exec("SET FOREIGN_KEY_CHECKS=1")

	// Clean up the orphaned rows after the test (FK cascade won't fire now the user is gone).
	t.Cleanup(func() {
		db.Exec("SET FOREIGN_KEY_CHECKS=0")
		db.Exec("DELETE FROM memberships WHERE id = ?", memID)
		db.Exec("DELETE FROM users_emails WHERE userid = ?", spamUserID)
		db.Exec("SET FOREIGN_KEY_CHECKS=1")
	})

	// --- Work-count endpoint ---
	workResp, _ := getApp().Test(
		httptest.NewRequest("GET", "/api/group/work?jwt="+token, nil),
	)
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
	assert.NotNil(t, found, "Group %d should appear in work results", groupID)

	var countInWork int64
	if found != nil {
		// Active group + no heldby → orphan lands in Spammembers (not Spammembersother).
		countInWork = found.Spammembers
	}

	// --- Listing endpoint (collection=Spam, scoped to our group) ---
	listURL := fmt.Sprintf(
		"/api/memberships?collection=Spam&groupid=%d&limit=50&jwt=%s",
		groupID, token,
	)
	listResp, _ := getApp().Test(
		httptest.NewRequest("GET", listURL, nil),
	)
	assert.Equal(t, 200, listResp.StatusCode)

	var spamList []map[string]interface{}
	json2.Unmarshal(rsp(listResp), &spamList)

	// AssertFlip STEP 2 (FLIPPED): count must equal the listing length.
	//
	// Today this FAILS: countInWork=1 (orphaned membership is counted) but
	// len(spamList)=0 (the listing's JOIN users excludes the orphan).
	//
	// After the fix (count query also JOINs users): countInWork=0, len(spamList)=0 → passes.
	assert.Equal(t, int64(len(spamList)), countInWork,
		"work-count Spammembers (%d) must equal the number of members returned by the listing (%d): "+
			"an orphaned membership (user row deleted, membership row remains) must not inflate the count",
		countInWork, len(spamList),
	)
}
