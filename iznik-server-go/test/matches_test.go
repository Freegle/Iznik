package test

import (
	"encoding/json"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/embedding"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// matchesReq issues a GET /message/matches with a mocked sidecar returning
// queryVec, and decodes the result.
func matchesReq(t *testing.T, url string, queryVec [embedding.EmbeddingDim]float32) []message.SimilarResult {
	embedding.ResetQueryCache()
	t.Cleanup(embedding.ResetQueryCache)
	server := mockSidecarReturning(t, queryVec[:])
	defer server.Close()
	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

	resp, _ := getApp().Test(httptest.NewRequest("GET", url, nil), 60000)
	require.Equal(t, 200, resp.StatusCode)
	var results []message.SimilarResult
	json.NewDecoder(resp.Body).Decode(&results)
	return results
}

func TestMatchesReturnsNearbyOffers(t *testing.T) {
	queryVec := makeTestVec(0.5)
	near := makeTestVec(0.5001) // cosine ~1, above MinVectorScore
	const offerID, wantedID, farID = 850001, 850002, 850003
	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: offerID, Fromuser: 60001, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "Sofa", Arrival: time.Now(), SubjectVec: near},
		{Msgid: wantedID, Fromuser: 60002, Groupid: 100, Msgtype: "Wanted", Lat: 51.5, Lng: -0.1, Subject: "Want sofa", Arrival: time.Now(), SubjectVec: near},
		{Msgid: farID, Fromuser: 60003, Groupid: 100, Msgtype: "Offer", Lat: 53.0, Lng: 2.0, Subject: "Far sofa", Arrival: time.Now(), SubjectVec: near},
	})
	defer embedding.Global.SetEntries(nil)

	results := matchesReq(t, "/api/message/matches?query=sofa&lat=51.5&lng=-0.1", queryVec)
	ids := map[uint64]bool{}
	for _, r := range results {
		ids[r.Msgid] = true
	}
	assert.True(t, ids[uint64(offerID)], "nearby matching offer is returned")
	assert.False(t, ids[uint64(wantedID)], "a Wanted is excluded (offers only)")
	assert.False(t, ids[uint64(farID)], "an offer outside the ~15km box is excluded")
}

func TestMatchesFlagOff(t *testing.T) {
	t.Setenv("FEATURE_WANTED_MATCH", "off")
	queryVec := makeTestVec(0.5)
	near := makeTestVec(0.5001)
	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: 851001, Fromuser: 60001, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "Sofa", Arrival: time.Now(), SubjectVec: near},
	})
	defer embedding.Global.SetEntries(nil)

	results := matchesReq(t, "/api/message/matches?query=sofa&lat=51.5&lng=-0.1", queryVec)
	assert.Empty(t, results, "flag off → empty")
}

func TestMatchesNoLocation(t *testing.T) {
	queryVec := makeTestVec(0.5)
	near := makeTestVec(0.5001)
	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: 852001, Fromuser: 60001, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "Sofa", Arrival: time.Now(), SubjectVec: near},
	})
	defer embedding.Global.SetEntries(nil)

	results := matchesReq(t, "/api/message/matches?query=sofa", queryVec)
	assert.Empty(t, results, "no location → empty (can't scope or reach-filter)")
}

func TestMatchesExcludesOwnPosts(t *testing.T) {
	prefix := uniquePrefix("matchesown")
	poster := CreateTestUser(t, prefix, "Member")
	token := getToken(t, poster)

	queryVec := makeTestVec(0.5)
	near := makeTestVec(0.5001)
	const ownID, otherID = 853001, 853002
	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: ownID, Fromuser: poster, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "My sofa", Arrival: time.Now(), SubjectVec: near},
		{Msgid: otherID, Fromuser: 60002, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "Their sofa", Arrival: time.Now(), SubjectVec: near},
	})
	defer embedding.Global.SetEntries(nil)

	results := matchesReq(t, "/api/message/matches?query=sofa&lat=51.5&lng=-0.1&jwt="+token, queryVec)
	ids := map[uint64]bool{}
	for _, r := range results {
		ids[r.Msgid] = true
	}
	assert.False(t, ids[uint64(ownID)], "caller's own offer is excluded")
	assert.True(t, ids[uint64(otherID)], "another user's matching offer is returned")
}

func TestMatchesReachFilter(t *testing.T) {
	t.Setenv("RIPPLE_ENABLED", "true")
	db := database.DBConn
	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
		polygon_cells MEDIUMBLOB NULL,
		outer_bound GEOMETRY NULL,
		status VARCHAR(16) NOT NULL DEFAULT 'expanding'
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	prefix := uniquePrefix("matchesreach")
	groupID := CreateTestGroup(t, prefix)
	posterOut := CreateTestUser(t, prefix+"po", "Member")
	// Out-of-reach candidate needs a real message (rippling_reach FK to messages).
	outID := CreateTestMessage(t, posterOut, groupID, "out of reach sofa", 51.5, -0.1)

	queryVec := makeTestVec(0.5)
	near := makeTestVec(0.5001)
	const inID = 854001
	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: inID, Fromuser: 60002, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "in reach sofa", Arrival: time.Now(), SubjectVec: near},
		{Msgid: outID, Fromuser: posterOut, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "out of reach sofa", Arrival: time.Now(), SubjectVec: near},
	})
	defer embedding.Global.SetEntries(nil)

	db.Exec("DELETE FROM rippling_reach WHERE msgid IN (?, ?)", inID, outID)
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound, status) VALUES (?, 51.5, -0.1, ?, "+
		"ST_Envelope(ST_GeomFromText('POLYGON((2.4 53.4, 2.6 53.4, 2.6 53.6, 2.4 53.6, 2.4 53.4))', 3857)), 'expanding')", outID,
		mustRasterize(t, "POLYGON((2.4 53.4, 2.6 53.4, 2.6 53.6, 2.4 53.6, 2.4 53.4))"))
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid IN (?, ?)", inID, outID)

	// The poster's location (51.5,-0.1) is outside outID's reach polygon → outID blocked.
	results := matchesReq(t, "/api/message/matches?query=sofa&lat=51.5&lng=-0.1", queryVec)
	ids := map[uint64]bool{}
	for _, r := range results {
		ids[r.Msgid] = true
	}
	assert.True(t, ids[uint64(inID)], "offer with no reach row is kept (fail open)")
	assert.False(t, ids[uint64(outID)], "offer outside the poster's reach is dropped")
}
