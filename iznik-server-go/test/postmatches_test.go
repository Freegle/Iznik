package test

import (
	"encoding/json"
	"math"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/embedding"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// makeVecWithCosine returns a unit vector whose cosine with base is approximately
// target, by mixing base with a vector orthogonal to it. The orthogonal component
// is built by rotating adjacent pairs of base's components (a, b) -> (b, -a),
// which is orthogonal to base because each pair contributes ab - ba = 0.
// Lets a test pin behaviour at a specific similarity rather than just "parallel"
// or "antiparallel".
func makeVecWithCosine(base [embedding.EmbeddingDim]float32, target float64) [embedding.EmbeddingDim]float32 {
	var orth [embedding.EmbeddingDim]float32
	for i := 0; i+1 < embedding.EmbeddingDim; i += 2 {
		orth[i] = base[i+1]
		orth[i+1] = -base[i]
	}
	a := float32(target)
	b := float32(math.Sqrt(1 - target*target))

	var v [embedding.EmbeddingDim]float32
	var norm float32
	for i := 0; i < embedding.EmbeddingDim; i++ {
		v[i] = a*base[i] + b*orth[i]
		norm += v[i] * v[i]
	}
	norm = float32(math.Sqrt(float64(norm)))
	for i := 0; i < embedding.EmbeddingDim; i++ {
		v[i] /= norm
	}
	return v
}

// TestPostMatchesThresholdExcludesMidBand pins the matched-posts floor at
// MinMatchedPostScore rather than the similar-posts MinSimilarScore (0.60).
//
// The band between them is exactly where this feature used to hurt: on 150
// hand-judged live samples, candidates scoring 0.75-0.85 were relevant only ~36%
// of the time, so a 0.60 floor put an irrelevant item in roughly half the emails.
// A 0.78-similarity candidate clears the old floor comfortably and must still be
// dropped here; without this test, reverting to MinSimilarScore stays green.
func TestPostMatchesThresholdExcludesMidBand(t *testing.T) {
	base := makeTestVec(0.5)
	const srcID, midID, strongID = 852001, 852002, 852003
	const u1, u2 = uint64(94001), uint64(94002)

	mid := makeVecWithCosine(base, 0.78)    // above old 0.60, below new 0.85
	strong := makeVecWithCosine(base, 0.95) // clears the new floor

	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: srcID, Fromuser: u1, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "Bed lever", Arrival: time.Now(), SubjectVec: base},
		{Msgid: midID, Fromuser: u2, Groupid: 100, Msgtype: "Wanted", Lat: 51.5, Lng: -0.1, Subject: "Bed", Arrival: time.Now(), SubjectVec: mid},
		{Msgid: strongID, Fromuser: u2, Groupid: 100, Msgtype: "Wanted", Lat: 51.5, Lng: -0.1, Subject: "Bed lever wanted", Arrival: time.Now(), SubjectVec: strong},
	})
	defer embedding.Global.SetEntries(nil)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/852001/matches", nil), 60000)
	require.Equal(t, 200, resp.StatusCode)

	var results []message.SimilarResult
	json.NewDecoder(resp.Body).Decode(&results)

	got := map[uint64]bool{}
	for _, r := range results {
		got[r.Msgid] = true
		assert.GreaterOrEqual(t, r.Score, float32(message.MinMatchedPostScore))
	}
	assert.True(t, got[uint64(strongID)], "0.95 similarity is emailed")
	assert.False(t, got[uint64(midID)], "0.78 similarity clears the old 0.60 floor but must not be emailed")
}

// TestPostMatchesReturnsOppositeType: given a source Offer, /message/:id/matches
// returns only open OPPOSITE-type (Wanted) posts, above MinMatchedPostScore, by a
// DIFFERENT author. Same-type, weak, and same-author candidates are all dropped.
func TestPostMatchesReturnsOppositeType(t *testing.T) {
	base := makeTestVec(0.5)
	near := makeTestVec(0.5001) // cosine ~1 with base
	const srcID, wantedID, offerID, weakID, sameAuthorID = 850001, 850002, 850003, 850004, 850005
	const u1, u2 = uint64(92001), uint64(92002)

	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: srcID, Fromuser: u1, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "Blue sofa", Arrival: time.Now(), SubjectVec: base},
		{Msgid: wantedID, Fromuser: u2, Groupid: 100, Msgtype: "Wanted", Lat: 51.5, Lng: -0.1, Subject: "Want a sofa", Arrival: time.Now(), SubjectVec: near},
		{Msgid: offerID, Fromuser: u2, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "Grey sofa", Arrival: time.Now(), SubjectVec: near},
		{Msgid: weakID, Fromuser: u2, Groupid: 100, Msgtype: "Wanted", Lat: 51.5, Lng: -0.1, Subject: "unrelated noise", Arrival: time.Now(), SubjectVec: makeAntiparallelVec(0.5)},
		{Msgid: sameAuthorID, Fromuser: u1, Groupid: 100, Msgtype: "Wanted", Lat: 51.5, Lng: -0.1, Subject: "Want another sofa", Arrival: time.Now(), SubjectVec: near},
	})
	defer embedding.Global.SetEntries(nil)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/850001/matches", nil), 60000)
	require.Equal(t, 200, resp.StatusCode)

	var results []message.SimilarResult
	json.NewDecoder(resp.Body).Decode(&results)

	require.Len(t, results, 1, "only the opposite-type, above-threshold, different-author match qualifies")
	assert.Equal(t, uint64(wantedID), results[0].Msgid)
	assert.GreaterOrEqual(t, results[0].Score, float32(message.MinMatchedPostScore))
	assert.NotZero(t, results[0].Lat, "lat populated (blurred)")
	assert.NotZero(t, results[0].Lng, "lng populated (blurred)")
	assert.Equal(t, uint64(100), results[0].Groupid)
}

// TestPostMatchesFlagOff: FEATURE_MATCHED_POSTS=off short-circuits to empty.
func TestPostMatchesFlagOff(t *testing.T) {
	t.Setenv("FEATURE_MATCHED_POSTS", "off")
	base := makeTestVec(0.5)
	near := makeTestVec(0.5001)
	const srcID, wantedID = 851001, 851002
	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: srcID, Fromuser: 1, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "x", Arrival: time.Now(), SubjectVec: base},
		{Msgid: wantedID, Fromuser: 2, Groupid: 100, Msgtype: "Wanted", Lat: 51.5, Lng: -0.1, Subject: "y", Arrival: time.Now(), SubjectVec: near},
	})
	defer embedding.Global.SetEntries(nil)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/851001/matches", nil), 60000)
	require.Equal(t, 200, resp.StatusCode)
	var results []message.SimilarResult
	json.NewDecoder(resp.Body).Decode(&results)
	assert.Empty(t, results, "flag off → empty regardless of matches")
}

// TestPostMatchesNoEmbedding: a source id with no embedding row returns 200 +
// empty, never a 500.
func TestPostMatchesNoEmbedding(t *testing.T) {
	embedding.Global.SetEntries(nil)
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/999999998/matches", nil), 60000)
	require.Equal(t, 200, resp.StatusCode)
	var results []message.SimilarResult
	json.NewDecoder(resp.Body).Decode(&results)
	assert.Empty(t, results)
}

// TestPostMatchesReachFilteredForOwner: reach is keyed on the POST's location,
// not the caller's — so an unauthenticated (batch) request still drops a
// candidate outside the post owner's reach, while a candidate with no reach row
// is kept (fail-open).
func TestPostMatchesReachFilteredForOwner(t *testing.T) {
	t.Setenv("RIPPLE_ENABLED", "true")
	db := database.DBConn
	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
		polygon GEOMETRY NOT NULL SRID 3857,
		outer_bound GEOMETRY NULL SRID 3857,
		status VARCHAR(16) NOT NULL DEFAULT 'expanding',
		SPATIAL INDEX msgreach_poly (polygon)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	prefix := uniquePrefix("postmatchesreach")
	groupID := CreateTestGroup(t, prefix)
	posterOut := CreateTestUser(t, prefix+"po", "Member")
	// Out-of-reach candidate needs a real message row (rippling_reach FK).
	outReachID := CreateTestMessage(t, posterOut, groupID, "out of reach wanted", 51.5, -0.1)

	base := makeTestVec(0.5)
	near := makeTestVec(0.5001)
	const srcID, inReachID = 860001, 860002
	const owner, other = uint64(93001), uint64(93002)
	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: srcID, Fromuser: owner, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "src sofa", Arrival: time.Now(), SubjectVec: base},
		{Msgid: inReachID, Fromuser: other, Groupid: 100, Msgtype: "Wanted", Lat: 51.5, Lng: -0.1, Subject: "in reach want", Arrival: time.Now(), SubjectVec: near},
		{Msgid: outReachID, Fromuser: posterOut, Groupid: 100, Msgtype: "Wanted", Lat: 51.5, Lng: -0.1, Subject: "out of reach want", Arrival: time.Now(), SubjectVec: near},
	})
	defer embedding.Global.SetEntries(nil)

	db.Exec("DELETE FROM rippling_reach WHERE msgid IN (?, ?)", inReachID, outReachID)
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, status) VALUES (?, 51.5, -0.1, "+
		"ST_GeomFromText('POLYGON((2.4 53.4, 2.6 53.4, 2.6 53.6, 2.4 53.6, 2.4 53.4))', 3857), "+
		"ST_Envelope(ST_GeomFromText('POLYGON((2.4 53.4, 2.6 53.4, 2.6 53.6, 2.4 53.6, 2.4 53.4))', 3857)), 'expanding')", outReachID)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid IN (?, ?)", inReachID, outReachID)

	// Unauthenticated (batch) request — reach still applies, keyed on post location.
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/860001/matches", nil), 60000)
	require.Equal(t, 200, resp.StatusCode)
	var results []message.SimilarResult
	json.NewDecoder(resp.Body).Decode(&results)
	got := map[uint64]bool{}
	for _, r := range results {
		got[r.Msgid] = true
	}
	assert.True(t, got[uint64(inReachID)], "candidate with no reach row is kept (fail open)")
	assert.False(t, got[uint64(outReachID)], "candidate outside the post owner's reach is dropped, even unauthenticated")
}
