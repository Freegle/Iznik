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
	// 'midfar': also within the viewer's reach (so it's INCLUDED, unlike 'far'), but its own
	// post origin is much further from the viewer than 'near' — used below to assert the feed
	// is now ORDERED by rippling relevance score (closeness-weighted), not left in DB order.
	midfar := CreateTestMessage(t, posterID, group, "OFFER: reach covers viewer but origin is far (nearbyreach)", 51.9, -0.1)
	// The browse feed shows open posts only (messages_spatial.successful = 0); the helper
	// inserts them as successful = 1, so mark them open like the other browse-feed tests do.
	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid IN (?, ?, ?)", near, far, midfar)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid IN (?, ?, ?)", near, far, midfar)

	// A viewer (deliberately NOT the poster, so the own-posts arm doesn't mask the reach
	// filter) with a known location at (lat 51.5, lng -0.1). GetLatLng reads settings.mylocation.
	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)

	// 'near': small reach polygon centred on the viewer -> post origin == viewer, so
	// distance ≈ 0 and the closeness term is ≈ 1 (highest possible).
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, status) VALUES (?, 51.5, -0.1, "+
		"ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857), 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon)", near)
	// 'far': reach polygon is well away (~53N, 2E) and does NOT cover the viewer -> excluded.
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, status) VALUES (?, 53.0, 2.0, "+
		"ST_GeomFromText('POLYGON((2.0 53.0, 2.1 53.0, 2.1 53.1, 2.0 53.1, 2.0 53.0))', 3857), 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon)", far)
	// 'midfar': a much bigger reach polygon whose origin (51.9,-0.1, ~44km from the viewer) is
	// still far from the viewer, but the polygon is large enough to also cover the viewer -> the
	// post IS in reach, but its origin is much less close than 'near's, so its closeness (and
	// hence total) score must be lower.
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, status) VALUES (?, 51.9, -0.1, "+
		"ST_GeomFromText('POLYGON((-0.3 51.3, 0.1 51.3, 0.1 52.0, -0.3 52.0, -0.3 51.3))', 3857), 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon)", midfar)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/isochrone/message?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)

	got := map[uint64]bool{}
	byID := map[uint64]message.MessageSummary{}
	for _, m := range msgs {
		got[m.ID] = true
		byID[m.ID] = m
	}
	assert.True(t, got[near], "post whose reach covers the viewer appears in the nearby feed")
	assert.True(t, got[midfar], "post whose reach covers the viewer appears in the nearby feed, even with a distant origin")
	assert.False(t, got[far], "post whose reach excludes the viewer is absent from the nearby feed")

	// Every post on the nearby feed carries a non-negative distance (miles, from the viewer to
	// the post's BLURRED coordinates) and a score, and the feed is ordered by score descending.
	for i, m := range msgs {
		assert.GreaterOrEqual(t, m.Distance, 0.0, "post %d has a non-negative distance", m.ID)
		if i > 0 {
			assert.GreaterOrEqual(t, msgs[i-1].Score, msgs[i].Score,
				"nearby feed is ordered by score descending (index %d,%d)", i-1, i)
		}
	}

	// The core ordering assertion: 'near' (origin == viewer, closeness ≈ 1) must rank strictly
	// above 'midfar' (origin ~44km away, closeness much lower) — the rippling relevance score,
	// not DB/insert order, now drives the feed.
	nearSummary, midfarSummary := byID[near], byID[midfar]
	assert.Greater(t, nearSummary.Score, midfarSummary.Score,
		"a near, closer-origin post must score higher than a farther-origin post also in reach")

	nearIdx, midfarIdx := -1, -1
	for i, m := range msgs {
		if m.ID == near {
			nearIdx = i
		}
		if m.ID == midfar {
			midfarIdx = i
		}
	}
	assert.Less(t, nearIdx, midfarIdx, "'near' ranks above 'midfar' in the returned feed order")

	// The nearby count (GET /message/count, nearby view) mirrors the feed: the covered posts
	// are unseen for this brand-new viewer, so the count includes them.
	cresp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/count?jwt="+token, nil))
	assert.Equal(t, 200, cresp.StatusCode)
	var cbody map[string]interface{}
	json2.Unmarshal(rsp(cresp), &cbody)
	assert.GreaterOrEqual(t, cbody["count"].(float64), float64(2), "nearby count includes both reach-covered unseen posts")
}

// TestNearbyCountDistanceLimit: GET /message/count (nearby view) narrows the badge to posts
// within a maxDistance (miles) of the viewer, using the SAME blurred-coordinate Haversine
// distance the feed exposes as `distance` — so the badge matches a client-side list filtered
// at the same limit. With no limit (the sentinel, or the param/setting absent) it counts every
// reach-covered unseen post, exactly as before the distance slider existed.
func TestNearbyCountDistanceLimit(t *testing.T) {
	db := database.DBConn

	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
		polygon GEOMETRY NOT NULL SRID 3857,
		status VARCHAR(16) NOT NULL DEFAULT 'expanding',
		SPATIAL INDEX msgreach_poly (polygon)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	prefix := uniquePrefix("nearbycountdist")
	posterID := CreateTestUser(t, prefix+"_poster", "Poster")
	group := CreateTestGroup(t, prefix)
	near := CreateTestMessage(t, posterID, group, "OFFER: near for count distance limit (nearbycountdist)", 51.5, -0.1)
	far := CreateTestMessage(t, posterID, group, "OFFER: far but in reach for count distance limit (nearbycountdist)", 51.9, -0.1)
	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid IN (?, ?)", near, far)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid IN (?, ?)", near, far)

	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)

	// 'near': origin == viewer -> blurred distance is a small fraction of a mile (BLUR_USER is
	// only 400m).
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, status) VALUES (?, 51.5, -0.1, "+
		"ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857), 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon)", near)
	// 'far': origin ~44km (~27 miles) from the viewer, but its (large) reach polygon still
	// covers the viewer, so it IS in the reach set -> counted when there's no distance limit.
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, status) VALUES (?, 51.9, -0.1, "+
		"ST_GeomFromText('POLYGON((-0.3 51.3, 0.1 51.3, 0.1 52.0, -0.3 52.0, -0.3 51.3))', 3857), 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon)", far)

	// No limit: both unseen reach-covered posts are counted (the sentinel/absent default).
	unlimitedResp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/count?jwt="+token, nil))
	assert.Equal(t, 200, unlimitedResp.StatusCode)
	var unlimitedBody map[string]interface{}
	json2.Unmarshal(rsp(unlimitedResp), &unlimitedBody)
	unlimitedCount := unlimitedBody["count"].(float64)
	assert.GreaterOrEqual(t, unlimitedCount, float64(2),
		"with no distance limit, count includes both reach-covered unseen posts")

	// maxDistance=10 (miles): 'near' (≈0mi) is within it, 'far' (≈27mi) is not.
	limitedResp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/count?jwt="+token+"&maxDistance=10", nil))
	assert.Equal(t, 200, limitedResp.StatusCode)
	var limitedBody map[string]interface{}
	json2.Unmarshal(rsp(limitedResp), &limitedBody)
	limitedCount := limitedBody["count"].(float64)
	assert.GreaterOrEqual(t, limitedCount, float64(1), "the near post is still counted within a 10-mile limit")
	assert.Less(t, limitedCount, unlimitedCount,
		"a 10-mile limit excludes the ~27-mile-away post, so the filtered count is strictly lower than unlimited")

	// settings.browseMaxDistance is honoured server-side too (no query param needed), so the
	// app-wide navbar badge respects the slider without every call site passing it explicitly.
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.browseMaxDistance', 10) WHERE id = ?", viewerID)
	settingResp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/count?jwt="+token, nil))
	assert.Equal(t, 200, settingResp.StatusCode)
	var settingBody map[string]interface{}
	json2.Unmarshal(rsp(settingResp), &settingBody)
	assert.Equal(t, limitedCount, settingBody["count"].(float64),
		"settings.browseMaxDistance is honoured server-side, matching the explicit query param result")
}
