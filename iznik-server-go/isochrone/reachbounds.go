package isochrone

import (
	"os"
	"strconv"
	"strings"

	"github.com/freegle/iznik-server-go/rippling"
	"github.com/freegle/iznik-server-go/utils"
	"gorm.io/gorm"
)

// Sandwich-bounds prefilter for the browse reach queries — see
// rippling/reachbounds.go for the shared fragments, the sentinel ladder and the design
// rationale (plans/2026-07-17-db3-cpu-reach-sql-prefilter.md). The browse form drives
// the R-tree from the small indexed outer_bound column (the design's target shape), so
// completed posts — degraded to POINT bounds — are pruned by the index itself.

// reachContainmentSQL returns the WHERE fragment (and its point parameters) for
// testing whether the viewer point lies inside rr's reach: the sandwich form when the
// bounds columns exist, else the legacy exact-polygon test. Callers splice it in place
// of the old ST_Contains conjunct.
func reachContainmentSQL(db *gorm.DB, lng, lat float32) (where string, args []interface{}) {
	if rippling.ReachBoundsReady(db) {
		return rippling.ReachBrowseWhere(float64(lng), float64(lat), utils.SRID)
	}

	return "AND ST_Contains(rr.polygon, ST_SRID(POINT(?, ?), ?)) ", []interface{}{lng, lat, utils.SRID}
}

// ruralBandPaths maps a member's recorded density band to the ring that band earns. Built from
// a fixed set so that a settings value can never become part of a JSON path: the band is looked
// UP here, never interpolated.
var ruralBandPaths = map[string]string{
	"dense":  "$.rural.dense",
	"medium": "$.rural.medium",
	"sparse": "$.rural.sparse",
}

// ruralOverflowEnabled reports whether the rural-access lane is switched on for reads. Kept as
// its own check rather than folded into the band lookup so that "the lane is off" and "this
// viewer has no band" stay distinguishable when something looks wrong.
func ruralOverflowEnabled() bool {
	v := strings.ToLower(strings.TrimSpace(os.Getenv("RIPPLE_RURAL_ACCESS_ENABLED")))
	return v == "1" || v == "true" || v == "yes"
}

// viewerRuralPath is the ring path for this viewer, or "" when the lane cannot apply: switched
// off, no viewer, or no band recorded for them.
//
// A member with no band recorded gets NOTHING extra, which is the safe direction. The band is
// written by the batch backfill, so before that has run - or for a member it has not reached -
// treating absence as "matches anything" would widen the feed for the whole membership the
// moment the lane was switched on.
func viewerRuralPath(db *gorm.DB, myid uint64) string {
	if myid == 0 || !ruralOverflowEnabled() {
		return ""
	}

	var band string
	// COALESCE for the same reason as resolveMaxDistance: a member who has never had the key
	// written scans cleanly into a non-nullable string instead of erroring.
	db.Table("users").
		Select("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings, '$.browseDensityBand')), '') AS band").
		Where("id = ?", myid).
		Scan(&band)

	return ruralBandPaths[band]
}

// fairnessOverflowEnabled reports whether the deprivation lane is switched on for reads.
func fairnessOverflowEnabled() bool {
	v := strings.ToLower(strings.TrimSpace(os.Getenv("RIPPLE_FAIRNESS_ENABLED")))
	return v == "1" || v == "true" || v == "yes"
}

// fairnessMaxQuintile is how far down the deprivation scale the lane reaches, clamped to the
// range the rings are built for. Must agree with the batch: a viewer admitted by browse and
// refused by the mail, or the other way round, is worse than either behaviour on its own.
func fairnessMaxQuintile() int {
	q, err := strconv.Atoi(strings.TrimSpace(os.Getenv("RIPPLE_FAIRNESS_MAX_QUINTILE")))
	if err != nil || q < 1 {
		return 1
	}
	if q > 4 {
		return 4
	}
	return q
}

// viewerFairnessPath is the ring path for this viewer's deprivation fifth, or "" when the lane
// cannot apply to them.
//
// The fifth comes from the spatial server rather than the database, because that is the only
// place it exists - and asking keeps it that way: nothing records what fifth anyone is in. One
// request per feed load, not per post, and only when the lane is on.
//
// Any failure yields "", so an outage costs the lane its extra posts rather than showing
// everyone inside a stretched ring - which is the behaviour the fifth exists to prevent.
func viewerFairnessPath(lat, lng float32) string {
	if !fairnessOverflowEnabled() {
		return ""
	}

	q := rippling.QuintileFor(float64(lat), float64(lng))
	if q < 1 || q > fairnessMaxQuintile() {
		return ""
	}

	// Quoted: a JSON path member that is a number is not a bare identifier, so
	// $.fairness.1 does not address the ring - it silently matches nothing.
	return "$.fairness.\"" + strconv.Itoa(q) + "\""
}

// viewerOverflowPath is the one place browse decides which ring, if any, could let this viewer
// in. Rural first, because it is answered from a column already to hand; the deprivation lane
// only then, so its network call is not paid by viewers the rural ring has already admitted.
//
// A post carries rings from one lane only, so at most one of these can ever match.
func viewerOverflowPath(db *gorm.DB, myid uint64, lat, lng float32) string {
	if p := viewerRuralPath(db, myid); p != "" {
		return p
	}
	return viewerFairnessPath(lat, lng)
}

// reachOrOverflowSQL is reachContainmentSQL plus, when the rural lane applies to this viewer,
// the ring as an alternative way in. Returned as ONE conjunct so it can be spliced into the
// single concatenated WHERE the browse query builds - see the note in reachCandidateQuery about
// why that query cannot be split across several Where() calls.
func reachOrOverflowSQL(db *gorm.DB, myid uint64, lng, lat float32) (string, []interface{}) {
	reachWhere, reachArgs := reachContainmentSQL(db, lng, lat)

	return composeReachOverflow(reachWhere, reachArgs, lng, lat, viewerOverflowPath(db, myid, lat, lng))
}

// composeReachOverflow brackets the reach test and the ring as ALTERNATIVES within one
// conjunct. Kept separate from the database lookup above so the SQL shape can be tested
// directly - the shape is where this fails silently, since a stray keyword or a mis-ordered
// parameter still runs and still returns a plausible feed.
//
// An empty path returns the containment SQL untouched, byte for byte: a lane nobody is using
// must not change the query the feed has always run, nor its cost.
func composeReachOverflow(reachWhere string, reachArgs []interface{}, lng, lat float32, path string) (string, []interface{}) {
	if path == "" {
		return reachWhere, reachArgs
	}

	overflowWhere, overflowArgs := rippling.RuralOverflowWhere(float64(lng), float64(lat), utils.SRID, path)

	// reachWhere is a run of conjuncts opening with "AND ", so that keyword is stripped and
	// re-applied around the pair; wrapping without stripping would leave "AND ((AND ...".
	// The outer brackets are load-bearing: without them the OR would escape the conjunct and
	// dissolve every other filter in the WHERE.
	inner := strings.TrimSpace(strings.TrimPrefix(strings.TrimSpace(reachWhere), "AND"))

	return "AND ((" + inner + ") OR " + overflowWhere + ") ",
		append(append([]interface{}{}, reachArgs...), overflowArgs...)
}
