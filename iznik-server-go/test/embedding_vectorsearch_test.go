package test

import (
	"encoding/json"
	"math"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/embedding"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

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

// unitVecWithCosine returns a normalised 256-dim vector whose dot product
// with the reference query vector [1, 0, 0, …] equals exactly cos. Used for
// threshold-boundary tests where we need deterministic, targeted cosines.
func unitVecWithCosine(cos float32) [embedding.EmbeddingDim]float32 {
	var v [embedding.EmbeddingDim]float32
	v[0] = cos
	v[1] = float32(math.Sqrt(float64(1.0 - cos*cos)))
	return v
}

// TestVectorSearchWhiteGoodsRegression reproduces Discourse 9585 post 18
// (Jos): "'White goods' now finding one older (19 days) item but not 4
// recent ones (ringed, all still live)."
//
// Cosines below were measured against the live embedding sidecar
// (nomic-embed-text-v1.5 256-dim Matryoshka, quantized, asymmetric
// search_query:/search_document: prefixes) on the exact preprocessed
// subjects from Jos's screenshot:
//
//	white goods vs "Whirlpool free standing fridge freezer" = 0.47
//	white goods vs "Washing machine"                         = 0.43
//	white goods vs "Dishwasher"                              = 0.40
//	white goods vs "fridge"                                  = 0.45
//	white goods vs "white goods" (literal)                   = 0.80
//	unrelated ("Yoga mat", "Bike helmet", "Lego set")        = 0.35–0.39
//
// At the previous 0.65 floor every tangentially-related item was
// silently dropped — the reporter saw a single 19-day-old post and
// nothing else. 0.45 restores the clearly-related cluster (fridge, 0.45;
// Whirlpool fridge freezer, 0.47 — plus the literal "white goods"
// match at 0.80) alongside the literal match, while leaving the clear
// noise baseline below the floor. Washing machine (0.43) and Dishwasher
// (0.40) remain below the new floor — lowering further admits
// indistinguishable noise (Garden pot 0.42, Vinyl records 0.46); those
// items simply don't share enough signal with "white goods" in a 256-dim
// embedding for any threshold-based filter to recover safely.
func TestVectorSearchWhiteGoodsRegression(t *testing.T) {
	queryVec := unitVecWithCosine(1.0) // reference [1, 0, 0, …]

	// Exact measured cosines from the live sidecar, subjects as
	// preprocessed by iznik-batch's EmbeddingService::preprocessSubject.
	whirlpool := unitVecWithCosine(0.47)
	washer := unitVecWithCosine(0.43)
	dishwasher := unitVecWithCosine(0.40)
	fridge := unitVecWithCosine(0.45)
	literal := unitVecWithCosine(0.80)
	// Clear noise baselines — stay below 0.45 at either threshold.
	yogaMat := unitVecWithCosine(0.39)
	bikeHelmet := unitVecWithCosine(0.38)

	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: 1001, Groupid: 1, Msgtype: "Offer", Subject: "OFFER: Whirlpool free standing fridge freezer (Kensington W14)", SubjectVec: whirlpool},
		{Msgid: 1002, Groupid: 1, Msgtype: "Wanted", Subject: "WANTED: Washing machine (Holland Park W14)", SubjectVec: washer},
		{Msgid: 1003, Groupid: 1, Msgtype: "Wanted", Subject: "WANTED: Dishwasher (Holland Park W14)", SubjectVec: dishwasher},
		{Msgid: 1004, Groupid: 1, Msgtype: "Offer", Subject: "OFFER: fridge (North Kensington W10)", SubjectVec: fridge},
		{Msgid: 1005, Groupid: 1, Msgtype: "Offer", Subject: "OFFER: white goods (Anytown)", SubjectVec: literal},
		{Msgid: 9001, Groupid: 1, Msgtype: "Offer", Subject: "OFFER: Yoga mat", SubjectVec: yogaMat},
		{Msgid: 9002, Groupid: 1, Msgtype: "Offer", Subject: "OFFER: Bike helmet", SubjectVec: bikeHelmet},
	})
	defer embedding.Global.SetEntries(nil)

	server := mockSidecarReturning(t, queryVec[:])
	defer server.Close()
	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

	results, err := message.VectorSearch("white goods", 20, nil, "", 0, 0, 0, 0)
	require.NoError(t, err)

	found := map[uint64]bool{}
	for _, r := range results {
		found[r.Msgid] = true
	}

	// Core regression: the old 0.65 floor returned ONE result for "white
	// goods" on Jos's group; at 0.45 the recent clearly-related posts
	// (cosines ≥ 0.45) must all surface alongside the literal match.
	assert.True(t, found[1001], "Whirlpool fridge freezer (cos 0.47) must be returned — dropped at 0.65, recovered at 0.45")
	assert.True(t, found[1004], "OFFER: fridge (cos 0.45) must be returned — dropped at 0.65, recovered at 0.45")
	assert.True(t, found[1005], "literal 'white goods' item (cos 0.80) must be returned")

	// Clear noise baselines must NOT leak in — the point of lowering is
	// to capture signal, not to empty the filter.
	assert.False(t, found[9001], "Yoga mat (cos 0.39) must stay filtered")
	assert.False(t, found[9002], "Bike helmet (cos 0.38) must stay filtered")

	// The literal phrase match should rank first — keyword boost +
	// highest subject cosine. Protects against future regressions where
	// noise reorders the strong signal.
	require.NotEmpty(t, results)
	assert.Equal(t, uint64(1005), results[0].Msgid, "literal 'white goods' match should rank first")

	// Before the fix, results for "white goods" on the test data would
	// contain only msgid 1005 (cos 0.80); after the fix we expect the
	// strong-cluster items above.
	assert.GreaterOrEqualf(t, len(results), 3,
		"expected the literal match plus at least 2 clearly-related recent posts to surface — got %d. Before the fix this returned 1 (literal only).",
		len(results))
}

// TestVectorSearchSingularQueryReturnsLiteralMatch reproduces observable (B)
// of Discourse 9585.18: "'white good' didn't find anything." Singular form
// scores ~0.64 against the literal "white goods" document — below the old
// 0.65 cutoff, above the new 0.45 one. The test guards against a future
// threshold raise from silently re-breaking singular/plural queries.
func TestVectorSearchSingularQueryReturnsLiteralMatch(t *testing.T) {
	queryVec := unitVecWithCosine(1.0)
	literal := unitVecWithCosine(0.64) // measured white good vs white goods
	noise := unitVecWithCosine(0.38)

	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: 2001, Groupid: 1, Msgtype: "Offer", Subject: "OFFER: white goods (Anytown)", SubjectVec: literal},
		{Msgid: 9003, Groupid: 1, Msgtype: "Offer", Subject: "OFFER: Lego set", SubjectVec: noise},
	})
	defer embedding.Global.SetEntries(nil)

	server := mockSidecarReturning(t, queryVec[:])
	defer server.Close()
	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

	results, err := message.VectorSearch("white good", 10, nil, "", 0, 0, 0, 0)
	require.NoError(t, err)
	require.NotEmpty(t, results, "singular 'white good' must not return zero results when a 0.64-cosine literal-phrase match exists")
	assert.Equal(t, uint64(2001), results[0].Msgid)
}
