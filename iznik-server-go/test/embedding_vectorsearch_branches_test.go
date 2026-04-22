package test

import (
	"math"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/embedding"
	"github.com/freegle/iznik-server-go/message"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// makeOrthogonalTestVec returns a unit vector near-orthogonal to ref.
// makeTestVec produces vectors that are nearly collinear (cosine ~0.97)
// regardless of seed, so we can't use it to drive SubjectCos below
// MinVectorScore (0.65). We Gram–Schmidt against the specific reference
// to get a genuinely near-zero cosine, mirroring the helper the embedding
// store tests use for the same reason.
func makeOrthogonalTestVec(ref [embedding.EmbeddingDim]float32) [embedding.EmbeddingDim]float32 {
	var v [embedding.EmbeddingDim]float32
	for i := 0; i < embedding.EmbeddingDim; i++ {
		if i%2 == 0 {
			v[i] = 1.0
		} else {
			v[i] = -1.0
		}
	}
	var dot float32
	for i := 0; i < embedding.EmbeddingDim; i++ {
		dot += v[i] * ref[i]
	}
	for i := 0; i < embedding.EmbeddingDim; i++ {
		v[i] -= dot * ref[i]
	}
	var norm float32
	for i := 0; i < embedding.EmbeddingDim; i++ {
		norm += v[i] * v[i]
	}
	norm = float32(math.Sqrt(float64(norm)))
	for i := 0; i < embedding.EmbeddingDim; i++ {
		v[i] /= norm
	}
	return v
}

func bodyVecPtr(v [embedding.EmbeddingDim]float32) *[embedding.EmbeddingDim]float32 {
	c := v
	return &c
}

// TestVectorSearchBodyTierWhenSubjectBelowThreshold covers the
// "else if vr.HasBody && vr.BodyCos >= MinVectorScore" branch.
// Without this test, the body-tier append path is never exercised:
// every existing test has SubjectCos >= MinVectorScore, so control flow
// always takes the first leg and never enters the body-tier branch.
func TestVectorSearchBodyTierWhenSubjectBelowThreshold(t *testing.T) {
	query := makeTestVec(1.0)
	noise := makeOrthogonalTestVec(query)

	// Body-only match: subject is orthogonal (SubjectCos ≈ 0, below
	// MinVectorScore), body is parallel (BodyCos = 1.0, above threshold).
	// Must surface via the bodyTier branch.
	embedding.Global.SetEntries([]embedding.Entry{
		{
			Msgid: 100, Groupid: 100, Msgtype: "Offer",
			Subject: "OFFER: unrelated subject", Arrival: time.Now(),
			SubjectVec: noise, BodyVec: bodyVecPtr(query),
		},
	})
	defer embedding.Global.SetEntries(nil)

	server := mockSidecarReturning(t, query[:])
	defer server.Close()
	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

	results, err := message.VectorSearch("item", 10, nil, "", 0, 0, 0, 0)
	require.NoError(t, err)
	require.Len(t, results, 1, "body-only match must be returned, not dropped")
	assert.Equal(t, uint64(100), results[0].Msgid)
	assert.Equal(t, "Vector", results[0].Matchedon.Type)
}

// TestVectorSearchSubjectTierComesBeforeBodyTier covers the combined
// ordering: `combined := append(subjectTier, bodyTier...)`. A body-tier
// hit with a higher raw score (via keyword boost) must still appear
// after a subject-tier hit — subject matches always rank first.
func TestVectorSearchSubjectTierComesBeforeBodyTier(t *testing.T) {
	query := makeTestVec(1.0)
	noise := makeOrthogonalTestVec(query)

	embedding.Global.SetEntries([]embedding.Entry{
		// Subject-tier hit: keyword in subject does NOT match the query,
		// so no keyword boost. SubjectCos = 1.0.
		{
			Msgid: 200, Groupid: 100, Msgtype: "Offer",
			Subject: "OFFER: completely different words", Arrival: time.Now(),
			SubjectVec: query,
		},
		// Body-tier hit: SubjectCos ≈ 0 (below threshold), BodyCos = 1.0,
		// AND the query word is in the subject so it gets a keyword
		// boost. Raw score = 1.0 + 0.3 = 1.3, higher than the subject
		// hit's 1.0. Despite that, it must come SECOND — tier beats score.
		{
			Msgid: 201, Groupid: 100, Msgtype: "Offer",
			Subject: "OFFER: coyote sighting", Arrival: time.Now(),
			SubjectVec: noise, BodyVec: bodyVecPtr(query),
		},
	})
	defer embedding.Global.SetEntries(nil)

	server := mockSidecarReturning(t, query[:])
	defer server.Close()
	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

	results, err := message.VectorSearch("coyote", 10, nil, "", 0, 0, 0, 0)
	require.NoError(t, err)
	require.Len(t, results, 2)
	assert.Equal(t, uint64(200), results[0].Msgid, "subject-tier hit must come first")
	assert.Equal(t, uint64(201), results[1].Msgid, "body-tier hit comes after subject tier regardless of raw score")
}

// TestVectorSearchCombinedTruncationAcrossTiers covers the
// `if len(combined) > limit` branch when the truncation crosses the
// subject/body tier boundary. Existing tests only truncate within a
// single tier (all entries are subject-tier).
func TestVectorSearchCombinedTruncationAcrossTiers(t *testing.T) {
	query := makeTestVec(1.0)
	noise := makeOrthogonalTestVec(query)

	embedding.Global.SetEntries([]embedding.Entry{
		// Two subject-tier hits.
		{Msgid: 300, Groupid: 100, Msgtype: "Offer", Subject: "A", SubjectVec: query},
		{Msgid: 301, Groupid: 100, Msgtype: "Offer", Subject: "B", SubjectVec: query},
		// Two body-tier hits.
		{Msgid: 302, Groupid: 100, Msgtype: "Offer", Subject: "C", SubjectVec: noise, BodyVec: bodyVecPtr(query)},
		{Msgid: 303, Groupid: 100, Msgtype: "Offer", Subject: "D", SubjectVec: noise, BodyVec: bodyVecPtr(query)},
	})
	defer embedding.Global.SetEntries(nil)

	server := mockSidecarReturning(t, query[:])
	defer server.Close()
	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

	// Limit=3 crosses the tier boundary: 2 subject + 1 body = 3 returned,
	// one body-tier entry is dropped.
	results, err := message.VectorSearch("item", 3, nil, "", 0, 0, 0, 0)
	require.NoError(t, err)
	require.Len(t, results, 3)
	// First two must be the subject-tier entries, in some order.
	subjectIDs := map[uint64]bool{300: true, 301: true}
	assert.True(t, subjectIDs[results[0].Msgid], "position 0 should be subject-tier")
	assert.True(t, subjectIDs[results[1].Msgid], "position 1 should be subject-tier")
	// Third is a body-tier entry.
	bodyIDs := map[uint64]bool{302: true, 303: true}
	assert.True(t, bodyIDs[results[2].Msgid], "position 2 should be body-tier")
}

// TestVectorSearchDropsResultsBelowBothThresholds covers the implicit
// "neither branch taken" path: when SubjectCos and BodyCos are both
// below MinVectorScore, the result must be dropped entirely (not
// silently returned as an unscored entry).
func TestVectorSearchDropsResultsBelowBothThresholds(t *testing.T) {
	query := makeTestVec(1.0)
	noise := makeOrthogonalTestVec(query)

	embedding.Global.SetEntries([]embedding.Entry{
		// Both cosines are ≈ 0 — well below the 0.65 floor on both fields.
		{
			Msgid: 400, Groupid: 100, Msgtype: "Offer",
			Subject: "OFFER: unrelated", Arrival: time.Now(),
			SubjectVec: noise, BodyVec: bodyVecPtr(noise),
		},
		// A genuine subject hit, so the test proves the filter drops
		// only the below-threshold entry and keeps the passing one.
		{
			Msgid: 401, Groupid: 100, Msgtype: "Offer",
			Subject: "OFFER: match", Arrival: time.Now(),
			SubjectVec: query,
		},
	})
	defer embedding.Global.SetEntries(nil)

	server := mockSidecarReturning(t, query[:])
	defer server.Close()
	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

	results, err := message.VectorSearch("item", 10, nil, "", 0, 0, 0, 0)
	require.NoError(t, err)
	require.Len(t, results, 1, "below-threshold entries must be dropped")
	assert.Equal(t, uint64(401), results[0].Msgid)
}

// TestVectorSearchStopWordQuerySkipsKeywordBoost covers the
// "len(queryWords) > 0" false branch: when the query term tokenises to
// zero non-common words (e.g. "the and or"), the keyword-boost block is
// skipped. keywordScore stays at 0, so the two equally-scored subject
// hits are ordered by vector cosine alone.
func TestVectorSearchStopWordQuerySkipsKeywordBoost(t *testing.T) {
	query := makeTestVec(1.0)
	similar := makeTestVec(1.001)

	embedding.Global.SetEntries([]embedding.Entry{
		// Slightly lower SubjectCos, but would receive a keyword boost
		// if queryWords contained anything — the subject words "and"
		// "or" are in the common-words list and would drop from
		// GetWords(). If the skip branch didn't fire, this entry might
		// be boosted. With it firing, both entries get boost=0 and the
		// higher raw SubjectCos (Msgid 500) must win.
		{Msgid: 500, Groupid: 100, Msgtype: "Offer", Subject: "OFFER: or", SubjectVec: query},
		{Msgid: 501, Groupid: 100, Msgtype: "Offer", Subject: "OFFER: and or", SubjectVec: similar},
	})
	defer embedding.Global.SetEntries(nil)

	server := mockSidecarReturning(t, query[:])
	defer server.Close()
	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

	// Sanity-check GetWords behaviour: all-stop-word input returns no
	// tokens, so the skip branch WILL fire inside VectorSearch.
	require.Empty(t, message.GetWords("the and or"),
		"premise: all-stop-word query must tokenise to zero words")

	results, err := message.VectorSearch("the and or", 10, nil, "", 0, 0, 0, 0)
	require.NoError(t, err)
	require.Len(t, results, 2)
	// Deterministic ordering by raw cosine, no keyword noise applied.
	assert.Equal(t, uint64(500), results[0].Msgid)
	assert.Equal(t, uint64(501), results[1].Msgid)
}

// TestVectorSearchBlursReturnedCoordinates covers the
// `lat, lng := utils.Blur(vr.Lat, vr.Lng, utils.BLUR_USER)` line:
// returned lat/lng must differ from the stored precise coordinates.
// The public API exposes these as privacy-preserving approximations
// (±400m), and no existing test pins that invariant for vector search.
func TestVectorSearchBlursReturnedCoordinates(t *testing.T) {
	query := makeTestVec(1.0)

	const preciseLat = 51.547291
	const preciseLng = -0.105438

	embedding.Global.SetEntries([]embedding.Entry{
		{
			Msgid: 600, Groupid: 100, Msgtype: "Offer",
			Lat: preciseLat, Lng: preciseLng,
			Subject: "OFFER: thing", Arrival: time.Now(),
			SubjectVec: query,
		},
	})
	defer embedding.Global.SetEntries(nil)

	server := mockSidecarReturning(t, query[:])
	defer server.Close()
	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

	results, err := message.VectorSearch("thing", 10, nil, "", 0, 0, 0, 0)
	require.NoError(t, err)
	require.Len(t, results, 1)

	// Privacy invariant: precise coordinates must not leak. Blur applies
	// ≥400m displacement and rounds to 3 dp, so neither axis can equal
	// the stored value.
	assert.NotEqual(t, preciseLat, results[0].Lat,
		"lat must be blurred before leaving the API")
	assert.NotEqual(t, preciseLng, results[0].Lng,
		"lng must be blurred before leaving the API")

	// Blurred result must still be within a reasonable radius of the
	// real location (sanity bound — Blur uses BLUR_USER = 400m).
	dist := utils.Haversine(preciseLat, preciseLng, results[0].Lat, results[0].Lng)
	assert.Less(t, dist, 2.0, "blurred location should stay within ~2 miles of source")
}

// TestVectorSearchHasBodyFalseDoesNotEnterBodyTier covers the
// short-circuit on the body-tier condition: when HasBody is false (no
// body embedding stored), the entry must never be placed in the body
// tier, even if BodyCos happened to be non-zero in the result struct.
// Combined with the subject-below-threshold condition, the whole entry
// is dropped.
func TestVectorSearchHasBodyFalseDoesNotEnterBodyTier(t *testing.T) {
	query := makeTestVec(1.0)
	noise := makeOrthogonalTestVec(query)

	embedding.Global.SetEntries([]embedding.Entry{
		// Subject below threshold, no body embedding at all.
		{
			Msgid: 700, Groupid: 100, Msgtype: "Offer",
			Subject: "OFFER: unrelated", Arrival: time.Now(),
			SubjectVec: noise, BodyVec: nil,
		},
		// A subject-tier match so the test isn't trivially empty.
		{
			Msgid: 701, Groupid: 100, Msgtype: "Offer",
			Subject: "OFFER: match", Arrival: time.Now(),
			SubjectVec: query, BodyVec: nil,
		},
	})
	defer embedding.Global.SetEntries(nil)

	server := mockSidecarReturning(t, query[:])
	defer server.Close()
	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

	results, err := message.VectorSearch("match", 10, nil, "", 0, 0, 0, 0)
	require.NoError(t, err)
	require.Len(t, results, 1, "HasBody=false + subject below threshold must drop entry")
	assert.Equal(t, uint64(701), results[0].Msgid)
}
