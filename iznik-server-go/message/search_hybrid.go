package message

import "github.com/freegle/iznik-server-go/utils"

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

	for _, r := range keywordResults {
		if r.Msgid != 0 && !seen[r.Msgid] {
			seen[r.Msgid] = true
			r.Lat, r.Lng = utils.Blur(r.Lat, r.Lng, utils.BLUR_USER)
			merged = append(merged, r)
		}
	}

	return merged
}
