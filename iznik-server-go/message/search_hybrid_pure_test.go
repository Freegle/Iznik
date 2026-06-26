package message

import (
	"testing"

	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func makeSearchResult(msgid uint64, lat, lng float64) SearchResult {
	return SearchResult{
		Msgid: msgid,
		Lat:   lat,
		Lng:   lng,
		Word:  "test",
	}
}

// ── mergeHybrid ───────────────────────────────────────────────────────────────

func TestMergeHybrid_BothEmpty(t *testing.T) {
	result := mergeHybrid(nil, nil)
	assert.Empty(t, result)
}

func TestMergeHybrid_VectorOnly(t *testing.T) {
	vector := []SearchResult{
		makeSearchResult(1, 51.5, -0.1),
		makeSearchResult(2, 51.6, -0.2),
	}
	result := mergeHybrid(vector, nil)
	require.Len(t, result, 2)
	assert.Equal(t, uint64(1), result[0].Msgid)
	assert.Equal(t, uint64(2), result[1].Msgid)
}

func TestMergeHybrid_KeywordOnly(t *testing.T) {
	keyword := []SearchResult{
		makeSearchResult(10, 51.5, -0.1),
		makeSearchResult(20, 51.6, -0.2),
	}
	result := mergeHybrid(nil, keyword)
	require.Len(t, result, 2)
	assert.Equal(t, uint64(10), result[0].Msgid)
	assert.Equal(t, uint64(20), result[1].Msgid)
}

func TestMergeHybrid_NoOverlap_VectorFirst(t *testing.T) {
	vector := []SearchResult{makeSearchResult(1, 51.5, -0.1)}
	keyword := []SearchResult{makeSearchResult(2, 51.6, -0.2)}
	result := mergeHybrid(vector, keyword)
	require.Len(t, result, 2)
	assert.Equal(t, uint64(1), result[0].Msgid, "vector result must come first")
	assert.Equal(t, uint64(2), result[1].Msgid, "keyword result must follow")
}

func TestMergeHybrid_Deduplication(t *testing.T) {
	// Msgid 1 appears in both; must appear only once (from vector).
	vector := []SearchResult{makeSearchResult(1, 51.5, -0.1)}
	keyword := []SearchResult{
		makeSearchResult(1, 51.5, -0.1), // duplicate
		makeSearchResult(2, 51.6, -0.2),
	}
	result := mergeHybrid(vector, keyword)
	require.Len(t, result, 2)
	// Check each msgid appears exactly once.
	seen := make(map[uint64]int)
	for _, r := range result {
		seen[r.Msgid]++
	}
	assert.Equal(t, 1, seen[1], "msgid 1 must appear exactly once")
	assert.Equal(t, 1, seen[2], "msgid 2 must appear exactly once")
}

func TestMergeHybrid_ZeroMsgidFiltered(t *testing.T) {
	// Rows with Msgid=0 are invalid and must be filtered from both sets.
	vector := []SearchResult{
		makeSearchResult(0, 51.5, -0.1), // invalid
		makeSearchResult(1, 51.5, -0.1),
	}
	keyword := []SearchResult{
		makeSearchResult(0, 51.6, -0.2), // invalid
		makeSearchResult(2, 51.6, -0.2),
	}
	result := mergeHybrid(vector, keyword)
	require.Len(t, result, 2)
	for _, r := range result {
		assert.NotZero(t, r.Msgid, "zero msgid must not appear in result")
	}
}

func TestMergeHybrid_KeywordResultsAreBlurred(t *testing.T) {
	// Keyword results must have coordinates blurred before merging.
	// Vector results are already blurred by VectorSearch, so mergeHybrid leaves them alone.
	const rawLat, rawLng = 51.5074, -0.1278

	vector := []SearchResult{makeSearchResult(1, rawLat, rawLng)}
	keyword := []SearchResult{makeSearchResult(2, rawLat, rawLng)}

	result := mergeHybrid(vector, keyword)
	require.Len(t, result, 2)

	expectedLat, expectedLng := utils.Blur(rawLat, rawLng, utils.BLUR_USER)

	// Keyword result (index 1) must have blurred coords.
	assert.Equal(t, expectedLat, result[1].Lat, "keyword lat must be blurred")
	assert.Equal(t, expectedLng, result[1].Lng, "keyword lng must be blurred")
}

func TestMergeHybrid_VectorCoordinatesUnchanged(t *testing.T) {
	// mergeHybrid must NOT blur vector results — VectorSearch already blurred them.
	const rawLat, rawLng = 51.5074, -0.1278
	vector := []SearchResult{makeSearchResult(1, rawLat, rawLng)}

	result := mergeHybrid(vector, nil)
	require.Len(t, result, 1)

	assert.Equal(t, rawLat, result[0].Lat, "vector lat must not be modified")
	assert.Equal(t, rawLng, result[0].Lng, "vector lng must not be modified")
}

func TestMergeHybrid_OrderPreserved(t *testing.T) {
	// Within each slice the order must be preserved.
	vector := []SearchResult{
		makeSearchResult(10, 51.1, -0.1),
		makeSearchResult(20, 51.2, -0.2),
		makeSearchResult(30, 51.3, -0.3),
	}
	keyword := []SearchResult{
		makeSearchResult(40, 51.4, -0.4),
		makeSearchResult(50, 51.5, -0.5),
	}
	result := mergeHybrid(vector, keyword)
	require.Len(t, result, 5)
	assert.Equal(t, uint64(10), result[0].Msgid)
	assert.Equal(t, uint64(20), result[1].Msgid)
	assert.Equal(t, uint64(30), result[2].Msgid)
	assert.Equal(t, uint64(40), result[3].Msgid)
	assert.Equal(t, uint64(50), result[4].Msgid)
}

func TestMergeHybrid_AllOverlap(t *testing.T) {
	// All keyword results already appear in vector → result is just the vector slice.
	vector := []SearchResult{
		makeSearchResult(1, 51.1, -0.1),
		makeSearchResult(2, 51.2, -0.2),
	}
	keyword := []SearchResult{
		makeSearchResult(1, 51.1, -0.1),
		makeSearchResult(2, 51.2, -0.2),
	}
	result := mergeHybrid(vector, keyword)
	require.Len(t, result, 2)
	assert.Equal(t, uint64(1), result[0].Msgid)
	assert.Equal(t, uint64(2), result[1].Msgid)
}

func TestMergeHybrid_EmptyKeyword(t *testing.T) {
	vector := []SearchResult{makeSearchResult(1, 51.5, -0.1)}
	result := mergeHybrid(vector, []SearchResult{})
	require.Len(t, result, 1)
	assert.Equal(t, uint64(1), result[0].Msgid)
}

func TestMergeHybrid_EmptyVector(t *testing.T) {
	keyword := []SearchResult{makeSearchResult(5, 51.5, -0.1)}
	result := mergeHybrid([]SearchResult{}, keyword)
	require.Len(t, result, 1)
	assert.Equal(t, uint64(5), result[0].Msgid)
}
