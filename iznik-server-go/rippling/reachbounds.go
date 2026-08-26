package rippling

import (
	"sync"

	"gorm.io/gorm"
)

// Shared sandwich-bounds SQL for the reach containment tests
// (plans/2026-07-17-db3-cpu-reach-sql-prefilter.md). rippling_reach.polygon averages
// ~11k vertices / 178 KB; the bounds live as SAME-ROW columns (outer_bound NOT NULL and
// spatially indexed, inner_bound nullable), written in the same statement as the polygon
// so no timing window can exist between them.
//
// Sentinel ladder for outer_bound, which every consumer here relies on:
//
//	real bound   — cheap reject/accept work; browse drives its R-tree from it
//	ST_Envelope  — derivation failed: the MBR still finds the row, the exact
//	               polygon decides (correct, just less pruning)
//	POINT        — completed posts ONLY: pruned from the browse R-tree entirely;
//	               consumers that still serve completed posts (digest came-and-went,
//	               held replies) treat POINT as "no bounds info" and use the exact test
//
// The exact polygon is referenced ONLY inside correlated EXISTS arms (measured MySQL
// executor behaviour: lazy BLOB fetch does not cross OR items, so a direct reference
// in an OR arm would fetch it for every evaluated row).
//
// This lives in package rippling because it is imported by chat, message AND isochrone
// without cycles (isochrone imports message, so neither of those can host it).

// exactReachExists is the shared correlated-EXISTS arm testing the exact reach
// geometry. With share, the geometry may live in rippling_reach_geom
// (content-addressed dedup): PK join + COALESCE keeps the same lazy-BLOB shape
// whether the row is deduped, drained or untouched. The driver predicates on
// outer_bound/inner_bound around it never change - they are what the browse
// R-tree plan hangs off, and the 2026-08-21 outage is what perturbing them
// looks like.
func exactReachExists(share bool) string {
	if share {
		return "EXISTS (SELECT 1 FROM rippling_reach r2 LEFT JOIN rippling_reach_geom g2 ON g2.hash = r2.polygon_hash " +
			"WHERE r2.msgid = rr.msgid AND ST_Contains(COALESCE(g2.geom, r2.polygon), ST_SRID(POINT(?, ?), ?)))"
	}
	return "EXISTS (SELECT 1 FROM rippling_reach r2 WHERE r2.msgid = rr.msgid AND ST_Contains(r2.polygon, ST_SRID(POINT(?, ?), ?)))"
}

// ReachInReachExpr returns a boolean SQL expression — true when rr's reach contains the
// point — for consumers that may serve COMPLETED posts (single-point gates): a POINT
// outer falls back to the exact polygon rather than rejecting. Consumes FOUR
// (lng, lat, srid) triples. share is GeomShareReady(db) at the call site.
func ReachInReachExpr(share bool, lng, lat float64, srid int) (string, []interface{}) {
	exact := exactReachExists(share)
	expr := "((ST_GeometryType(rr.outer_bound) <> 'POINT' AND ST_Contains(rr.outer_bound, ST_SRID(POINT(?, ?), ?)) " +
		"AND (COALESCE(ST_Contains(rr.inner_bound, ST_SRID(POINT(?, ?), ?)), 0) = 1 " +
		"OR " + exact + ")) " +
		"OR (ST_GeometryType(rr.outer_bound) = 'POINT' AND " + exact + "))"
	args := make([]interface{}, 0, 12)
	for i := 0; i < 4; i++ {
		args = append(args, lng, lat, srid)
	}
	return expr, args
}

// ReachBrowseWhere returns the browse-feed containment conjuncts (leading "AND ..."):
// the R-tree is DRIVEN from the small outer_bound index — the design's target shape —
// so degraded (POINT) bounds of completed posts are pruned by the index itself and no
// POINT special-case is needed (browse additionally filters ms.successful = 0).
// Consumes FOUR (lng, lat, srid) triples. share is GeomShareReady(db) at the call site.
func ReachBrowseWhere(share bool, lng, lat float64, srid int) (string, []interface{}) {
	where := "AND MBRContains(rr.outer_bound, ST_SRID(POINT(?, ?), ?)) " +
		"AND ST_Contains(rr.outer_bound, ST_SRID(POINT(?, ?), ?)) " +
		"AND (COALESCE(ST_Contains(rr.inner_bound, ST_SRID(POINT(?, ?), ?)), 0) = 1 " +
		"OR " + exactReachExists(share) + ") "
	args := make([]interface{}, 0, 12)
	for i := 0; i < 4; i++ {
		args = append(args, lng, lat, srid)
	}
	return where, args
}

// ReachOuterOnlyWhere is the browse containment reduced to the outer bound
// alone (leading "AND ..."): a SUPERSET of the reach, for the degraded path
// where neither the spatial index nor the legacy exact geometry can decide
// (spatial server down after the polygon columns are dropped). Rows passing
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
// deploying this code before the migration keeps the legacy exact-polygon queries;
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
