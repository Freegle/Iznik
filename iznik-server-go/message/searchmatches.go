package message

import (
	"math"
	"strconv"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/embedding"
	"github.com/gofiber/fiber/v2"
)

// SearchMatch is one member whose saved search matches a post.
type SearchMatch struct {
	Userid   uint64  `json:"userid"`
	Searchid uint64  `json:"searchid"`
	Term     string  `json:"term"`
	Score    float64 `json:"score"`
}

// SearchMatchesForPost returns the members whose saved search matches this post
// well enough to be worth an unsolicited email about it.
//
// Held to MinMatchedPostScore - the SAME constant the matched-posts email uses -
// rather than a number of its own, and that is deliberate on two counts.
//
// First, the bar. A saved search used to be matched with SQL LIKE on keywords,
// which has no score at all: "bed lever" matched "bed" and nothing could say how
// badly. That is fine on a surface somebody chose to look at and wrong for
// deciding to put mail in an inbox. Measured on hand-judged live posts, precision
// falls off a cliff below 0.85 (0.85-0.90 -> 0.92, 0.80-0.85 -> 0.43), which is
// why the browsing strip (MinSimilarScore, 0.80) and the email (0.85) are
// separate numbers in the first place.
//
// Second, the SCALE. This compares two stored DOCUMENT embeddings - the post's
// subject against the saved term, both written by EmbeddingService the same way -
// so the cosines are directly comparable with the matched-posts ones and 0.85
// means the same thing here. Had the term been embedded as a query instead, the
// cosines would sit on the search scale (MinVectorScore, 0.65) and reusing 0.85
// would silently filter out nearly everything. That distinction is the reason the
// matched-posts email sat at 0.60 with a precision of 0.49 for as long as it did.
//
// Recall is traded away on purpose: a missed match costs one email nobody ever
// sees, a junk match teaches somebody to ignore the next one.
func SearchMatchesForPost(c *fiber.Ctx) error {
	id, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil || id == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid message id")
	}

	limit := 10
	if v := c.QueryInt("limit"); v > 0 && v <= 100 {
		limit = v
	}

	// The post's own subject vector, from the in-memory index where possible.
	var srcVec []float32
	var srcFromuser uint64

	if e, ok := embedding.Global.FindByMsgid(id); ok {
		srcVec = e.SubjectVec[:]
		srcFromuser = e.Fromuser
	} else {
		var row struct {
			SubjectEmbedding []byte `gorm:"column:subject_embedding"`
			Fromuser         uint64 `gorm:"column:fromuser"`
		}
		database.DBConn.Table("messages_embeddings me").
			Select("me.subject_embedding, m.fromuser").
			Joins("INNER JOIN messages m ON m.id = me.msgid").
			Where("me.msgid = ?", id).
			Scan(&row)

		if len(row.SubjectEmbedding) == 0 {
			return c.JSON([]SearchMatch{})
		}

		vec, decErr := embedding.DecodeVector(row.SubjectEmbedding)
		if decErr != nil {
			return c.JSON([]SearchMatch{})
		}
		srcVec = vec
		srcFromuser = row.Fromuser
	}

	// Saved searches are few enough to score directly - there is no separate
	// index for them, and building one for a table this size would cost more
	// than it saved.
	var rows []struct {
		Searchid      uint64 `gorm:"column:searchid"`
		Userid        uint64 `gorm:"column:userid"`
		Term          string `gorm:"column:term"`
		TermEmbedding []byte `gorm:"column:term_embedding"`
	}

	database.DBConn.Table("users_searches_embeddings e").
		Select("e.searchid, s.userid, s.term, e.term_embedding").
		Joins("INNER JOIN users_searches s ON s.id = e.searchid").
		Where("s.deleted = 0").
		Where("s.userid <> ?", srcFromuser).
		Scan(&rows)

	matches := []SearchMatch{}

	for _, r := range rows {
		vec, err := embedding.DecodeVector(r.TermEmbedding)
		if err != nil || len(vec) != len(srcVec) {
			continue
		}

		score := cosine(srcVec, vec)
		if score < MinMatchedPostScore {
			continue
		}

		matches = append(matches, SearchMatch{
			Userid:   r.Userid,
			Searchid: r.Searchid,
			Term:     r.Term,
			Score:    score,
		})
	}

	// Best first, so a caller taking the top N takes the best N.
	for i := 1; i < len(matches); i++ {
		for j := i; j > 0 && matches[j].Score > matches[j-1].Score; j-- {
			matches[j], matches[j-1] = matches[j-1], matches[j]
		}
	}

	if len(matches) > limit {
		matches = matches[:limit]
	}

	return c.JSON(matches)
}

// cosine of two equal-length vectors. The stored embeddings are normalised when
// written, but this does not assume it - an unnormalised vector would otherwise
// score arbitrarily high and walk straight through the threshold.
func cosine(a, b []float32) float64 {
	var dot, na, nb float64

	for i := range a {
		dot += float64(a[i]) * float64(b[i])
		na += float64(a[i]) * float64(a[i])
		nb += float64(b[i]) * float64(b[i])
	}

	if na == 0 || nb == 0 {
		return 0
	}

	return dot / (math.Sqrt(na) * math.Sqrt(nb))
}
