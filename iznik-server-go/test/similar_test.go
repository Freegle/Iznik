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
// candidate with no reach row is kept (fail-open). An anonymous request gets no
// reach filtering.
func TestSimilarReachFiltered(t *testing.T) {
	t.Setenv("RIPPLE_ENABLED", "true")
	db := database.DBConn
	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
		polygon GEOMETRY NOT NULL SRID 3857,
		status VARCHAR(16) NOT NULL DEFAULT 'expanding',
		SPATIAL INDEX msgreach_poly (polygon)
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
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, status) VALUES (?, 51.5, -0.1, "+
		"ST_GeomFromText('POLYGON((2.4 53.4, 2.6 53.4, 2.6 53.6, 2.4 53.6, 2.4 53.4))', 3857), 'expanding')", outReachID)
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

	// Anonymous viewer: no location, no reach filtering — out-of-reach appears.
	respAnon, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/840001/similar", nil), 60000)
	require.Equal(t, 200, respAnon.StatusCode)
	var anon []message.SimilarResult
	json.NewDecoder(respAnon.Body).Decode(&anon)
	gotAnon := map[uint64]bool{}
	for _, r := range anon {
		gotAnon[r.Msgid] = true
	}
	assert.True(t, gotAnon[uint64(outReachID)], "anonymous viewer gets no reach filtering")
}
