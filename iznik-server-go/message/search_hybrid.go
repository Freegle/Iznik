package message

import (
	"github.com/freegle/iznik-server-go/roadblur"
	"github.com/freegle/iznik-server-go/utils"
)

// mergeHybrid combines vector and keyword search results for the hybrid search
// path. Vector results come first (semantic ranking). Keyword results that do
// not already appear in the vector set are appended — guaranteeing exact lexical
// matches even when the embedding model misses them. Keyword coordinates are
// blurred here; vector results are already blurred by VectorSearch.
func mergeHybrid(vectorResults, keywordResults []SearchResult) []SearchResult {
	seen := make(map[uint64]bool, len(vectorResults))
	merged := make([]SearchResult, 0, len(vectorResults)+len(keywordResults))

	for _, r := range vectorResults {
		if r.Msgid != 0 {
			seen[r.Msgid] = true
			merged = append(merged, r)
		}
	}

	// Batch the road-aware blur for the keyword-only supplement, then blur
	// per row from cache - same deterministic point as every other surface.
	blurCoords := make([][2]float64, 0, len(keywordResults))
	for _, r := range keywordResults {
		if r.Msgid != 0 && !seen[r.Msgid] {
			blurCoords = append(blurCoords, [2]float64{r.Lat, r.Lng})
		}
	}
	roadblur.RoadBlurPrewarm(blurCoords, utils.BLUR_USER)
	for _, r := range keywordResults {
		if r.Msgid != 0 && !seen[r.Msgid] {
			seen[r.Msgid] = true
			r.Lat, r.Lng = roadblur.RoadBlur(r.Lat, r.Lng, utils.BLUR_USER)
			merged = append(merged, r)
		}
	}

	return merged
}
