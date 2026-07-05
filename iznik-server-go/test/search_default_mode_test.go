package test

import (
	"encoding/json"
	"net/http/httptest"
	"strconv"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/embedding"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// TestSearchHandlerDefaultsToVector verifies that when a caller supplies NO
// searchmode param, the handler runs the vector-hybrid path (the new default).
// The store is seeded with an entry that is a STRONG vector match to the query
// but whose subject shares no words with the query term — so only vector search
// can surface it; the keyword cascade cannot. Its presence proves vector ran by
// default.
func TestSearchHandlerDefaultsToVector(t *testing.T) {
	embedding.ResetQueryCache()
	t.Cleanup(embedding.ResetQueryCache)

	prefix := uniquePrefix("vectordefault")
	groupID := CreateTestGroup(t, prefix)

	queryVec := makeTestVec(2.0)
	strongMatch := makeTestVec(2.001)
	const vectorOnlyMsgid = uint64(770001)
	embedding.Global.SetEntries([]embedding.Entry{
		{
			Msgid: vectorOnlyMsgid, Groupid: groupID, Msgtype: "Offer",
			Lat: 55.9533, Lng: -3.1883,
			Subject: "zzz nomatch subject", Arrival: time.Now(),
			SubjectVec: strongMatch,
		},
	})
	defer embedding.Global.SetEntries(nil)

	server := mockSidecarReturning(t, queryVec[:])
	defer server.Close()
	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

	// Query term "sofa" is absent from the entry's subject, so the keyword leg
	// cannot match it. No searchmode param → must default to vector.
	url := "/api/message/search/sofa?groupids=" + strconv.FormatUint(groupID, 10)
	resp, _ := getApp().Test(httptest.NewRequest("GET", url, nil), 60000)
	require.Equal(t, 200, resp.StatusCode)

	var results []message.SearchResult
	json.NewDecoder(resp.Body).Decode(&results)

	var match *message.SearchResult
	for i := range results {
		if results[i].Msgid == vectorOnlyMsgid {
			match = &results[i]
		}
	}
	require.NotNil(t, match,
		"with no searchmode param the handler must default to vector search and surface the vector-only match")

	// Response-shape parity: the vector-default path must populate every field
	// the public site's search consumer (PostMap.vue) reads — id, groupid, lat,
	// lng, type — plus the Vector attribution. If any of these regress, the
	// frontend can't place or render the result.
	assert.Equal(t, groupID, match.Groupid, "groupid must be populated")
	assert.Equal(t, "Offer", match.Type, "type must be populated")
	// lat/lng are privacy-blurred (posts' exact coordinates are fuzzed), so we
	// assert the seeded location within the blur radius rather than exactly —
	// enough to prove the fields are populated and correctly sourced (not 0/swapped).
	assert.InDelta(t, 55.9533, match.Lat, 0.05, "lat must be populated near the seeded location")
	assert.InDelta(t, -3.1883, match.Lng, 0.05, "lng must be populated near the seeded location")
	assert.Equal(t, "Vector", match.Matchedon.Type, "vector hits must be attributed as matchedon=Vector")
}

// TestSearchDefaultModeEnvOverrideToKeyword verifies the VECTOR_SEARCH_DEFAULT
// env killswitch: set to "keyword", a caller supplying no searchmode gets the
// keyword cascade, which cannot find the vector-only entry. This is the no-deploy
// rollback lever for the vector-default flip.
func TestSearchDefaultModeEnvOverrideToKeyword(t *testing.T) {
	t.Setenv("VECTOR_SEARCH_DEFAULT", "keyword")
	embedding.ResetQueryCache()
	t.Cleanup(embedding.ResetQueryCache)

	prefix := uniquePrefix("vectordefaultoff")
	groupID := CreateTestGroup(t, prefix)

	queryVec := makeTestVec(2.0)
	strongMatch := makeTestVec(2.001)
	const vectorOnlyMsgid = uint64(770002)
	embedding.Global.SetEntries([]embedding.Entry{
		{
			Msgid: vectorOnlyMsgid, Groupid: groupID, Msgtype: "Offer",
			Lat: 55.9533, Lng: -3.1883,
			Subject: "zzz nomatch subject", Arrival: time.Now(),
			SubjectVec: strongMatch,
		},
	})
	defer embedding.Global.SetEntries(nil)

	server := mockSidecarReturning(t, queryVec[:])
	defer server.Close()
	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

	url := "/api/message/search/sofa?groupids=" + strconv.FormatUint(groupID, 10)
	resp, _ := getApp().Test(httptest.NewRequest("GET", url, nil), 60000)
	require.Equal(t, 200, resp.StatusCode)

	var results []message.SearchResult
	json.NewDecoder(resp.Body).Decode(&results)

	for _, r := range results {
		assert.NotEqual(t, uint64(vectorOnlyMsgid), r.Msgid,
			"with VECTOR_SEARCH_DEFAULT=keyword the vector-only entry must not appear")
	}
}
