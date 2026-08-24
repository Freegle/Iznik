package test

import (
	json2 "encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

// TestNearbyFeed_OwnPostFlaggedMine verifies the browse feed (GET /isochrone/message) flags the
// viewer's own posts with `mine` (MessageSummary.Mine), and includes them even when they are NOT
// in the viewer's reach — the own-posts arm queries messages directly, not the reach polygon. The
// client pins `mine` posts to the top of every sort order so members can always find their own
// posts instead of losing them in the reach order (Discourse 9933).
func TestNearbyFeed_OwnPostFlaggedMine(t *testing.T) {
	db := database.DBConn

	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
		polygon GEOMETRY NOT NULL SRID 3857,
		status VARCHAR(16) NOT NULL DEFAULT 'expanding',
		SPATIAL INDEX msgreach_poly (polygon)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	prefix := uniquePrefix("ownmine")
	group := CreateTestGroup(t, prefix)
	otherID := CreateTestUser(t, prefix+"_other", "Other")

	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)

	// 'rival': another member's post, in reach of the viewer -> appears via the reach arm, NOT mine.
	rival := CreateTestMessage(t, otherID, group, "OFFER: someone else, in reach (ownmine)", 51.5, -0.1)
	// 'own': the viewer's OWN post, placed far away with NO reach row covering the viewer, so it can
	// only reach the feed via the own-posts arm — proving own posts show regardless of reach.
	own := CreateTestMessage(t, viewerID, group, "OFFER: my own post, out of reach (ownmine)", 53.0, 2.0)
	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid IN (?, ?)", rival, own)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid IN (?, ?)", rival, own)

	// Only the rival gets a reach polygon covering the viewer; the own post gets none.
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, status) VALUES (?, 51.5, -0.1, "+
		"ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857), ST_Envelope(ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857)), 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon)", rival)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/isochrone/message?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)
	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)

	byID := map[uint64]message.MessageSummary{}
	for _, m := range msgs {
		byID[m.ID] = m
	}

	// The viewer's own out-of-reach post is present and flagged mine.
	ownMsg, ownPresent := byID[own]
	assert.True(t, ownPresent, "the viewer's own post appears even though it is not in reach")
	assert.True(t, ownMsg.Mine, "the viewer's own post is flagged mine")

	// The other member's in-reach post is present and NOT flagged mine.
	rivalMsg, rivalPresent := byID[rival]
	assert.True(t, rivalPresent, "another member's in-reach post appears")
	assert.False(t, rivalMsg.Mine, "another member's post is not flagged mine")
}
