package rippling

import (
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/gofiber/fiber/v2"
)

// DensityBand is how posts in one local-density band fared.
//
// A post's reach budget is chosen from how thinly freeglers are spread around
// its origin: shorter than the flat cap in cities, longer in the country (see
// the batch side, App\Services\Ripple\DensityService). Every question that
// choice raises is a comparison BETWEEN bands, and this is that comparison.
//
// Read it as three separate claims, because a band can move on one and not the
// others and each means something different:
//
//   - Reach:   did the cap actually bind? CapMinutes is what was asked for,
//     AvgDriveMin what the road network and the audience governor
//     allowed. A dense band whose AvgDriveMin sits well under its
//     CapMinutes was never being held back by the cap in the first
//     place, so shortening it changes nothing and the rest of the row
//     says nothing about the cap either.
//   - Demand:  ReplyRate is the share of posts that got any reply at all. This
//     is what a SHORTER cap risks: fewer people told, so fewer replies.
//   - Outcome: TakenRate is the share that ended up rehomed, which is the only
//     one that matters on its own. A longer rural cap that lifts
//     ReplyRate but not TakenRate has bought mail, not reuse.
//
// Held and Released come last and are the cost side: replies that had to wait
// because they arrived from outside the reach. A cap that is too short shows up
// here first, as held replies rising while TakenRate does not.
type DensityBand struct {
	// Band is 'dense', 'medium', 'sparse', or 'unknown' where no measurement was
	// made (spatial server unreachable, or the killswitch on). 'unknown' rows are
	// on the flat cap and are NOT a fourth density - keep them visible rather than
	// folded away, because a growing 'unknown' means the measurement is failing.
	Band string `json:"band"`

	// Posts is how many posts started rippling in this band in the window.
	Posts int64 `json:"posts"`

	// CapMinutes is the drive-time budget asked for; AvgDriveMin is what the
	// schedule actually reached. See the type comment: they answer different
	// questions and the gap between them is the interesting part.
	//
	// Explicit column tags on every multi-word field below. GORM derives
	// snake_case from the field name (CapMinutes -> cap_minutes), which does not
	// match these one-word SQL aliases, and a Scan mismatch does not error - it
	// leaves the field at its zero value. A dashboard of confident zeroes is worse
	// than one that fails.
	CapMinutes  float64 `json:"capminutes" gorm:"column:capminutes"`
	AvgDriveMin float64 `json:"avgdrivemin" gorm:"column:avgdrivemin"`

	// AvgRadiusMiles is the measured density itself - the radius holding the
	// nearest k freeglers. Kept so the band thresholds can be re-cut against real
	// data without re-measuring anything.
	AvgRadiusMiles float64 `json:"avgradiusmiles" gorm:"column:avgradiusmiles"`

	// AvgAudience is the mean number of freeglers the reach covered. The lever
	// moves this directly, so it is how you tell a cap change took effect at all.
	AvgAudience float64 `json:"avgaudience" gorm:"column:avgaudience"`

	// Replied/Taken are post counts, not rates, so the caller can pool bands.
	Replied int64 `json:"replied"`
	Taken   int64 `json:"taken"`

	// Held is replies held because they came from outside the reach; Released is
	// those since delivered. Both counted against posts in this band.
	Held     int64 `json:"held"`
	Released int64 `json:"released"`
}

// DensityResponse is the whole comparison plus the window it covers.
type DensityResponse struct {
	Start string        `json:"start"`
	End   string        `json:"end"`
	Bands []DensityBand `json:"bands"`
}

// Density reports, per local-density band, how the posts sized by that band's
// reach budget actually did.
//
// Anchored on rippling_reach.created_at rather than the post date, because the
// band is a property of the reach row and a post has no band until it starts
// rippling.
//
// A caveat that has to travel with these numbers: a withdrawn or deleted post
// loses its reach row, so it drops out of every column here. TakenRate is
// therefore an overestimate in all bands. It is only safe to compare bands with
// each other, not to read any single figure as the true rate - and only as long
// as nothing makes withdrawal differ BY band.
//
// @Summary Reach outcomes by local freegler density
// @Description Posts, reach budget asked for vs reached, audience, reply and taken counts, and held replies, per density band.
// @Tags rippling
// @Produce json
// @Security BearerAuth
// @Param days query int false "Window length in days (default 30, max 365)"
// @Success 200 {object} rippling.DensityResponse
// @Failure 401 {object} fiber.Error "Authentication required"
// @Failure 403 {object} fiber.Error "Support or Admin role required"
// @Router /rippling/density [get]
func Density(c *fiber.Ctx) error {
	days := c.QueryInt("days", 30)
	if days < 1 || days > 365 {
		days = 30
	}

	end := time.Now()
	start := end.AddDate(0, 0, -days)
	startStr := start.Format("2006-01-02 15:04:05")
	endStr := end.Format("2006-01-02 15:04:05")

	db := database.DBConn

	// Everything in one grouped pass. The per-post existence tests are correlated
	// subqueries rather than joins on purpose: a post has many chat messages and
	// many held replies, and joining them would multiply the reach rows before the
	// aggregate ever ran, inflating Posts and every average with it.
	bands := []DensityBand{}
	db.Table("rippling_reach rr").
		Select("COALESCE(rr.density_band, 'unknown') AS band, "+
			"COUNT(*) AS posts, "+
			"AVG(COALESCE(rr.max_minutes_cap, 0)) AS capminutes, "+
			"AVG(COALESCE(rr.max_drive_min, 0)) AS avgdrivemin, "+
			"AVG(COALESCE(rr.density_radius_miles, 0)) AS avgradiusmiles, "+
			"AVG(COALESCE(rr.total_freeglers, 0)) AS avgaudience, "+
			"SUM(EXISTS (SELECT 1 FROM chat_messages cm "+
			"            WHERE cm.refmsgid = rr.msgid AND cm.type = 'Interested')) AS replied, "+
			"SUM(EXISTS (SELECT 1 FROM messages_outcomes mo "+
			"            WHERE mo.msgid = rr.msgid AND mo.outcome IN ('Taken', 'Received'))) AS taken, "+
			"COALESCE(SUM((SELECT COUNT(*) FROM rippling_held_replies h "+
			"              WHERE h.msgid = rr.msgid)), 0) AS held, "+
			"COALESCE(SUM((SELECT COUNT(*) FROM rippling_held_replies h "+
			"              WHERE h.msgid = rr.msgid AND h.status = 'released')), 0) AS released").
		Where("rr.created_at BETWEEN ? AND ?", startStr, endStr).
		Group("band").
		Order("posts DESC").
		Scan(&bands)

	return c.JSON(DensityResponse{
		Start: startStr,
		End:   endStr,
		Bands: bands,
	})
}
