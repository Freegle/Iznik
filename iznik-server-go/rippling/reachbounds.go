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

// ReachInReachExpr returns a boolean SQL expression — true when rr's reach contains the
// point — for consumers that may serve COMPLETED posts (single-point gates): a POINT
// outer falls back to the exact polygon rather than rejecting. Consumes FOUR
// (lng, lat, srid) triples.
func ReachInReachExpr(lng, lat float64, srid int) (string, []interface{}) {
	expr := "((ST_GeometryType(rr.outer_bound) <> 'POINT' AND ST_Contains(rr.outer_bound, ST_SRID(POINT(?, ?), ?)) " +
		"AND (COALESCE(ST_Contains(rr.inner_bound, ST_SRID(POINT(?, ?), ?)), 0) = 1 " +
		"OR EXISTS (SELECT 1 FROM rippling_reach r2 WHERE r2.msgid = rr.msgid AND ST_Contains(r2.polygon, ST_SRID(POINT(?, ?), ?))))) " +
		"OR (ST_GeometryType(rr.outer_bound) = 'POINT' AND EXISTS (SELECT 1 FROM rippling_reach r2 WHERE r2.msgid = rr.msgid AND ST_Contains(r2.polygon, ST_SRID(POINT(?, ?), ?)))))"
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
// Consumes FOUR (lng, lat, srid) triples.
func ReachBrowseWhere(lng, lat float64, srid int) (string, []interface{}) {
	where := "AND MBRContains(rr.outer_bound, ST_SRID(POINT(?, ?), ?)) " +
		"AND ST_Contains(rr.outer_bound, ST_SRID(POINT(?, ?), ?)) " +
		"AND (COALESCE(ST_Contains(rr.inner_bound, ST_SRID(POINT(?, ?), ?)), 0) = 1 " +
		"OR EXISTS (SELECT 1 FROM rippling_reach r2 WHERE r2.msgid = rr.msgid AND ST_Contains(r2.polygon, ST_SRID(POINT(?, ?), ?)))) "
	args := make([]interface{}, 0, 12)
	for i := 0; i < 4; i++ {
		args = append(args, lng, lat, srid)
	}
	return where, args
}

// RuralOverflowWhere is the rural-access ring as an alternative way in, for a viewer whose own
// density band earns a wider travel budget than the reach the audience cap allowed.
//
// Two tests, cheap one first, and the order is the whole point. On the mail path the rings can
// be read once and bound as parameters, because there is one post; here every candidate row is
// a DIFFERENT post with a different ring, so the exact test has to parse a polygon per row - on
// the path every browse request goes through. The stored bounding box rejects almost every row
// with four numeric comparisons before that happens, which is the same shape as the cheap
// outer/inner check already sitting in front of the exact reach polygon.
//
// jsonPath selects the ring for the viewer's own band and is built by the caller from a fixed
// set, never from anything a member controls. Empty means "this viewer has no band recorded",
// which callers must treat as no overflow at all rather than as a ring that matches anything.
func RuralOverflowWhere(lng, lat float64, srid int, jsonPath string) (string, []interface{}) {
	where := "(rr.overflow_bounds IS NOT NULL " +
		"AND ? BETWEEN JSON_EXTRACT(rr.overflow_bounds, '$.bbox[0]') AND JSON_EXTRACT(rr.overflow_bounds, '$.bbox[2]') " +
		"AND ? BETWEEN JSON_EXTRACT(rr.overflow_bounds, '$.bbox[1]') AND JSON_EXTRACT(rr.overflow_bounds, '$.bbox[3]') " +
		"AND ST_Contains(ST_GeomFromText(JSON_UNQUOTE(JSON_EXTRACT(rr.overflow_bounds, ?)), ?), ST_SRID(POINT(?, ?), ?))) "
	return where, []interface{}{lng, lat, jsonPath, srid, lng, lat, srid}
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
