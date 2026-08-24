package message

import (
	"os"
	"strconv"
	"strings"

	"github.com/freegle/iznik-server-go/embedding"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
)

// matchBoxDegrees is the half-width of the lat/lng box searched around the
// wanted-poster's chosen location (~15km at UK latitudes). Matches only make
// sense if the offer is realistically collectable.
const matchBoxDegrees = 0.15

// wantedMatchEnabled reports whether wanted→offer matching is on.
// FEATURE_WANTED_MATCH=off is a no-deploy killswitch (default on); when off the
// endpoint returns an empty list so the compose panel just hides.
func wantedMatchEnabled() bool {
	return os.Getenv("FEATURE_WANTED_MATCH") != "off"
}

// Matches returns existing OFFERs near a location that semantically match a
// free-text query — for the "people are offering these near you" panel shown
// while someone composes a WANTED. Unlike /similar (which reuses a stored
// embedding), this embeds the query text via the sidecar because the wanted
// isn't saved yet. Reach-filtered against the given location so a matched offer
// the poster couldn't reply to is never shown; the poster's own offers are
// excluded.
//
// @Router /message/matches [get]
// @Summary Offers matching a wanted being composed (recommendations)
// @Tags message
// @Produce json
// @Param query query string true "Item text of the wanted being posted"
// @Param lat query number true "Poster's chosen latitude"
// @Param lng query number true "Poster's chosen longitude"
// @Param limit query int false "Max results (default 6, max 12)"
// @Success 200 {array} message.SimilarResult
func Matches(c *fiber.Ctx) error {
	if !wantedMatchEnabled() {
		return c.JSON([]SimilarResult{})
	}

	query := strings.TrimSpace(c.Query("query", ""))
	if query == "" {
		return c.JSON([]SimilarResult{})
	}

	lat, _ := strconv.ParseFloat(c.Query("lat", "0"), 64)
	lng, _ := strconv.ParseFloat(c.Query("lng", "0"), 64)
	if lat == 0 && lng == 0 {
		// No usable location → we can't scope or reach-filter, so return nothing
		// rather than surface far-away offers.
		return c.JSON([]SimilarResult{})
	}

	limit := c.QueryInt("limit", 6)
	if limit < 1 {
		limit = 6
	}
	if limit > 12 {
		limit = 12
	}

	queryVec, err := embedding.EmbedQuery(query)
	if err != nil {
		// Embedding unavailable (e.g. sidecar down) → empty, never a 500. The
		// compose flow must never break because matching couldn't run.
		return c.JSON([]SimilarResult{})
	}

	swlat := float32(lat - matchBoxDegrees)
	swlng := float32(lng - matchBoxDegrees)
	nelat := float32(lat + matchBoxDegrees)
	nelng := float32(lng + matchBoxDegrees)
	candidates := embedding.Global.Search(queryVec, limit*3, "Offer", nil, nil, swlat, swlng, nelat, nelng)

	myid := user.WhoAmI(c)

	// Reach-filter against the poster's chosen location. myid so that a member an
	// overflow ring admits is offered the same matches they can see on browse.
	blocked := ReachBlockedSet(myid, candidateMsgids(candidates), lat, lng)

	out := make([]SimilarResult, 0, limit)
	for _, cnd := range candidates {
		if cnd.SubjectCos < MinVectorScore {
			continue // search-grade threshold: this is a match claim, not exploration
		}
		if myid > 0 && cnd.Fromuser == myid {
			continue // don't offer someone their own posts
		}
		if blocked[cnd.Msgid] {
			continue
		}
		blat, blng := utils.Blur(cnd.Lat, cnd.Lng, utils.BLUR_USER)
		out = append(out, SimilarResult{
			Msgid:   cnd.Msgid,
			Groupid: cnd.Groupid,
			Score:   cnd.SubjectCos,
			Lat:     blat,
			Lng:     blng,
		})
		if len(out) >= limit {
			break
		}
	}

	return c.JSON(out)
}

// candidateMsgids extracts the msgids from vector search results.
func candidateMsgids(candidates []embedding.VectorSearchResult) []uint64 {
	ids := make([]uint64, 0, len(candidates))
	for _, c := range candidates {
		ids = append(ids, c.Msgid)
	}
	return ids
}
