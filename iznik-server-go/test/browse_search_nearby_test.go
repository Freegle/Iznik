package test

import (
	json2 "encoding/json"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/embedding"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

// Browse-scoped search (GET /message/search/:term?browse=1, no groupids) restricts
// keyword/vector candidates to the viewer's Nearby feed universe - nearbyFeedMsgIDs:
// posts whose rippling reach covers the viewer, plus the viewer's own posts (Discourse
// 9933). Same reach model as the /isochrone/message feed (see isochrone_reach_test.go),
// but nothing previously called the search endpoint with browse=1 + a member location,
// so nearbyFeedMsgIDs - including the reach-universe cache added on top of it - was never
// exercised by the Go suite (0% covered per Coveralls on c66acbe0e). Two calls in a row
// exercise both the cache-miss (first, runs the reach containment query) and cache-hit
// (second, immediate) paths.
//
// Both messages are seeded into the embedding store with an antiparallel (far
// below MinVectorScore) vector so neither is found via cosine ranking - only via
// the lexical guarantee (LexicalMatch). That's deliberate: LexicalMatch must
// respect the same allowedIDs (reach universe) restriction as the cosine path,
// or the guarantee would leak the out-of-reach post around the reach filter.
func TestBrowseScopedSearchNearby(t *testing.T) {
	embedding.ResetQueryCache()
	t.Cleanup(embedding.ResetQueryCache)

	db := database.DBConn

	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
		polygon_cells MEDIUMBLOB NULL,
		outer_bound GEOMETRY NULL,
		status VARCHAR(16) NOT NULL DEFAULT 'expanding'
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	prefix := uniquePrefix("browsesearchnearby")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	group := CreateTestGroup(t, prefix)
	CreateTestMembership(t, posterID, group, "Member")
	inReach := CreateTestMessage(t, posterID, group, "Zorbnak Sofa reach covers viewer (browsesearchnearby)", 51.5, -0.1)
	outOfReach := CreateTestMessage(t, posterID, group, "Zorbnak Sofa reach excludes viewer (browsesearchnearby)", 51.5, -0.1)
	// The browse feed only searches open posts (messages_spatial.successful = 0); the
	// helper inserts them as successful = 1, so mark them open like the reach feed test does.
	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid IN (?, ?)", inReach, outOfReach)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid IN (?, ?)", inReach, outOfReach)

	// A viewer (deliberately not the poster, so the own-posts arm doesn't mask the reach
	// filter) with a known location. GetLatLng reads settings.mylocation.
	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)

	// inReach: small reach polygon centred on the viewer.
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound, status) VALUES (?, 51.5, -0.1, ?, "+
		"ST_Envelope(ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857)), 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon_cells = VALUES(polygon_cells)", inReach, mustRasterize(t, "POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))"))
	// outOfReach: reach polygon well away from the viewer (~53N, 2E) - does not cover it.
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound, status) VALUES (?, 53.0, 2.0, ?, "+
		"ST_Envelope(ST_GeomFromText('POLYGON((2.0 53.0, 2.1 53.0, 2.1 53.1, 2.0 53.1, 2.0 53.0))', 3857)), 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon_cells = VALUES(polygon_cells)", outOfReach, mustRasterize(t, "POLYGON((2.0 53.0, 2.1 53.0, 2.1 53.1, 2.0 53.1, 2.0 53.0))"))

	// The reach universe must come from THESE fixtures, not the real
	// index built from another database.
	stubReachIndexFromDB(t, false)

	// Seed both messages into the embedding store with antiparallel vectors (cosine
	// ~-1, far below MinVectorScore) so they're findable only via the lexical
	// guarantee, not cosine ranking - the reach restriction under test is applied
	// on that path too (see LexicalMatch's allowedIDs filter).
	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: inReach, Groupid: group, Msgtype: "Offer", Lat: 51.5, Lng: -0.1,
			Subject: "Zorbnak Sofa reach covers viewer (browsesearchnearby)", Arrival: time.Now(), SubjectVec: makeAntiparallelVec(10.0)},
		{Msgid: outOfReach, Groupid: group, Msgtype: "Offer", Lat: 51.5, Lng: -0.1,
			Subject: "Zorbnak Sofa reach excludes viewer (browsesearchnearby)", Arrival: time.Now(), SubjectVec: makeAntiparallelVec(11.0)},
	})
	t.Cleanup(func() { embedding.Global.SetEntries(nil) })

	queryVec := makeTestVec(1.0)
	server := mockSidecarReturning(t, queryVec[:])
	defer server.Close()
	embedding.SetSidecarURL(server.URL)
	t.Cleanup(func() { embedding.SetSidecarURL("") })

	words := message.GetWords("Zorbnak Sofa reach covers viewer (browsesearchnearby)")
	searchWord := words[0]
	url := "/api/message/search/" + searchWord + "?browse=1&jwt=" + token

	checkResults := func(label string) {
		resp, _ := getApp().Test(httptest.NewRequest("GET", url, nil), 60000)
		assert.Equal(t, 200, resp.StatusCode, label)

		var results []message.SearchResult
		json2.Unmarshal(rsp(resp), &results)
		got := map[uint64]bool{}
		for _, r := range results {
			got[r.Msgid] = true
		}
		assert.Truef(t, got[inReach], "%s: browse-scoped search finds a post whose reach covers the viewer", label)
		assert.Falsef(t, got[outOfReach], "%s: browse-scoped search excludes a post whose reach does not cover the viewer", label)
	}

	checkResults("first call (cache miss)")
	checkResults("second call (cache hit)")
}
