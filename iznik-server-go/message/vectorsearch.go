package message

import (
	"fmt"
	"strconv"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/embedding"
	"github.com/freegle/iznik-server-go/misc"
	"github.com/freegle/iznik-server-go/utils"
)

const keywordBoostWeight = 0.3

// MinVectorScore is the minimum per-field cosine (subject OR body) to include
// a result. nomic-embed-text-v1.5 normalized dot products: random noise ~0.50,
// tangential ~0.60, genuine semantic matches 0.70+, exact 0.75+.
const MinVectorScore = 0.65

// VectorStats captures diagnostic signal about one VectorSearch call, for the
// handler to emit to Loki. It exists because repeat identical queries for Dee
// (Discourse 9594) produced different result sets, and the raw response alone
// didn't reveal *which* stage (sidecar, store, scoring) was behaving
// differently between calls. With these fields logged per call, the same term
// called twice will show in Loki whether the embedding, the store size, or the
// top candidate cosines changed.
type VectorStats struct {
	EmbedMs       float64 // duration of the sidecar EmbedQuery call
	StoreMs       float64 // duration of the brute-force Store.Search call
	TotalMs       float64 // total VectorSearch duration incl. scoring
	StoreSize     int     // embedding.Global.Count() at call time
	Candidates    int     // raw count returned by Store.Search (pre-threshold)
	SubjectTier   int     // candidates passing MinVectorScore on SubjectCos
	BodyTier      int     // candidates passing MinVectorScore on BodyCos (and not in subject tier)
	Dropped       int     // candidates below MinVectorScore on both fields
	TopSubjectCos float32 // max SubjectCos across the candidates (diagnoses "why empty")
	TopBodyCos    float32 // max BodyCos across the candidates
	QueryVecFP    string  // fingerprint of the returned embedding — identical query should yield identical fingerprint
	Error         string  // populated if EmbedQuery failed (otherwise "")
	TopK          string  // top-5 candidates serialized as "msgid:subjCos:bodyCos:subject|..." for threshold tuning
}

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
//
// Returns VectorStats alongside the results so the caller can emit diagnostic
// logs — see the type comment for why.
func VectorSearch(term string, limit int, groupids []uint64, msgtype string,
	nelat, nelng, swlat, swlng float32) ([]SearchResult, VectorStats, error) {

	stats := VectorStats{StoreSize: embedding.Global.Count()}
	start := time.Now()

	embedStart := time.Now()
	queryVec, err := embedding.EmbedQuery(term)
	stats.EmbedMs = float64(time.Since(embedStart).Microseconds()) / 1000.0
	if err != nil {
		stats.Error = err.Error()
		stats.TotalMs = float64(time.Since(start).Microseconds()) / 1000.0
		return nil, stats, err
	}
	stats.QueryVecFP = fingerprintVec(queryVec)

	// Fetch more than needed so we can re-rank with keyword boost.
	storeStart := time.Now()
	vecResults := embedding.Global.Search(queryVec, limit*3, msgtype, groupids,
		swlat, swlng, nelat, nelng)
	stats.StoreMs = float64(time.Since(storeStart).Microseconds()) / 1000.0
	stats.Candidates = len(vecResults)

	queryWords := GetWords(term)

	var subjectTier []scoredResult
	var bodyTier []scoredResult

	for _, vr := range vecResults {
		if vr.SubjectCos > stats.TopSubjectCos {
			stats.TopSubjectCos = vr.SubjectCos
		}
		if vr.HasBody && vr.BodyCos > stats.TopBodyCos {
			stats.TopBodyCos = vr.BodyCos
		}

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
		} else {
			stats.Dropped++
		}
	}
	stats.SubjectTier = len(subjectTier)
	stats.BodyTier = len(bodyTier)

	// Capture top-5 candidates by max(subjectCos,bodyCos) for threshold tuning.
	// Cheap — vecResults is already the top-K chosen by the store.
	var topK strings.Builder
	kMax := 5
	if len(vecResults) < kMax {
		kMax = len(vecResults)
	}
	for i := 0; i < kMax; i++ {
		vr := vecResults[i]
		if i > 0 {
			topK.WriteString("|")
		}
		subj := vr.Subject
		if len(subj) > 60 {
			subj = subj[:60]
		}
		fmt.Fprintf(&topK, "%d:%.4f:%.4f:%s", vr.Msgid, vr.SubjectCos, vr.BodyCos, subj)
	}
	stats.TopK = topK.String()

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

	stats.TotalMs = float64(time.Since(start).Microseconds()) / 1000.0
	return results, stats, nil
}

// fingerprintVec returns a short hex-ish string derived from the first 4 values
// of a vector. Identical inputs to a deterministic embedder must produce
// identical fingerprints; a fingerprint drift between calls means the sidecar
// itself is non-deterministic.
func fingerprintVec(v []float32) string {
	if len(v) < 4 {
		return ""
	}
	return fmt.Sprintf("%.4f,%.4f,%.4f,%.4f", v[0], v[1], v[2], v[3])
}

// logVectorSearch emits a structured diagnostic log to Loki summarising one
// vector search call. Cheap no-op when Loki is disabled.
func logVectorSearch(term string, groupids []uint64, msgtype string, userID uint64,
	searchmode string, returned int, fallbackTaken bool, stats VectorStats) {

	l := misc.GetLoki()
	if l == nil || !l.IsEnabled() {
		return
	}

	groupStrs := make([]string, len(groupids))
	for i, g := range groupids {
		groupStrs[i] = strconv.FormatUint(g, 10)
	}

	labels := map[string]string{
		"searchmode":     searchmode,
		"fallback_taken": strconv.FormatBool(fallbackTaken),
		"empty":          strconv.FormatBool(returned == 0),
	}

	data := map[string]interface{}{
		"term":            term,
		"term_len":        len(term),
		"msgtype":         msgtype,
		"groupids":        strings.Join(groupStrs, ","),
		"user_id":         userID,
		"returned":        returned,
		"fallback_taken":  fallbackTaken,
		"embed_ms":        stats.EmbedMs,
		"store_ms":        stats.StoreMs,
		"total_ms":        stats.TotalMs,
		"store_size":      stats.StoreSize,
		"candidates":      stats.Candidates,
		"subject_tier":    stats.SubjectTier,
		"body_tier":       stats.BodyTier,
		"dropped":         stats.Dropped,
		"top_subject_cos": stats.TopSubjectCos,
		"top_body_cos":    stats.TopBodyCos,
		"query_vec_fp":    stats.QueryVecFP,
		"min_score":       MinVectorScore,
		"top_k":           stats.TopK,
	}
	if stats.Error != "" {
		data["error"] = stats.Error
	}

	l.LogCustom("vector_search", labels, data)
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
