package test

import (
	json2 "encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/browsecount"
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
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, status) VALUES (?, 51.5, -0.1, "+
		"ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857), ST_Envelope(ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857)), 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon)", near)
	// 'far': reach polygon is well away (~53N, 2E) and does NOT cover the viewer -> excluded.
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, status) VALUES (?, 53.0, 2.0, "+
		"ST_GeomFromText('POLYGON((2.0 53.0, 2.1 53.0, 2.1 53.1, 2.0 53.1, 2.0 53.0))', 3857), ST_Envelope(ST_GeomFromText('POLYGON((2.0 53.0, 2.1 53.0, 2.1 53.1, 2.0 53.1, 2.0 53.0))', 3857)), 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon)", far)
	// 'midfar': a much bigger reach polygon whose origin (51.9,-0.1, ~44km from the viewer) is
	// still far from the viewer, but the polygon is large enough to also cover the viewer -> the
	// post IS in reach, but its origin is much less close than 'near's, so its closeness (and
	// hence total) score must be lower.
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, status) VALUES (?, 51.9, -0.1, "+
		"ST_GeomFromText('POLYGON((-0.3 51.3, 0.1 51.3, 0.1 52.0, -0.3 52.0, -0.3 51.3))', 3857), ST_Envelope(ST_GeomFromText('POLYGON((-0.3 51.3, 0.1 51.3, 0.1 52.0, -0.3 52.0, -0.3 51.3))', 3857)), 'expanding') "+
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

// TestNearbyReachFeed_TnCrosspostDedup: a TN crosspost to two Freegle groups creates two
// independent `messages` rows sharing one tnpostid, not one message with two messages_groups
// rows. TN emails each per-group copy separately, and IncomingMailService::createGroupPostMessage
// (iznik-batch) creates a brand-new message for every inbound email with no check for an
// existing message carrying the same tnpostid. Confirmed at scale on production (2026-08-19):
// many tnpostids map to 2-6 separate message ids arriving seconds apart, e.g. tnpostid 47158849
// -> messages 121542133/121542136/121542139/121542142 across four groups, two of them Approved
// and spatially indexed simultaneously. When a viewer's reach covers more than one of the
// crossposted groups, both copies appeared on the nearby feed as separate cards for what is, to
// the member, the same donation (Discourse 9808/689).
func TestNearbyReachFeed_TnCrosspostDedup(t *testing.T) {
	db := database.DBConn

	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
		polygon GEOMETRY NOT NULL SRID 3857,
		status VARCHAR(16) NOT NULL DEFAULT 'expanding',
		SPATIAL INDEX msgreach_poly (polygon)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	prefix := uniquePrefix("tncrosspostdedup")
	posterID := CreateTestUser(t, prefix+"_poster", "Poster")
	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")

	// Two independent messages rows for what is, on TrashNothing, ONE physical item
	// crossposted to two Freegle groups - exactly what today's email ingestion path
	// produces (one messages row per inbound per-group copy, both stamped with the same
	// tnpostid).
	copyA := CreateTestMessage(t, posterID, groupA, "OFFER: crossposted table (tncrosspostdedup)", 51.5, -0.1)
	copyB := CreateTestMessage(t, posterID, groupB, "OFFER: crossposted table (tncrosspostdedup)", 51.5, -0.1)
	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid IN (?, ?)", copyA, copyB)
	db.Exec("UPDATE messages SET tnpostid = ? WHERE id IN (?, ?)", "tncrosspostdedup-12345", copyA, copyB)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid IN (?, ?)", copyA, copyB)

	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)

	// Both copies' reach polygons cover the viewer - e.g. the viewer lives where the reach
	// of both crossposted groups overlaps.
	for _, id := range []uint64{copyA, copyB} {
		db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, status) VALUES (?, 51.5, -0.1, "+
			"ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857), ST_Envelope(ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857)), 'expanding') "+
			"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon)", id)
	}

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/isochrone/message?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)

	seen := 0
	for _, m := range msgs {
		if m.ID == copyA || m.ID == copyB {
			seen++
		}
	}
	assert.Equal(t, 1, seen,
		"a TN crosspost to two groups must appear once on the nearby feed, not once per group copy")
}

// TestNearbyCountTnCrosspostDedup: the nav badge (GET /message/count, nearby view) must collapse
// TN crosspost copies exactly like the feed does (TestNearbyReachFeed_TnCrosspostDedup) — both the
// unlimited fast-path COUNT and the distance-limited per-post loop select msgid straight off
// messages_spatial, so without a tnpostid-aware collapse each extra crossposted copy that reaches
// the viewer inflates the badge by one even though the feed shows the item only once (Discourse
// 9808/689). Uses a before/after diff rather than an absolute count so it is immune to any
// in-reach posts other tests using the same viewer location left behind: adding the FIRST TN copy
// must move the count by exactly 1, and adding a SECOND copy sharing that copy's tnpostid must not
// move it at all.
func TestNearbyCountTnCrosspostDedup(t *testing.T) {
	db := database.DBConn

	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
		polygon GEOMETRY NOT NULL SRID 3857,
		status VARCHAR(16) NOT NULL DEFAULT 'expanding',
		SPATIAL INDEX msgreach_poly (polygon)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	prefix := uniquePrefix("nearbycounttncrosspost")
	posterID := CreateTestUser(t, prefix+"_poster", "Poster")
	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")

	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)

	coveringPoly := "ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857)"
	addReach := func(msgid uint64) {
		db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, status) VALUES (?, 51.5, -0.1, "+
			coveringPoly+", ST_Envelope("+coveringPoly+"), 'expanding') "+
			"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon)", msgid)
	}

	countAt := func(qs string) float64 {
		browsecount.Invalidate(viewerID)
		resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/count?jwt="+token+qs, nil))
		assert.Equal(t, 200, resp.StatusCode)
		var body map[string]interface{}
		json2.Unmarshal(rsp(resp), &body)
		c, _ := body["count"].(float64)
		return c
	}

	for _, qs := range []string{"", "&maxDistance=10"} {
		baseline := countAt(qs)

		copyA := CreateTestMessage(t, posterID, groupA, "OFFER: crossposted table nearbycount "+qs+" (tncrosspostdedup)", 51.5, -0.1)
		db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid = ?", copyA)
		addReach(copyA)
		afterFirst := countAt(qs)
		assert.Equal(t, baseline+1, afterFirst,
			"the first crosspost copy (qs=%q) is counted like any other unseen in-reach post", qs)

		copyB := CreateTestMessage(t, posterID, groupB, "OFFER: crossposted table nearbycount "+qs+" (tncrosspostdedup)", 51.5, -0.1)
		db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid = ?", copyB)
		addReach(copyB)
		db.Exec("UPDATE messages SET tnpostid = ? WHERE id IN (?, ?)", fmt.Sprintf("%s-tn-%s", prefix, qs), copyA, copyB)
		afterSecond := countAt(qs)
		assert.Equal(t, afterFirst, afterSecond,
			"a second copy sharing the first's tnpostid (qs=%q) must not increase the badge", qs)

		db.Exec("DELETE FROM rippling_reach WHERE msgid IN (?, ?)", copyA, copyB)
	}
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
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, status) VALUES (?, 51.5, -0.1, "+
		"ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857), ST_Envelope(ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857)), 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon)", near)
	// 'far': origin ~44km (~27 miles) from the viewer, but its (large) reach polygon still
	// covers the viewer, so it IS in the reach set -> counted when there's no distance limit.
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, status) VALUES (?, 51.9, -0.1, "+
		"ST_GeomFromText('POLYGON((-0.3 51.3, 0.1 51.3, 0.1 52.0, -0.3 52.0, -0.3 51.3))', 3857), ST_Envelope(ST_GeomFromText('POLYGON((-0.3 51.3, 0.1 51.3, 0.1 52.0, -0.3 52.0, -0.3 51.3))', 3857)), 'expanding') "+
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

	// This asks the same question as the explicit maxDistance=10 call above - same viewer,
	// same resolved distance - so it would be answered from the remembered count and pass
	// whatever the server did with the setting. Forget it first, so the assertion below
	// still proves the setting was read.
	browsecount.Invalidate(viewerID)

	settingResp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/count?jwt="+token, nil))
	assert.Equal(t, 200, settingResp.StatusCode)
	var settingBody map[string]interface{}
	json2.Unmarshal(rsp(settingResp), &settingBody)
	assert.Equal(t, limitedCount, settingBody["count"].(float64),
		"settings.browseMaxDistance is honoured server-side, matching the explicit query param result")
}

// TestNearbyFeedHonoursDistanceLimit: the browse FEED (GET /isochrone/message) applies the
// SAME distance filter as the count, so the posts the client shows above its "You're up to
// date" divider match the unread badge. Without it the feed returned every reach-covered
// post regardless of the member's browseMaxDistance while the count honoured it, so the badge
// and the feed diverged (a Tower Hamlets moderator saw a badge of 3 with 9 posts above the
// divider). Mirrors TestNearbyCountDistanceLimit's setup for the feed side.
func TestNearbyFeedHonoursDistanceLimit(t *testing.T) {
	db := database.DBConn

	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
		polygon GEOMETRY NOT NULL SRID 3857,
		status VARCHAR(16) NOT NULL DEFAULT 'expanding',
		SPATIAL INDEX msgreach_poly (polygon)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	prefix := uniquePrefix("nearbyfeeddist")
	posterID := CreateTestUser(t, prefix+"_poster", "Poster")
	group := CreateTestGroup(t, prefix)
	near := CreateTestMessage(t, posterID, group, "OFFER: near for feed distance limit (nearbyfeeddist)", 51.5, -0.1)
	far := CreateTestMessage(t, posterID, group, "OFFER: far but in reach for feed distance limit (nearbyfeeddist)", 51.9, -0.1)
	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid IN (?, ?)", near, far)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid IN (?, ?)", near, far)

	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)

	// 'near' reach polygon covers the viewer; 'far' is ~27 miles away but its large reach
	// polygon still covers the viewer, so both are in the reach set.
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, status) VALUES (?, 51.5, -0.1, "+
		"ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857), ST_Envelope(ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857)), 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon)", near)
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, status) VALUES (?, 51.9, -0.1, "+
		"ST_GeomFromText('POLYGON((-0.3 51.3, 0.1 51.3, 0.1 52.0, -0.3 52.0, -0.3 51.3))', 3857), ST_Envelope(ST_GeomFromText('POLYGON((-0.3 51.3, 0.1 51.3, 0.1 52.0, -0.3 52.0, -0.3 51.3))', 3857)), 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon)", far)

	inFeed := func(url string) map[float64]bool {
		resp, _ := getApp().Test(httptest.NewRequest("GET", url, nil))
		assert.Equal(t, 200, resp.StatusCode)
		var msgs []map[string]interface{}
		json2.Unmarshal(rsp(resp), &msgs)
		got := map[float64]bool{}
		for _, m := range msgs {
			if id, ok := m["id"].(float64); ok {
				got[id] = true
			}
		}
		return got
	}

	// No limit: both reach-covered posts appear in the feed.
	unlimited := inFeed("/api/isochrone/message?jwt=" + token)
	assert.True(t, unlimited[float64(near)], "near post appears in the feed with no distance limit")
	assert.True(t, unlimited[float64(far)], "far (but in-reach) post appears in the feed with no distance limit")

	// browseMaxDistance=10: the ~27-mile 'far' post drops from the feed, matching the count.
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.browseMaxDistance', 10) WHERE id = ?", viewerID)
	limited := inFeed("/api/isochrone/message?jwt=" + token)
	assert.True(t, limited[float64(near)], "near post still appears within the 10-mile browseMaxDistance")
	assert.False(t, limited[float64(far)], "the ~27-mile far post is filtered out of the feed, matching the count")
}

// TestNearbyFeedPostedIsOriginalArrival: the nearby feed exposes `posted` = the ORIGINAL post
// arrival (messages.arrival), which is stable across rippling, DISTINCT from `arrival` =
// messages_spatial.arrival, which the reach engine bumps forward every time the post ripples
// into a new group. The client's "Newest posted" sort orders by `posted`, so a post that merely
// rippled again does not leap to the top of the feed with a days-old badge (Discourse 9844). This
// guards the SQL that JOINs messages for m.arrival AS posted on the reach arm.
func TestNearbyFeedPostedIsOriginalArrival(t *testing.T) {
	db := database.DBConn

	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
		polygon GEOMETRY NOT NULL SRID 3857,
		status VARCHAR(16) NOT NULL DEFAULT 'expanding',
		SPATIAL INDEX msgreach_poly (polygon)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	prefix := uniquePrefix("nearbyposted")
	posterID := CreateTestUser(t, prefix+"_poster", "Poster")
	group := CreateTestGroup(t, prefix)
	msg := CreateTestMessage(t, posterID, group, "OFFER: originally posted long ago, rippled recently (nearbyposted)", 51.5, -0.1)
	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid = ?", msg)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msg)

	// A post first offered on 2024-01-01 (messages.arrival) that has since rippled out: the reach
	// engine bumped messages_spatial.arrival forward to its latest expansion time (2024-06-01).
	db.Exec("UPDATE messages SET arrival = '2024-01-01 00:00:00' WHERE id = ?", msg)
	db.Exec("UPDATE messages_spatial SET arrival = '2024-06-01 00:00:00' WHERE msgid = ?", msg)

	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)

	// Reach polygon covers the viewer so the post is on the nearby feed.
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, status) VALUES (?, 51.5, -0.1, "+
		"ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857), ST_Envelope(ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857)), 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon)", msg)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/isochrone/message?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)
	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)

	var found *message.MessageSummary
	for i := range msgs {
		if msgs[i].ID == msg {
			found = &msgs[i]
			break
		}
	}
	assert.NotNil(t, found, "the rippled post appears in the nearby feed")
	if found != nil {
		// posted = original messages.arrival (January); arrival = ripple-bumped spatial arrival (June).
		assert.Equal(t, 2024, found.Posted.Year(), "posted carries the original post year")
		assert.Equal(t, time.January, found.Posted.Month(), "posted is the original post month, not the ripple month")
		assert.Equal(t, time.June, found.Arrival.Month(), "arrival is the ripple-bumped spatial month")
		assert.True(t, found.Posted.Before(found.Arrival),
			"posted (original post time) is strictly earlier than the ripple-bumped spatial arrival")
	}
}

// A post whose reach has been frozen for moderation (rippling_reach.status = 'held' -
// set when its origin copy is pulled back to Pending by reports or Back to Pending)
// must NOT appear on the nearby browse feed. Every batch-side reach consumer already
// excludes held rows; this pins the read side, which is how a reported post kept
// showing in browse (https://discourse.ilovefreegle.org/t/reporting-posts/9862/7).
func TestNearbyReachFeedExcludesHeld(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("nearbyheld")
	posterID := CreateTestUser(t, prefix+"_poster", "Poster")
	group := CreateTestGroup(t, prefix)
	live := CreateTestMessage(t, posterID, group, "OFFER: live reach (nearbyheld)", 51.5, -0.1)
	held := CreateTestMessage(t, posterID, group, "OFFER: held reach (nearbyheld)", 51.5, -0.1)
	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid IN (?, ?)", live, held)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid IN (?, ?)", live, held)

	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)

	// Both reach polygons cover the viewer - only the status differs.
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, status) VALUES (?, 51.5, -0.1, "+
		"ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857), ST_Envelope(ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857)), 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon), status = VALUES(status)", live)
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, status) VALUES (?, 51.5, -0.1, "+
		"ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857), ST_Envelope(ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857)), 'held') "+
		"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon), status = VALUES(status)", held)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/isochrone/message?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)
	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)

	got := map[uint64]bool{}
	for _, m := range msgs {
		got[m.ID] = true
	}
	assert.True(t, got[live], "a post with a live reach appears in the nearby feed")
	assert.False(t, got[held], "a post whose reach is held for moderation is absent from the nearby feed")
}

// TestNearbyFeedHonoursAuthorDistanceLimit: the OUTBOUND half of the distance preference. A post
// author's settings.browseMaxDistance also caps WHO SEES their post — a viewer beyond that distance
// of the post does not see it in the nearby feed (or unread count) even when the viewer has no
// distance limit of their own and the post's reach polygon covers them. Mirrors the recipient-side
// TestNearbyFeedHonoursDistanceLimit, but the cap is set on the POSTER, not the viewer.
func TestNearbyFeedHonoursAuthorDistanceLimit(t *testing.T) {
	db := database.DBConn

	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
		polygon GEOMETRY NOT NULL SRID 3857,
		status VARCHAR(16) NOT NULL DEFAULT 'expanding',
		SPATIAL INDEX msgreach_poly (polygon)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	prefix := uniquePrefix("nearbyauthordist")
	posterID := CreateTestUser(t, prefix+"_poster", "Poster")
	// The poster caps how far away their posts are shown at 10 miles.
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.browseMaxDistance', 10) WHERE id = ?", posterID)

	group := CreateTestGroup(t, prefix)
	near := CreateTestMessage(t, posterID, group, "OFFER: near author-cap (nearbyauthordist)", 51.5, -0.1)
	far := CreateTestMessage(t, posterID, group, "OFFER: far author-cap (nearbyauthordist)", 51.9, -0.1)
	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid IN (?, ?)", near, far)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid IN (?, ?)", near, far)

	// The viewer has NO distance limit of their own, so any filtering is the author's doing.
	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)

	// Both reach polygons cover the viewer; 'near' is at the viewer (~0mi), 'far' is ~27mi away.
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, status) VALUES (?, 51.5, -0.1, "+
		"ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857), ST_Envelope(ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857)), 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon)", near)
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, status) VALUES (?, 51.9, -0.1, "+
		"ST_GeomFromText('POLYGON((-0.3 51.3, 0.1 51.3, 0.1 52.0, -0.3 52.0, -0.3 51.3))', 3857), ST_Envelope(ST_GeomFromText('POLYGON((-0.3 51.3, 0.1 51.3, 0.1 52.0, -0.3 52.0, -0.3 51.3))', 3857)), 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon)", far)

	inFeed := func(url string) map[float64]bool {
		resp, _ := getApp().Test(httptest.NewRequest("GET", url, nil))
		assert.Equal(t, 200, resp.StatusCode)
		var msgs []map[string]interface{}
		json2.Unmarshal(rsp(resp), &msgs)
		got := map[float64]bool{}
		for _, m := range msgs {
			if id, ok := m["id"].(float64); ok {
				got[id] = true
			}
		}
		return got
	}

	// The viewer has no distance limit of their own, so the unread count takes the fast (no per-post
	// distance) path - which must ALSO honour the author cap so the badge matches the feed.
	countOf := func() float64 {
		// The badge may lag a post arriving or changing by a few seconds (see the
		// browsecount package, whose own tests cover that). These tests are about which
		// posts the counting SQL includes, so ask afresh rather than measure the reuse.
		browsecount.Invalidate(viewerID)

		resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/count?jwt="+token, nil))
		assert.Equal(t, 200, resp.StatusCode)
		var body map[string]interface{}
		json2.Unmarshal(rsp(resp), &body)
		if c, ok := body["count"].(float64); ok {
			return c
		}
		return -1
	}

	// With the poster's 10-mile cap, the ~27-mile far viewer position drops the far post; near stays.
	capped := inFeed("/api/isochrone/message?jwt=" + token)
	assert.True(t, capped[float64(near)], "the near post (within the author's 10-mile cap) appears")
	assert.False(t, capped[float64(far)],
		"the ~27-mile far post is hidden by the author's 10-mile outbound cap, despite being in reach and the viewer having no limit")
	cappedCount := countOf()

	// Control: remove the author's cap and the far in-reach post reappears — proving the cap, not
	// reach or the viewer's own setting, is what hid it.
	db.Exec("UPDATE users SET settings = JSON_REMOVE(settings, '$.browseMaxDistance') WHERE id = ?", posterID)
	uncapped := inFeed("/api/isochrone/message?jwt=" + token)
	assert.True(t, uncapped[float64(far)], "with no author cap, the far in-reach post appears again")
	assert.Less(t, cappedCount, countOf(),
		"the fast-path unread count excludes the far post while the author caps distance, and re-includes it once uncapped - staying in lock-step with the feed")
}

// TestNearbyCountExcludesHeld: the unread badge (GET /message/count, nearby view) must skip
// posts whose reach is held for moderation, exactly as the feed does
// (TestNearbyReachFeedExcludesHeld) - without the same rr.status filter the badge counted a
// held post the feed never rendered, so the count could never drain to zero by reading.
// Asserted on BOTH count paths: the fast unlimited COUNT and the distance-limited walk.
func TestNearbyCountExcludesHeld(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("nearbycountheld")
	posterID := CreateTestUser(t, prefix+"_poster", "Poster")
	group := CreateTestGroup(t, prefix)
	live := CreateTestMessage(t, posterID, group, "OFFER: live reach for count (nearbycountheld)", 51.5, -0.1)
	held := CreateTestMessage(t, posterID, group, "OFFER: held reach for count (nearbycountheld)", 51.5, -0.1)
	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid IN (?, ?)", live, held)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid IN (?, ?)", live, held)

	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)

	// Both reach polygons cover the viewer - only the status differs.
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, status) VALUES (?, 51.5, -0.1, "+
		"ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857), ST_Envelope(ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857)), 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon), status = VALUES(status)", live)
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, status) VALUES (?, 51.5, -0.1, "+
		"ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857), ST_Envelope(ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857)), 'held') "+
		"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon), status = VALUES(status)", held)

	countOf := func(url string) float64 {
		// The badge may lag a post arriving or changing by a few seconds (see the
		// browsecount package, whose own tests cover that). These tests are about which
		// posts the counting SQL includes, so ask afresh rather than measure the reuse.
		browsecount.Invalidate(viewerID)

		resp, _ := getApp().Test(httptest.NewRequest("GET", url, nil))
		assert.Equal(t, 200, resp.StatusCode)
		var body map[string]interface{}
		json2.Unmarshal(rsp(resp), &body)
		c, _ := body["count"].(float64)
		return c
	}

	// Fast unlimited path: with the held row hidden, hiding the held post must drop the count by
	// exactly one relative to both rows counting - measured by flipping the held row live and back
	// rather than asserting absolute numbers, since parallel tests may add their own posts.
	heldCount := countOf("/api/message/count?jwt=" + token)
	db.Exec("UPDATE rippling_reach SET status = 'expanding' WHERE msgid = ?", held)
	bothLiveCount := countOf("/api/message/count?jwt=" + token)
	assert.Equal(t, heldCount+1, bothLiveCount,
		"releasing the held reach adds exactly that post to the fast-path badge count")
	db.Exec("UPDATE rippling_reach SET status = 'held' WHERE msgid = ?", held)

	// Distance-limited path (the per-post blurred-Haversine walk) must skip held rows too.
	heldLimited := countOf("/api/message/count?jwt=" + token + "&maxDistance=10")
	db.Exec("UPDATE rippling_reach SET status = 'expanding' WHERE msgid = ?", held)
	bothLimited := countOf("/api/message/count?jwt=" + token + "&maxDistance=10")
	assert.Equal(t, heldLimited+1, bothLimited,
		"releasing the held reach adds exactly that post to the distance-limited badge count")
}

// TestNearbyCountSpatialReach: with SPATIAL_REACH_MODE=on the badge count takes its reach
// containment from the spatial server (stubbed here): `in` ids count directly, `partial`
// ids are exact-tested against rippling_reach.polygon (including a held re-check newer
// than the spatial index), and a spatial failure falls back to the SQL containment path
// with the same answer.
func TestNearbyCountSpatialReach(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("nearbyspatial")
	posterID := CreateTestUser(t, prefix+"_poster", "Poster")
	group := CreateTestGroup(t, prefix)
	// covered: reach covers the viewer; the stub reports it as definite `in`.
	covered := CreateTestMessage(t, posterID, group, "OFFER: spatial in (nearbyspatial)", 51.5, -0.1)
	// boundary: reach covers the viewer; the stub reports it as `partial`, so only the
	// exact SQL test brings it in.
	boundary := CreateTestMessage(t, posterID, group, "OFFER: spatial partial covered (nearbyspatial)", 51.5, -0.1)
	// outside: reach does NOT cover the viewer; the stub still reports it `partial`
	// (a fat raster band), and the exact test must exclude it.
	outside := CreateTestMessage(t, posterID, group, "OFFER: spatial partial outside (nearbyspatial)", 51.5, -0.1)
	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid IN (?, ?, ?)", covered, boundary, outside)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid IN (?, ?, ?)", covered, boundary, outside)

	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)

	coveringPoly := "ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857)"
	elsewherePoly := "ST_GeomFromText('POLYGON((2.0 53.0, 2.1 53.0, 2.1 53.1, 2.0 53.1, 2.0 53.0))', 3857)"
	for _, row := range []struct {
		msgid uint64
		poly  string
	}{{covered, coveringPoly}, {boundary, coveringPoly}, {outside, elsewherePoly}} {
		db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, status) VALUES (?, 51.5, -0.1, "+
			row.poly+", ST_Envelope("+row.poly+"), 'expanding') "+
			"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon), status = VALUES(status)", row.msgid)
	}

	stub := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/v1/reach/containing" {
			w.WriteHeader(http.StatusNotFound)
			return
		}
		w.Header().Set("Content-Type", "application/json")
		fmt.Fprintf(w, `{"in":[%d],"partial":[%d,%d]}`, covered, boundary, outside)
	}))
	defer stub.Close()
	t.Setenv("SPATIAL_REACH_MODE", "on")
	t.Setenv("SPATIAL_KNN_URL", stub.URL)

	countOf := func() float64 {
		// The badge may lag a post arriving or changing by a few seconds (see the
		// browsecount package, whose own tests cover that). These tests are about which
		// posts the counting SQL includes, so ask afresh rather than measure the reuse.
		browsecount.Invalidate(viewerID)

		resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/count?jwt="+token, nil))
		assert.Equal(t, 200, resp.StatusCode)
		var body map[string]interface{}
		json2.Unmarshal(rsp(resp), &body)
		c, _ := body["count"].(float64)
		return c
	}

	// Spatial path: `covered` counts from the in-list, `boundary` survives the exact
	// test, `outside` is excluded by it. The stub only returns these three ids, so the
	// count is exact (no >= hedging needed).
	assert.Equal(t, float64(2), countOf(),
		"in-list counts directly; partial ids resolve by the exact polygon test")

	// A hold newer than the spatial index must still hide the post: flip `boundary`
	// (a partial id, so the SQL exact test sees the fresh status) to held.
	db.Exec("UPDATE rippling_reach SET status = 'held' WHERE msgid = ?", boundary)
	assert.Equal(t, float64(1), countOf(),
		"a freshly-held partial id is re-checked in SQL and excluded")
	db.Exec("UPDATE rippling_reach SET status = 'expanding' WHERE msgid = ?", boundary)

	// The same must hold for a definite `in` id. The raster is rebuilt on a 2-minute
	// delta (iznik-spatial-go dataset_reach.go DeltaInterval), so for up to that long
	// after a member report or a moderator's Back to Pending the index still says the
	// post covers this viewer. The FEED excludes it immediately - reachCandidateQuery
	// always joins rippling_reach and tests rr.status != 'held' - so without the same
	// re-check here the badge counts a post the feed will not show, which is exactly
	// the "N new posts" / "you're up to date" mismatch members report.
	db.Exec("UPDATE rippling_reach SET status = 'held' WHERE msgid = ?", covered)
	assert.Equal(t, float64(1), countOf(),
		"a freshly-held in-list id is re-checked in SQL and excluded, like a partial one")
	db.Exec("UPDATE rippling_reach SET status = 'expanding' WHERE msgid = ?", covered)

	// And a reach row that has gone entirely (retraction) must not leave the id
	// countable either - the in-list disjunct has to require a live reach row, not
	// merely the absence of a held one.
	db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", covered)
	assert.Equal(t, float64(1), countOf(),
		"an in-list id whose reach row has gone is not counted")
	// Put it back: the fallback assertions below expect both posts in reach again.
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, status) VALUES (?, 51.5, -0.1, "+
		coveringPoly+", ST_Envelope("+coveringPoly+"), 'expanding')", covered)

	// Spatial down: transparent fallback to the SQL containment path, same answer.
	stub.Close()
	assert.Equal(t, float64(2), countOf(),
		"spatial failure falls back to the SQL containment path with the same result")

	// Mode off: SQL path even with a (dead) URL configured.
	t.Setenv("SPATIAL_REACH_MODE", "")
	assert.Equal(t, float64(2), countOf(), "mode off uses the SQL path")
}
