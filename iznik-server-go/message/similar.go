package message

import (
	"os"
	"strconv"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/embedding"
	"github.com/freegle/iznik-server-go/misc"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
)

// MinSimilarScore is the minimum subject-field cosine for a post to count as
// "similar". This is an exploratory surface (a recommendation strip, not a
// search query), so the bar is lower than for the matched-posts email, which
// pushes into an inbox unasked and uses MinMatchedPostScore (0.85).
//
// It is NOT lower than search's MinVectorScore (0.65), which is what the
// original 0.60 assumed. Search compares a typed "search_query:" embedding
// against stored "search_document:" ones; this compares two stored document
// embeddings, and those cosines run much higher, so 0.60 here is a far weaker
// filter than 0.65 there. The two numbers are not on the same scale.
//
// Measured by scoring 150 randomly sampled live Offers against the live Offer
// pool (same type, self and verbatim reprints excluded) and hand-judging the
// top suggestion, asking "would someone looking at this plausibly want that?":
//
//	0.90-1.00  44 shown, precision 0.98
//	0.85-0.90  34 shown, precision 1.00
//	0.80-0.85  33 shown, precision 0.67
//	0.75-0.80  21 shown, precision 0.43
//	below 0.75 18 shown, precision 0.17
//
// At 0.60 about a quarter of strips led with something unrelated that merely
// shared a word: Crocosmia -> "Crockery", Motherboard -> "Headboard", Laptop
// arm -> "Arm chair", Green House -> "Hedgehog House". 0.80 lifts precision
// from 0.74 to 0.89 while still showing a strip on 74% of posts.
//
// 0.85 would give 0.99 precision but halves coverage to 52%, which is the wrong
// trade here: unlike the email, a slightly-off suggestion beside something you
// are already looking at costs little, whereas an empty strip loses the
// exploration entirely.
//
// Live telemetry agrees the old floor was not working. Over 14 days to
// 2026-07-20, source='similar_posts' saw 714 impressions and 3 clicks, a 0.4%
// click-through, against 16% for 'wanted_match' (163/26). Placement differs so
// they are not strictly comparable, but a 40x gap is not placement alone.
//
// Both numbers above are hand-judged, because until now nothing recorded the
// score behind an impression. logSimilarImpressions (below) now does, so the
// next revision of this constant can be made on click-through per score band
// instead.
const MinSimilarScore = 0.80

// similarBoxLatDeg / similarBoxLngDeg size the box around the "nearby" centre that
// the candidate search is restricted to, before the exact reach filter. ~0.6 lat and
// ~0.9 lng degrees is roughly a 65km radius at UK latitudes — comfortably wider than
// a post's rippling reach, so the box never cuts a candidate that is actually
// repliable; it only keeps the globally-most-similar-but-far posts out of the
// top-by-score set, which is what used to empty the strip for viewers away from the
// semantic cluster.
const similarBoxLatDeg = 0.6
const similarBoxLngDeg = 0.9

// similarCandidatePool is how many by-score candidates to pull from the box before
// the reach filter, independent of the requested limit. The reach filter can reject
// almost all of the highest-scoring candidates when the viewer sits in a sparsely-
// rippled area, so a small pool (the old limit*3) leaves nothing even though
// reachable-but-lower-scored matches exist further down the ranking. A generous pool
// lets those survive; the box already bounds it to nearby posts so it stays cheap.
const similarCandidatePool = 200

// similarPostsEnabled reports whether the similar-posts feature is on.
// FEATURE_SIMILAR_POSTS=off is a no-deploy killswitch (default on); when off the
// endpoint returns an empty list so the frontend strip renders nothing.
func similarPostsEnabled() bool {
	return os.Getenv("FEATURE_SIMILAR_POSTS") != "off"
}

// SimilarResult is one recommended post: enough for the frontend to render a
// card (it fetches full message details separately) and place it on the map.
type SimilarResult struct {
	Msgid   uint64  `json:"id"`
	Groupid uint64  `json:"groupid"`
	Score   float32 `json:"score"`
	Lat     float64 `json:"lat"`
	Lng     float64 `json:"lng"`
}

// Similar returns open posts of the same type that are semantically similar to
// the given post, for a "more like this nearby" recommendation strip. It uses
// the STORED subject embedding of the source post (no sidecar/query-embed call):
// from the in-memory store when the post is open, else a single indexed read of
// messages_embeddings. Excludes the source post and its own author, applies
// MinSimilarScore, and restricts results to posts near — and repliable from — a
// centre: the viewer's location when we know it, otherwise the post's own location
// (so a logged-out reader, or one we can't place, still gets posts local to what
// they are looking at rather than the globally-most-similar, which may be hundreds
// of miles away and unrepliable). "Repliable" is the rippling-reach containment
// check; distant candidates the viewer could not reply to are dropped, never shown.
//
// @Router /message/{id}/similar [get]
// @Summary Posts similar to a given post (recommendations)
// @Tags message
// @Produce json
// @Param id path int true "Message ID"
// @Param limit query int false "Max results (default 8, max 20)"
// @Success 200 {array} message.SimilarResult
func Similar(c *fiber.Ctx) error {
	// Killswitch: return an empty list (not an error) so the FE strip just hides.
	if !similarPostsEnabled() {
		return c.JSON([]SimilarResult{})
	}

	db := database.DBConn

	id, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid message id")
	}

	limit := c.QueryInt("limit", 8)
	if limit < 1 {
		limit = 8
	}
	if limit > 20 {
		limit = 20
	}

	// Source post's embedding + metadata. Prefer the in-memory store (open post,
	// no DB read); fall back to a single indexed read for a post not in the store.
	var srcVec []float32
	var srcType string
	var srcFromuser uint64
	var srcLat, srcLng float64

	if e, ok := embedding.Global.FindByMsgid(id); ok {
		srcVec = e.SubjectVec[:]
		srcType = e.Msgtype
		srcFromuser = e.Fromuser
		srcLat = e.Lat
		srcLng = e.Lng
	} else {
		var row struct {
			SubjectEmbedding []byte  `gorm:"column:subject_embedding"`
			Fromuser         uint64  `gorm:"column:fromuser"`
			Type             string  `gorm:"column:type"`
			Lat              float64 `gorm:"column:lat"`
			Lng              float64 `gorm:"column:lng"`
		}
		// ORM migration site 00ea2c253192 (wave 4).
		db.Table("messages_embeddings me").
			Select("me.subject_embedding, m.fromuser, m.type, m.lat, m.lng").
			Joins("INNER JOIN messages m ON m.id = me.msgid").
			Where("me.msgid = ?", id).
			Scan(&row)
		if len(row.SubjectEmbedding) == 0 {
			// No embedding for this post yet — nothing to compare. Empty, never 500.
			return c.JSON([]SimilarResult{})
		}
		vec, decErr := embedding.DecodeVector(row.SubjectEmbedding)
		if decErr != nil {
			return c.JSON([]SimilarResult{})
		}
		srcVec = vec
		srcType = row.Type
		srcFromuser = row.Fromuser
		srcLat = row.Lat
		srcLng = row.Lng
	}

	// "Nearby" centre: the viewer's own location when we know it, else the post's
	// location — so a logged-out reader, or one we can't place, still gets posts local
	// to what they are looking at rather than the globally-most-similar.
	centreLat, centreLng := srcLat, srcLng
	myid := user.WhoAmI(c)
	if myid > 0 {
		ll := user.GetLatLng(myid)
		if ll.Lat != 0 || ll.Lng != 0 {
			centreLat, centreLng = float64(ll.Lat), float64(ll.Lng)
		}
	}

	// Viewing your OWN post flips the type: for your Wanted show Offers ("is what I want
	// available?"), for your Offer show Wanteds ("who wants what I have?"). For anyone
	// else's post, like-with-like (same type) is what "more like this" means.
	searchType := srcType
	if myid > 0 && myid == srcFromuser {
		if srcType == "Offer" {
			searchType = "Wanted"
		} else if srcType == "Wanted" {
			searchType = "Offer"
		}
	}

	// Constrain the candidate search to a generous box around the centre so the
	// top-by-score set is drawn from posts plausibly in reach, not from the global
	// pool. Without this the strongest semantic matches can all sit in one distant
	// cluster, and the reach filter below then empties the strip entirely. The box is
	// deliberately wide; the reach filter is the precise "can I reply" cut. With no
	// centre at all (a post with no location) it falls back to an unbounded search.
	var swlat, swlng, nelat, nelng float32
	if centreLat != 0 || centreLng != 0 {
		swlat = float32(centreLat - similarBoxLatDeg)
		nelat = float32(centreLat + similarBoxLatDeg)
		swlng = float32(centreLng - similarBoxLngDeg)
		nelng = float32(centreLng + similarBoxLngDeg)
	}
	candidates := embedding.Global.Search(srcVec, similarCandidatePool, searchType, nil, nil, swlat, swlng, nelat, nelng)

	// Reach filter: drop candidates that could not be replied to from the centre
	// (rippled out but not yet to it). Applies to logged-out readers too, using the
	// post's location. Fail-open — no reach row, or any error, never blocks.
	var blocked map[uint64]bool
	if centreLat != 0 || centreLng != 0 {
		ids := make([]uint64, 0, len(candidates))
		for _, cnd := range candidates {
			ids = append(ids, cnd.Msgid)
		}
		blocked = ReachBlockedSet(ids, centreLat, centreLng)
	}

	out := make([]SimilarResult, 0, limit)
	// De-duplicate items that appear more than once. A single post rippled to many
	// groups is one msgid (fine), but rippling can also strand genuine COPIES — a
	// separate msgid per group for the same item — which would otherwise show as
	// repeated cards. Same author + same subject identifies those; keep the first
	// (highest-scoring, since candidates are score-ordered).
	seen := make(map[string]bool)
	for _, cnd := range candidates {
		if cnd.Msgid == id {
			continue // the source post itself
		}
		if cnd.Fromuser == srcFromuser {
			continue // same author — not a useful recommendation
		}
		if cnd.SubjectCos < MinSimilarScore {
			continue
		}
		if blocked[cnd.Msgid] {
			continue // viewer can't reply (outside reach)
		}
		dedupKey := strconv.FormatUint(cnd.Fromuser, 10) + "\x00" + cnd.Subject
		if seen[dedupKey] {
			continue // a rippled copy of an item already included
		}
		seen[dedupKey] = true
		lat, lng := utils.Blur(cnd.Lat, cnd.Lng, utils.BLUR_USER)
		out = append(out, SimilarResult{
			Msgid:   cnd.Msgid,
			Groupid: cnd.Groupid,
			Score:   cnd.SubjectCos,
			Lat:     lat,
			Lng:     lng,
		})
		if len(out) >= limit {
			break
		}
	}

	logSimilarImpressions(id, myid, out)

	return c.JSON(out)
}

// logSimilarImpressions records what this strip actually served, one line per
// candidate, so the floor above can be tuned on evidence rather than judgement.
//
// The gap this closes: messages_likes tags a recommendation impression with
// `source` and whether it was opened (`pageview`), but not the similarity score
// that caused it to be shown. So the funnel in recommendations/stats.go can say
// the strip converts at 0.4%, but not whether the clicks come from the 0.95
// suggestions and the 0.82 ones are dead weight, which is the number needed to
// set MinSimilarScore. Joining these lines to the click rows on (msgid, userid)
// within the view window gives click-through per score band.
//
// Deliberately NOT a score column on messages_likes: that table is 65M rows and
// 11GB on prod, so widening it is an online-DDL job on a hot write path, for a
// surface serving ~50 impressions a day. Logging is free and enough at this
// volume. Revisit if the strip ever gets heavily used.
//
// To read it back:
//
//	{app="freegle", source="similar_posts", event="impression"} | json
//
// giving msgid, score, src_msgid and userid per served candidate. Bucket by
// score and join to the clicks (messages_likes, type='View', source='similar_posts',
// pageview=1) on (msgid, userid) to get click-through per band.
func logSimilarImpressions(srcMsgid, myid uint64, served []SimilarResult) {
	loki := misc.GetLoki()
	if loki == nil || !loki.IsEnabled() || len(served) == 0 {
		return
	}

	for _, r := range served {
		loki.LogCustom("similar_posts", map[string]string{"event": "impression"}, map[string]interface{}{
			"src_msgid": srcMsgid,
			"msgid":     r.Msgid,
			"score":     r.Score,
			"userid":    myid, // 0 when logged out
		})
	}
}
