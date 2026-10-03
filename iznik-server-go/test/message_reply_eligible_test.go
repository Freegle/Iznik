package test

import (
	"fmt"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/freegle/iznik-server-go/rippling"
	"github.com/stretchr/testify/assert"
)

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
	mid := CreateTestMessage(t, posterID, "OFFER: reply-eligible trial test", 51.5, -0.1)
	viewerID := CreateTestUser(t, prefix+"v", "Viewer")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)
	idStr := fmt.Sprint(mid)

	// Reach the routing server decides the viewer is OUT of → replyeligible=false,
	// even though RIPPLE_ENABLED is off (the post is rippling via the per-group trial).
	// The verdict has to be a decision: an unanswered row now fails open, so the
	// notice can no longer appear just because reach evaluation was unavailable.
	stubReachEvalMax(t, rippling.LabelVerdictOut)
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
