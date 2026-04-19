package test

import (
	"encoding/json"
	"math"
	"net/http"
	"net/http/httptest"
	"strconv"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/embedding"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// makeAntiparallelVec returns a unit vector pointing opposite to makeTestVec,
// so cosine similarity is ~-1 — reliably below MinVectorScore.
func makeAntiparallelVec(seed float32) [embedding.EmbeddingDim]float32 {
	var v [embedding.EmbeddingDim]float32
	var norm float32
	for i := 0; i < embedding.EmbeddingDim; i++ {
		v[i] = -(seed + float32(i)*0.01)
		norm += v[i] * v[i]
	}
	norm = float32(math.Sqrt(float64(norm)))
	for i := 0; i < embedding.EmbeddingDim; i++ {
		v[i] /= norm
	}
	return v
}

func makeTestVec(seed float32) [embedding.EmbeddingDim]float32 {
	var v [embedding.EmbeddingDim]float32
	var norm float32
	for i := 0; i < embedding.EmbeddingDim; i++ {
		v[i] = seed + float32(i)*0.01
		norm += v[i] * v[i]
	}
	norm = float32(math.Sqrt(float64(norm)))
	for i := 0; i < embedding.EmbeddingDim; i++ {
		v[i] /= norm
	}
	return v
}

func mockSidecarReturning(t *testing.T, vec []float32) *httptest.Server {
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		type resp struct {
			Embeddings [][]float32 `json:"embeddings"`
		}
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(resp{Embeddings: [][]float32{vec}})
	}))
}

func TestVectorSearchBasic(t *testing.T) {
	sofaVec := makeTestVec(0.5)
	chairVec := makeTestVec(0.51)
	bikeVec := makeTestVec(5.0)

	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: 1, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "OFFER: Sofa bed", Arrival: time.Now(), SubjectVec: sofaVec},
		{Msgid: 2, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "OFFER: Chair", Arrival: time.Now(), SubjectVec: chairVec},
		{Msgid: 3, Groupid: 200, Msgtype: "Wanted", Lat: 52.0, Lng: 0.0, Subject: "WANTED: Bike", Arrival: time.Now(), SubjectVec: bikeVec},
	})
	defer embedding.Global.SetEntries(nil)

	server := mockSidecarReturning(t, sofaVec[:])
	defer server.Close()
	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

	results, err := message.VectorSearch("sofa", 10, nil, "", 0, 0, 0, 0)
	require.NoError(t, err)
	assert.NotEmpty(t, results)
	assert.Equal(t, uint64(1), results[0].Msgid)
	assert.Equal(t, "Vector", results[0].Matchedon.Type)
	assert.Equal(t, "sofa", results[0].Matchedon.Word)
}

func TestVectorSearchKeywordBoost(t *testing.T) {
	vec := makeTestVec(1.0)
	vecSimilar := makeTestVec(1.001)

	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: 10, Groupid: 100, Msgtype: "Offer", Subject: "OFFER: Table lamp", SubjectVec: vecSimilar},
		{Msgid: 11, Groupid: 100, Msgtype: "Offer", Subject: "OFFER: Sofa bed", SubjectVec: vecSimilar},
	})
	defer embedding.Global.SetEntries(nil)

	server := mockSidecarReturning(t, vec[:])
	defer server.Close()
	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

	results, err := message.VectorSearch("sofa", 10, nil, "", 0, 0, 0, 0)
	require.NoError(t, err)
	require.Len(t, results, 2)
	// Sofa should be boosted to first by keyword match in subject
	assert.Equal(t, uint64(11), results[0].Msgid)
}

func TestVectorSearchWithMsgtypeFilter(t *testing.T) {
	vec := makeTestVec(1.0)

	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: 20, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "OFFER: Sofa", SubjectVec: vec},
		{Msgid: 21, Groupid: 200, Msgtype: "Wanted", Lat: 52.0, Lng: 0.0, Subject: "WANTED: Sofa", SubjectVec: vec},
	})
	defer embedding.Global.SetEntries(nil)

	server := mockSidecarReturning(t, vec[:])
	defer server.Close()
	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

	results, err := message.VectorSearch("sofa", 10, nil, "Offer", 0, 0, 0, 0)
	require.NoError(t, err)
	assert.Len(t, results, 1)
	assert.Equal(t, uint64(20), results[0].Msgid)
}

func TestVectorSearchWithGroupFilter(t *testing.T) {
	vec := makeTestVec(1.0)

	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: 30, Groupid: 100, Msgtype: "Offer", Subject: "OFFER: Sofa", SubjectVec: vec},
		{Msgid: 31, Groupid: 200, Msgtype: "Offer", Subject: "OFFER: Sofa", SubjectVec: vec},
	})
	defer embedding.Global.SetEntries(nil)

	server := mockSidecarReturning(t, vec[:])
	defer server.Close()
	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

	results, err := message.VectorSearch("sofa", 10, []uint64{200}, "", 0, 0, 0, 0)
	require.NoError(t, err)
	assert.Len(t, results, 1)
	assert.Equal(t, uint64(31), results[0].Msgid)
}

func TestVectorSearchLimit(t *testing.T) {
	vec := makeTestVec(1.0)
	entries := make([]embedding.Entry, 10)
	for i := range entries {
		entries[i] = embedding.Entry{
			Msgid: uint64(i + 1), Groupid: 100, Msgtype: "Offer",
			Subject: "OFFER: Item", SubjectVec: vec,
		}
	}
	embedding.Global.SetEntries(entries)
	defer embedding.Global.SetEntries(nil)

	server := mockSidecarReturning(t, vec[:])
	defer server.Close()
	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

	results, err := message.VectorSearch("item", 3, nil, "", 0, 0, 0, 0)
	require.NoError(t, err)
	assert.Len(t, results, 3)
}

func TestVectorSearchSidecarError(t *testing.T) {
	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: 1, Groupid: 100, Msgtype: "Offer", Subject: "test", SubjectVec: makeTestVec(1.0)},
	})
	defer embedding.Global.SetEntries(nil)

	// Start and immediately close a server to get a guaranteed-refused port
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {}))
	url := server.URL
	server.Close()

	embedding.SetSidecarURL(url)
	defer embedding.SetSidecarURL("")

	_, err := message.VectorSearch("sofa", 10, nil, "", 0, 0, 0, 0)
	assert.Error(t, err)
}

func TestEmbedBatch(t *testing.T) {
	vec1 := makeTestVec(1.0)
	vec2 := makeTestVec(2.0)

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		type resp struct {
			Embeddings [][]float32 `json:"embeddings"`
		}
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(resp{Embeddings: [][]float32{vec1[:], vec2[:]}})
	}))
	defer server.Close()
	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

	results, err := embedding.EmbedBatch([]string{"chair", "table"})
	require.NoError(t, err)
	require.Len(t, results, 2)
	assert.Equal(t, vec1[0], results[0][0])
	assert.Equal(t, vec2[0], results[1][0])
}

func TestEmbedBatchEmpty(t *testing.T) {
	results, err := embedding.EmbedBatch([]string{})
	require.NoError(t, err)
	assert.Nil(t, results)
}

func TestStoreSetEntriesAndCount(t *testing.T) {
	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: 1}, {Msgid: 2}, {Msgid: 3},
	})
	assert.Equal(t, 3, embedding.Global.Count())

	embedding.Global.SetEntries(nil)
	assert.Equal(t, 0, embedding.Global.Count())
}

// TestSearchHandlerVectorModeDoesNotFallBackToKeyword reproduces the
// non-determinism Dee reported on Discourse 9594: the same query returning
// wildly different result sets on repeat attempts (flat screens → wood/floor
// paint → flat screens again). Root cause: when searchmode=vector returned
// zero matches above MinVectorScore, the handler silently fell back to the
// keyword index, which has a completely different match model. Flaky network
// conditions flipping vector between success and timeout produced the
// observed mode-switching.
//
// After the fix, an explicit searchmode=vector request respects the vector
// result set (even if empty) instead of secretly switching to keyword.
func TestSearchHandlerVectorModeDoesNotFallBackToKeyword(t *testing.T) {
	prefix := uniquePrefix("vectornofallback")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, userID, groupID, "Member")

	// Create a message whose indexed words WOULD match a keyword search for
	// "television" (via exact + prefix word matches on the search index).
	CreateTestMessage(t, userID, groupID, "television stand oak", 55.9533, -3.1883)

	// Confirm the keyword path actually finds this message — otherwise the
	// test below would pass trivially and wouldn't prove we avoided fallback.
	keywordResp, _ := getApp().Test(httptest.NewRequest(
		"GET",
		"/api/message/search/television?searchmode=keyword&groupids="+strconv.FormatUint(groupID, 10),
		nil,
	), 60000)
	require.Equal(t, 200, keywordResp.StatusCode)
	var keywordResults []message.SearchResult
	json.NewDecoder(keywordResp.Body).Decode(&keywordResults)
	require.NotEmpty(t, keywordResults, "sanity check: keyword search must find the seeded message")

	// Set up the embedding store with ONE entry whose vector points in the
	// opposite direction to the query — cosine ≈ -1, far below MinVectorScore
	// of 0.65. Count() > 0 so the handler enters the vector branch; the
	// filtered vector result is legitimately empty.
	queryVec := makeTestVec(1.0)
	antiparallel := makeAntiparallelVec(1.0)
	embedding.Global.SetEntries([]embedding.Entry{
		{
			Msgid: 999999, Groupid: groupID, Msgtype: "Offer",
			Lat: 55.9533, Lng: -3.1883,
			Subject: "totally unrelated noise", Arrival: time.Now(),
			SubjectVec: antiparallel,
		},
	})
	defer embedding.Global.SetEntries(nil)

	server := mockSidecarReturning(t, queryVec[:])
	defer server.Close()
	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

	// Now the same query with searchmode=vector. Vector legitimately returns
	// nothing above threshold. The handler must NOT silently fall back to the
	// keyword index (which would surface "television stand oak").
	vectorResp, _ := getApp().Test(httptest.NewRequest(
		"GET",
		"/api/message/search/television?searchmode=vector&groupids="+strconv.FormatUint(groupID, 10),
		nil,
	), 60000)
	require.Equal(t, 200, vectorResp.StatusCode)

	var vectorResults []message.SearchResult
	json.NewDecoder(vectorResp.Body).Decode(&vectorResults)

	assert.Empty(t, vectorResults,
		"searchmode=vector with no matches above threshold must return empty, not fall back to keyword")

	// No result should carry a keyword matchedon.Type — fail clearly if the
	// fallback leaks through.
	for _, r := range vectorResults {
		assert.Equal(t, "Vector", r.Matchedon.Type,
			"non-Vector matchedon.Type (%q) for msgid=%d indicates keyword fallback", r.Matchedon.Type, r.Msgid)
	}
}

// TestSearchHandlerVectorModeIsDeterministic confirms the same vector query
// returns the same result set on repeat calls — directly addresses Dee's
// "very puzzling" report that identical queries produced different results
// (Discourse 9594).
func TestSearchHandlerVectorModeIsDeterministic(t *testing.T) {
	prefix := uniquePrefix("vectordeterministic")
	groupID := CreateTestGroup(t, prefix)

	// Two entries: one strong match, one antiparallel (noise).
	queryVec := makeTestVec(1.0)
	strongMatch := makeTestVec(1.001)
	embedding.Global.SetEntries([]embedding.Entry{
		{
			Msgid: 11111, Groupid: groupID, Msgtype: "Offer",
			Lat: 55.9533, Lng: -3.1883,
			Subject: "television", Arrival: time.Now(), SubjectVec: strongMatch,
		},
		{
			Msgid: 22222, Groupid: groupID, Msgtype: "Offer",
			Lat: 55.9533, Lng: -3.1883,
			Subject: "unrelated", Arrival: time.Now(),
			SubjectVec: makeAntiparallelVec(1.0),
		},
	})
	defer embedding.Global.SetEntries(nil)

	server := mockSidecarReturning(t, queryVec[:])
	defer server.Close()
	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

	url := "/api/message/search/television?searchmode=vector&groupids=" + strconv.FormatUint(groupID, 10)

	runOnce := func() []uint64 {
		resp, _ := getApp().Test(httptest.NewRequest("GET", url, nil), 60000)
		require.Equal(t, 200, resp.StatusCode)
		var results []message.SearchResult
		json.NewDecoder(resp.Body).Decode(&results)
		ids := make([]uint64, len(results))
		for i, r := range results {
			ids[i] = r.Msgid
		}
		return ids
	}

	first := runOnce()
	for i := 0; i < 3; i++ {
		assert.Equal(t, first, runOnce(),
			"repeat #%d returned different ids — vector search must be deterministic for identical inputs", i+1)
	}

	// Sanity: the strong match should be in the result set; the antiparallel
	// noise must be filtered by MinVectorScore.
	assert.Contains(t, first, uint64(11111))
	assert.NotContains(t, first, uint64(22222))
}
