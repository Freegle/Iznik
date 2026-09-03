package message

import (
	"os"
	"strconv"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/embedding"
	"github.com/freegle/iznik-server-go/roadblur"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
)

// matchedPostsEnabled reports whether opposite-type post matching is on.
// FEATURE_MATCHED_POSTS=off is a no-deploy killswitch (default on); when off the
// endpoint returns an empty list so the matched-posts email simply sends nothing.
func matchedPostsEnabled() bool {
	return os.Getenv("FEATURE_MATCHED_POSTS") != "off"
}

// MinMatchedPostScore is the minimum subject cosine for an opposite-type post to
// be worth emailing about. Deliberately higher than MinSimilarScore (0.80):
// that floor governs the on-site similar-posts strip, where the viewer is
// already browsing and a weak suggestion costs nothing. Two things make this
// surface different: the match is pushed into someone's inbox unasked, and
// both sides are stored "search_document:" embeddings, so cosines sit far
// higher than the query-vs-document scores these floors were picked against.
//
// Measured on 150 randomly sampled live Offers scored against the live Wanted
// pool, hand-judging the top match ("would someone who posted this Wanted be
// glad to see this Offer?"). Precision by band is a cliff, not a slope:
//
//	>= 0.90   20 matches, precision 1.00
//	0.85-0.90 25 matches, precision 0.92
//	0.80-0.85 21 matches, precision 0.43   <-- falls off here
//	0.75-0.80 47 matches, precision 0.36
//	0.60-0.75 37 matches, precision 0.11
//
// At 0.60 just under half the emails would carry an irrelevant item (Bed lever →
// "Bed", Screen protectors → "Curtains", Keyrings → "Rugs"). At 0.85 precision
// is 0.96 and 30% of sampled posts still produce a match, which is ample volume
// for an every-10-minutes job. Recall is the thing being traded away (59% of
// true matches kept) and that is the right trade for unsolicited mail: a missed
// match costs one email, a junk match teaches people to ignore the next one.
const MinMatchedPostScore = 0.85

// oppositeType maps Offer↔Wanted; any other type yields "" (no matching).
func oppositeType(t string) string {
	switch t {
	case "Offer":
		return "Wanted"
	case "Wanted":
		return "Offer"
	default:
		return ""
	}
}

// PostMatches returns open posts of the OPPOSITE type that semantically match a
// given post and are near it — the candidate set for the matched-posts email.
//
// It differs from /similar in three ways, all because it serves a batch job
// rather than an on-site viewer: (1) opposite type (an Offer's matches are
// Wanteds and vice versa); (2) boxed to collectable distance around the post;
// (3) reach-filtered against the POST's own location, not the caller's — the
// batch calls this unauthenticated, and the recipient is the post's owner, so a
// candidate that hasn't rippled out to the post's area is dropped. Excludes the
// owner's own posts and applies MinMatchedPostScore. Uses the stored subject
// embedding (in-memory store, else one indexed read). Empty (never 500) when the
// post has no embedding, no location, or isn't an Offer/Wanted.
//
// @Router /message/{id}/matches [get]
// @Summary Opposite-type posts matching a given post (matched-posts email)
// @Tags message
// @Produce json
// @Param id path int true "Message ID"
// @Param limit query int false "Max results (default 10, max 30)"
// @Success 200 {array} message.SimilarResult
func PostMatches(c *fiber.Ctx) error {
	if !matchedPostsEnabled() {
		return c.JSON([]SimilarResult{})
	}

	id, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid message id")
	}

	limit := c.QueryInt("limit", 10)
	if limit < 1 {
		limit = 10
	}
	if limit > 30 {
		limit = 30
	}

	// Source post: embedding + type + author + location. Prefer the in-memory
	// store (open post, no DB read); fall back to one indexed read for a post not
	// in the current snapshot.
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
		database.DBConn.Table("messages_embeddings me").
			Select("me.subject_embedding, m.fromuser, m.type, ST_Y(ms.point) AS lat, ST_X(ms.point) AS lng").
			Joins("INNER JOIN messages m ON m.id = me.msgid").
			Joins("LEFT JOIN messages_spatial ms ON ms.msgid = me.msgid").
			Where("me.msgid = ?", id).
			Scan(&row)
		if len(row.SubjectEmbedding) == 0 {
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

	opp := oppositeType(srcType)
	if opp == "" {
		return c.JSON([]SimilarResult{})
	}
	if srcLat == 0 && srcLng == 0 {
		// No usable location → we can't box or reach-filter, so return nothing
		// rather than surface far-away posts.
		return c.JSON([]SimilarResult{})
	}

	// Box the search to collectable distance around the post (same half-width as
	// the compose-time wanted-match panel).
	swlat := float32(srcLat - matchBoxDegrees)
	swlng := float32(srcLng - matchBoxDegrees)
	nelat := float32(srcLat + matchBoxDegrees)
	nelng := float32(srcLng + matchBoxDegrees)
	candidates := embedding.Global.Search(srcVec, limit*3, opp, nil, nil, swlat, swlng, nelat, nelng)

	// Reach-filter against the post's location (the owner is the recipient): drop
	// opposite posts that haven't rippled out to where the post is, so we never
	// email a match the owner couldn't reply to. No reach row at all still means
	// kept, but a row the routing server cannot decide means dropped: mail is the
	// surface where sending wrongly costs more than sending nothing.
	blocked := ReachBlockedSetForMail(candidateMsgids(candidates), srcLat, srcLng)

	out := make([]SimilarResult, 0, limit)
	for _, cnd := range candidates {
		if cnd.Fromuser == srcFromuser {
			continue // the owner's own posts
		}
		if cnd.SubjectCos < MinMatchedPostScore {
			continue
		}
		if blocked[cnd.Msgid] {
			continue
		}
		lat, lng := roadblur.RoadBlur(cnd.Lat, cnd.Lng, utils.BLUR_USER)
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

	return c.JSON(out)
}
