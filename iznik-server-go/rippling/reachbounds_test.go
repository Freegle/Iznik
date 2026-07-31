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
func TestReachInReachExpr_GoldenSQLAndArgs(t *testing.T) {
	expr, args := ReachInReachExpr(-0.1, 51.5, 4326)

	wantExpr := "((ST_GeometryType(rr.outer_bound) <> 'POINT' AND ST_Contains(rr.outer_bound, ST_SRID(POINT(?, ?), ?)) " +
		"AND (COALESCE(ST_Contains(rr.inner_bound, ST_SRID(POINT(?, ?), ?)), 0) = 1 " +
		"OR EXISTS (SELECT 1 FROM rippling_reach r2 WHERE r2.msgid = rr.msgid AND ST_Contains(r2.polygon, ST_SRID(POINT(?, ?), ?))))) " +
		"OR (ST_GeometryType(rr.outer_bound) = 'POINT' AND EXISTS (SELECT 1 FROM rippling_reach r2 WHERE r2.msgid = rr.msgid AND ST_Contains(r2.polygon, ST_SRID(POINT(?, ?), ?)))))"
	assert.Equal(t, wantExpr, expr)

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

func TestReachInReachExpr_ArgsScaleWithDistinctInputs(t *testing.T) {
	// Distinct (non-repeating) inputs make it obvious the same triple is used
	// for all four placeholders rather than some other combination.
	expr, args := ReachInReachExpr(1.23, -33.87, 3857)
	assert.NotEmpty(t, expr)
	for i := 0; i < 4; i++ {
		base := i * 3
		assert.Equal(t, 1.23, args[base])
		assert.Equal(t, -33.87, args[base+1])
		assert.Equal(t, 3857, args[base+2])
	}
}

// ReachBrowseWhere returns the browse-feed containment conjuncts: the R-tree
// is driven from the MBRContains(outer_bound, ...) prefilter, then the exact
// ST_Contains(outer_bound, ...) test, then inner_bound / exact-polygon EXISTS.
// Unlike ReachInReachExpr there is no POINT special case here (browse relies
// on the index pruning degraded bounds away entirely).
func TestReachBrowseWhere_GoldenSQLAndArgs(t *testing.T) {
	where, args := ReachBrowseWhere(-0.1, 51.5, 4326)

	wantWhere := "AND MBRContains(rr.outer_bound, ST_SRID(POINT(?, ?), ?)) " +
		"AND ST_Contains(rr.outer_bound, ST_SRID(POINT(?, ?), ?)) " +
		"AND (COALESCE(ST_Contains(rr.inner_bound, ST_SRID(POINT(?, ?), ?)), 0) = 1 " +
		"OR EXISTS (SELECT 1 FROM rippling_reach r2 WHERE r2.msgid = rr.msgid AND ST_Contains(r2.polygon, ST_SRID(POINT(?, ?), ?)))) "
	assert.Equal(t, wantWhere, where)

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

func TestReachBrowseWhere_ArgsScaleWithDistinctInputs(t *testing.T) {
	where, args := ReachBrowseWhere(1.23, -33.87, 3857)
	assert.NotEmpty(t, where)
	for i := 0; i < 4; i++ {
		base := i * 3
		assert.Equal(t, 1.23, args[base])
		assert.Equal(t, -33.87, args[base+1])
		assert.Equal(t, 3857, args[base+2])
	}
}

// Neither function has a POINT-sentinel-vs-real-bound branch in Go - both SQL
// shapes are baked into one static string returned for every call - so the
// same golden string is the whole contract regardless of input values.
func TestReachExprs_SQLShapeIndependentOfInputValues(t *testing.T) {
	expr1, _ := ReachInReachExpr(0, 0, 0)
	expr2, _ := ReachInReachExpr(179.99, -89.99, 900913)
	assert.Equal(t, expr1, expr2)

	where1, _ := ReachBrowseWhere(0, 0, 0)
	where2, _ := ReachBrowseWhere(179.99, -89.99, 900913)
	assert.Equal(t, where1, where2)
}
