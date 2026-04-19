package message

import (
	"github.com/freegle/iznik-server-go/embedding"
	"github.com/freegle/iznik-server-go/utils"
)

const keywordBoostWeight = 0.3

// MinVectorScore is the minimum per-field cosine (subject OR body) to include
// a result. Calibrated against the production sidecar (nomic-embed-text-v1.5,
// quantized, Matryoshka-truncated to 256 dim) with asymmetric prefixes —
// "search_query: …" on the query and "search_document: …" on the stored
// subject/body. Measured on the subjects from Discourse 9585.18 (Jos —
// query "white goods"):
//
//	unrelated noise ("Yoga mat", "Bike helmet", "Lego set") …… 0.35–0.46
//	tangentially related ("Washing machine", "Dishwasher") … 0.40–0.48
//	clearly related ("Whirlpool … fridge freezer", "fridge") … 0.45–0.55
//	literal phrase match ("white goods")                   … 0.70–0.85
//
// Matryoshka 256-dim truncation compresses the dynamic range by ~0.15
// vs the published full-1024-dim figures, so real-world scores run
// lower than the original comment suggested. The previous 0.65 floor
// silently dropped every tangentially-related item for domain-category
// queries like "white goods" → "fridge/washing machine/dishwasher",
// which is exactly what Jos observed: only one item (literal phrase
// match in its body) cleared the cutoff, and four live recent
// appliance posts — all measured at 0.40–0.47 — were hidden. Singular
// "white good" dropped below the floor even against the literal match
// (0.64), producing zero results.
//
// 0.45 keeps semantic matches in the candidate pool while still
// filtering obvious noise; the keyword boost and subject/body tiering
// continue to order literal matches first.
const MinVectorScore = 0.45

type scoredResult struct {
	result SearchResult
	score  float32
}

// VectorSearch performs semantic search with subject-first tiering and keyword
// re-ranking. Subject-tier hits (subjectCos ≥ MinVectorScore) come first;
// body-tier hits (only bodyCos ≥ MinVectorScore) follow. Within each tier,
// results are ordered by their tier cosine + a keyword boost (literal query
// word matches in the subject). This matches what users expect: an item whose
// subject literally says "table" should surface before one that only mentions
// "table" buried in the body.
func VectorSearch(term string, limit int, groupids []uint64, msgtype string,
	nelat, nelng, swlat, swlng float32) ([]SearchResult, error) {

	queryVec, err := embedding.EmbedQuery(term)
	if err != nil {
		return nil, err
	}

	// Fetch more than needed so we can re-rank with keyword boost.
	vecResults := embedding.Global.Search(queryVec, limit*3, msgtype, groupids,
		swlat, swlng, nelat, nelng)

	queryWords := GetWords(term)

	var subjectTier []scoredResult
	var bodyTier []scoredResult

	for _, vr := range vecResults {
		// Keyword boost: literal query-word matches in the subject.
		// Re-ranks within a tier; doesn't rescue results below threshold.
		var keywordScore float32
		if len(queryWords) > 0 {
			subjectWords := GetWords(vr.Subject)
			subjectSet := make(map[string]struct{}, len(subjectWords))
			for _, w := range subjectWords {
				subjectSet[w] = struct{}{}
			}
			matched := 0
			for _, w := range queryWords {
				if _, ok := subjectSet[w]; ok {
					matched++
				}
			}
			keywordScore = float32(matched) / float32(len(queryWords))
		}

		lat, lng := utils.Blur(vr.Lat, vr.Lng, utils.BLUR_USER)
		sr := SearchResult{
			Msgid:   vr.Msgid,
			Arrival: vr.Arrival,
			Groupid: vr.Groupid,
			Lat:     lat,
			Lng:     lng,
			Word:    term,
			Type:    vr.Msgtype,
			Matchedon: Matchedon{
				Type: "Vector",
				Word: term,
			},
		}

		if vr.SubjectCos >= MinVectorScore {
			subjectTier = append(subjectTier, scoredResult{
				result: sr,
				score:  vr.SubjectCos + keywordScore*keywordBoostWeight,
			})
		} else if vr.HasBody && vr.BodyCos >= MinVectorScore {
			bodyTier = append(bodyTier, scoredResult{
				result: sr,
				score:  vr.BodyCos + keywordScore*keywordBoostWeight,
			})
		}
	}

	sortByScoreDesc(subjectTier)
	sortByScoreDesc(bodyTier)

	combined := append(subjectTier, bodyTier...)
	if len(combined) > limit {
		combined = combined[:limit]
	}

	results := make([]SearchResult, len(combined))
	for i, s := range combined {
		results[i] = s.result
	}

	return results, nil
}

// sortByScoreDesc sorts in place by score descending. Selection sort —
// tier sizes are well under 1000, constant factors beat heap/quick overhead.
func sortByScoreDesc(s []scoredResult) {
	for i := 0; i < len(s)-1; i++ {
		for j := i + 1; j < len(s); j++ {
			if s[j].score > s[i].score {
				s[i], s[j] = s[j], s[i]
			}
		}
	}
}
