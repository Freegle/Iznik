package rippling

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

// ReachInReachExpr returns a single boolean SQL expression covering BOTH the
// real-bound path (outer_bound is a proper polygon: cheap containment test,
// falling to inner_bound / exact polygon EXISTS) and the POINT-sentinel path
// (degraded outer_bound on a completed post: skip straight to the exact
// polygon EXISTS). Golden-string assert so any change to either shape is
// visible in the diff.
//
// share=false must be BYTE-FOR-BYTE the pre-dedup shape (plans/2026-08-23-
// rippling-reach-polygon-dedup.md): a deploy ahead of the migration, or one
// that never enables it, must run exactly the query that shipped before.
func TestReachInReachExpr_GoldenSQLAndArgs_NotShared(t *testing.T) {
	expr, args := ReachInReachExpr(false, -0.1, 51.5, 4326)

	wantExpr := "((ST_GeometryType(rr.outer_bound) <> 'POINT' AND ST_Contains(rr.outer_bound, ST_SRID(POINT(?, ?), ?)) " +
		"AND (COALESCE(ST_Contains(rr.inner_bound, ST_SRID(POINT(?, ?), ?)), 0) = 1 " +
		"OR EXISTS (SELECT 1 FROM rippling_reach r2 WHERE r2.msgid = rr.msgid AND ST_Contains(r2.polygon, ST_SRID(POINT(?, ?), ?))))) " +
		"OR (ST_GeometryType(rr.outer_bound) = 'POINT' AND EXISTS (SELECT 1 FROM rippling_reach r2 WHERE r2.msgid = rr.msgid AND ST_Contains(r2.polygon, ST_SRID(POINT(?, ?), ?)))))"
	assert.Equal(t, wantExpr, expr)
	assert.NotContains(t, expr, "rippling_reach_geom")

	// Sanity check both branches are textually present: the real-bound path
	// (ST_Contains against outer_bound directly) and the POINT-sentinel path
	// (falls straight to the exact-polygon EXISTS).
	assert.Contains(t, expr, "ST_GeometryType(rr.outer_bound) <> 'POINT'")
	assert.Contains(t, expr, "ST_GeometryType(rr.outer_bound) = 'POINT'")

	// Four (lng, lat, srid) triples, in order.
	wantArgs := []interface{}{
		-0.1, 51.5, 4326,
		-0.1, 51.5, 4326,
		-0.1, 51.5, 4326,
		-0.1, 51.5, 4326,
	}
	assert.Equal(t, wantArgs, args)
	assert.Len(t, args, 12)
}

// share=true reads the exact polygon through the same PK join + COALESCE as
// every other exact-polygon test (content-addressed dedup): the SHARED row
// when r2.polygon_hash points at one, the local blob otherwise. Everything
// outside that EXISTS - the outer_bound / inner_bound conjuncts that decide
// the branch - is untouched, so this changes nothing about which branch runs,
// only how the exact test inside it resolves the geometry.
func TestReachInReachExpr_GoldenSQLAndArgs_Shared(t *testing.T) {
	expr, args := ReachInReachExpr(true, -0.1, 51.5, 4326)

	exactExists := "EXISTS (SELECT 1 FROM rippling_reach r2 LEFT JOIN rippling_reach_geom g2 ON g2.hash = r2.polygon_hash " +
		"WHERE r2.msgid = rr.msgid AND ST_Contains(COALESCE(g2.geom, r2.polygon), ST_SRID(POINT(?, ?), ?)))"
	wantExpr := "((ST_GeometryType(rr.outer_bound) <> 'POINT' AND ST_Contains(rr.outer_bound, ST_SRID(POINT(?, ?), ?)) " +
		"AND (COALESCE(ST_Contains(rr.inner_bound, ST_SRID(POINT(?, ?), ?)), 0) = 1 " +
		"OR " + exactExists + ")) " +
		"OR (ST_GeometryType(rr.outer_bound) = 'POINT' AND " + exactExists + "))"
	assert.Equal(t, wantExpr, expr)

	// The outer_bound driver conjunct - the one the browse R-tree plan hangs
	// off - must be IDENTICAL to the not-shared shape: only the exact-polygon
	// EXISTS arm may change.
	assert.Contains(t, expr, "ST_GeometryType(rr.outer_bound) <> 'POINT' AND ST_Contains(rr.outer_bound, ST_SRID(POINT(?, ?), ?))")
	assert.Contains(t, expr, "ST_GeometryType(rr.outer_bound) = 'POINT'")

	wantArgs := []interface{}{
		-0.1, 51.5, 4326,
		-0.1, 51.5, 4326,
		-0.1, 51.5, 4326,
		-0.1, 51.5, 4326,
	}
	assert.Equal(t, wantArgs, args)
	assert.Len(t, args, 12)
}

func TestReachInReachExpr_ArgsScaleWithDistinctInputs(t *testing.T) {
	// Distinct (non-repeating) inputs make it obvious the same triple is used
	// for all four placeholders rather than some other combination. Checked
	// for both share states: the arg SHAPE (four repeated triples) is the
	// same either way, only the expression text differs.
	for _, share := range []bool{false, true} {
		expr, args := ReachInReachExpr(share, 1.23, -33.87, 3857)
		assert.NotEmpty(t, expr)
		for i := 0; i < 4; i++ {
			base := i * 3
			assert.Equal(t, 1.23, args[base])
			assert.Equal(t, -33.87, args[base+1])
			assert.Equal(t, 3857, args[base+2])
		}
	}
}

// ReachBrowseWhere returns the browse-feed containment conjuncts: the R-tree
// is driven from the MBRContains(outer_bound, ...) prefilter, then the exact
// ST_Contains(outer_bound, ...) test, then inner_bound / exact-polygon EXISTS.
// Unlike ReachInReachExpr there is no POINT special case here (browse relies
// on the index pruning degraded bounds away entirely).
//
// share=false must be BYTE-FOR-BYTE the pre-dedup shape: this is the exact
// query the 2026-08-21 outage hit (splicing an unrelated JSON test into it
// dropped the driving index), so its unmigrated form must never move.
func TestReachBrowseWhere_GoldenSQLAndArgs_NotShared(t *testing.T) {
	where, args := ReachBrowseWhere(false, -0.1, 51.5, 4326)

	wantWhere := "AND MBRContains(rr.outer_bound, ST_SRID(POINT(?, ?), ?)) " +
		"AND ST_Contains(rr.outer_bound, ST_SRID(POINT(?, ?), ?)) " +
		"AND (COALESCE(ST_Contains(rr.inner_bound, ST_SRID(POINT(?, ?), ?)), 0) = 1 " +
		"OR EXISTS (SELECT 1 FROM rippling_reach r2 WHERE r2.msgid = rr.msgid AND ST_Contains(r2.polygon, ST_SRID(POINT(?, ?), ?)))) "
	assert.Equal(t, wantWhere, where)
	assert.NotContains(t, where, "rippling_reach_geom")

	assert.Contains(t, where, "MBRContains(rr.outer_bound")
	assert.Contains(t, where, "ST_Contains(rr.outer_bound")
	assert.True(t, len(where) > 0 && where[0] == 'A', "must start with a leading AND so callers can splice it directly onto a WHERE clause")

	wantArgs := []interface{}{
		-0.1, 51.5, 4326,
		-0.1, 51.5, 4326,
		-0.1, 51.5, 4326,
		-0.1, 51.5, 4326,
	}
	assert.Equal(t, wantArgs, args)
	assert.Len(t, args, 12)
}

// share=true must keep the browse driver conjuncts (MBRContains/ST_Contains
// against outer_bound) IDENTICAL to the not-shared shape - that is the whole
// point of the design (plans/2026-08-23-rippling-reach-polygon-dedup.md
// Category 1): the browse feed's index access path never changes, only the
// exact-polygon fallback arm reads through the PK join + COALESCE.
func TestReachBrowseWhere_GoldenSQLAndArgs_Shared(t *testing.T) {
	where, args := ReachBrowseWhere(true, -0.1, 51.5, 4326)

	wantWhere := "AND MBRContains(rr.outer_bound, ST_SRID(POINT(?, ?), ?)) " +
		"AND ST_Contains(rr.outer_bound, ST_SRID(POINT(?, ?), ?)) " +
		"AND (COALESCE(ST_Contains(rr.inner_bound, ST_SRID(POINT(?, ?), ?)), 0) = 1 " +
		"OR EXISTS (SELECT 1 FROM rippling_reach r2 LEFT JOIN rippling_reach_geom g2 ON g2.hash = r2.polygon_hash " +
		"WHERE r2.msgid = rr.msgid AND ST_Contains(COALESCE(g2.geom, r2.polygon), ST_SRID(POINT(?, ?), ?)))) "
	assert.Equal(t, wantWhere, where)

	// The exact byte prefix shared with the not-shared variant: the driver
	// conjuncts that decide the query plan.
	wantDriverPrefix := "AND MBRContains(rr.outer_bound, ST_SRID(POINT(?, ?), ?)) " +
		"AND ST_Contains(rr.outer_bound, ST_SRID(POINT(?, ?), ?)) " +
		"AND (COALESCE(ST_Contains(rr.inner_bound, ST_SRID(POINT(?, ?), ?)), 0) = 1 " +
		"OR EXISTS (SELECT 1 FROM rippling_reach r2"
	assert.Contains(t, where, wantDriverPrefix)
	assert.Contains(t, where, "LEFT JOIN rippling_reach_geom g2 ON g2.hash = r2.polygon_hash")
	assert.Contains(t, where, "ST_Contains(COALESCE(g2.geom, r2.polygon), ST_SRID(POINT(?, ?), ?))")

	wantArgs := []interface{}{
		-0.1, 51.5, 4326,
		-0.1, 51.5, 4326,
		-0.1, 51.5, 4326,
		-0.1, 51.5, 4326,
	}
	assert.Equal(t, wantArgs, args)
	assert.Len(t, args, 12)
}

// The MBRContains/ST_Contains(outer_bound, ...) driver conjuncts - what the
// browse R-tree plan actually hangs off - must be byte-identical between the
// two share states. This is the specific claim the dedup plan makes and the
// specific thing the 2026-08-21 outage broke, so it gets its own assertion
// rather than relying on the golden strings above to catch a drift.
func TestReachBrowseWhere_DriverConjunctsUnaffectedBySharing(t *testing.T) {
	notShared, _ := ReachBrowseWhere(false, -0.1, 51.5, 4326)
	shared, _ := ReachBrowseWhere(true, -0.1, 51.5, 4326)

	driver := "AND MBRContains(rr.outer_bound, ST_SRID(POINT(?, ?), ?)) " +
		"AND ST_Contains(rr.outer_bound, ST_SRID(POINT(?, ?), ?)) "
	assert.Contains(t, notShared, driver)
	assert.Contains(t, shared, driver)
	assert.True(t, len(notShared) > len(driver) && notShared[:len(driver)] == driver)
	assert.True(t, len(shared) > len(driver) && shared[:len(driver)] == driver)
}

func TestReachBrowseWhere_ArgsScaleWithDistinctInputs(t *testing.T) {
	for _, share := range []bool{false, true} {
		where, args := ReachBrowseWhere(share, 1.23, -33.87, 3857)
		assert.NotEmpty(t, where)
		for i := 0; i < 4; i++ {
			base := i * 3
			assert.Equal(t, 1.23, args[base])
			assert.Equal(t, -33.87, args[base+1])
			assert.Equal(t, 3857, args[base+2])
		}
	}
}

// Neither function has a POINT-sentinel-vs-real-bound branch in Go - both SQL
// shapes are baked into one static string returned for every call - so the
// same golden string is the whole contract regardless of input values, for
// EITHER share state.
func TestReachExprs_SQLShapeIndependentOfInputValues(t *testing.T) {
	for _, share := range []bool{false, true} {
		expr1, _ := ReachInReachExpr(share, 0, 0, 0)
		expr2, _ := ReachInReachExpr(share, 179.99, -89.99, 900913)
		assert.Equal(t, expr1, expr2)

		where1, _ := ReachBrowseWhere(share, 0, 0, 0)
		where2, _ := ReachBrowseWhere(share, 179.99, -89.99, 900913)
		assert.Equal(t, where1, where2)
	}
}
