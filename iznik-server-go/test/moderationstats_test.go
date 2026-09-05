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

// D4: the Trusted count's NOT EXISTS is scoped by groupid, so a cross-posted message
// that was auto-approved on one group still counts as trusted on the OTHER group.
func TestModerationStats_TrustedScopedByGroup(t *testing.T) {
	prefix := uniquePrefix("modstats_trustscope")
	db := database.DBConn

	// Isolated historical window so the global counts are exact.
	const winStart = "2005-06-14 00:00:00"
	const winEnd = "2005-06-14 12:00:00"
	const day = "2005-06-14"

	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")
	poster := CreateTestUser(t, prefix+"_poster", "User")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	CreateTestMembership(t, poster, groupA, "Member")
	CreateTestMembership(t, poster, groupB, "Member")
	db.Exec("UPDATE memberships SET ourPostingStatus='DEFAULT' WHERE userid=? AND groupid IN (?, ?)", poster, groupA, groupB)
	_, adminToken := CreateTestSession(t, adminID)

	// Cross-posted, trusted-live message: approved (approvedby NULL, content-checked)
	// on group A; an Autoapproved log exists only for group B.
	msgT := CreateTestMessage(t, poster, groupA, prefix+" trusted xpost", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Approved', approvedby=NULL, approvedat=?, contentcheck_checked_at=? WHERE msgid=? AND groupid=?",
		winStart, winStart, msgT, groupA)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, approvedby, approvedat, contentcheck_checked_at, deleted) "+
		"VALUES (?, ?, ?, 'Approved', 0, NULL, ?, ?, 0)", msgT, groupB, winStart, winStart, winStart)
	db.Exec("INSERT INTO logs (timestamp, type, subtype, msgid, groupid, user) VALUES (?, 'Message', 'Autoapproved', ?, ?, ?)",
		winStart, msgT, groupB, poster)

	resp, err := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/modtools/moderationstats?start=%s&end=%s&jwt=%s", day, day, adminToken), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	var stats map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&stats)

	// Group A's row must count as trusted; the Autoapproved log on group B must not
	// exclude it (it would, without the groupid-scoped NOT EXISTS).
	trusted, _ := stats["trusted"].(float64)
	assert.Equal(t, float64(1), trusted, "cross-posted trusted row on group A should count despite a group-B Autoapproved log")
	_ = winEnd

	db.Exec("DELETE FROM logs WHERE msgid=?", msgT)
	db.Exec("DELETE FROM messages_groups WHERE msgid=?", msgT)
	db.Exec("DELETE FROM messages WHERE id=?", msgT)
}

// D7: exact-count coverage for all nine moderation-stats fields, isolated in a unique
// historical window so the (mostly un-group-scoped) counts are deterministic.
func TestModerationStats_AllFieldsExactCounts(t *testing.T) {
	prefix := uniquePrefix("modstats_all")
	db := database.DBConn

	const day = "2005-06-13"
	const tEarly = "2005-06-13 09:00:00"
	const tAuto = "2005-06-13 10:00:00"
	const tLater = "2005-06-13 11:00:00"

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	mod := CreateTestUser(t, prefix+"_mod", "User")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	CreateTestMembership(t, poster, groupID, "Member")
	db.Exec("UPDATE memberships SET ourPostingStatus='DEFAULT' WHERE userid=? AND groupid=?", poster, groupID)
	_, adminToken := CreateTestSession(t, adminID)

	// arrived = 3 Received logs.
	for i := 0; i < 3; i++ {
		db.Exec("INSERT INTO logs (timestamp, type, subtype, user) VALUES (?, 'Message', 'Received', ?)", tEarly, poster)
	}
	// manualApproved = 1.
	db.Exec("INSERT INTO logs (timestamp, type, subtype, byuser, user) VALUES (?, 'Message', 'Approved', ?, ?)", tEarly, mod, poster)
	// A standalone manual Rejected (contributes 1 to manualRejected).
	db.Exec("INSERT INTO logs (timestamp, type, subtype, byuser, user) VALUES (?, 'Message', 'Rejected', ?, ?)", tEarly, mod, poster)

	// autoApproved = 1 (msgA): Autoapproved log + a checked messages_groups row.
	msgA := CreateTestMessage(t, poster, groupID, prefix+" auto", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Approved', approvedby=NULL, approvedat=?, contentcheck_checked_at=?, checkedat=?, checkedby=? WHERE msgid=?",
		tAuto, tAuto, tAuto, mod, msgA)
	db.Exec("INSERT INTO logs (timestamp, type, subtype, msgid, groupid, user) VALUES (?, 'Message', 'Autoapproved', ?, ?, ?)", tAuto, msgA, groupID, poster)
	// autoLaterActioned = 1: a later Rejected on msgA by the mod (also +1 to manualRejected).
	db.Exec("INSERT INTO logs (timestamp, type, subtype, msgid, groupid, byuser, user) VALUES (?, 'Message', 'Rejected', ?, ?, ?, ?)", tLater, msgA, groupID, mod, poster)

	// trusted = 1 (msgT): approved-live, DEFAULT member, no Autoapproved log.
	msgT := CreateTestMessage(t, poster, groupID, prefix+" trusted", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Approved', approvedby=NULL, approvedat=?, contentcheck_checked_at=? WHERE msgid=?", tAuto, tAuto, msgT)

	// qualitySampled = 1 (msgQ) + qualitySampleBad = 1 (a Rejected on it, also +1 manualRejected).
	msgQ := CreateTestMessage(t, poster, groupID, prefix+" sample", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Pending', quality_sample=1, arrival=? WHERE msgid=?", tEarly, msgQ)
	db.Exec("INSERT INTO logs (timestamp, type, subtype, msgid, groupid, byuser, user) VALUES (?, 'Message', 'Rejected', ?, ?, ?, ?)", tEarly, msgQ, groupID, mod, poster)

	resp, err := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/modtools/moderationstats?start=%s&end=%s&jwt=%s", day, day, adminToken), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	var stats map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&stats)
	f := func(k string) float64 { v, _ := stats[k].(float64); return v }

	assert.Equal(t, float64(3), f("arrived"), "arrived")
	assert.Equal(t, float64(1), f("manualApproved"), "manualApproved")
	assert.Equal(t, float64(3), f("manualRejected"), "manualRejected (standalone + msgA-later + msgQ-bad)")
	assert.Equal(t, float64(1), f("autoApproved"), "autoApproved")
	assert.Equal(t, float64(1), f("trusted"), "trusted")
	assert.Equal(t, float64(1), f("autoModChecked"), "autoModChecked")
	assert.Equal(t, float64(1), f("autoLaterActioned"), "autoLaterActioned")
	assert.Equal(t, float64(1), f("qualitySampled"), "qualitySampled")
	assert.Equal(t, float64(1), f("qualitySampleBad"), "qualitySampleBad")

	db.Exec("DELETE FROM logs WHERE timestamp BETWEEN ? AND ?", "2005-06-13 00:00:00", "2005-06-13 23:59:59")
	db.Exec("DELETE FROM messages_groups WHERE msgid IN (?, ?, ?)", msgA, msgT, msgQ)
	db.Exec("DELETE FROM messages WHERE id IN (?, ?, ?)", msgA, msgT, msgQ)
}
