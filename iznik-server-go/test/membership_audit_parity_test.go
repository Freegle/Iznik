package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// Gap 1: per-group (Group, Left) audit log on self-delete.
//
// V1 writes one Group/Left log per group when the user actually leaves: at
// grace-period expiry the processForgets cron calls removeMembership() per group
// (User.php:1087-1095). V2 bulk-deletes approved memberships eagerly at delete
// time, so the Left logs must be emitted at that point — otherwise the audit
// trail V1 produced is lost entirely (the later cleanup cron finds no memberships
// to iterate). Matches the Nimmy940 report: membership vanished with only a
// User/Deleted log written.

// TestForgetWritesGroupLeftLog covers the session "Forget" self-delete path
// (POST /session action=Forget -> session.handleForget).
func TestForgetWritesGroupLeftLog(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("forget-left")
	userID := CreateTestUser(t, prefix, "User")
	groupID := CreateTestGroup(t, prefix)
	db.Exec("INSERT INTO memberships (userid, groupid, role, collection) VALUES (?, ?, 'Member', 'Approved')", userID, groupID)

	token := getToken(t, userID)
	req := httptest.NewRequest("POST", "/api/session?jwt="+token, strings.NewReader(`{"action":"Forget"}`))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var leftCount int64
	db.Raw("SELECT COUNT(*) FROM logs WHERE type = 'Group' AND subtype = 'Left' AND user = ? AND groupid = ?",
		userID, groupID).Scan(&leftCount)
	assert.Equal(t, int64(1), leftCount, "expected one Group/Left log for the user's group on self-delete")

	// The membership should be gone (V2 eager delete) and the User/Deleted log
	// present (existing parity must be preserved).
	var memberCount, deletedCount int64
	db.Raw("SELECT COUNT(*) FROM memberships WHERE userid = ? AND groupid = ?", userID, groupID).Scan(&memberCount)
	assert.Equal(t, int64(0), memberCount)
	db.Raw("SELECT COUNT(*) FROM logs WHERE type = 'User' AND subtype = 'Deleted' AND user = ?", userID).Scan(&deletedCount)
	assert.Equal(t, int64(1), deletedCount)
}

// TestLimboUserWritesGroupLeftLogPerGroup covers the DELETE /user self-delete
// path (user.LimboUser -> softLimboUser, shared with the Unsubscribe action) and
// asserts the Left log is written for EACH group the user belonged to.
func TestLimboUserWritesGroupLeftLogPerGroup(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("limbo-left")
	userID := CreateTestUser(t, prefix, "User")
	group1 := CreateTestGroup(t, prefix+"a")
	group2 := CreateTestGroup(t, prefix+"b")
	db.Exec("INSERT INTO memberships (userid, groupid, role, collection) VALUES (?, ?, 'Member', 'Approved')", userID, group1)
	db.Exec("INSERT INTO memberships (userid, groupid, role, collection) VALUES (?, ?, 'Member', 'Approved')", userID, group2)

	token := getToken(t, userID)
	req := httptest.NewRequest("DELETE", "/api/user?jwt="+token, nil)
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var left1, left2 int64
	db.Raw("SELECT COUNT(*) FROM logs WHERE type = 'Group' AND subtype = 'Left' AND user = ? AND groupid = ?", userID, group1).Scan(&left1)
	db.Raw("SELECT COUNT(*) FROM logs WHERE type = 'Group' AND subtype = 'Left' AND user = ? AND groupid = ?", userID, group2).Scan(&left2)
	assert.Equal(t, int64(1), left1, "expected a Group/Left log for group1")
	assert.Equal(t, int64(1), left2, "expected a Group/Left log for group2")
}

// Gap 2: memberships_history row on website signup.
//
// V1 addMembership() unconditionally writes memberships_history with
// processingrequired=1 (User.php:911-916), which the memberships:process consumer
// uses to subject brand-new joiners to welcome / abuse-detection / member-review
// scrutiny. V2's PutUser website-signup path inserts the membership inline and
// never calls AddMembership() (where the e041eded2 fix lives), so no history row
// was written and the new member bypassed that scrutiny. Matches Nimmy940: joined
// via the signup form, empty memberships_history.
func TestPutUserWithGroupWritesMembershipsHistory(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("puthist")
	groupID := CreateTestGroup(t, prefix)

	payload := map[string]interface{}{
		"email":     fmt.Sprintf("%s@test.com", prefix),
		"firstname": "Hist",
		"lastname":  "Joiner",
		"groupid":   groupID,
	}
	s, _ := json.Marshal(payload)
	req := httptest.NewRequest("PUT", "/api/user", strings.NewReader(string(s)))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)
	assert.Equal(t, float64(0), result["ret"])
	userID := uint64(result["id"].(float64))

	var histCount int64
	db.Raw("SELECT COUNT(*) FROM memberships_history WHERE userid = ? AND groupid = ? AND collection = 'Approved' AND processingrequired = 1",
		userID, groupID).Scan(&histCount)
	assert.Equal(t, int64(1), histCount, "expected a memberships_history row with processingrequired=1 for the signup join")
}
