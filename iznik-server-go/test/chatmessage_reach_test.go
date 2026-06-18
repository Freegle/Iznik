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
