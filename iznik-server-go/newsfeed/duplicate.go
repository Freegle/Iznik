package newsfeed

import (
	"strconv"
	"strings"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/embedding"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
)

// DuplicateMinCosine is how alike a ChitChat post and one of the member's own
// live posts must be before we tell a moderator they are the same thing.
//
// Set deliberately high. A false positive costs a member their ChitChat post
// (the moderator is being invited to hide it), whereas a false negative just
// leaves the moderator doing what they do today. The same-author restriction
// already removes most of the noise, so the threshold only has to separate
// "the member said this twice" from "the member posted about two similar
// things".
const DuplicateMinCosine = 0.82

// duplicateCandidates is how many neighbours to pull before filtering to the
// post's own author. The store is not author-indexed, so a member's own post
// can sit behind other people's closer matches.
const duplicateCandidates = 200

// DuplicateMatch is a live OFFER/WANTED by the same member that says the same
// thing as their ChitChat post.
type DuplicateMatch struct {
	ID      uint64  `json:"id"`
	Type    string  `json:"type"`
	Subject string  `json:"subject"`
	Cosine  float32 `json:"cosine"`
}

// DuplicateResponse is empty-but-successful when nothing matches, so the
// caller can render "no duplicate" without treating it as an error.
type DuplicateResponse struct {
	Duplicate *DuplicateMatch `json:"duplicate"`
}

// Duplicate reports whether a ChitChat post duplicates one of the same
// member's live OFFER/WANTED posts.
//
// Moderator-only, and gated server-side rather than in the template: the
// answer names one of the member's posts and exists to prompt hiding theirs,
// which is not something other members should see.
//
// @Summary Whether a ChitChat post duplicates the poster's own live OFFER/WANTED
// @Tags newsfeed
// @Produce json
// @Param id path int true "Newsfeed ID"
// @Success 200 {object} newsfeed.DuplicateResponse
// @Router /newsfeed/{id}/duplicate [get]
func Duplicate(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if !canHidePost(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Permission denied")
	}

	id, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid newsfeed id")
	}

	db := database.DBConn

	var nf struct {
		Userid  uint64
		Message string
		Type    string
	}
	db.Table("newsfeed").Select("userid, COALESCE(message, '') AS message, type").Where("id = ?", id).Scan(&nf)

	if nf.Userid == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Newsfeed entry not found")
	}

	match := findDuplicate(nf.Userid, nf.Message)

	return c.JSON(DuplicateResponse{Duplicate: match})
}

// findDuplicate returns the member's own live post closest to the given text,
// or nil. Split out from the handler so it can be exercised without a request.
func findDuplicate(author uint64, text string) *DuplicateMatch {
	if author == 0 || strings.TrimSpace(text) == "" {
		return nil
	}

	// No embedding sidecar, or it is unhappy: report no duplicate rather than
	// failing the request. This is advisory information beside a moderator's
	// existing controls, so degrading to silence is the right failure mode.
	vec, err := embedding.EmbedQuery(text)
	if err != nil || len(vec) == 0 {
		return nil
	}

	// The store holds only OPEN messages, so a match is a post still standing —
	// a member repeating a post they already took down is not a duplicate.
	results := embedding.Global.Search(vec, duplicateCandidates, "", nil, nil, 0, 0, 0, 0)

	var best *DuplicateMatch

	for i := range results {
		r := results[i]

		// A duplicate means THIS member already posted it. Someone else offering
		// a similar item is a different post, not a repeat, and hiding the
		// ChitChat entry over it would be wrong.
		if r.Fromuser != author {
			continue
		}

		cos := r.SubjectCos
		if r.HasBody && r.BodyCos > cos {
			cos = r.BodyCos
		}

		if cos < DuplicateMinCosine {
			continue
		}

		if best == nil || cos > best.Cosine {
			best = &DuplicateMatch{
				ID:      r.Msgid,
				Type:    r.Msgtype,
				Subject: r.Subject,
				Cosine:  cos,
			}
		}
	}

	return best
}
