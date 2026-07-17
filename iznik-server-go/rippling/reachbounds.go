package rippling

import (
	"sync"

	"gorm.io/gorm"
)

// Shared sandwich-bounds SQL for the reach containment tests
// (plans/2026-07-17-db3-cpu-reach-sql-prefilter.md). rippling_reach.polygon averages
// ~11k vertices / 178 KB, so every reach consumer — browse (package isochrone), the
// message-list replyeligible probe (package message), the chat reply hold (package chat)
// — consults the small verified bounds in rippling_reach_bounds first: outside
// outer_bound is an authoritative reject, inside inner_bound an authoritative accept,
// and only the band between them touches the exact polygon, via a correlated EXISTS
// (measured MySQL executor behaviour: lazy BLOB fetch does not cross OR items, so any
// direct reference to the polygon in an OR arm would fetch it for every evaluated row).
//
// This lives in package rippling because it is imported by chat, message AND isochrone
// without cycles (isochrone imports message, so neither of those can host it).
//
// The join skips DEGRADED bounds (outer collapsed to a POINT when a post completes):
// they mean "stop matching cheaply in browse", and consumers that still serve completed
// posts (digest came-and-went, held replies to taken posts) must fall back to the exact
// polygon for them. A missing bounds row falls back to the exact polygon, so every
// rollout state is correct.

// ReachBoundsJoin pairs each reach row (alias rr required) with its non-degraded
// sandwich bounds (alias b).
const ReachBoundsJoin = "LEFT JOIN rippling_reach_bounds b ON b.msgid = rr.msgid AND ST_GeometryType(b.outer_bound) <> 'POINT' "

// ReachInReachExpr returns a boolean SQL expression — true when rr's reach contains the
// point — plus its args. Requires ReachBoundsJoin in the query. The exact polygon is
// referenced ONLY inside correlated EXISTS arms. Consumes FOUR (lng, lat, srid) triples.
func ReachInReachExpr(lng, lat float64, srid int) (string, []interface{}) {
	expr := "((b.msgid IS NOT NULL AND ST_Contains(b.outer_bound, ST_SRID(POINT(?, ?), ?)) " +
		"AND (COALESCE(ST_Contains(b.inner_bound, ST_SRID(POINT(?, ?), ?)), 0) = 1 " +
		"OR EXISTS (SELECT 1 FROM rippling_reach r2 WHERE r2.msgid = rr.msgid AND ST_Contains(r2.polygon, ST_SRID(POINT(?, ?), ?))))) " +
		"OR (b.msgid IS NULL AND EXISTS (SELECT 1 FROM rippling_reach r2 WHERE r2.msgid = rr.msgid AND ST_Contains(r2.polygon, ST_SRID(POINT(?, ?), ?)))))"
	args := make([]interface{}, 0, 12)
	for i := 0; i < 4; i++ {
		args = append(args, lng, lat, srid)
	}
	return expr, args
}

var reachBoundsOnce sync.Once
var reachBoundsExists bool

// ReachBoundsReady reports whether the rippling_reach_bounds table has been migrated.
// Checked once per process (like chat's AttributionSchemaReady): deploying this code
// before the migration keeps the legacy exact-polygon queries; restart the Go API after
// running the migration to pick the sandwich up.
func ReachBoundsReady(db *gorm.DB) bool {
	reachBoundsOnce.Do(func() {
		var n int
		db.Raw("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'rippling_reach_bounds'").Scan(&n)
		reachBoundsExists = n > 0
	})
	return reachBoundsExists
}
