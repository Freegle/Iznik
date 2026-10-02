package test

import (
	json2 "encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

// TestNearbyFeed_PinnedPostFirst verifies the pinned-post feature on the 'nearby' browse feed
// (GET /isochrone/message): a post with a messages_pinned row floats to the very TOP of the feed
// even when another in-reach post has a higher rippling relevance score. Pinning only reorders
// within the already reach-filtered set, so a pinned post that is NOT in reach still does not
// appear (tested via 'farPinned').
func TestNearbyFeed_PinnedPostFirst(t *testing.T) {
	db := database.DBConn

	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
		polygon_cells MEDIUMBLOB NULL,
		outer_bound GEOMETRY NULL,
		status VARCHAR(16) NOT NULL DEFAULT 'expanding'
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)
	db.Exec(`CREATE TABLE IF NOT EXISTS messages_pinned (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	prefix := uniquePrefix("pinnednearby")
	posterID := CreateTestUser(t, prefix+"_poster", "Poster")
	group := CreateTestGroup(t, prefix)

	// 'rival': origin == viewer, small polygon -> closeness ≈ 1 -> highest natural score.
	rival := CreateTestMessage(t, posterID, group, "OFFER: near rival, high score (pinnednearby)", 51.5, -0.1)
	// 'pinned': origin far (~44km), big polygon still covering the viewer -> lower natural score,
	// but pinned, so it must lead the feed regardless.
	pinned := CreateTestMessage(t, posterID, group, "OFFER: distant origin but PINNED (pinnednearby)", 51.9, -0.1)
	// 'farPinned': pinned but its reach does NOT cover the viewer -> must stay absent (pinning
	// only floats posts that already qualify to appear).
	farPinned := CreateTestMessage(t, posterID, group, "OFFER: pinned but out of reach (pinnednearby)", 53.0, 2.0)
	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid IN (?, ?, ?)", rival, pinned, farPinned)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid IN (?, ?, ?)", rival, pinned, farPinned)
	defer db.Exec("DELETE FROM messages_pinned WHERE msgid IN (?, ?)", pinned, farPinned)

	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)

	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound, status) VALUES (?, 51.5, -0.1, ?, "+
		"ST_Envelope(ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857)), 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon_cells = VALUES(polygon_cells)", rival, mustRasterize(t, "POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))"))
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound, status) VALUES (?, 51.9, -0.1, ?, "+
		"ST_Envelope(ST_GeomFromText('POLYGON((-0.3 51.3, 0.1 51.3, 0.1 52.0, -0.3 52.0, -0.3 51.3))', 3857)), 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon_cells = VALUES(polygon_cells)", pinned, mustRasterize(t, "POLYGON((-0.3 51.3, 0.1 51.3, 0.1 52.0, -0.3 52.0, -0.3 51.3))"))
	// farPinned's reach is well away (~53N, 2E) — does NOT cover the viewer.
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound, status) VALUES (?, 53.0, 2.0, ?, "+
		"ST_Envelope(ST_GeomFromText('POLYGON((2.0 53.0, 2.1 53.0, 2.1 53.1, 2.0 53.1, 2.0 53.0))', 3857)), 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon_cells = VALUES(polygon_cells)", farPinned, mustRasterize(t, "POLYGON((2.0 53.0, 2.1 53.0, 2.1 53.1, 2.0 53.1, 2.0 53.0))"))

	// Pin the distant-origin post and the out-of-reach post.
	// The reach universe must come from THESE fixtures, not the real
	// index built from another database.
	stubReachIndexFromDB(t, false)

	db.Exec("INSERT INTO messages_pinned (msgid) VALUES (?), (?) ON DUPLICATE KEY UPDATE msgid = VALUES(msgid)", pinned, farPinned)

	fetch := func() []message.MessageSummary {
		resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/isochrone/message?jwt="+token, nil))
		assert.Equal(t, 200, resp.StatusCode)
		var msgs []message.MessageSummary
		json2.Unmarshal(rsp(resp), &msgs)
		return msgs
	}

	msgs := fetch()

	byID := map[uint64]message.MessageSummary{}
	idx := map[uint64]int{}
	for i, m := range msgs {
		byID[m.ID] = m
		idx[m.ID] = i
	}

	// Both in-reach posts appear; the out-of-reach pinned post does not.
	assert.Contains(t, idx, rival, "in-reach rival post appears")
	assert.Contains(t, idx, pinned, "in-reach pinned post appears")
	assert.NotContains(t, idx, farPinned, "a pinned post whose reach does not cover the viewer stays absent")

	// The pinned post leads the feed and is flagged pinned...
	assert.Greater(t, len(msgs), 0, "feed is non-empty")
	assert.Equal(t, pinned, msgs[0].ID, "the pinned post is first in the feed")
	assert.True(t, msgs[0].Pinned, "the first post is flagged pinned")
	assert.False(t, byID[rival].Pinned, "the non-pinned rival is not flagged pinned")

	// ...even though the rival has a strictly higher natural relevance score.
	assert.Greater(t, byID[rival].Score, byID[pinned].Score,
		"the rival's natural score is higher, so the pinned post leads only because it is pinned")
	assert.Less(t, idx[pinned], idx[rival], "pinned post ranks above the higher-scoring rival")

	// Removing the pin restores natural score order: the rival leads.
	db.Exec("DELETE FROM messages_pinned WHERE msgid = ?", pinned)
	msgs = fetch()
	assert.Greater(t, len(msgs), 0, "feed still non-empty after unpin")
	assert.Equal(t, rival, msgs[0].ID, "with the pin removed, the higher-scoring rival leads again")
	assert.False(t, msgs[0].Pinned, "no post is flagged pinned once the pin is removed")
}
