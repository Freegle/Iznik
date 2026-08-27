package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/embedding"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// TestSimilarImpressionLoggingDoesNotAffectResults covers the impression
// logging added so MinSimilarScore can be tuned on click-through per score band.
//
// It asserts the endpoint's output is unchanged with Loki enabled, not that a
// line was written: misc.GetLoki() is a sync.Once singleton, so whichever test
// runs first fixes its enabled/disabled state for the whole process and a
// file-content assertion would pass or fail on test ordering. What matters here
// is that instrumentation on the serving path cannot change or break what the
// strip returns.
func TestSimilarImpressionLoggingDoesNotAffectResults(t *testing.T) {
	t.Setenv("LOKI_ENABLED", "true")
	t.Setenv("LOKI_JSON_PATH", t.TempDir())

	base := makeTestVec(0.5)
	strong := makeVecWithCosine(base, 0.93)
	const srcID, okID = 813001, 813002
	const u1, u2 = uint64(96001), uint64(96002)

	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: srcID, Fromuser: u1, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "Dining table", Arrival: time.Now(), SubjectVec: base},
		{Msgid: okID, Fromuser: u2, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "Dining table and chairs", Arrival: time.Now(), SubjectVec: strong},
	})
	defer embedding.Global.SetEntries(nil)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/813001/similar", nil), 60000)
	require.Equal(t, 200, resp.StatusCode)

	var results []message.SimilarResult
	json.NewDecoder(resp.Body).Decode(&results)

	require.Len(t, results, 1, "logging must not drop or duplicate served candidates")
	assert.Equal(t, uint64(okID), results[0].Msgid)
	assert.GreaterOrEqual(t, results[0].Score, float32(message.MinSimilarScore))
}

// TestSimilarThresholdExcludesWeakBand pins the strip's floor at the measured
// 0.80 rather than the original 0.60.
//
// The band between them is where the strip used to embarrass itself: on 150
// hand-judged live samples, top suggestions scoring 0.75-0.80 were useful only
// 43% of the time and below 0.75 only 17%, producing pairings that shared a word
// and nothing else (Crocosmia -> "Crockery", Motherboard -> "Headboard"). A 0.70
// candidate clears the old floor comfortably and must still be dropped; without
// this, lowering the constant back to 0.60 stays green.
func TestSimilarThresholdExcludesWeakBand(t *testing.T) {
	base := makeTestVec(0.5)
	const srcID, weakID, strongID = 812001, 812002, 812003
	const u1, u2 = uint64(95001), uint64(95002)

	weak := makeVecWithCosine(base, 0.70)   // above old 0.60, below new 0.80
	strong := makeVecWithCosine(base, 0.92) // clears the new floor

	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: srcID, Fromuser: u1, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "Crocosmia", Arrival: time.Now(), SubjectVec: base},
		{Msgid: weakID, Fromuser: u2, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "Crockery", Arrival: time.Now(), SubjectVec: weak},
		{Msgid: strongID, Fromuser: u2, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "Crocosmia bulbs", Arrival: time.Now(), SubjectVec: strong},
	})
	defer embedding.Global.SetEntries(nil)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/812001/similar", nil), 60000)
	require.Equal(t, 200, resp.StatusCode)

	var results []message.SimilarResult
	json.NewDecoder(resp.Body).Decode(&results)

	got := map[uint64]bool{}
	for _, r := range results {
		got[r.Msgid] = true
		assert.GreaterOrEqual(t, r.Score, float32(message.MinSimilarScore))
	}
	assert.True(t, got[uint64(strongID)], "0.92 similarity is shown")
	assert.False(t, got[uint64(weakID)], "0.70 similarity clears the old 0.60 floor but must not be shown")
}

// TestSimilarReturnsNearMatchesSameType: given a source Offer, /similar returns
// only open posts that are the same type, above MinSimilarScore, by a DIFFERENT
// author, and not the source itself.
func TestSimilarReturnsNearMatchesSameType(t *testing.T) {
	base := makeTestVec(0.5)
	near := makeTestVec(0.5001) // cosine ~1 with base
	const srcID, nearID, wantedID, weakID, sameAuthorID = 810001, 810002, 810003, 810004, 810005
	const u1, u2 = uint64(91001), uint64(91002)

	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: srcID, Fromuser: u1, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "Blue sofa", Arrival: time.Now(), SubjectVec: base},
		{Msgid: nearID, Fromuser: u2, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "Grey sofa", Arrival: time.Now(), SubjectVec: near},
		{Msgid: wantedID, Fromuser: u2, Groupid: 100, Msgtype: "Wanted", Lat: 51.5, Lng: -0.1, Subject: "Want sofa", Arrival: time.Now(), SubjectVec: near},
		{Msgid: weakID, Fromuser: u2, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "unrelated noise", Arrival: time.Now(), SubjectVec: makeAntiparallelVec(0.5)},
		{Msgid: sameAuthorID, Fromuser: u1, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "Another sofa", Arrival: time.Now(), SubjectVec: near},
	})
	defer embedding.Global.SetEntries(nil)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/810001/similar", nil), 60000)
	require.Equal(t, 200, resp.StatusCode)

	var results []message.SimilarResult
	json.NewDecoder(resp.Body).Decode(&results)

	require.Len(t, results, 1, "only the same-type, above-threshold, different-author match qualifies")
	assert.Equal(t, uint64(nearID), results[0].Msgid)
	assert.GreaterOrEqual(t, results[0].Score, float32(message.MinSimilarScore))
	assert.NotZero(t, results[0].Lat, "lat populated (blurred)")
	assert.NotZero(t, results[0].Lng, "lng populated (blurred)")
	assert.Equal(t, uint64(100), results[0].Groupid)
}

// TestSimilarFlipsTypeOnOwnPost: viewing your OWN post flips the type — a Wanted you
// posted surfaces Offers (what you want, available), and an Offer surfaces Wanteds.
// Anyone else viewing the same post gets like-with-like (same type).
func TestSimilarFlipsTypeOnOwnPost(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("similarflip")
	author := CreateTestUser(t, prefix+"a", "Member")
	other := CreateTestUser(t, prefix+"o", "Member")
	for _, u := range []uint64{author, other} {
		db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
			"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", u)
	}
	authorToken := getToken(t, author)
	otherToken := getToken(t, other)

	base := makeTestVec(0.5)
	near := makeTestVec(0.5001)
	const srcID, offerID, wantedID = 880001, 880002, 880003

	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: srcID, Fromuser: author, Groupid: 100, Msgtype: "Wanted", Lat: 51.5, Lng: -0.1, Subject: "Want a sofa", Arrival: time.Now(), SubjectVec: base},
		// A matching OFFER by someone else — the flipped type for the author.
		{Msgid: offerID, Fromuser: 99001, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "Sofa", Arrival: time.Now(), SubjectVec: near},
		// Another WANTED — same type as the source.
		{Msgid: wantedID, Fromuser: 99002, Groupid: 100, Msgtype: "Wanted", Lat: 51.5, Lng: -0.1, Subject: "Want sofa too", Arrival: time.Now(), SubjectVec: near},
	})
	defer embedding.Global.SetEntries(nil)

	// Author views their own Wanted → sees the Offer, not the other Wanted.
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/880001/similar?jwt="+authorToken, nil), 60000)
	require.Equal(t, 200, resp.StatusCode)
	var mine []message.SimilarResult
	json.NewDecoder(resp.Body).Decode(&mine)
	gotMine := map[uint64]bool{}
	for _, r := range mine {
		gotMine[r.Msgid] = true
	}
	assert.True(t, gotMine[uint64(offerID)], "own Wanted surfaces a matching Offer")
	assert.False(t, gotMine[uint64(wantedID)], "own Wanted does not surface other Wanteds")

	// A different viewer gets same-type (Wanted) matches, not flipped.
	resp2, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/880001/similar?jwt="+otherToken, nil), 60000)
	var theirs []message.SimilarResult
	json.NewDecoder(resp2.Body).Decode(&theirs)
	gotTheirs := map[uint64]bool{}
	for _, r := range theirs {
		gotTheirs[r.Msgid] = true
	}
	assert.True(t, gotTheirs[uint64(wantedID)], "another viewer gets same-type (Wanted) matches")
	assert.False(t, gotTheirs[uint64(offerID)], "another viewer does not get the flipped Offer")
}

// TestSimilarMessageNotInStore: a source post that isn't in the in-memory store
// but has an embedding row in the DB resolves via the fallback read and still
// matches store candidates.
func TestSimilarMessageNotInStore(t *testing.T) {
	prefix := uniquePrefix("similarnotinstore")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "Member")
	otherUser := CreateTestUser(t, prefix+"o", "Member")

	base := makeTestVec(0.5)
	near := makeTestVec(0.5001)

	// Source in the DB only (helper inserts an OPEN message + embedding); never
	// loaded into embedding.Global, so the handler must hit the DB fallback.
	srcID := createOpenTestMessageWithEmbedding(t, userID, groupID, "DB sofa "+prefix, 51.5, -0.1, base)

	const candID = uint64(820002)
	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: candID, Fromuser: otherUser, Groupid: groupID, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "Store sofa", Arrival: time.Now(), SubjectVec: near},
	})
	defer embedding.Global.SetEntries(nil)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/"+fmt.Sprint(srcID)+"/similar", nil), 60000)
	require.Equal(t, 200, resp.StatusCode)

	var results []message.SimilarResult
	json.NewDecoder(resp.Body).Decode(&results)
	require.Len(t, results, 1, "DB-resolved source should match the store candidate")
	assert.Equal(t, candID, results[0].Msgid)
}

// TestSimilarNoEmbedding: a source id with no embedding row returns 200 + empty,
// never a 500.
func TestSimilarNoEmbedding(t *testing.T) {
	embedding.Global.SetEntries(nil)
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/999999999/similar", nil), 60000)
	require.Equal(t, 200, resp.StatusCode)

	var results []message.SimilarResult
	json.NewDecoder(resp.Body).Decode(&results)
	assert.Empty(t, results)
}

// TestSimilarFlagOff: FEATURE_SIMILAR_POSTS=off short-circuits to an empty list
// regardless of available matches.
// TestSimilarOverfetchSurvivesReachRejection: a reachable match that scores BELOW
// more candidates than the requested limit*3 must still be shown. The search pulls a
// large fixed candidate pool before the reach filter; if it only took the top limit*3
// by score, a viewer in a sparsely-rippled area — whose highest-scoring nearby posts
// are all out of reach — would get an empty strip even though reachable matches exist
// further down the ranking (the "still showing []" bug).
func TestSimilarOverfetchSurvivesReachRejection(t *testing.T) {
	t.Setenv("RIPPLE_ENABLED", "true")
	db := database.DBConn
	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
		polygon_cells MEDIUMBLOB NULL,
		outer_bound GEOMETRY NULL,
		status VARCHAR(16) NOT NULL DEFAULT 'expanding'
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	prefix := uniquePrefix("similaroverfetch")
	viewerID := CreateTestUser(t, prefix+"v", "Viewer")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)
	token := getToken(t, viewerID)
	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"p", "Member")

	base := makeTestVec(0.5)
	strong := makeVecWithCosine(base, 0.95) // higher score than the reachable one
	reachableVec := makeVecWithCosine(base, 0.85)

	const srcID = 870001
	entries := []embedding.Entry{
		{Msgid: srcID, Fromuser: 88001, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "src", Arrival: time.Now(), SubjectVec: base},
	}

	// Eight higher-scoring candidates, all near the viewer (in the box) but rippled
	// far away so the viewer is outside their reach → every one is blocked. That is
	// more than the old limit*3 pool for a small requested limit.
	blocked := make([]uint64, 0, 8)
	for i := 0; i < 8; i++ {
		mid := CreateTestMessage(t, poster, groupID, fmt.Sprintf("%s blocked %d", prefix, i), 51.5, -0.1)
		entries = append(entries, embedding.Entry{Msgid: mid, Fromuser: poster, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: fmt.Sprintf("blocked %d", i), Arrival: time.Now(), SubjectVec: strong})
		blocked = append(blocked, mid)
		db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", mid)
		db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound, status) VALUES (?, 51.5, -0.1, ?, "+
			"ST_Envelope(ST_GeomFromText('POLYGON((2.4 53.4, 2.6 53.4, 2.6 53.6, 2.4 53.6, 2.4 53.4))', 3857)), 'expanding')", mid,
			mustRasterize(t, "POLYGON((2.4 53.4, 2.6 53.4, 2.6 53.6, 2.4 53.6, 2.4 53.4))"))
	}
	t.Cleanup(func() {
		for _, m := range blocked {
			db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", m)
		}
	})

	// One lower-scoring, reachable candidate (no reach row → fail-open). It ranks below
	// all eight blocked ones, so at limit=2 (old pool = 6) it never entered the set.
	const reachableID = 870999
	entries = append(entries, embedding.Entry{Msgid: reachableID, Fromuser: 88002, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "reachable", Arrival: time.Now(), SubjectVec: reachableVec})
	embedding.Global.SetEntries(entries)
	defer embedding.Global.SetEntries(nil)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/870001/similar?limit=2&jwt="+token, nil), 60000)
	require.Equal(t, 200, resp.StatusCode)
	var results []message.SimilarResult
	json.NewDecoder(resp.Body).Decode(&results)

	got := map[uint64]bool{}
	for _, r := range results {
		got[r.Msgid] = true
	}
	assert.True(t, got[uint64(reachableID)], "the reachable lower-scored match survives the large over-fetch")
	for _, m := range blocked {
		assert.False(t, got[m], "out-of-reach candidates are dropped")
	}
}

func TestSimilarFlagOff(t *testing.T) {
	t.Setenv("FEATURE_SIMILAR_POSTS", "off")
	base := makeTestVec(0.5)
	near := makeTestVec(0.5001)
	const srcID, nearID = 830001, 830002
	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: srcID, Fromuser: 1, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "x", Arrival: time.Now(), SubjectVec: base},
		{Msgid: nearID, Fromuser: 2, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "y", Arrival: time.Now(), SubjectVec: near},
	})
	defer embedding.Global.SetEntries(nil)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/830001/similar", nil), 60000)
	require.Equal(t, 200, resp.StatusCode)

	var results []message.SimilarResult
	json.NewDecoder(resp.Body).Decode(&results)
	assert.Empty(t, results, "flag off → empty regardless of matches")
}

// TestSimilarReachFiltered: for a logged-in viewer with a known location, a
// candidate whose rippling reach doesn't cover the viewer is dropped, while a
// candidate with no reach row is kept (fail-open). An anonymous request is filtered
// the same way but centred on the POST's location instead of the viewer's.
func TestSimilarReachFiltered(t *testing.T) {
	t.Setenv("RIPPLE_ENABLED", "true")
	db := database.DBConn
	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
		polygon_cells MEDIUMBLOB NULL,
		outer_bound GEOMETRY NULL,
		status VARCHAR(16) NOT NULL DEFAULT 'expanding'
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	prefix := uniquePrefix("similarreach")
	viewerID := CreateTestUser(t, prefix+"v", "Viewer")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)
	token := getToken(t, viewerID)

	// The out-of-reach candidate needs a real message row because rippling_reach
	// has a FK to messages(id); the source and in-reach candidate are synthetic
	// store-only entries (they need no reach row).
	groupID := CreateTestGroup(t, prefix)
	posterOut := CreateTestUser(t, prefix+"po", "Member")
	outReachID := CreateTestMessage(t, posterOut, groupID, "out of reach sofa", 51.5, -0.1)

	base := makeTestVec(0.5)
	near := makeTestVec(0.5001)
	const srcID, inReachID = 840001, 840002
	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: srcID, Fromuser: 70001, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "src sofa", Arrival: time.Now(), SubjectVec: base},
		{Msgid: inReachID, Fromuser: 70002, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "in reach sofa", Arrival: time.Now(), SubjectVec: near},
		{Msgid: outReachID, Fromuser: posterOut, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "out of reach sofa", Arrival: time.Now(), SubjectVec: near},
	})
	defer embedding.Global.SetEntries(nil)

	// outReachID's reach polygon is far from the viewer's (51.5, -0.1) → blocked.
	// inReachID has NO reach row → fail-open (kept).
	db.Exec("DELETE FROM rippling_reach WHERE msgid IN (?, ?, ?)", srcID, inReachID, outReachID)
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound, status) VALUES (?, 51.5, -0.1, ?, "+
		"ST_Envelope(ST_GeomFromText('POLYGON((2.4 53.4, 2.6 53.4, 2.6 53.6, 2.4 53.6, 2.4 53.4))', 3857)), 'expanding')", outReachID,
		mustRasterize(t, "POLYGON((2.4 53.4, 2.6 53.4, 2.6 53.6, 2.4 53.6, 2.4 53.4))"))
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid IN (?, ?, ?)", srcID, inReachID, outReachID)

	// Authenticated viewer: reach filter applies.
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/840001/similar?jwt="+token, nil), 60000)
	require.Equal(t, 200, resp.StatusCode)
	var results []message.SimilarResult
	json.NewDecoder(resp.Body).Decode(&results)
	got := map[uint64]bool{}
	for _, r := range results {
		got[r.Msgid] = true
	}
	assert.True(t, got[uint64(inReachID)], "candidate with no reach row is kept (fail open)")
	assert.False(t, got[uint64(outReachID)], "candidate outside the viewer's reach is dropped")

	// Anonymous viewer: no viewer location, so the reach filter centres on the POST's
	// location (51.5, -0.1) instead. outReachID's reach doesn't cover that point, so it
	// is dropped here too; inReachID (no reach row) is kept.
	respAnon, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/840001/similar", nil), 60000)
	require.Equal(t, 200, respAnon.StatusCode)
	var anon []message.SimilarResult
	json.NewDecoder(respAnon.Body).Decode(&anon)
	gotAnon := map[uint64]bool{}
	for _, r := range anon {
		gotAnon[r.Msgid] = true
	}
	assert.True(t, gotAnon[uint64(inReachID)], "in-reach candidate kept for anonymous (centred on the post)")
	assert.False(t, gotAnon[uint64(outReachID)], "out-of-reach candidate dropped for anonymous too, centred on the post")
}

// TestSimilarBoxRestrictsToCentre pins the geographic pre-filter: a semantically
// strong candidate far from the centre is excluded by the search box even though it
// clears the score threshold. This is the bug that emptied the strip — the globally-
// most-similar posts all sat in one distant cluster, so after the reach cut nothing
// was left. With no viewer location the box centres on the post itself.
func TestSimilarBoxRestrictsToCentre(t *testing.T) {
	base := makeTestVec(0.5)
	near := makeTestVec(0.5001) // ~1.0 cosine with base — both candidates clear the floor
	const srcID, nearID, farID = 850001, 850002, 850003
	const u1, u2 = uint64(97001), uint64(97002)

	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: srcID, Fromuser: u1, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "Preston drawers", Arrival: time.Now(), SubjectVec: base},
		// Equally similar, but one is local to the post and one is ~500km away.
		{Msgid: nearID, Fromuser: u2, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "local drawers", Arrival: time.Now(), SubjectVec: near},
		{Msgid: farID, Fromuser: u2, Groupid: 100, Msgtype: "Offer", Lat: 55.9, Lng: -3.2, Subject: "distant drawers", Arrival: time.Now(), SubjectVec: near},
	})
	defer embedding.Global.SetEntries(nil)

	// Anonymous → the box centres on the post (51.5, -0.1); the far candidate is
	// outside it and never enters the top-by-score set.
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/850001/similar", nil), 60000)
	require.Equal(t, 200, resp.StatusCode)
	var results []message.SimilarResult
	json.NewDecoder(resp.Body).Decode(&results)

	got := map[uint64]bool{}
	for _, r := range results {
		got[r.Msgid] = true
	}
	assert.True(t, got[uint64(nearID)], "the local strong match is shown")
	assert.False(t, got[uint64(farID)], "the equally-similar but far match is excluded by the box")
}

// TestSimilarDedupesRippledCopies: two candidates that are the same item (same
// author, same subject, different msgid — a rippling copy) collapse to one card,
// while a different author's same-subject item is kept.
func TestSimilarDedupesRippledCopies(t *testing.T) {
	base := makeTestVec(0.5)
	near := makeTestVec(0.5001)
	const srcID, copyA, copyB, other = 860001, 860002, 860003, 860004
	const u1, u2, u3 = uint64(98001), uint64(98002), uint64(98003)

	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: srcID, Fromuser: u1, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "Oak table", Arrival: time.Now(), SubjectVec: base},
		// Same author + same subject on two msgids/groups = one rippled item.
		{Msgid: copyA, Fromuser: u2, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "Pine wardrobe", Arrival: time.Now(), SubjectVec: near},
		{Msgid: copyB, Fromuser: u2, Groupid: 101, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "Pine wardrobe", Arrival: time.Now(), SubjectVec: near},
		// Different author, same subject — a genuinely separate offer, must remain.
		{Msgid: other, Fromuser: u3, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "Pine wardrobe", Arrival: time.Now(), SubjectVec: near},
	})
	defer embedding.Global.SetEntries(nil)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/860001/similar", nil), 60000)
	require.Equal(t, 200, resp.StatusCode)
	var results []message.SimilarResult
	json.NewDecoder(resp.Body).Decode(&results)

	got := map[uint64]bool{}
	for _, r := range results {
		got[r.Msgid] = true
	}
	copies := 0
	if got[uint64(copyA)] {
		copies++
	}
	if got[uint64(copyB)] {
		copies++
	}
	assert.Equal(t, 1, copies, "the two rippled copies collapse to a single card")
	assert.True(t, got[uint64(other)], "a different author's same-subject item is NOT deduped away")
}
