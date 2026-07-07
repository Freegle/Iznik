package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/chat"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

// TestCreateChatMessage_ReachBlockedReplyRejected verifies the rippling reply gate on the WRITE
// path: an in-app reply to a post whose reach has not yet reached the replier is rejected (403),
// not just hidden in the UI. The UI gate (replyeligible / ?reply= link) is bypassable by a stale
// or modified client, so the server must enforce it. Once the reach covers the replier, the
// reply is accepted. Fails open when no reach row exists (post isn't rippling).
func TestCreateChatMessage_ReachBlockedReplyRejected(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("reachreply")

	// Self-sufficient: rippling_reach belongs to PR A (merges before PR E). Use the SAME
	// stand-in schema as the other reach tests (isochrone_reach_test, message_reply_eligible_test)
	// — Go tests share one DB with CREATE TABLE IF NOT EXISTS, so a narrower schema here would
	// break their lat/lng/status inserts (whichever test runs first wins the table).
	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
		polygon GEOMETRY NOT NULL SRID 3857,
		status VARCHAR(16) NOT NULL DEFAULT 'expanding',
		SPATIAL INDEX msgreach_poly (polygon)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	replierID := CreateTestUser(t, prefix+"_replier", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	CreateTestMembership(t, replierID, groupID, "Member")
	// GetLatLng reads settings.mylocation first — put the replier at (51.5, -0.1).
	db.Exec(`UPDATE users SET settings = '{"mylocation":{"lat":51.5,"lng":-0.1}}' WHERE id = ?`, replierID)

	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: reach reply test item", 51.5, -0.1)

	// Reach exists but does NOT cover the replier (far to the east). lat/lng are NOT NULL.
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon) VALUES (?, 51.5, -0.1, ST_GeomFromText("+
		"'POLYGON((5.0 51.4,5.2 51.4,5.2 51.6,5.0 51.6,5.0 51.4))', 3857))", msgID)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)

	chatID := CreateTestChatRoom(t, replierID, &posterID, nil, "User2User")
	_, token := CreateTestSession(t, replierID)

	post := func() int {
		var payload chat.ChatMessage
		payload.Message = "I'd like this please"
		payload.Refmsgid = &msgID
		s, _ := json.Marshal(payload)
		req := httptest.NewRequest("POST", fmt.Sprintf("/api/chat/%d/message?jwt=%s", chatID, token), bytes.NewBuffer(s))
		req.Header.Set("Content-Type", "application/json")
		resp, _ := getApp().Test(req)
		return resp.StatusCode
	}

	assert.Equal(t, fiber.StatusForbidden, post(), "in-app reply rejected when replier is outside the post's reach")

	// Reach grows to cover the replier → accepted.
	db.Exec("UPDATE rippling_reach SET polygon = ST_GeomFromText("+
		"'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))', 3857) WHERE msgid = ?", msgID)
	assert.Equal(t, fiber.StatusOK, post(), "in-app reply accepted once the reach covers the replier")
}

// TestCreateChatMessage_ReportToModsNotReachGated verifies the reach gate does NOT apply to a
// report. A report goes to the group's mods (User2Mod) and carries refmsgid (to link the reported
// post), so CreateChatMessage types it CHAT_MESSAGE_INTERESTED — the same type as an Interested
// reply. But reporting must work regardless of the reporter's location, even for a rippled post
// whose reach hasn't reached them (Discourse #9852: a report of a rippled South-London post 403'd
// because the reporter was outside its reach polygon). The gate is scoped to User2User, so the
// identical setup that rejects a reply (above) must ACCEPT a report.
func TestCreateChatMessage_ReportToModsNotReachGated(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("reachreport")

	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
		polygon GEOMETRY NOT NULL SRID 3857,
		status VARCHAR(16) NOT NULL DEFAULT 'expanding',
		SPATIAL INDEX msgreach_poly (polygon)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	reporterID := CreateTestUser(t, prefix+"_reporter", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	// Reporter is at (51.5, -0.1) and — like Neville in #9852 — is NOT a member of the post's group.
	db.Exec(`UPDATE users SET settings = '{"mylocation":{"lat":51.5,"lng":-0.1}}' WHERE id = ?`, reporterID)

	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: reach report test item", 51.5, -0.1)

	// Reach exists but does NOT cover the reporter (far to the east) — this is exactly the polygon
	// that 403s a User2User reply in the test above.
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon) VALUES (?, 51.5, -0.1, ST_GeomFromText("+
		"'POLYGON((5.0 51.4,5.2 51.4,5.2 51.6,5.0 51.6,5.0 51.4))', 3857))", msgID)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)

	// Report chat: reporter -> the group's mods (User2Mod), reporter is user1 so is authorised.
	chatID := CreateTestChatRoom(t, reporterID, nil, &groupID, "User2Mod")
	_, token := CreateTestSession(t, reporterID)

	var payload chat.ChatMessage
	payload.Message = "I'm reporting this post as inappropriate: it's written as a sale."
	payload.Refmsgid = &msgID
	s, _ := json.Marshal(payload)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/chat/%d/message?jwt=%s", chatID, token), bytes.NewBuffer(s))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)

	assert.Equal(t, fiber.StatusOK, resp.StatusCode,
		"a report to mods (User2Mod) must NOT be reach-gated even when the reporter is outside the post's reach")

	// A report is not a reply: it must not pollute the reply-source metric with an
	// attribution row (the capture is scoped to User2User).
	var reportRows int
	db.Raw("SELECT COUNT(*) FROM rippling_reply_attribution WHERE msgid = ? AND userid = ?",
		msgID, reporterID).Scan(&reportRows)
	assert.Equal(t, 0, reportRows, "no reply-attribution row is recorded for a report")
}

// attributionRow is the full evidence snapshot the graded capture writes; shared by the
// attribution tests below.
type attributionRow struct {
	WasHomeMember  int     `gorm:"column:was_home_member"`
	WasNotified    *int    `gorm:"column:was_notified"`
	WasRippleGroup *int    `gorm:"column:was_ripple_group_member"`
	InOrigin       *int    `gorm:"column:in_origin_catchment"`
	InReach        *int    `gorm:"column:in_reach"`
	PostHadRippled *int    `gorm:"column:post_had_rippled"`
	Attribution    *string `gorm:"column:attribution"`
	ClientSource   *string `gorm:"column:client_source"`
}

func fetchAttribution(t *testing.T, msgID, uid uint64) (attributionRow, bool) {
	var rows []attributionRow
	database.DBConn.Raw("SELECT was_home_member, was_notified, was_ripple_group_member, "+
		"in_origin_catchment, in_reach, post_had_rippled, attribution, client_source "+
		"FROM rippling_reply_attribution WHERE msgid = ? AND userid = ?", msgID, uid).Scan(&rows)
	if len(rows) == 0 {
		return attributionRow{}, false
	}
	return rows[0], true
}

// postInterestedReply posts an Interested User2User reply to msgID as replierID, optionally
// carrying the client-reported surface, and returns the HTTP status.
func postInterestedReply(t *testing.T, replierID, posterID, msgID uint64, replysource string) int {
	chatID := CreateTestChatRoom(t, replierID, &posterID, nil, "User2User")
	_, token := CreateTestSession(t, replierID)
	var payload chat.ChatMessage
	payload.Message = "I'd like this please"
	payload.Refmsgid = &msgID
	if replysource != "" {
		payload.Replysource = &replysource
	}
	s, _ := json.Marshal(payload)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/chat/%d/message?jwt=%s", chatID, token), bytes.NewBuffer(s))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	return resp.StatusCode
}

// TestCreateChatMessage_RecordsReplyAttribution verifies the graded reply-source capture on a
// post that never rippled: an ESTABLISHED origin-group member -> home; a non-member -> unknown
// (the hard guard: with no ripple the reply can never be ripple-attributed - under the old
// single-bit scheme this non-member would have been silently credited to rippling).
func TestCreateChatMessage_RecordsReplyAttribution(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("replyattr")

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: reply attribution test item", 51.5, -0.1)
	defer db.Exec("DELETE FROM rippling_reply_attribution WHERE msgid = ?", msgID)

	// Established member: approved membership added an hour ago -> home.
	memberID := CreateTestUser(t, prefix+"_member", "User")
	CreateTestMembership(t, memberID, groupID, "Member")
	db.Exec("UPDATE memberships SET added = NOW() - INTERVAL 1 HOUR, collection = 'Approved' WHERE userid = ? AND groupid = ?", memberID, groupID)
	assert.Equal(t, fiber.StatusOK, postInterestedReply(t, memberID, posterID, msgID, ""))
	row, ok := fetchAttribution(t, msgID, memberID)
	assert.True(t, ok, "attribution row recorded for the established-member reply")
	assert.Equal(t, 1, row.WasHomeMember, "established member reply recorded as home-group")
	if assert.NotNil(t, row.Attribution) {
		assert.Equal(t, "home", *row.Attribution)
	}

	// Non-member on a NEVER-RIPPLED post: not home, but not rippling either - unknown, with
	// the hard-guard evidence bit post_had_rippled = 0 frozen in the row.
	nonMemberID := CreateTestUser(t, prefix+"_nonmember", "User")
	assert.Equal(t, fiber.StatusOK, postInterestedReply(t, nonMemberID, posterID, msgID, ""))
	row, ok = fetchAttribution(t, msgID, nonMemberID)
	assert.True(t, ok, "attribution row recorded for the non-member reply")
	assert.Equal(t, 0, row.WasHomeMember)
	if assert.NotNil(t, row.PostHadRippled) {
		assert.Equal(t, 0, *row.PostHadRippled, "post never rippled -> hard guard bit is 0")
	}
	if assert.NotNil(t, row.Attribution) {
		assert.Equal(t, "unknown", *row.Attribution,
			"a reply to a never-rippled post is never credited to rippling")
	}
}

// TestCreateChatMessage_AttributionLadder walks the graded ladder end-to-end on a rippled post:
// notified-ledger hit -> ripple_notified; established member of the rippled-into group ->
// ripple_group; non-member inside the origin catchment -> organic_local (would have seen it in
// Browse anyway - outranks ripple_reach because the reach polygon always covers the origin area);
// non-member outside the catchment but inside the reach -> ripple_reach. Also verifies the
// client-reported surface: a well-formed value is stored, an ill-formed one is dropped.
func TestCreateChatMessage_AttributionLadder(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("attrladder")

	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
		polygon GEOMETRY NOT NULL SRID 3857,
		status VARCHAR(16) NOT NULL DEFAULT 'expanding',
		SPATIAL INDEX msgreach_poly (polygon)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)
	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach_notified (
		msgid BIGINT UNSIGNED NOT NULL, userid BIGINT UNSIGNED NOT NULL,
		notified_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (msgid, userid), KEY rrn_userid (userid))`)

	originGroup := CreateTestGroup(t, prefix+"_origin")
	rippledGroup := CreateTestGroup(t, prefix+"_rippled")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	CreateTestMembership(t, posterID, originGroup, "Member")
	msgID := CreateTestMessage(t, posterID, originGroup, "OFFER: attribution ladder test item", 51.5, -0.1)
	defer db.Exec("DELETE FROM rippling_reply_attribution WHERE msgid = ?", msgID)
	defer db.Exec("DELETE FROM rippling_reach_notified WHERE msgid = ?", msgID)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)

	// Origin group's catchment covers (-0.1, 51.5) only; the reach polygon covers both that
	// and (0.5, 51.5) - reach always covers the origin area, which is exactly why
	// organic_local must outrank ripple_reach.
	db.Exec(fmt.Sprintf("UPDATE `groups` SET polyindex = ST_GeomFromText("+
		"'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))', %d) WHERE id = ?", utils.SRID),
		originGroup)
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon) VALUES (?, 51.5, -0.1, ST_GeomFromText("+
		"'POLYGON((-0.3 51.3,0.7 51.3,0.7 51.7,-0.3 51.7,-0.3 51.3))', 3857))", msgID)
	// The post rippled into a second group just now.
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in) "+
		"VALUES (?, ?, NOW(), 'Approved', 0, 1)", msgID, rippledGroup)

	// 1. Notified-ledger hit (no location on file, no memberships) -> ripple_notified.
	notifiedID := CreateTestUser(t, prefix+"_notified", "User")
	db.Exec("INSERT INTO rippling_reach_notified (msgid, userid, notified_at) VALUES (?, ?, NOW() - INTERVAL 1 HOUR)",
		msgID, notifiedID)
	assert.Equal(t, fiber.StatusOK, postInterestedReply(t, notifiedID, posterID, msgID, ""))
	row, ok := fetchAttribution(t, msgID, notifiedID)
	assert.True(t, ok)
	if assert.NotNil(t, row.Attribution) {
		assert.Equal(t, "ripple_notified", *row.Attribution)
	}
	if assert.NotNil(t, row.WasNotified) {
		assert.Equal(t, 1, *row.WasNotified)
	}
	assert.Nil(t, row.InOrigin, "no location on file -> location evidence is NULL, not 0")

	// 2. Established member of the group the post rippled INTO -> ripple_group.
	rippleMemberID := CreateTestUser(t, prefix+"_rgmember", "User")
	CreateTestMembership(t, rippleMemberID, rippledGroup, "Member")
	db.Exec("UPDATE memberships SET added = NOW() - INTERVAL 1 HOUR, collection = 'Approved' WHERE userid = ? AND groupid = ?",
		rippleMemberID, rippledGroup)
	assert.Equal(t, fiber.StatusOK, postInterestedReply(t, rippleMemberID, posterID, msgID, ""))
	row, ok = fetchAttribution(t, msgID, rippleMemberID)
	assert.True(t, ok)
	if assert.NotNil(t, row.Attribution) {
		assert.Equal(t, "ripple_group", *row.Attribution)
	}

	// 3. Non-member INSIDE the origin catchment (and inside the reach) -> organic_local, with
	//    a well-formed client surface stored verbatim.
	localID := CreateTestUser(t, prefix+"_local", "User")
	db.Exec(`UPDATE users SET settings = '{"mylocation":{"lat":51.5,"lng":-0.1}}' WHERE id = ?`, localID)
	assert.Equal(t, fiber.StatusOK, postInterestedReply(t, localID, posterID, msgID, "browse"))
	row, ok = fetchAttribution(t, msgID, localID)
	assert.True(t, ok)
	if assert.NotNil(t, row.Attribution) {
		assert.Equal(t, "organic_local", *row.Attribution,
			"inside the origin catchment outranks inside the reach")
	}
	if assert.NotNil(t, row.InOrigin) {
		assert.Equal(t, 1, *row.InOrigin)
	}
	if assert.NotNil(t, row.InReach) {
		assert.Equal(t, 1, *row.InReach)
	}
	if assert.NotNil(t, row.ClientSource) {
		assert.Equal(t, "browse", *row.ClientSource)
	}

	// 4. Non-member OUTSIDE the origin catchment but inside the reach -> ripple_reach: their
	//    Browse exposure existed only because the ripple extended to them. An ill-formed
	//    client surface is dropped, not stored.
	reachID := CreateTestUser(t, prefix+"_reach", "User")
	db.Exec(`UPDATE users SET settings = '{"mylocation":{"lat":51.5,"lng":0.5}}' WHERE id = ?`, reachID)
	assert.Equal(t, fiber.StatusOK, postInterestedReply(t, reachID, posterID, msgID, "Bad Source!"))
	row, ok = fetchAttribution(t, msgID, reachID)
	assert.True(t, ok)
	if assert.NotNil(t, row.Attribution) {
		assert.Equal(t, "ripple_reach", *row.Attribution)
	}
	if assert.NotNil(t, row.InOrigin) {
		assert.Equal(t, 0, *row.InOrigin)
	}
	assert.Nil(t, row.ClientSource, "an ill-formed client surface is dropped")
}
