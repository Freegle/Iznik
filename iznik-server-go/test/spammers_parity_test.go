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

// V1 parity: when a mod views a user with a PendingAdd spam_users row,
// GET /user/{id} returns spammer as a rich object {collection, reason, byuserid, ...}
// so ModSpammer.vue can display "Unconfirmed Spammer".
func TestGetUserSpammerPendingAddObjectForMods(t *testing.T) {
	prefix := uniquePrefix("SpamParPA")
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	_, modToken := CreateTestSession(t, modID)

	targetID := CreateTestUser(t, prefix+"_target", "User")
	reporterID := CreateTestUser(t, prefix+"_reporter", "User")

	db := database.DBConn
	db.Exec("REPLACE INTO spam_users (userid, collection, reason, byuserid) VALUES (?, 'PendingAdd', 'Looks dodgy', ?)",
		targetID, reporterID)

	url := fmt.Sprintf("/api/user/%d?modtools=true&jwt=%s", targetID, modToken)
	resp, err := getApp().Test(httptest.NewRequest("GET", url, nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	err = json.NewDecoder(resp.Body).Decode(&result)
	assert.NoError(t, err)

	spammer, ok := result["spammer"].(map[string]interface{})
	assert.True(t, ok, "spammer should be an object for mods viewing a PendingAdd user, got %T: %v", result["spammer"], result["spammer"])
	assert.Equal(t, "PendingAdd", spammer["collection"])
	assert.Equal(t, "Looks dodgy", spammer["reason"])
	assert.Equal(t, float64(reporterID), spammer["byuserid"])
	assert.NotNil(t, spammer["added"])
	assert.NotNil(t, spammer["id"])
}

// V1 parity: confirmed Spammer row also gives mods a rich object (not a bare bool).
func TestGetUserSpammerConfirmedObjectForMods(t *testing.T) {
	prefix := uniquePrefix("SpamParCS")
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	_, modToken := CreateTestSession(t, modID)

	targetID := CreateTestUser(t, prefix+"_target", "User")
	adderID := CreateTestUser(t, prefix+"_adder", "Admin")

	db := database.DBConn
	db.Exec("REPLACE INTO spam_users (userid, collection, reason, byuserid) VALUES (?, 'Spammer', 'Confirmed', ?)",
		targetID, adderID)

	url := fmt.Sprintf("/api/user/%d?modtools=true&jwt=%s", targetID, modToken)
	resp, err := getApp().Test(httptest.NewRequest("GET", url, nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	err = json.NewDecoder(resp.Body).Decode(&result)
	assert.NoError(t, err)

	spammer, ok := result["spammer"].(map[string]interface{})
	assert.True(t, ok, "spammer should be an object for mods viewing a confirmed Spammer, got %T", result["spammer"])
	assert.Equal(t, "Spammer", spammer["collection"])
}

// V1 parity: non-mod member viewing a confirmed Spammer gets spammer=true (bool).
// This is so chat UI etc. can warn about confirmed spammers but not leak PendingAdd reports.
func TestGetUserSpammerConfirmedBoolForMembers(t *testing.T) {
	prefix := uniquePrefix("SpamParMB")
	userID := CreateTestUser(t, prefix+"_viewer", "User")
	_, userToken := CreateTestSession(t, userID)

	targetID := CreateTestUser(t, prefix+"_target", "User")
	db := database.DBConn
	db.Exec("REPLACE INTO spam_users (userid, collection, reason, byuserid) VALUES (?, 'Spammer', 'Confirmed', ?)",
		targetID, userID)

	url := fmt.Sprintf("/api/user/%d?jwt=%s", targetID, userToken)
	resp, err := getApp().Test(httptest.NewRequest("GET", url, nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	err = json.NewDecoder(resp.Body).Decode(&result)
	assert.NoError(t, err)

	// For non-mods, confirmed Spammer → true.
	assert.Equal(t, true, result["spammer"], "non-mods should get spammer=true for confirmed Spammer, got %v", result["spammer"])
}

// V1 parity: non-mod member viewing a user with ONLY a PendingAdd row sees spammer=false —
// pending reports must not leak to regular users.
func TestGetUserSpammerPendingAddHiddenFromMembers(t *testing.T) {
	prefix := uniquePrefix("SpamParMH")
	userID := CreateTestUser(t, prefix+"_viewer", "User")
	_, userToken := CreateTestSession(t, userID)

	targetID := CreateTestUser(t, prefix+"_target", "User")
	db := database.DBConn
	db.Exec("REPLACE INTO spam_users (userid, collection, reason, byuserid) VALUES (?, 'PendingAdd', 'Dodgy', ?)",
		targetID, userID)

	url := fmt.Sprintf("/api/user/%d?jwt=%s", targetID, userToken)
	resp, err := getApp().Test(httptest.NewRequest("GET", url, nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	err = json.NewDecoder(resp.Body).Decode(&result)
	assert.NoError(t, err)

	assert.Equal(t, false, result["spammer"], "non-mods must not see PendingAdd as spammer, got %v", result["spammer"])
}

// V1 parity (Spam.php addSpammer): reporting a user as PendingAdd sets
// users.newsfeedmodstatus = 'Suppressed' for SYSTEMROLE_USER targets,
// so their ChitChat/newsfeed posts are muted while pending review.
func TestPostSpammerPendingAddSuppressesNewsfeed(t *testing.T) {
	prefix := uniquePrefix("SpamParSup")
	reporterID := CreateTestUser(t, prefix+"_reporter", "User")
	_, reporterToken := CreateTestSession(t, reporterID)

	targetID := CreateTestUser(t, prefix+"_target", "User")
	// Sanity check: starts unsuppressed.
	db := database.DBConn
	db.Exec("UPDATE users SET newsfeedmodstatus = NULL WHERE id = ?", targetID)

	body := fmt.Sprintf(`{"userid":%d,"collection":"PendingAdd","reason":"Looks like spam"}`, targetID)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/modtools/spammers?jwt=%s", reporterToken), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var modstatus *string
	db.Raw("SELECT newsfeedmodstatus FROM users WHERE id = ?", targetID).Scan(&modstatus)
	assert.NotNil(t, modstatus, "newsfeedmodstatus should have been set after PendingAdd report")
	assert.Equal(t, "Suppressed", *modstatus, "reported user's newsfeed should be Suppressed")
}

// V1 parity: a second PendingAdd report must NOT overwrite the original byuserid
// (reason for Discourse #9589 wrong-attribution bug). V1 skips the REPLACE when a
// spam_users row already exists for that userid.
func TestPostSpammerPendingAddPreservesOriginalReporter(t *testing.T) {
	prefix := uniquePrefix("SpamParDup")

	firstReporterID := CreateTestUser(t, prefix+"_r1", "User")
	_, firstToken := CreateTestSession(t, firstReporterID)
	secondReporterID := CreateTestUser(t, prefix+"_r2", "User")
	_, secondToken := CreateTestSession(t, secondReporterID)

	targetID := CreateTestUser(t, prefix+"_target", "User")

	body := fmt.Sprintf(`{"userid":%d,"collection":"PendingAdd","reason":"First report"}`, targetID)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/modtools/spammers?jwt=%s", firstToken), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp1, _ := getApp().Test(req)
	assert.Equal(t, 200, resp1.StatusCode)

	// Second report by a different user — should be a no-op for byuserid/reason.
	body2 := fmt.Sprintf(`{"userid":%d,"collection":"PendingAdd","reason":"Second report"}`, targetID)
	req2 := httptest.NewRequest("POST", fmt.Sprintf("/api/modtools/spammers?jwt=%s", secondToken), strings.NewReader(body2))
	req2.Header.Set("Content-Type", "application/json")
	resp2, _ := getApp().Test(req2)
	assert.Equal(t, 200, resp2.StatusCode)

	db := database.DBConn
	var row struct {
		Byuserid   uint64
		Reason     string
		Collection string
	}
	db.Raw("SELECT byuserid, reason, collection FROM spam_users WHERE userid = ? ORDER BY id ASC LIMIT 1", targetID).Scan(&row)
	assert.Equal(t, firstReporterID, row.Byuserid, "first reporter must be preserved; last writer must not win")
	assert.Equal(t, "First report", row.Reason, "first reason must be preserved")
	assert.Equal(t, "PendingAdd", row.Collection)

	// And there should be exactly one row for this user (no duplicate insert).
	var count int64
	db.Raw("SELECT COUNT(*) FROM spam_users WHERE userid = ?", targetID).Scan(&count)
	assert.Equal(t, int64(1), count, "duplicate PendingAdd report must not create a second row")
}
