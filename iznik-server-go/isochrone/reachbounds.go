package isochrone

import (
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
