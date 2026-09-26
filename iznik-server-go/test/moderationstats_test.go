package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

func TestModerationStats_NonAdminForbidden(t *testing.T) {
	prefix := uniquePrefix("modstats_unauth")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	today := time.Now().Format("2006-01-02")
	resp, err := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/modtools/moderationstats?start=%s&end=%s&jwt=%s", today, today, token), nil))
	assert.NoError(t, err)
	assert.Equal(t, 403, resp.StatusCode)
}

func TestModerationStats_RequiresDates(t *testing.T) {
	prefix := uniquePrefix("modstats_nodate")
	adminID := CreateTestUser(t, prefix, "Admin")
	_, token := CreateTestSession(t, adminID)

	resp, err := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/modtools/moderationstats?jwt=%s", token), nil))
	assert.NoError(t, err)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestModerationStats_CountsAutoApprovedAndSample(t *testing.T) {
	prefix := uniquePrefix("modstats")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	nullPoster := CreateTestUser(t, prefix+"_null", "User")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	CreateTestMembership(t, nullPoster, groupID, "Member")
	db.Exec("UPDATE memberships SET ourPostingStatus = NULL WHERE userid = ? AND groupid = ?", nullPoster, groupID)
	_, adminToken := CreateTestSession(t, adminID)

	// An auto-approved (Checked path) post: Autoapproved log + content-checked.
	autoMsg := CreateTestMessage(t, nullPoster, groupID, prefix+" auto checked", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Approved', approvedby=NULL, approvedat=NOW(), contentcheck_checked_at=NOW() WHERE msgid=?", autoMsg)
	db.Exec("INSERT INTO logs (timestamp, type, subtype, msgid, groupid, user) VALUES (NOW(), 'Message', 'Autoapproved', ?, ?, ?)",
		autoMsg, groupID, nullPoster)

	// A quality-check sample post (held back for manual review).
	sampleMsg := CreateTestMessage(t, nullPoster, groupID, prefix+" quality sample", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Pending', quality_sample=1, arrival=NOW() WHERE msgid=?", sampleMsg)

	today := time.Now().Format("2006-01-02")
	resp, err := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/modtools/moderationstats?start=%s&end=%s&jwt=%s", today, today, adminToken), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var stats map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&stats)

	asFloat := func(k string) float64 {
		v, _ := stats[k].(float64)
		return v
	}
	// The auto-approved post is counted in the auto-approved (Checked) total.
	assert.GreaterOrEqual(t, asFloat("autoApproved"), float64(1))
	// The quality-check sample is counted.
	assert.GreaterOrEqual(t, asFloat("qualitySampled"), float64(1))

	db.Exec("DELETE FROM logs WHERE msgid IN (?, ?)", autoMsg, sampleMsg)
	db.Exec("DELETE FROM messages_groups WHERE msgid IN (?, ?)", autoMsg, sampleMsg)
	db.Exec("DELETE FROM messages WHERE id IN (?, ?)", autoMsg, sampleMsg)
}
