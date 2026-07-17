package message

import (
	"os"
	"strconv"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/embedding"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
)

// MinSimilarScore is the minimum subject-field cosine for a post to count as
// "similar". Lower than search's MinVectorScore (0.65) because this is an
// exploratory surface (a recommendation strip, not a search query), but high
// enough to keep out junk. Tune from the recommendations telemetry.
const MinSimilarScore = 0.60

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
// MinSimilarScore, and — for a logged-in viewer whose location is known — drops
// candidates the viewer could not reply to because they are outside the post's
// rippling reach.
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

	if e, ok := embedding.Global.FindByMsgid(id); ok {
		srcVec = e.SubjectVec[:]
		srcType = e.Msgtype
		srcFromuser = e.Fromuser
	} else {
		var row struct {
			SubjectEmbedding []byte `gorm:"column:subject_embedding"`
			Fromuser         uint64 `gorm:"column:fromuser"`
			Type             string `gorm:"column:type"`
		}
		db.Raw("SELECT me.subject_embedding, m.fromuser, m.type "+
			"FROM messages_embeddings me INNER JOIN messages m ON m.id = me.msgid "+
			"WHERE me.msgid = ?", id).Scan(&row)
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
	}

	// Over-fetch so post-filtering (self, same author, threshold, reach) still
	// leaves enough. Same-type only; no group/bbox filter (nearby handled by the
	// recommendation being about this post; reach handles "can I reply").
	candidates := embedding.Global.Search(srcVec, limit*3, srcType, nil, 0, 0, 0, 0)

	// Reach filter: for a logged-in viewer with a known location, drop candidates
	// they could not reply to (rippled out but not yet to them). Fail-open.
	var blocked map[uint64]bool
	myid := user.WhoAmI(c)
	if myid > 0 {
		ll := user.GetLatLng(myid)
		if ll.Lat != 0 || ll.Lng != 0 {
			ids := make([]uint64, 0, len(candidates))
			for _, cnd := range candidates {
				ids = append(ids, cnd.Msgid)
			}
			blocked = ReachBlockedSet(ids, float64(ll.Lat), float64(ll.Lng))
		}
	}

	out := make([]SimilarResult, 0, limit)
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

	return c.JSON(out)
}
