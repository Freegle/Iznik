package test

import (
	json2 "encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

// The 'nearby' browse feed (GET /isochrone/message) now selects posts whose rippling-out
// reach polygon currently covers the viewer's location — the reach model's read-side test
// — replacing the old per-user isochrone containment. A post whose reach does not cover the
// viewer is excluded. Self-sufficient: creates rippling_reach if the reach-engine migration
// isn't in this schema yet, so it runs regardless of merge order.
func TestNearbyReachFeed(t *testing.T) {
	db := database.DBConn

	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
		polygon GEOMETRY NOT NULL SRID 3857,
		status VARCHAR(16) NOT NULL DEFAULT 'expanding',
		SPATIAL INDEX msgreach_poly (polygon)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	prefix := uniquePrefix("nearbyreach")
	posterID := CreateTestUser(t, prefix+"_poster", "Poster")
	group := CreateTestGroup(t, prefix)
	near := CreateTestMessage(t, posterID, group, "OFFER: reach covers viewer (nearbyreach)", 51.5, -0.1)
	far := CreateTestMessage(t, posterID, group, "OFFER: reach excludes viewer (nearbyreach)", 51.5, -0.1)
	// The browse feed shows open posts only (messages_spatial.successful = 0); the helper
	// inserts them as successful = 1, so mark them open like the other browse-feed tests do.
	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid IN (?, ?)", near, far)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid IN (?, ?)", near, far)

	// A viewer (deliberately NOT the poster, so the own-posts arm doesn't mask the reach
	// filter) with a known location at (lat 51.5, lng -0.1). GetLatLng reads settings.mylocation.
	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)

	// 'near': reach polygon covers the viewer. 'far': reach polygon is well away (~53N, 2E).
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, status) VALUES (?, 51.5, -0.1, "+
		"ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857), 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon)", near)
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, status) VALUES (?, 53.0, 2.0, "+
		"ST_GeomFromText('POLYGON((2.0 53.0, 2.1 53.0, 2.1 53.1, 2.0 53.1, 2.0 53.0))', 3857), 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon)", far)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/isochrone/message?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)

	got := map[uint64]bool{}
	for _, m := range msgs {
		got[m.ID] = true
	}
	assert.True(t, got[near], "post whose reach covers the viewer appears in the nearby feed")
	assert.False(t, got[far], "post whose reach excludes the viewer is absent from the nearby feed")

	// The nearby count (GET /message/count, nearby view) mirrors the feed: the covered post
	// is unseen for this brand-new viewer, so the count includes it.
	cresp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/count?jwt="+token, nil))
	assert.Equal(t, 200, cresp.StatusCode)
	var cbody map[string]interface{}
	json2.Unmarshal(rsp(cresp), &cbody)
	assert.GreaterOrEqual(t, cbody["count"].(float64), float64(1), "nearby count includes the reach-covered unseen post")
}
