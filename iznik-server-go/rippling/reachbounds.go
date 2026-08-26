package rippling

import (
	"sync"

	"gorm.io/gorm"
)

// Shared sandwich-bounds SQL for the reach containment tests
// (plans/2026-07-17-db3-cpu-reach-sql-prefilter.md). The bounds live as
// SAME-ROW columns (outer_bound NOT NULL and spatially indexed, inner_bound
// nullable), written in the same statement as the reach grid so no timing
// window can exist between them.
//
// Sentinel ladder for outer_bound, which every consumer here relies on:
//
//	real bound   — cheap reject/accept work; browse drives its R-tree from it
//	ST_Envelope  — derivation failed: the MBR still finds the row and the
//	               stored cells decide (correct, just less pruning)
//	POINT        — completed posts ONLY: pruned from the browse R-tree entirely;
//	               consumers that still serve completed posts (digest came-and-went,
//	               held replies) treat POINT as "no bounds info"
//
// This lives in package rippling because it is imported by chat, message AND isochrone
// without cycles (isochrone imports message, so neither of those can host it).

// ReachOuterOnlyWhere is the browse containment reduced to the outer bound
// alone (leading "AND ..."): a SUPERSET of the reach, for the degraded path
// where the spatial index cannot decide (spatial server down). Rows passing
// it must be probed against their stored cells by the caller. Consumes TWO
// (lng, lat, srid) triples.
func ReachOuterOnlyWhere(lng, lat float64, srid int) (string, []interface{}) {
	return "AND MBRContains(rr.outer_bound, ST_SRID(POINT(?, ?), ?)) " +
			"AND ST_Contains(rr.outer_bound, ST_SRID(POINT(?, ?), ?)) ",
		[]interface{}{lng, lat, srid, lng, lat, srid}
}

var reachBoundsOnce sync.Once
var reachBoundsExists bool

// ReachBoundsReady reports whether the sandwich-bounds columns have been migrated onto
// rippling_reach. Checked once per process (like chat's AttributionSchemaReady):
// deploying this code before the migration keeps the bounds-free queries;
// restart the Go API after the schema migration to pick the sandwich up.
func ReachBoundsReady(db *gorm.DB) bool {
	reachBoundsOnce.Do(func() {
		var n int64
		db.Table("information_schema.COLUMNS").
			Where("table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'outer_bound'").
			Count(&n)
		reachBoundsExists = n > 0
	})
	return reachBoundsExists
}
