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
	// The sample is stamped as the automated review records its approve, and counted from then.
	db.Exec("INSERT INTO messages_automod (msgid, groupid, mode, chart_version, verdict, end_node, reason, path, created) "+
		"VALUES (?, ?, 'approve', 'v1', 'approve', 'test_end', NULL, '{}', NOW())", sampleMsg, groupID)
	defer db.Exec("DELETE FROM messages_automod WHERE msgid = ?", sampleMsg)

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
	db.Exec("UPDATE messages_groups SET collection='Approved', approvedby=NULL, approvedat=?, arrival=?, contentcheck_checked_at=? WHERE msgid=? AND groupid=?",
		winStart, winStart, winStart, msgT, groupA)
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
	// The clean path (AutoApproveCleanService) writes text='automod' on its Autoapproved log;
	// the 48h fallback writes none. So autoApprovedClean = 1 (msgA) of autoApproved = 2.
	db.Exec("INSERT INTO logs (timestamp, type, subtype, msgid, groupid, user, text) VALUES (?, 'Message', 'Autoapproved', ?, ?, ?, 'automod')", tAuto, msgA, groupID, poster)
	// autoLaterActioned = 1: a later Rejected on msgA by the mod (also +1 to manualRejected).
	db.Exec("INSERT INTO logs (timestamp, type, subtype, msgid, groupid, byuser, user) VALUES (?, 'Message', 'Rejected', ?, ?, ?, ?)", tLater, msgA, groupID, mod, poster)

	// autoApproved +1 (msgV): Autoapproved log + checked by microvolunteers (checkedat, no checkedby).
	msgV := CreateTestMessage(t, poster, groupID, prefix+" volunteers", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Approved', approvedby=NULL, approvedat=?, contentcheck_checked_at=?, checkedat=?, checkedby=NULL WHERE msgid=?",
		tAuto, tAuto, tAuto, msgV)
	db.Exec("INSERT INTO logs (timestamp, type, subtype, msgid, groupid, user) VALUES (?, 'Message', 'Autoapproved', ?, ?, ?)", tAuto, msgV, groupID, poster)

	// trusted = 1 (msgT): approved-live, DEFAULT member, no Autoapproved log.
	msgT := CreateTestMessage(t, poster, groupID, prefix+" trusted", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Approved', approvedby=NULL, approvedat=?, arrival=?, contentcheck_checked_at=? WHERE msgid=?", tAuto, tAuto, tAuto, msgT)

	// qualitySampled = 1 (msgQ) + qualitySampleBad = 1 (a Rejected on it, also +1 manualRejected).
	msgQ := CreateTestMessage(t, poster, groupID, prefix+" sample", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Pending', quality_sample=1, arrival=? WHERE msgid=?", tEarly, msgQ)
	// The sample is counted from the automated review's approve, which is what stamped it.
	db.Exec("INSERT INTO messages_automod (msgid, groupid, mode, chart_version, verdict, end_node, reason, path, created) "+
		"VALUES (?, ?, 'approve', 'v1', 'approve', 'test_end', NULL, '{}', ?)", msgQ, groupID, tEarly)
	db.Exec("INSERT INTO logs (timestamp, type, subtype, msgid, groupid, byuser, user) VALUES (?, 'Message', 'Rejected', ?, ?, ?, ?)", tLater, msgQ, groupID, mod, poster)

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
	assert.Equal(t, float64(2), f("autoApproved"), "autoApproved (msgA + msgV)")
	assert.Equal(t, float64(1), f("autoApprovedClean"), "autoApprovedClean (msgA: the clean path's Autoapproved log carries text 'automod')")
	assert.Equal(t, float64(1), f("trusted"), "trusted")
	assert.Equal(t, float64(2), f("autoModChecked"), "autoModChecked (moderator + microvolunteers)")
	assert.Equal(t, float64(1), f("autoModCheckedByVolunteers"), "autoModCheckedByVolunteers (msgV only)")
	assert.Equal(t, float64(1), f("autoLaterActioned"), "autoLaterActioned")
	assert.Equal(t, float64(1), f("qualitySampled"), "qualitySampled")
	assert.Equal(t, float64(1), f("qualitySampleBad"), "qualitySampleBad")
	assert.Equal(t, float64(1), f("qualitySampleDecided"), "qualitySampleDecided (the moderator's rejection is a verdict)")
	assert.Equal(t, float64(1), f("autoLaterActionedClean"), "autoLaterActionedClean (msgA: published by the clean path, then rejected)")

	db.Exec("DELETE FROM messages_automod WHERE msgid = ?", msgQ)
	db.Exec("DELETE FROM logs WHERE timestamp BETWEEN ? AND ?", "2005-06-13 00:00:00", "2005-06-13 23:59:59")
	db.Exec("DELETE FROM messages_groups WHERE msgid IN (?, ?, ?, ?)", msgA, msgV, msgT, msgQ)
	db.Exec("DELETE FROM messages WHERE id IN (?, ?, ?, ?)", msgA, msgV, msgT, msgQ)
}

// The Trusted count reads the copies approved in the window. messages_groups has no index on
// approvedat, so on its own that is a scan of the whole table (tens of millions of rows) for a
// panel that loads a month by default; arrival is indexed and, for a copy that publishes by
// itself, is the same moment as approval or a minute or so before it. So the count is bounded by
// both, and reads only the window's rows.
func TestModerationStats_TrustedIsBoundedByArrival(t *testing.T) {
	prefix := uniquePrefix("modstats_trustbound")
	db := database.DBConn

	const day = "2005-06-12"
	const inWindow = "2005-06-12 10:00:00"
	const longBefore = "2004-01-01 10:00:00"

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	CreateTestMembership(t, poster, groupID, "Member")
	db.Exec("UPDATE memberships SET ourPostingStatus='DEFAULT' WHERE userid=? AND groupid=?", poster, groupID)
	_, adminToken := CreateTestSession(t, adminID)

	counted := CreateTestMessage(t, poster, groupID, prefix+" arrived and approved in the window", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Approved', approvedby=NULL, approvedat=?, arrival=?, contentcheck_checked_at=? WHERE msgid=?",
		inWindow, inWindow, inWindow, counted)
	// Approved in the window, but it arrived long before: outside the arrival bound.
	outside := CreateTestMessage(t, poster, groupID, prefix+" arrived long before", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Approved', approvedby=NULL, approvedat=?, arrival=?, contentcheck_checked_at=? WHERE msgid=?",
		inWindow, longBefore, inWindow, outside)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid IN (?, ?)", counted, outside)
	defer db.Exec("DELETE FROM messages WHERE id IN (?, ?)", counted, outside)

	resp, err := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/modtools/moderationstats?start=%s&end=%s&jwt=%s", day, day, adminToken), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	var stats map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&stats)
	trusted, _ := stats["trusted"].(float64)
	assert.Equal(t, float64(1), trusted, "the post that arrived and was approved in the window counts; the one that arrived long before is outside the bound")
}

// sampleCopy makes a copy held back as a quality sample: stamped when the automated review recorded
// its approve at decided, and with arrival wherever the caller says (a moderator's approve
// re-stamps it to the time of the approval).
func sampleCopy(t *testing.T, poster, groupID uint64, name, decided, arrival string, sampled bool) uint64 {
	db := database.DBConn
	msg := CreateTestMessage(t, poster, groupID, name, 52.0, -1.0)
	flag := 0
	if sampled {
		flag = 1
	}
	db.Exec("UPDATE messages_groups SET collection='Pending', quality_sample=?, arrival=? WHERE msgid=?", flag, arrival, msg)
	db.Exec("INSERT INTO messages_automod (msgid, groupid, mode, chart_version, verdict, end_node, reason, path, created) "+
		"VALUES (?, ?, 'approve', 'v1', 'approve', 'test_end', NULL, '{}', ?)", msg, groupID, decided)
	return msg
}

// The quality sample is counted in the window its approve was recorded in, which is when it was
// drawn, and not by messages_groups.arrival: a moderator's approve re-stamps arrival to the time
// of the approval, so approved samples drifted into the window after the one they belonged to
// while rejected ones stayed. And the rate of bad ones is of those a moderator has decided, not
// of every sample, most of which are still waiting for someone to look.
func TestModerationStats_QualitySampleIsBucketedByTheDecisionAndDividedByDecided(t *testing.T) {
	prefix := uniquePrefix("modstats_sample")
	db := database.DBConn

	const day = "2005-06-16"
	const inWindow = "2005-06-16 10:00:00"
	const verdictAt = "2005-06-16 11:00:00"
	const beforeSample = "2005-06-16 09:00:00"
	const longBefore = "2005-06-09 10:00:00"
	const afterWindow = "2005-06-30 09:00:00"

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	mod := CreateTestUser(t, prefix+"_mod", "User")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	CreateTestMembership(t, poster, groupID, "Member")
	_, adminToken := CreateTestSession(t, adminID)

	verdict := func(msg uint64, subtype string, at string, by uint64) {
		db.Exec("INSERT INTO logs (timestamp, type, subtype, msgid, groupid, byuser, user) VALUES (?, 'Message', ?, ?, ?, ?, ?)",
			at, subtype, msg, groupID, by, poster)
	}

	// Approved by a moderator, whose approve re-stamped arrival to long after the window.
	approved := sampleCopy(t, poster, groupID, prefix+" approved", inWindow, afterWindow, true)
	verdict(approved, "Approved", verdictAt, mod)
	// Rejected by a moderator: a bad one.
	rejected := sampleCopy(t, poster, groupID, prefix+" rejected", inWindow, inWindow, true)
	verdict(rejected, "Rejected", verdictAt, mod)
	// Still waiting for a verdict, arrival also re-stamped away from the window.
	waiting := sampleCopy(t, poster, groupID, prefix+" waiting", inWindow, afterWindow, true)
	// The poster withdrew it themselves: not a moderator's verdict.
	withdrawn := sampleCopy(t, poster, groupID, prefix+" withdrawn", inWindow, inWindow, true)
	verdict(withdrawn, "Deleted", verdictAt, poster)
	// An approval from before the sample was drawn is not a verdict on the sample.
	earlier := sampleCopy(t, poster, groupID, prefix+" earlier approval", inWindow, inWindow, true)
	verdict(earlier, "Approved", beforeSample, mod)
	// Drawn long before the window, arrival (and a verdict) inside it: not this window's.
	old := sampleCopy(t, poster, groupID, prefix+" old sample", longBefore, inWindow, true)
	verdict(old, "Rejected", verdictAt, mod)
	// Not in the sample at all.
	plain := sampleCopy(t, poster, groupID, prefix+" not sampled", inWindow, inWindow, false)
	verdict(plain, "Approved", verdictAt, mod)

	msgs := []uint64{approved, rejected, waiting, withdrawn, earlier, old, plain}
	defer func() {
		for _, m := range msgs {
			db.Exec("DELETE FROM messages_automod WHERE msgid = ?", m)
			db.Exec("DELETE FROM messages_groups WHERE msgid = ?", m)
			db.Exec("DELETE FROM messages WHERE id = ?", m)
		}
		db.Exec("DELETE FROM logs WHERE timestamp BETWEEN ? AND ?", "2005-06-16 00:00:00", "2005-06-16 23:59:59")
	}()

	resp, err := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/modtools/moderationstats?start=%s&end=%s&jwt=%s", day, day, adminToken), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	var stats map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&stats)
	f := func(k string) float64 { v, _ := stats[k].(float64); return v }

	assert.Equal(t, float64(5), f("qualitySampled"), "drawn in the window: approved, rejected, waiting, withdrawn, earlier approval")
	assert.Equal(t, float64(2), f("qualitySampleDecided"), "a moderator has decided two: the approved one and the rejected one")
	assert.Equal(t, float64(1), f("qualitySampleBad"), "of which one was rejected")
}

// autoLaterActioned counts every auto-published post that was later acted on, the 48-hour
// fallback's included; the trial's own error rate needs the same count over the posts the clean
// path published (the Autoapproved log's text is 'automod'), to set against autoApprovedClean.
func TestModerationStats_LaterActionedCleanCountsOnlyThePostsTheTrialPublished(t *testing.T) {
	prefix := uniquePrefix("modstats_clean")
	db := database.DBConn

	const day = "2005-06-17"
	const published = "2005-06-17 10:00:00"
	const actedOn = "2005-06-17 11:00:00"

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	mod := CreateTestUser(t, prefix+"_mod", "User")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	CreateTestMembership(t, poster, groupID, "Member")
	_, adminToken := CreateTestSession(t, adminID)

	autoPublished := func(name, text string, actedOnAfter string) uint64 {
		msg := CreateTestMessage(t, poster, groupID, prefix+" "+name, 52.0, -1.0)
		db.Exec("INSERT INTO logs (timestamp, type, subtype, msgid, groupid, user, text) VALUES (?, 'Message', 'Autoapproved', ?, ?, ?, ?)",
			published, msg, groupID, poster, text)
		if actedOnAfter != "" {
			db.Exec("INSERT INTO logs (timestamp, type, subtype, msgid, groupid, byuser, user) VALUES (?, 'Message', ?, ?, ?, ?, ?)",
				actedOn, actedOnAfter, msg, groupID, mod, poster)
		}
		return msg
	}
	cleanRejected := autoPublished("clean rejected", "automod", "Rejected")
	cleanQuiet := autoPublished("clean quiet", "automod", "")
	fallbackDeleted := autoPublished("fallback deleted", "", "Deleted")
	msgs := []uint64{cleanRejected, cleanQuiet, fallbackDeleted}
	defer func() {
		for _, m := range msgs {
			db.Exec("DELETE FROM messages_groups WHERE msgid = ?", m)
			db.Exec("DELETE FROM messages WHERE id = ?", m)
		}
		db.Exec("DELETE FROM logs WHERE timestamp BETWEEN ? AND ?", "2005-06-17 00:00:00", "2005-06-17 23:59:59")
	}()

	resp, err := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/modtools/moderationstats?start=%s&end=%s&jwt=%s", day, day, adminToken), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	var stats map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&stats)
	f := func(k string) float64 { v, _ := stats[k].(float64); return v }

	assert.Equal(t, float64(3), f("autoApproved"), "all three were auto-published")
	assert.Equal(t, float64(2), f("autoApprovedClean"), "two by the clean path")
	assert.Equal(t, float64(2), f("autoLaterActioned"), "two were acted on afterwards: one clean, one fallback")
	assert.Equal(t, float64(1), f("autoLaterActionedClean"), "of the clean path's, one")
}
