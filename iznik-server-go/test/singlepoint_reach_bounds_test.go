package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"strconv"
	"testing"

	"github.com/freegle/iznik-server-go/chat"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

// The single-point reach gates — replyeligible on the message list and the chat reply
// hold — consult the sandwich bounds like the browse queries do
// (plans/2026-07-17-db3-cpu-reach-sql-prefilter.md): outside outer_bound is an
// authoritative reject, inside inner_bound an authoritative accept, and only the band
// between (or a missing/degraded bounds row) touches the ~178KB exact polygon. As in
// isochrone_reach_bounds_test.go the fixtures are ADVERSARIAL — bounds contradicting the
// polygon, impossible for verified writer-derived bounds — because that is the only way
// to observe which shape the gate trusted. These are PK lookups (no R-tree), so plain
// contradictory fixtures suffice (no C-shape needed).

// TestReplyEligibleSandwichBounds: the message-list reach probe (replyeligible).
func TestReplyEligibleSandwichBounds(t *testing.T) {
	db := database.DBConn
	ensureReachBoundsTable()

	prefix := uniquePrefix("spbre")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	group := CreateTestGroup(t, prefix)
	viewerID := CreateTestUser(t, prefix+"_viewer", "User")
	CreateTestMembership(t, viewerID, group, "Member")
	db.Exec(`UPDATE users SET settings = '{"mylocation":{"lat":51.5,"lng":-0.1}}' WHERE id = ?`, viewerID)

	mid := CreateTestMessage(t, posterID, group, "OFFER: single point bounds (spbre)", 51.5, -0.1)
	idStr := strconv.FormatUint(mid, 10)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", mid)

	eligibility := func() *bool {
		msgs := message.GetMessagesByIds(viewerID, []string{idStr}, false)
		if !assert.Len(t, msgs, 1) {
			t.FailNow()
		}
		return msgs[0].ReplyEligible
	}

	// Cheap reject: polygon COVERS the viewer, but outer_bound excludes them — the
	// bounds are authoritative, so the post reads as not-yet-reached (view-only).
	insertReachPolygon(mid, coversViewerWkt)
	insertBounds(mid, farAwayWkt, nil)
	if e := eligibility(); assert.NotNil(t, e, "outside outer_bound → replyeligible set") {
		assert.False(t, *e, "a viewer outside outer_bound is reach-blocked without testing the polygon")
	}

	// Cheap accept: polygon does NOT cover the viewer, but inner_bound does.
	inner := coversViewerWkt
	insertReachPolygon(mid, missesViewerWkt)
	insertBounds(mid, bigCoversViewerWkt, &inner)
	assert.Nil(t, eligibility(), "a viewer inside inner_bound is eligible without testing the polygon")

	// Degraded bounds (completion pruning) are treated as ABSENT: the exact polygon
	// decides, so a viewer inside the polygon stays eligible.
	insertReachPolygon(mid, coversViewerWkt)
	db.Exec("UPDATE rippling_reach_bounds SET outer_bound = ST_SRID(POINT(-0.1, 51.5), 3857), "+
		"inner_bound = NULL WHERE msgid = ?", mid)
	assert.Nil(t, eligibility(), "degraded bounds fall back to the exact polygon (covered → eligible)")

	// Band (inside outer, no inner): the exact polygon decides — not covered → blocked.
	insertReachPolygon(mid, missesViewerWkt)
	insertBounds(mid, bigCoversViewerWkt, nil)
	if e := eligibility(); assert.NotNil(t, e, "band + polygon misses → replyeligible set") {
		assert.False(t, *e, "boundary band falls back to the exact polygon (not covered → blocked)")
	}
}

// TestChatReplyGateSandwichBounds: the write-path reply hold consults the bounds too.
func TestChatReplyGateSandwichBounds(t *testing.T) {
	db := database.DBConn
	ensureReachBoundsTable()
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

	prefix := uniquePrefix("spbchat")
	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	replierID := CreateTestUser(t, prefix+"_replier", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	CreateTestMembership(t, replierID, groupID, "Member")
	db.Exec(`UPDATE users SET settings = '{"mylocation":{"lat":51.5,"lng":-0.1}}' WHERE id = ?`, replierID)

	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: chat gate bounds (spbchat)", 51.5, -0.1)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)
	defer db.Exec("DELETE FROM rippling_held_replies WHERE msgid = ?", msgID)

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
	heldCount := func() int {
		var n int
		db.Raw("SELECT COUNT(*) FROM rippling_held_replies WHERE msgid = ? AND replieruserid = ?",
			msgID, replierID).Scan(&n)
		return n
	}

	// Cheap accept: polygon does NOT cover the replier, but inner_bound does — the
	// verified inner is authoritative, so the reply is delivered, not held.
	inner := coversViewerWkt
	insertReachPolygon(msgID, missesViewerWkt)
	insertBounds(msgID, bigCoversViewerWkt, &inner)
	assert.Equal(t, fiber.StatusOK, post())
	assert.Equal(t, 0, heldCount(), "a replier inside inner_bound is in reach without testing the polygon — not held")

	// Cheap reject: polygon COVERS the replier, but outer_bound excludes them — held.
	insertReachPolygon(msgID, coversViewerWkt)
	insertBounds(msgID, farAwayWkt, nil)
	assert.Equal(t, fiber.StatusOK, post())
	assert.Equal(t, 1, heldCount(), "a replier outside outer_bound is out of reach without testing the polygon — held")
}
