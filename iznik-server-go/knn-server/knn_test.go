package main

import (
	"testing"

	"github.com/peterstace/simplefeatures/geom"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// makeTestRow builds a LocationRow from a WKT polygon, computing all derived
// fields (area, WKB, bounding box) via simplefeatures.
func makeTestRow(t *testing.T, locationID int64, name, locType, wkt string) LocationRow {
	t.Helper()
	g, err := geom.UnmarshalWKT(wkt)
	require.NoError(t, err)
	wkb := g.AsBinary()
	minXY, maxXY, ok := g.Envelope().MinMaxXYs()
	require.True(t, ok, "geometry must have an envelope")
	return LocationRow{
		LocationID: locationID,
		Name:       name,
		Type:       locType,
		Area:       g.Area(),
		WKB:        wkb,
		MinLng:     minXY.X,
		MaxLng:     maxXY.X,
		MinLat:     minXY.Y,
		MaxLat:     maxXY.Y,
	}
}

// setupTestIndex creates an in-memory index loaded with two overlapping polygons:
//
//   - ID=1 "Small":  ±0.01° around (0, 51.5), area ≈ 0.0004 sq°
//   - ID=2 "Large":  ±0.05° around (0, 51.5), area ≈ 0.01 sq°  (contains Small)
func setupTestIndex(t *testing.T) *Index {
	t.Helper()
	idx, err := CreateIndex(":memory:")
	require.NoError(t, err)
	t.Cleanup(func() { idx.Close() })

	small := makeTestRow(t, 1, "Small Area", "Polygon",
		"POLYGON((-0.01 51.49, 0.01 51.49, 0.01 51.51, -0.01 51.51, -0.01 51.49))")

	large := makeTestRow(t, 2, "Large Area", "Polygon",
		"POLYGON((-0.05 51.45, 0.05 51.45, 0.05 51.55, -0.05 51.55, -0.05 51.45))")

	require.NoError(t, InsertLocations(idx, []LocationRow{small, large}, nil))
	return idx
}

// TestFindNearestArea_PointInsideBothPolygons: query at the centre of both
// polygons — the smaller area must win.
func TestFindNearestArea_PointInsideBothPolygons(t *testing.T) {
	idx := setupTestIndex(t)
	id, err := FindNearestArea(idx, 0, 51.5)
	require.NoError(t, err)
	require.NotNil(t, id)
	assert.Equal(t, int64(1), *id, "smallest enclosing area should be returned")
}

// TestFindNearestArea_PointInsideLargeOnly: query inside Large but outside Small.
func TestFindNearestArea_PointInsideLargeOnly(t *testing.T) {
	idx := setupTestIndex(t)
	// (-0.04, 51.5) is inside Large (extends to -0.05) but outside Small (extends to -0.01).
	id, err := FindNearestArea(idx, -0.04, 51.5)
	require.NoError(t, err)
	require.NotNil(t, id)
	assert.Equal(t, int64(2), *id, "large area should be returned when point inside large only")
}

// TestFindNearestArea_PointNearButOutside: query outside both polygons;
// buffer expansion must find the large polygon.
func TestFindNearestArea_PointNearButOutside(t *testing.T) {
	idx := setupTestIndex(t)
	// (-0.06, 51.5) is 0.01° west of Large's west edge (-0.05).
	// Buffer level 7 has radius 0.01°; the circle just touches the polygon edge.
	id, err := FindNearestArea(idx, -0.06, 51.5)
	require.NoError(t, err)
	require.NotNil(t, id)
	assert.Equal(t, int64(2), *id, "large area should be found via buffer expansion")
}

// TestFindNearestArea_NoMatch: query far from all test geometries returns nil.
func TestFindNearestArea_NoMatch(t *testing.T) {
	idx := setupTestIndex(t)
	// (5, 51.5) is ~5° east of our polygons, well beyond the 0.32° max buffer.
	id, err := FindNearestArea(idx, 5, 51.5)
	require.NoError(t, err)
	assert.Nil(t, id, "should return nil when no area is within max buffer radius")
}

// TestFindNearestArea_AreaFilterTooSmall: a polygon below areaMin is not returned.
func TestFindNearestArea_AreaFilterTooSmall(t *testing.T) {
	idx, err := CreateIndex(":memory:")
	require.NoError(t, err)
	defer idx.Close()

	// 0.001° × 0.001° square — area ≈ 0.000001 sq°, below areaMin (0.00001).
	tiny := makeTestRow(t, 99, "Tiny", "Polygon",
		"POLYGON((0 51.5, 0.001 51.5, 0.001 51.501, 0 51.501, 0 51.5))")
	t.Logf("tiny polygon area: %g (areaMin=%g)", tiny.Area, areaMin)
	require.Less(t, tiny.Area, areaMin, "test data sanity: polygon must be below areaMin")

	require.NoError(t, InsertLocations(idx, []LocationRow{tiny}, nil))

	id, err := FindNearestArea(idx, 0.0005, 51.5005)
	require.NoError(t, err)
	assert.Nil(t, id, "polygon below areaMin should not be returned")
}

// TestFindNearestArea_AreaFilterTooLarge: a polygon above areaMax is not returned.
func TestFindNearestArea_AreaFilterTooLarge(t *testing.T) {
	idx, err := CreateIndex(":memory:")
	require.NoError(t, err)
	defer idx.Close()

	// 0.5° × 0.5° square — area = 0.25 sq°, above areaMax (0.15).
	huge := makeTestRow(t, 100, "Huge", "Polygon",
		"POLYGON((0 51, 0.5 51, 0.5 51.5, 0 51.5, 0 51))")
	t.Logf("huge polygon area: %g (areaMax=%g)", huge.Area, areaMax)
	require.Greater(t, huge.Area, areaMax, "test data sanity: polygon must exceed areaMax")

	require.NoError(t, InsertLocations(idx, []LocationRow{huge}, nil))

	id, err := FindNearestArea(idx, 0.25, 51.25)
	require.NoError(t, err)
	assert.Nil(t, id, "polygon above areaMax should not be returned")
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

// TestCircleWKT_FirstPointRightmost verifies that the circle approximation
// places its rightmost point exactly at (cx+r, cy), which is critical for the
// boundary-touching intersection tests.
func TestCircleWKT_FirstPointRightmost(t *testing.T) {
	wkt := circleWKT(-0.06, 51.5, 0.01)
	g, err := geom.UnmarshalWKT(wkt)
	require.NoError(t, err)
	_, maxXY, ok := g.Envelope().MinMaxXYs()
	require.True(t, ok)
	assert.InDelta(t, -0.05, maxXY.X, 1e-9, "rightmost x should be cx+r")
}
