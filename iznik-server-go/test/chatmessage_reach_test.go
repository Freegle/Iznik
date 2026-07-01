package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/chat"
	"github.com/freegle/iznik-server-go/database"
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
}

// TestCreateChatMessage_RecordsReplyAttribution verifies the rippling reply-source capture: posting
// an Interested reply records, in rippling_reply_attribution, whether the replier was an ESTABLISHED
// home-group member at reply time. A membership made well before the reply -> home (1). A non-member
// (and, identically, a join-to-reply whose membership is younger than the 300s grace) -> rippling (0).
func TestCreateChatMessage_RecordsReplyAttribution(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("replyattr")

	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reply_attribution (
		msgid BIGINT UNSIGNED NOT NULL, userid BIGINT UNSIGNED NOT NULL,
		replied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, was_home_member TINYINT(1) NOT NULL,
		PRIMARY KEY (msgid, userid), KEY rra_replied_at (replied_at))`)

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: reply attribution test item", 51.5, -0.1)
	defer db.Exec("DELETE FROM rippling_reply_attribution WHERE msgid = ?", msgID)

	postReply := func(replierID uint64) int {
		chatID := CreateTestChatRoom(t, replierID, &posterID, nil, "User2User")
		_, token := CreateTestSession(t, replierID)
		var payload chat.ChatMessage
		payload.Message = "I'd like this please"
		payload.Refmsgid = &msgID
		s, _ := json.Marshal(payload)
		req := httptest.NewRequest("POST", fmt.Sprintf("/api/chat/%d/message?jwt=%s", chatID, token), bytes.NewBuffer(s))
		req.Header.Set("Content-Type", "application/json")
		resp, _ := getApp().Test(req)
		return resp.StatusCode
	}
	attribution := func(uid uint64) (int, bool) {
		var rows []int
		db.Raw("SELECT was_home_member FROM rippling_reply_attribution WHERE msgid = ? AND userid = ?", msgID, uid).Scan(&rows)
		if len(rows) == 0 {
			return 0, false
		}
		return rows[0], true
	}

	// Established member: approved membership added an hour ago -> home (was_home_member = 1).
	memberID := CreateTestUser(t, prefix+"_member", "User")
	CreateTestMembership(t, memberID, groupID, "Member")
	db.Exec("UPDATE memberships SET added = NOW() - INTERVAL 1 HOUR, collection = 'Approved' WHERE userid = ? AND groupid = ?", memberID, groupID)
	assert.Equal(t, fiber.StatusOK, postReply(memberID))
	v, ok := attribution(memberID)
	assert.True(t, ok, "attribution row recorded for the established-member reply")
	assert.Equal(t, 1, v, "established member reply recorded as home-group (was_home_member=1)")

	// Non-member: no membership of the origin group -> reached via rippling (was_home_member = 0).
	nonMemberID := CreateTestUser(t, prefix+"_nonmember", "User")
	assert.Equal(t, fiber.StatusOK, postReply(nonMemberID))
	v, ok = attribution(nonMemberID)
	assert.True(t, ok, "attribution row recorded for the non-member reply")
	assert.Equal(t, 0, v, "non-member reply recorded as rippling (was_home_member=0)")
}
