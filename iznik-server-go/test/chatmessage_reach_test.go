package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/chat"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/roadblur"
	"github.com/stretchr/testify/assert"
	"github.com/gofiber/fiber/v2"
)

// TestCreateChatMessage_ReachBlockedReplyHeld verifies the rippling reply HOLD on the WRITE path:
// an in-app reply to a post whose reach has not yet reached the replier is ACCEPTED (200) but HELD
// — a rippling_held_replies row (source='web', status='held') is recorded and the poster does not
// see the reply until it is released. This replaced the old 403 reject: rather than turning the
// member away, we hold their reply and deliver it when the post ripples to them (matching the
// email/TN path). Once the reach covers the replier, the reply is delivered normally (no hold row).
func TestCreateChatMessage_ReachBlockedReplyHeld(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("reachreply")

	// Self-sufficient: rippling_reach belongs to PR A (merges before PR E). Use the SAME
	// stand-in schema as the other reach tests (isochrone_reach_test, message_reply_eligible_test)
	// — Go tests share one DB with CREATE TABLE IF NOT EXISTS, so a narrower schema here would
	// break their lat/lng/status inserts (whichever test runs first wins the table).
	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
		polygon_cells MEDIUMBLOB NULL,
		outer_bound GEOMETRY NULL,
		status VARCHAR(16) NOT NULL DEFAULT 'expanding'
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)
	// The hold target table (source column matches the 2026_07_08 migration and the other
	// rippling_held_replies stand-ins; first CREATE wins, so all must agree).
	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_held_replies (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
		chatid BIGINT UNSIGNED NOT NULL, chatmsgid BIGINT UNSIGNED NOT NULL,
		msgid BIGINT UNSIGNED NOT NULL, replieruserid BIGINT UNSIGNED NOT NULL,
		source ENUM('email','tn','web') NOT NULL DEFAULT 'email',
		lat DOUBLE, lng DOUBLE,
		status ENUM('held','released','dropped','taken-gone') NOT NULL DEFAULT 'held',
		created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, releasedat TIMESTAMP NULL,
		INDEX (msgid), INDEX (chatid), INDEX (status)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	posterID := CreateTestUser(t, prefix+"_poster", "User")
	replierID := CreateTestUser(t, prefix+"_replier", "User")
	// GetLatLng reads settings.mylocation first — put the replier at (51.5, -0.1).
	db.Exec(`UPDATE users SET settings = '{"mylocation":{"lat":51.5,"lng":-0.1}}' WHERE id = ?`, replierID)

	msgID := CreateTestMessage(t, posterID, "OFFER: reach reply test item", 51.5, -0.1)

	// Reach exists but does NOT cover the replier (far to the east). lat/lng are NOT NULL.
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound) VALUES (?, 51.5, -0.1, ?, ST_Envelope(ST_GeomFromText("+
		"'POLYGON((5.0 51.4,5.2 51.4,5.2 51.6,5.0 51.6,5.0 51.4))', 3857)))", msgID, mustRasterize(t, "POLYGON((5.0 51.4,5.2 51.4,5.2 51.6,5.0 51.6,5.0 51.4))"))
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)
	defer db.Exec("DELETE FROM rippling_held_replies WHERE msgid = ?", msgID)

	chatID := CreateTestChatRoom(t, replierID, &posterID, "User2User")
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

	// The label refuses the replier: the reply is ACCEPTED (not rejected) and HELD.
	stubReachEvalMax(t, "out")
	assert.Equal(t, fiber.StatusOK, post(), "in-app reply is accepted (not rejected) when outside the post's reach")

	var held struct {
		Chatmsgid uint64 `gorm:"column:chatmsgid"`
		Source    string `gorm:"column:source"`
		Status    string `gorm:"column:status"`
	}
	db.Raw("SELECT chatmsgid, source, status FROM rippling_held_replies "+
		"WHERE msgid = ? AND replieruserid = ? ORDER BY id DESC LIMIT 1", msgID, replierID).Scan(&held)
	assert.NotZero(t, held.Chatmsgid, "an out-of-reach in-app reply records a rippling_held_replies row")
	assert.Equal(t, "web", held.Source, "the held row is tagged source='web'")
	assert.Equal(t, "held", held.Status, "the held row starts held")

	// Delivery gate: the poster must NOT see the held reply yet.
	_, posterToken := CreateTestSession(t, posterID)
	greq := httptest.NewRequest("GET", fmt.Sprintf("/api/chat/%d/message?jwt=%s", chatID, posterToken), nil)
	gresp, _ := getApp().Test(greq)
	var posterMsgs []chat.ChatMessage
	json.Unmarshal(rsp(gresp), &posterMsgs)
	for _, m := range posterMsgs {
		assert.NotEqual(t, held.Chatmsgid, m.ID, "the poster must not see the held reply until it is released")
	}

	// The reach grows to cover the replier (the label now admits) → the
	// reply is delivered normally (no new hold row).
	stubReachEvalMax(t, "in")
	assert.Equal(t, fiber.StatusOK, post(), "in-app reply accepted once the reach covers the replier")
	var heldCount int
	db.Raw("SELECT COUNT(*) FROM rippling_held_replies WHERE msgid = ? AND replieruserid = ?",
		msgID, replierID).Scan(&heldCount)
	assert.Equal(t, 1, heldCount, "the in-reach reply is delivered normally — only the earlier out-of-reach reply is held")
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
		polygon_cells MEDIUMBLOB NULL,
		outer_bound GEOMETRY NULL,
		status VARCHAR(16) NOT NULL DEFAULT 'expanding'
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	posterID := CreateTestUser(t, prefix+"_poster", "User")
	reporterID := CreateTestUser(t, prefix+"_reporter", "User")
	// Reporter is at (51.5, -0.1) and — like Neville in #9852 — is NOT a member of the post's group.
	db.Exec(`UPDATE users SET settings = '{"mylocation":{"lat":51.5,"lng":-0.1}}' WHERE id = ?`, reporterID)

	msgID := CreateTestMessage(t, posterID, "OFFER: reach report test item", 51.5, -0.1)

	// Reach exists but does NOT cover the reporter (far to the east) — this is exactly the polygon
	// that 403s a User2User reply in the test above.
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound) VALUES (?, 51.5, -0.1, ?, ST_Envelope(ST_GeomFromText("+
		"'POLYGON((5.0 51.4,5.2 51.4,5.2 51.6,5.0 51.6,5.0 51.4))', 3857)))", msgID, mustRasterize(t, "POLYGON((5.0 51.4,5.2 51.4,5.2 51.6,5.0 51.6,5.0 51.4))"))
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)

	// Report chat: reporter -> the group's mods (User2Mod), reporter is user1 so is authorised.
	chatID := CreateTestChatRoom(t, reporterID, nil, "User2Mod")
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
	WasRippleJoin  *int    `gorm:"column:was_ripple_join"`
	InOrigin       *int    `gorm:"column:in_origin_catchment"`
	InReach        *int    `gorm:"column:in_reach"`
	PostHadRippled *int    `gorm:"column:post_had_rippled"`
	Attribution    *string `gorm:"column:attribution"`
	ClientSource   *string `gorm:"column:client_source"`
}

func fetchAttribution(t *testing.T, msgID, uid uint64) (attributionRow, bool) {
	var rows []attributionRow
	database.DBConn.Raw("SELECT was_home_member, was_notified, was_ripple_group_member, "+
		"was_ripple_join, in_origin_catchment, in_reach, post_had_rippled, attribution, client_source "+
		"FROM rippling_reply_attribution WHERE msgid = ? AND userid = ?", msgID, uid).Scan(&rows)
	if len(rows) == 0 {
		return attributionRow{}, false
	}
	return rows[0], true
}

// postInterestedReply posts an Interested User2User reply to msgID as replierID, optionally
// carrying the client-reported surface, and returns the HTTP status.
func postInterestedReply(t *testing.T, replierID, posterID, msgID uint64, replysource string) int {
	chatID := CreateTestChatRoom(t, replierID, &posterID, "User2User")
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

// TestCreateChatMessage_ReachUndecidedReplyPassesThrough is the outage case: the
// post has a reach row, but the routing server cannot say whether the replier is
// inside it.
//
// No verdict is not a refusal. On 2026-09-02 the reach engine was down for 16
// hours and this gate read every undecided row as a refusal, so a member 13
// minutes' drive from a post - in the post's own group since 2009 - had her
// reply held and was shown a notice saying the post had not reached her yet.
// The notice even carried an arrival time in the past, because the drive-time
// estimate behind that text was still working.
//
// The reply now goes through, and the passthrough is counted, so the size of
// the next outage is measurable afterwards as well as alerted at the time.
func TestCreateChatMessage_ReachUndecidedReplyPassesThrough(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("reachundecided")

	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
		polygon_cells MEDIUMBLOB NULL,
		outer_bound GEOMETRY NULL,
		status VARCHAR(16) NOT NULL DEFAULT 'expanding'
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)
	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_held_replies (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
		chatid BIGINT UNSIGNED NOT NULL, chatmsgid BIGINT UNSIGNED NOT NULL,
		msgid BIGINT UNSIGNED NOT NULL, replieruserid BIGINT UNSIGNED NOT NULL,
		source ENUM('email','tn','web') NOT NULL DEFAULT 'email',
		lat DOUBLE, lng DOUBLE,
		status ENUM('held','released','dropped','taken-gone') NOT NULL DEFAULT 'held',
		created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, releasedat TIMESTAMP NULL,
		INDEX (msgid), INDEX (chatid), INDEX (status)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	posterID := CreateTestUser(t, prefix+"_poster", "User")
	replierID := CreateTestUser(t, prefix+"_replier", "User")
	db.Exec(`UPDATE users SET settings = '{"mylocation":{"lat":51.5,"lng":-0.1}}' WHERE id = ?`, replierID)

	msgID := CreateTestMessage(t, posterID, "OFFER: reach undecided test item", 51.5, -0.1)

	// The same row the hold test uses: a reach that does not cover the replier.
	// What differs is only that nothing can be asked about it.
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound) VALUES (?, 51.5, -0.1, ?, ST_Envelope(ST_GeomFromText("+
		"'POLYGON((5.0 51.4,5.2 51.4,5.2 51.6,5.0 51.6,5.0 51.4))', 3857)))", msgID, mustRasterize(t, "POLYGON((5.0 51.4,5.2 51.4,5.2 51.6,5.0 51.6,5.0 51.4))"))
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)
	defer db.Exec("DELETE FROM rippling_held_replies WHERE msgid = ?", msgID)

	// The routing server is unreachable, so there is no verdict at all.
	t.Setenv("ROUTING_EVAL_URL", "http://127.0.0.1:1")
	roadblur.ResetRoutingBreaker()
	t.Cleanup(roadblur.ResetRoutingBreaker)

	db.Exec("DELETE FROM rippling_event_metrics WHERE event = 'reply_undecided_passthrough' AND day = CURDATE()")
	defer db.Exec("DELETE FROM rippling_event_metrics WHERE event = 'reply_undecided_passthrough' AND day = CURDATE()")

	chatID := CreateTestChatRoom(t, replierID, &posterID, "User2User")
	_, token := CreateTestSession(t, replierID)

	var payload chat.ChatMessage
	payload.Message = "I'd like this please"
	payload.Refmsgid = &msgID
	s, _ := json.Marshal(payload)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/chat/%d/message?jwt=%s", chatID, token), bytes.NewBuffer(s))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode, "the reply is accepted while reach cannot be evaluated")

	var heldCount int
	db.Raw("SELECT COUNT(*) FROM rippling_held_replies WHERE msgid = ? AND replieruserid = ?",
		msgID, replierID).Scan(&heldCount)
	assert.Equal(t, 0, heldCount, "an undecided reach must not hold the reply - only a refusal does")

	var passthroughs int
	db.Raw("SELECT count FROM rippling_event_metrics WHERE day = CURDATE() AND event = 'reply_undecided_passthrough'").Scan(&passthroughs)
	assert.Equal(t, 1, passthroughs, "the passthrough is counted, so an outage can be sized afterwards")

	// A passed-through reply is an ordinary reply: it reaches the poster once
	// chats:process-incoming flips processingsuccessful, which is where a HELD
	// reply is still filtered out and this one is not.
	var chatMsgID uint64
	db.Raw("SELECT id FROM chat_messages WHERE chatid = ? AND userid = ? ORDER BY id DESC LIMIT 1",
		chatID, replierID).Scan(&chatMsgID)
	assert.NotZero(t, chatMsgID, "the reply was written to the chat")
	db.Exec("UPDATE chat_messages SET processingsuccessful = 1 WHERE id = ?", chatMsgID)

	_, posterToken := CreateTestSession(t, posterID)
	greq := httptest.NewRequest("GET", fmt.Sprintf("/api/chat/%d/message?jwt=%s", chatID, posterToken), nil)
	gresp, _ := getApp().Test(greq)
	var posterMsgs []chat.ChatMessage
	json.Unmarshal(rsp(gresp), &posterMsgs)
	seen := false
	for _, m := range posterMsgs {
		if m.ID == chatMsgID {
			seen = true
		}
	}
	assert.True(t, seen, "the poster sees the reply that was let through")
}
