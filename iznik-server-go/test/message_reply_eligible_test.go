package test

import (
	"fmt"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

// Reply-eligibility (#2 rippling-out): a post that has rippled out (has a messages_reach
// row) but not yet to the viewer's location must come back replyeligible=false so the UI
// shows it view-only. A post with no reach row isn't rippling, so replyeligible is omitted
// (eligible). Self-sufficient: creates messages_reach if the reach-engine migration (PR A)
// isn't in this schema yet, so it runs regardless of merge order.
func TestReplyEligibleReach(t *testing.T) {
	db := database.DBConn

	db.Exec(`CREATE TABLE IF NOT EXISTS messages_reach (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
		polygon GEOMETRY NOT NULL SRID 3857,
		status VARCHAR(16) NOT NULL DEFAULT 'expanding',
		SPATIAL INDEX msgreach_poly (polygon)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	prefix := uniquePrefix("repelig")
	posterID := CreateTestUser(t, prefix, "Poster")
	group := CreateTestGroup(t, prefix)
	mid := CreateTestMessage(t, posterID, group, "OFFER: reply-eligible test", 51.5, -0.1)

	// A viewer with a known location at (51.5, -0.1) — GetLatLng reads settings.mylocation.
	viewerID := CreateTestUser(t, prefix+"v", "Viewer")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)

	idStr := fmt.Sprint(mid)

	// 1) No reach row → eligible (field omitted/nil).
	db.Exec("DELETE FROM messages_reach WHERE msgid = ?", mid)
	msgs := message.GetMessagesByIds(viewerID, []string{idStr}, false)
	if assert.Len(t, msgs, 1) {
		assert.Nil(t, msgs[0].ReplyEligible, "no reach row → eligible (omitted)")
	}

	// 2) Reach row whose polygon does NOT contain the viewer → not eligible (false).
	db.Exec("INSERT INTO messages_reach (msgid, lat, lng, polygon, status) VALUES (?, 51.5, -0.1, "+
		"ST_GeomFromText('POLYGON((2.4 53.4, 2.6 53.4, 2.6 53.6, 2.4 53.6, 2.4 53.4))', 3857), 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon)", mid)
	msgs = message.GetMessagesByIds(viewerID, []string{idStr}, false)
	if assert.Len(t, msgs, 1) && assert.NotNil(t, msgs[0].ReplyEligible, "outside reach → replyeligible set") {
		assert.False(t, *msgs[0].ReplyEligible, "outside reach → replyeligible=false")
	}

	// 3) Reach row containing the viewer → eligible (nil).
	db.Exec("UPDATE messages_reach SET polygon = "+
		"ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857) WHERE msgid = ?", mid)
	msgs = message.GetMessagesByIds(viewerID, []string{idStr}, false)
	if assert.Len(t, msgs, 1) {
		assert.Nil(t, msgs[0].ReplyEligible, "inside reach → eligible (omitted)")
	}

	db.Exec("DELETE FROM messages_reach WHERE msgid = ?", mid)
}
