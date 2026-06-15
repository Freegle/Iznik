package main

import (
	"testing"

	"github.com/peterstace/simplefeatures/geom"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// makeTestItem builds a polygon Item from a WKT string, computing WKB and bbox.
func makeTestItem(t *testing.T, extID int64, name, locType, wkt string) Item {
	t.Helper()
	g, err := geom.UnmarshalWKT(wkt)
	require.NoError(t, err)
	wkb := g.AsBinary()
	minXY, maxXY, ok := g.Envelope().MinMaxXYs()
	require.True(t, ok, "geometry must have an envelope")
	return Item{
		ExtID:  extID,
		Area:   g.Area(),
		WKB:    wkb,
		MinLng: minXY.X,
		MaxLng: maxXY.X,
		MinLat: minXY.Y,
		MaxLat: maxXY.Y,
		Extra:  map[string]any{"name": name, "type": locType},
	}
}

// setupTestIndex creates an in-memory index with two overlapping polygons:
//
//   - ExtID=1 "Small":  ±0.01° around (0, 51.5), area ≈ 0.0004 sq°
//   - ExtID=2 "Large":  ±0.05° around (0, 51.5), area ≈ 0.01 sq°  (contains Small)
func setupTestIndex(t *testing.T) *Index {
	t.Helper()
	idx, err := CreateIndex(":memory:")
	require.NoError(t, err)
	t.Cleanup(func() { idx.Close() })

	small := makeTestItem(t, 1, "Small Area", "Polygon",
		"POLYGON((-0.01 51.49, 0.01 51.49, 0.01 51.51, -0.01 51.51, -0.01 51.49))")

	large := makeTestItem(t, 2, "Large Area", "Polygon",
		"POLYGON((-0.05 51.45, 0.05 51.45, 0.05 51.55, -0.05 51.55, -0.05 51.45))")

	require.NoError(t, InsertItems(idx, []Item{small, large}, nil))
	return idx
}

// TestFindNearestPolygon_PointInsideBothPolygons: query at the centre of both
// polygons — the smaller area must win.
func TestFindNearestPolygon_PointInsideBothPolygons(t *testing.T) {
	idx := setupTestIndex(t)
	results, err := FindNearestPolygon(idx, 0, 51.5, 1, nil)
	require.NoError(t, err)
	require.Len(t, results, 1)
	assert.Equal(t, int64(1), results[0].ID, "smallest enclosing area should be returned")
}

// TestFindNearestPolygon_PointInsideLargeOnly: query inside Large but outside Small.
func TestFindNearestPolygon_PointInsideLargeOnly(t *testing.T) {
	idx := setupTestIndex(t)
	results, err := FindNearestPolygon(idx, -0.04, 51.5, 1, nil)
	require.NoError(t, err)
	require.Len(t, results, 1)
	assert.Equal(t, int64(2), results[0].ID, "large area should be returned when point inside large only")
}

// TestFindNearestPolygon_PointNearButOutside: buffer expansion must find the large polygon.
func TestFindNearestPolygon_PointNearButOutside(t *testing.T) {
	idx := setupTestIndex(t)
	// (-0.06, 51.5) is 0.01° west of Large's west edge (-0.05).
	results, err := FindNearestPolygon(idx, -0.06, 51.5, 1, nil)
	require.NoError(t, err)
	require.Len(t, results, 1)
	assert.Equal(t, int64(2), results[0].ID, "large area should be found via buffer expansion")
}

// TestFindNearestPolygon_NoMatch: query far from all test geometries returns empty slice.
func TestFindNearestPolygon_NoMatch(t *testing.T) {
	idx := setupTestIndex(t)
	results, err := FindNearestPolygon(idx, 5, 51.5, 1, nil)
	require.NoError(t, err)
	assert.Empty(t, results, "should return no results when no area is within max buffer radius")
}

// TestFindNearestPolygon_LimitReturnsMultiple: limit=2 returns both polygons.
func TestFindNearestPolygon_LimitReturnsMultiple(t *testing.T) {
	idx := setupTestIndex(t)
	// Query at centre: both polygons match. limit=2 should return both.
	results, err := FindNearestPolygon(idx, 0, 51.5, 2, nil)
	require.NoError(t, err)
	require.Len(t, results, 2)
	// First result should be small (smallest area), second large.
	assert.Equal(t, int64(1), results[0].ID)
	assert.Equal(t, int64(2), results[1].ID)
}

// TestFindNearestPolygon_AreaFilterTooSmall: a polygon below effective area is not matched
// when queried via FindNearestPolygon (no area filter — test verifies it IS returned).
func TestFindNearestPolygon_SmallAreaIsReturnedWithoutFilter(t *testing.T) {
	idx, err := CreateIndex(":memory:")
	require.NoError(t, err)
	defer idx.Close()

	// Tiny polygon, but FindNearestPolygon has no area filter — it should still match.
	tiny := makeTestItem(t, 99, "Tiny", "Polygon",
		"POLYGON((0 51.5, 0.001 51.5, 0.001 51.501, 0 51.501, 0 51.5))")
	require.NoError(t, InsertItems(idx, []Item{tiny}, nil))

	results, err := FindNearestPolygon(idx, 0.0005, 51.5005, 1, nil)
	require.NoError(t, err)
	require.Len(t, results, 1)
	assert.Equal(t, int64(99), results[0].ID)
}

// TestFindNearestPolygon_MatchFilterExpandsPastNonMatching: a non-matching item
// found at an early buffer level must not satisfy the limit — expansion continues
// until a matching item is found.
func TestFindNearestPolygon_MatchFilterExpandsPastNonMatching(t *testing.T) {
	idx := setupTestIndex(t)
	// Query at centre: Small (id=1) matches first, but the filter only accepts
	// "Large Area" — expansion must continue and return id=2.
	onlyLarge := func(extra map[string]any) bool {
		name, _ := extra["name"].(string)
		return name == "Large Area"
	}
	results, err := FindNearestPolygon(idx, 0, 51.5, 1, onlyLarge)
	require.NoError(t, err)
	require.Len(t, results, 1)
	assert.Equal(t, int64(2), results[0].ID, "filter must skip non-matching items without counting them")
}

// TestFindNearestPoints_ReturnsNearestPoint: point dataset query returns nearest point.
func TestFindNearestPoints_ReturnsNearestPoint(t *testing.T) {
	idx, err := CreateIndex(":memory:")
	require.NoError(t, err)
	defer idx.Close()

	// Two points: one close, one far.
	items := []Item{
		{ExtID: 10, MinLng: 0.01, MaxLng: 0.01, MinLat: 51.50, MaxLat: 51.50},
		{ExtID: 11, MinLng: 1.00, MaxLng: 1.00, MinLat: 51.50, MaxLat: 51.50},
	}
	require.NoError(t, InsertItems(idx, items, nil))

	results, err := FindNearestPoints(idx, 0, 51.5, 1, nil)
	require.NoError(t, err)
	require.Len(t, results, 1)
	assert.Equal(t, int64(10), results[0].ID, "nearest point should be returned")
}

// TestFindNearestPoints_LimitTwo: returns two nearest points in distance order.
func TestFindNearestPoints_LimitTwo(t *testing.T) {
	idx, err := CreateIndex(":memory:")
	require.NoError(t, err)
	defer idx.Close()

	items := []Item{
		{ExtID: 20, MinLng: 0.02, MaxLng: 0.02, MinLat: 51.50, MaxLat: 51.50},
		{ExtID: 21, MinLng: 0.01, MaxLng: 0.01, MinLat: 51.50, MaxLat: 51.50},
		{ExtID: 22, MinLng: 1.00, MaxLng: 1.00, MinLat: 51.50, MaxLat: 51.50},
	}
	require.NoError(t, InsertItems(idx, items, nil))

	results, err := FindNearestPoints(idx, 0, 51.5, 2, nil)
	require.NoError(t, err)
	require.Len(t, results, 2)
	assert.Equal(t, int64(21), results[0].ID, "closest point first")
	assert.Equal(t, int64(20), results[1].ID, "second closest next")
}

// TestFindNearestPoints_PolygonFilter: polygon restricts results to items inside it.
func TestFindNearestPoints_PolygonFilter(t *testing.T) {
	idx, err := CreateIndex(":memory:")
	require.NoError(t, err)
	defer idx.Close()

	items := []Item{
		{ExtID: 30, MinLng: 0.01, MaxLng: 0.01, MinLat: 51.50, MaxLat: 51.50}, // inside box
		{ExtID: 31, MinLng: 1.00, MaxLng: 1.00, MinLat: 51.50, MaxLat: 51.50}, // outside box
	}
	require.NoError(t, InsertItems(idx, items, nil))

	// Polygon covering only the first point.
	box, err := geom.UnmarshalWKT("POLYGON((-0.1 51.4, 0.1 51.4, 0.1 51.6, -0.1 51.6, -0.1 51.4))")
	require.NoError(t, err)

	results, err := FindNearestPoints(idx, 0, 51.5, 5, &box)
	require.NoError(t, err)
	require.Len(t, results, 1)
	assert.Equal(t, int64(30), results[0].ID)
}

// TestQueryBBox_ReturnsOverlappingEntries verifies the R-tree range query directly.
func TestQueryBBox_ReturnsOverlappingEntries(t *testing.T) {
	idx := setupTestIndex(t)

	// A tight bbox around the centre — should hit both polygons' bounding boxes.
	results, err := QueryBBox(idx, -0.001, 0.001, 51.499, 51.501)
	require.NoError(t, err)
	assert.Len(t, results, 2, "both polygons' bboxes overlap the centre area")

	// A bbox far to the west — should hit nothing.
	results, err = QueryBBox(idx, -5, -4, 51, 52)
	require.NoError(t, err)
	assert.Empty(t, results)
}

// TestCircleWKT_FirstPointRightmost verifies the circle approximation geometry.
func TestCircleWKT_FirstPointRightmost(t *testing.T) {
	wkt := circleWKT(-0.06, 51.5, 0.01)
	g, err := geom.UnmarshalWKT(wkt)
	require.NoError(t, err)
	_, maxXY, ok := g.Envelope().MinMaxXYs()
	require.True(t, ok)
	assert.InDelta(t, -0.05, maxXY.X, 1e-9, "rightmost x should be cx+r")
}
