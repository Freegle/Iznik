package test

import (
	json2 "encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/microvolunteering"
	"github.com/stretchr/testify/assert"
)

// When the earned-reach gate is on (RIPPLE_EARNED_REACH_ENABLED), the CheckMessage reviewer pool
// is bounded to the post's reach: only posts whose rippling_reach max_drive_min covers the reviewer
// (straight-line distance <= max_drive_min * 1400 m) are offered; posts with no reach row are
// included regardless (fail-open). With the gate off the bound is lifted entirely.
func TestGetMicrovolunteering_CheckMessage_ReachBounded(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("mv_reachbound")

	reviewerID := CreateTestUser(t, prefix+"_reviewer", "User")
	db.Exec(`UPDATE users SET settings = '{"mylocation":{"lat":51.5,"lng":-0.1}}' WHERE id = ?`, reviewerID)
	_, token := CreateTestSession(t, reviewerID)
	senderID := CreateTestUser(t, prefix+"_sender", "User")

	groupID := CreateTestGroup(t, prefix)
	db.Exec("UPDATE `groups` SET microvolunteering = 1 WHERE id = ?", groupID)
	CreateTestMembership(t, reviewerID, groupID, "Member")
	CreateTestMembership(t, senderID, groupID, "Member")

	// Suppress the Invite challenge for this reviewer.
	db.Exec("INSERT INTO microactions (actiontype, userid, version, timestamp, score_negative) VALUES (?, ?, 4, NOW(), 0)",
		microvolunteering.ChallengeInvite, reviewerID)
	defer db.Exec("DELETE FROM microactions WHERE userid = ?", reviewerID)

	nearID := CreateTestMessage(t, senderID, groupID, "OFFER: near "+prefix, 51.5, -0.1)
	defer db.Exec("DELETE FROM messages WHERE id = ?", nearID)
	defer db.Exec("DELETE FROM messages_spatial WHERE msgid = ?", nearID)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid = ?", nearID)
	farID := CreateTestMessage(t, senderID, groupID, "OFFER: far "+prefix, 57.0, -3.0)
	defer db.Exec("DELETE FROM messages WHERE id = ?", farID)
	defer db.Exec("DELETE FROM messages_spatial WHERE msgid = ?", farID)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid = ?", farID)

	// Near: max_drive_min=60 -> ~84 km radius; reviewer at the origin -> included.
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, max_drive_min) VALUES "+
		"(?, 51.5, -0.1, ST_GeomFromText('POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))', 3857), "+
		"ST_Envelope(ST_GeomFromText('POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))', 3857)), 60) "+
		"ON DUPLICATE KEY UPDATE max_drive_min=60", nearID)
	// Far: max_drive_min=30 -> ~42 km radius; reviewer ~600 km away -> excluded.
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, max_drive_min) VALUES "+
		"(?, 57.0, -3.0, ST_GeomFromText('POLYGON((-3.1 56.9,-2.9 56.9,-2.9 57.1,-3.1 57.1,-3.1 56.9))', 3857), "+
		"ST_Envelope(ST_GeomFromText('POLYGON((-3.1 56.9,-2.9 56.9,-2.9 57.1,-3.1 57.1,-3.1 56.9))', 3857)), 30) "+
		"ON DUPLICATE KEY UPDATE max_drive_min=30", farID)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid IN (?, ?)", nearID, farID)

	// Gate on: only the near post is in reach.
	t.Setenv("RIPPLE_EARNED_REACH_ENABLED", "true")
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+token+"&types=CheckMessage", nil))
	assert.Equal(t, 200, resp.StatusCode)
	var got microvolunteering.Challenge
	json2.Unmarshal(rsp(resp), &got)
	assert.Equal(t, microvolunteering.ChallengeCheckMessage, got.Type)
	if assert.NotNil(t, got.Msgid, "expected a near-post challenge") {
		assert.Equal(t, nearID, *got.Msgid, "near post served")
		assert.NotEqual(t, farID, *got.Msgid, "far post must be excluded under the reach bound")
	}

	// Exhaust the near post for this reviewer, then with the gate off the far post becomes accessible.
	db.Exec("INSERT INTO microactions (userid, msgid, actiontype, result, timestamp, score_negative) "+
		"VALUES (?, ?, 'CheckMessage', 'Approve', NOW(), 0)", reviewerID, nearID)

	t.Setenv("RIPPLE_EARNED_REACH_ENABLED", "false")
	resp2, _ := getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+token+"&types=CheckMessage", nil))
	assert.Equal(t, 200, resp2.StatusCode)
	var got2 microvolunteering.Challenge
	json2.Unmarshal(rsp(resp2), &got2)
	if got2.Type == microvolunteering.ChallengeCheckMessage && got2.Msgid != nil {
		assert.Equal(t, farID, *got2.Msgid, "with the gate off, the far post is accessible (fail-open)")
	}
}

// With the gate on, the nearest in-reach post is offered first even when a farther (but still
// in-reach) post was posted earlier - the ORDER BY is distance-first, arrival as tiebreak.
func TestGetMicrovolunteering_CheckMessage_NearFirst(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("mv_nearfirst")
	t.Setenv("RIPPLE_EARNED_REACH_ENABLED", "true")

	reviewerID := CreateTestUser(t, prefix+"_reviewer", "User")
	db.Exec(`UPDATE users SET settings = '{"mylocation":{"lat":51.5,"lng":-0.1}}' WHERE id = ?`, reviewerID)
	_, token := CreateTestSession(t, reviewerID)
	senderID := CreateTestUser(t, prefix+"_sender", "User")
	groupID := CreateTestGroup(t, prefix)
	db.Exec("UPDATE `groups` SET microvolunteering = 1 WHERE id = ?", groupID)
	CreateTestMembership(t, reviewerID, groupID, "Member")
	CreateTestMembership(t, senderID, groupID, "Member")

	db.Exec("INSERT INTO microactions (actiontype, userid, version, timestamp, score_negative) VALUES (?, ?, 4, NOW(), 0)",
		microvolunteering.ChallengeInvite, reviewerID)
	defer db.Exec("DELETE FROM microactions WHERE userid = ?", reviewerID)

	// Far-but-in-reach post, inserted FIRST (earlier arrival); max_drive_min=180 covers London.
	farNorthID := CreateTestMessage(t, senderID, groupID, "OFFER: midlands "+prefix, 52.4, -1.9)
	defer db.Exec("DELETE FROM messages WHERE id = ?", farNorthID)
	defer db.Exec("DELETE FROM messages_spatial WHERE msgid = ?", farNorthID)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid = ?", farNorthID)
	// Near post, inserted SECOND (later arrival).
	nearID := CreateTestMessage(t, senderID, groupID, "OFFER: close "+prefix, 51.51, -0.09)
	defer db.Exec("DELETE FROM messages WHERE id = ?", nearID)
	defer db.Exec("DELETE FROM messages_spatial WHERE msgid = ?", nearID)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid = ?", nearID)

	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, max_drive_min) VALUES "+
		"(?, 52.4, -1.9, ST_GeomFromText('POLYGON((-2.0 52.3,-1.8 52.3,-1.8 52.5,-2.0 52.5,-2.0 52.3))', 3857), "+
		"ST_Envelope(ST_GeomFromText('POLYGON((-2.0 52.3,-1.8 52.3,-1.8 52.5,-2.0 52.5,-2.0 52.3))', 3857)), 180) "+
		"ON DUPLICATE KEY UPDATE max_drive_min=180", farNorthID)
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, max_drive_min) VALUES "+
		"(?, 51.51, -0.09, ST_GeomFromText('POLYGON((-0.1 51.5,0.0 51.5,0.0 51.52,-0.1 51.52,-0.1 51.5))', 3857), "+
		"ST_Envelope(ST_GeomFromText('POLYGON((-0.1 51.5,0.0 51.5,0.0 51.52,-0.1 51.52,-0.1 51.5))', 3857)), 60) "+
		"ON DUPLICATE KEY UPDATE max_drive_min=60", nearID)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid IN (?, ?)", farNorthID, nearID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+token+"&types=CheckMessage", nil))
	assert.Equal(t, 200, resp.StatusCode)
	var got microvolunteering.Challenge
	json2.Unmarshal(rsp(resp), &got)
	assert.Equal(t, microvolunteering.ChallengeCheckMessage, got.Type)
	if assert.NotNil(t, got.Msgid, "expected a challenge") {
		assert.Equal(t, nearID, *got.Msgid, "nearest in-reach post is offered first despite an earlier-arrival farther post")
	}
}
