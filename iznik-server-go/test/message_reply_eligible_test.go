package test

import (
	"fmt"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

// Reply-eligibility (#2 rippling-out): a post that has rippled out (has a rippling_reach
// row) but not yet to the viewer's location must come back replyeligible=false so the UI
// shows it view-only. A post with no reach row isn't rippling, so replyeligible is omitted
// (eligible). Self-sufficient: creates rippling_reach if the reach-engine migration (PR A)
// isn't in this schema yet, so it runs regardless of merge order.
func TestReplyEligibleReach(t *testing.T) {
	// Reply-eligibility is gated by the master activation switch; enable it here so the path runs.
	t.Setenv("RIPPLE_ENABLED", "true")
	db := database.DBConn

	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
		polygon_cells MEDIUMBLOB NULL,
		outer_bound GEOMETRY NULL,
		status VARCHAR(16) NOT NULL DEFAULT 'expanding'
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
	db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", mid)
	msgs := message.GetMessagesByIds(viewerID, []string{idStr}, false)
	if assert.Len(t, msgs, 1) {
		assert.Nil(t, msgs[0].ReplyEligible, "no reach row → eligible (omitted)")
	}

	// 2) Reach row whose grid does NOT contain the viewer → not eligible (false).
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound, status) VALUES (?, 51.5, -0.1, ?, "+
		"ST_Envelope(ST_GeomFromText('POLYGON((2.4 53.4, 2.6 53.4, 2.6 53.6, 2.4 53.6, 2.4 53.4))', 3857)), 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon_cells = VALUES(polygon_cells)", mid,
		mustRasterize(t, "POLYGON((2.4 53.4, 2.6 53.4, 2.6 53.6, 2.4 53.6, 2.4 53.4))"))
	db.Exec("DELETE FROM rippling_event_metrics WHERE event = 'reply_blocked' AND day = CURDATE()")
	msgs = message.GetMessagesByIds(viewerID, []string{idStr}, false)
	if assert.Len(t, msgs, 1) && assert.NotNil(t, msgs[0].ReplyEligible, "outside reach → replyeligible set") {
		assert.False(t, *msgs[0].ReplyEligible, "outside reach → replyeligible=false")
	}
	// Q5 (§15): a reach-blocked view increments the reply-blocked-by-reach counter.
	var blockedCount int
	db.Raw("SELECT count FROM rippling_event_metrics WHERE event = 'reply_blocked' AND day = CURDATE()").Scan(&blockedCount)
	assert.GreaterOrEqual(t, blockedCount, 1, "reply-blocked-by-reach event counted")

	// 3) Reach row containing the viewer → eligible (nil).
	db.Exec("UPDATE rippling_reach SET polygon_cells = ?, "+
		"outer_bound = ST_Envelope(ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857)), "+
		"inner_bound = NULL WHERE msgid = ?",
		mustRasterize(t, "POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))"), mid)
	msgs = message.GetMessagesByIds(viewerID, []string{idStr}, false)
	if assert.Len(t, msgs, 1) {
		assert.Nil(t, msgs[0].ReplyEligible, "inside reach → eligible (omitted)")
	}
	db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", mid)

	// 4) Viewer banned from the post's (only) group → reply-ineligible regardless of reach
	//    (a banned user must not interact with that group's posts).
	db.Exec("INSERT INTO users_banned (userid, groupid) VALUES (?, ?) "+
		"ON DUPLICATE KEY UPDATE userid = VALUES(userid)", viewerID, group)
	msgs = message.GetMessagesByIds(viewerID, []string{idStr}, false)
	if assert.Len(t, msgs, 1) && assert.NotNil(t, msgs[0].ReplyEligible, "banned → replyeligible set") {
		assert.False(t, *msgs[0].ReplyEligible, "banned from the post's group → replyeligible=false")
	}
	db.Exec("DELETE FROM users_banned WHERE userid = ? AND groupid = ?", viewerID, group)
}

// Data-driven activation: with the RIPPLE_ENABLED master switch OFF, a post that is actually
// rippling (it has a rippling_reach row) is STILL reply-gated. This is the per-group trial
// (RIPPLE_WITHIN_GROUPS) case — the reach engine populates rippling_reach without the master
// switch, and the write path (chat.CreateChatMessage) is likewise data-driven. The read path
// must flag out-of-reach posts here too so the UI can show the "we'll pass your reply on when it
// reaches you" hold notice — the reply itself is allowed and held server-side (Discourse: dejavu /
// msg 120820564, which pre-dated the hold and hit the old 403 not_in_reach).
func TestReplyEligibleReachWhenMasterSwitchOff(t *testing.T) {
	t.Setenv("RIPPLE_ENABLED", "false")
	db := database.DBConn

	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
		polygon_cells MEDIUMBLOB NULL,
		outer_bound GEOMETRY NULL,
		status VARCHAR(16) NOT NULL DEFAULT 'expanding'
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	prefix := uniquePrefix("repeligtrial")
	posterID := CreateTestUser(t, prefix, "Poster")
	group := CreateTestGroup(t, prefix)
	mid := CreateTestMessage(t, posterID, group, "OFFER: reply-eligible trial test", 51.5, -0.1)
	viewerID := CreateTestUser(t, prefix+"v", "Viewer")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)
	idStr := fmt.Sprint(mid)

	// Reach row whose grid does NOT contain the viewer → out of reach → replyeligible=false,
	// even though RIPPLE_ENABLED is off (the post is rippling via the per-group trial).
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound, status) VALUES (?, 51.5, -0.1, ?, "+
		"ST_Envelope(ST_GeomFromText('POLYGON((2.4 53.4, 2.6 53.4, 2.6 53.6, 2.4 53.6, 2.4 53.4))', 3857)), 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon_cells = VALUES(polygon_cells)", mid,
		mustRasterize(t, "POLYGON((2.4 53.4, 2.6 53.4, 2.6 53.6, 2.4 53.6, 2.4 53.4))"))
	msgs := message.GetMessagesByIds(viewerID, []string{idStr}, false)
	if assert.Len(t, msgs, 1) && assert.NotNil(t, msgs[0].ReplyEligible, "trial post, master off → replyeligible set") {
		assert.False(t, *msgs[0].ReplyEligible, "trial post outside reach, master off → replyeligible=false")
	}

	db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", mid)
}

// When a post is NOT rippling at all (no rippling_reach row) and the master switch is OFF, the
// reply-eligibility path stays dark: the field is omitted so the API is identical to pre-rippling,
// and a ban alone — with no reach row in play — does not mark the post view-only on this path.
func TestReplyEligibleDarkWhenNotRippling(t *testing.T) {
	t.Setenv("RIPPLE_ENABLED", "false")
	db := database.DBConn

	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
		polygon_cells MEDIUMBLOB NULL,
		outer_bound GEOMETRY NULL,
		status VARCHAR(16) NOT NULL DEFAULT 'expanding'
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	prefix := uniquePrefix("repeligoff")
	posterID := CreateTestUser(t, prefix, "Poster")
	group := CreateTestGroup(t, prefix)
	mid := CreateTestMessage(t, posterID, group, "OFFER: reply-eligible dark test", 51.5, -0.1)
	viewerID := CreateTestUser(t, prefix+"v", "Viewer")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)
	idStr := fmt.Sprint(mid)

	// No reach row for this post → not rippling. A ban alone must not set replyeligible here.
	db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", mid)
	db.Exec("INSERT INTO users_banned (userid, groupid) VALUES (?, ?) "+
		"ON DUPLICATE KEY UPDATE userid = VALUES(userid)", viewerID, group)

	msgs := message.GetMessagesByIds(viewerID, []string{idStr}, false)
	if assert.Len(t, msgs, 1) {
		assert.Nil(t, msgs[0].ReplyEligible, "not rippling + master off → reply-eligibility stays dark")
	}

	db.Exec("DELETE FROM users_banned WHERE userid = ? AND groupid = ?", viewerID, group)
}
