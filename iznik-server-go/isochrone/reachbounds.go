package isochrone

import (
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
//
// Which ring, if any, could admit a viewer is decided in rippling/overflowviewer.go,
// shared with search, the message page and the reply gates so that "can this member see
// this post" has one answer everywhere.

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

// viewerOverflowPaths is every ring path that could let this viewer in: their band or
// deprivation ring plus the cluster wedges. See rippling.ViewerOverflowPaths.
func viewerOverflowPaths(db *gorm.DB, myid uint64, lat, lng float32) []string {
	return rippling.ViewerOverflowPaths(db, myid, lat, lng)
}

// reachOrOverflowSQL is reachContainmentSQL plus, when any overflow ring applies to this
// viewer, the rings as alternative ways in. Returned as ONE conjunct so it can be spliced
// into the single concatenated WHERE the browse query builds - see the note in
// reachCandidateQuery about why that query cannot be split across several Where() calls.
func reachOrOverflowSQL(db *gorm.DB, myid uint64, lng, lat float32) (string, []interface{}) {
	reachWhere, reachArgs := reachContainmentSQL(db, lng, lat)

	return composeReachOverflow(reachWhere, reachArgs, lng, lat,
		viewerOverflowPaths(db, myid, lat, lng)...)
}

// composeReachOverflow brackets the reach test and the rings as ALTERNATIVES within one
// conjunct. Kept separate from the database lookup above so the SQL shape can be tested
// directly - the shape is where this fails silently, since a stray keyword or a
// mis-ordered parameter still runs and still returns a plausible feed.
//
// No paths returns the containment SQL untouched, byte for byte: a lane nobody is using
// must not change the query the feed has always run, nor its cost.
func composeReachOverflow(reachWhere string, reachArgs []interface{}, lng, lat float32, paths ...string) (string, []interface{}) {
	overflowWhere, overflowArgs := rippling.OverflowWhereAny(float64(lng), float64(lat), utils.SRID, paths)
	if overflowWhere == "" {
		return reachWhere, reachArgs
	}

	// reachWhere is a run of conjuncts opening with "AND ", so that keyword is stripped and
	// re-applied around the pair; wrapping without stripping would leave "AND ((AND ...".
	// The outer brackets are load-bearing: without them the OR would escape the conjunct and
	// dissolve every other filter in the WHERE.
	inner := strings.TrimSpace(strings.TrimPrefix(strings.TrimSpace(reachWhere), "AND"))

	return "AND ((" + inner + ") OR " + overflowWhere + ") ",
		append(append([]interface{}{}, reachArgs...), overflowArgs...)
}
