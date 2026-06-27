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
	results, err := FindNearestPolygon(idx, 0, 51.5, 1, nil, nil)
	require.NoError(t, err)
	require.Len(t, results, 1)
	assert.Equal(t, int64(1), results[0].ID, "smallest enclosing area should be returned")
}

// TestFindNearestPolygon_PointInsideLargeOnly: query inside Large but outside Small.
func TestFindNearestPolygon_PointInsideLargeOnly(t *testing.T) {
	idx := setupTestIndex(t)
	results, err := FindNearestPolygon(idx, -0.04, 51.5, 1, nil, nil)
	require.NoError(t, err)
	require.Len(t, results, 1)
	assert.Equal(t, int64(2), results[0].ID, "large area should be returned when point inside large only")
}

// TestFindNearestPolygon_PointNearButOutside: buffer expansion must find the large polygon.
func TestFindNearestPolygon_PointNearButOutside(t *testing.T) {
	idx := setupTestIndex(t)
	// (-0.06, 51.5) is 0.01° west of Large's west edge (-0.05).
	results, err := FindNearestPolygon(idx, -0.06, 51.5, 1, nil, nil)
	require.NoError(t, err)
	require.Len(t, results, 1)
	assert.Equal(t, int64(2), results[0].ID, "large area should be found via buffer expansion")
}

// TestFindNearestPolygon_NoMatch: query far from all test geometries returns empty slice.
func TestFindNearestPolygon_NoMatch(t *testing.T) {
	idx := setupTestIndex(t)
	results, err := FindNearestPolygon(idx, 5, 51.5, 1, nil, nil)
	require.NoError(t, err)
	assert.Empty(t, results, "should return no results when no area is within max buffer radius")
}

// TestFindNearestPolygon_LimitReturnsMultiple: limit=2 returns both polygons.
func TestFindNearestPolygon_LimitReturnsMultiple(t *testing.T) {
	idx := setupTestIndex(t)
	// Query at centre: both polygons match. limit=2 should return both.
	results, err := FindNearestPolygon(idx, 0, 51.5, 2, nil, nil)
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

	results, err := FindNearestPolygon(idx, 0.0005, 51.5005, 1, nil, nil)
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
	results, err := FindNearestPolygon(idx, 0, 51.5, 1, onlyLarge, nil)
	require.NoError(t, err)
	require.Len(t, results, 1)
	assert.Equal(t, int64(2), results[0].ID, "filter must skip non-matching items without counting them")
}

// makeJobItem builds a polygon Item carrying the jobs dataset's Extra fields
// (company/title), so jobsDedupKey can key on them.
func makeJobItem(t *testing.T, extID int64, company, title, wkt string) Item {
	t.Helper()
	g, err := geom.UnmarshalWKT(wkt)
	require.NoError(t, err)
	minXY, maxXY, ok := g.Envelope().MinMaxXYs()
	require.True(t, ok, "geometry must have an envelope")
	return Item{
		ExtID:  extID,
		Area:   g.Area(),
		WKB:    g.AsBinary(),
		MinLng: minXY.X,
		MaxLng: maxXY.X,
		MinLat: minXY.Y,
		MaxLat: maxXY.Y,
		Extra:  map[string]any{"company": company, "title": title},
	}
}

// TestFindNearestPolygon_DedupKeyExpandsPastDuplicate: the jobs use-case. WhatJobs
// posts the same (company, title) ad to many towns; with a dedupKey the nearest
// instance is kept and the buffer expands past the farther copies to fill the
// limit with distinct jobs (Discourse 9363). Three items along +lng from the
// query point: id=1 and id=2 are the SAME (company, title) at increasing distance,
// id=3 is a distinct ad farther still.
func TestFindNearestPolygon_DedupKeyExpandsPastDuplicate(t *testing.T) {
	idx, err := CreateIndex(":memory:")
	require.NoError(t, err)
	defer idx.Close()

	nearDup := makeJobItem(t, 1, "Deliveroo", "Rider",
		"POLYGON((-0.001 51.499, 0.001 51.499, 0.001 51.501, -0.001 51.501, -0.001 51.499))")
	farDup := makeJobItem(t, 2, "Deliveroo", "Rider",
		"POLYGON((0.009 51.499, 0.011 51.499, 0.011 51.501, 0.009 51.501, 0.009 51.499))")
	distinct := makeJobItem(t, 3, "Acme", "Cleaner",
		"POLYGON((0.019 51.499, 0.021 51.499, 0.021 51.501, 0.019 51.501, 0.019 51.499))")
	require.NoError(t, InsertItems(idx, []Item{nearDup, farDup, distinct}, nil))

	// Without dedup, the nearest two are both "Deliveroo Rider" (the duplicate).
	plain, err := FindNearestPolygon(idx, 0, 51.5, 2, nil, nil)
	require.NoError(t, err)
	require.Len(t, plain, 2)
	plainIDs := []int64{plain[0].ID, plain[1].ID}
	assert.ElementsMatch(t, []int64{1, 2}, plainIDs, "no dedup: nearest two are the duplicate pair")

	// With dedup, the farther copy (id=2) is skipped and expansion continues to the
	// distinct ad (id=3), so the result is one of each.
	deduped, err := FindNearestPolygon(idx, 0, 51.5, 2, nil, jobsDedupKey)
	require.NoError(t, err)
	require.Len(t, deduped, 2)
	dedupIDs := []int64{deduped[0].ID, deduped[1].ID}
	assert.ElementsMatch(t, []int64{1, 3}, dedupIDs, "dedup keeps nearest of the pair and fills with the distinct ad")
	assert.NotContains(t, dedupIDs, int64(2), "the farther duplicate must be dropped")
}

// makeJobItemBH is makeJobItem with an explicit bodyhash in Extra, for the
// nationwide-dup case where copies carry differing company/title but one body.
func makeJobItemBH(t *testing.T, extID int64, company, title, bodyhash, wkt string) Item {
	t.Helper()
	item := makeJobItem(t, extID, company, title, wkt)
	item.Extra["bodyhash"] = bodyhash
	return item
}

// TestJobsDedupKey_CollapsesByBodyhashAcrossCompanyTitle: WhatJobs spams one ad
// to many towns with DIFFERING company/title but the SAME body (analysis of
// email_tracking_clicks: 94% of >80km clicks were on these). The (company,title)
// key let the far copies through; keying on bodyhash collapses them so the
// nearest is served and the buffer expands to a genuinely distinct (different
// body) ad. id=1 and id=2 share a bodyhash at increasing distance; id=3 has a
// different body farther still.
func TestJobsDedupKey_CollapsesByBodyhashAcrossCompanyTitle(t *testing.T) {
	idx, err := CreateIndex(":memory:")
	require.NoError(t, err)
	defer idx.Close()

	nearDup := makeJobItemBH(t, 1, "CourierCo", "Bike Courier", "samebody",
		"POLYGON((-0.001 51.499, 0.001 51.499, 0.001 51.501, -0.001 51.501, -0.001 51.499))")
	farDup := makeJobItemBH(t, 2, "RidersLtd", "Delivery Driver", "samebody",
		"POLYGON((0.009 51.499, 0.011 51.499, 0.011 51.501, 0.009 51.501, 0.009 51.499))")
	distinct := makeJobItemBH(t, 3, "Acme", "Cleaner", "otherbody",
		"POLYGON((0.019 51.499, 0.021 51.499, 0.021 51.501, 0.019 51.501, 0.019 51.499))")
	require.NoError(t, InsertItems(idx, []Item{nearDup, farDup, distinct}, nil))

	// Without dedup, the nearest two are the same-body pair despite differing
	// company/title — exactly the far-copy leak the old (company,title) key had.
	plain, err := FindNearestPolygon(idx, 0, 51.5, 2, nil, nil)
	require.NoError(t, err)
	require.Len(t, plain, 2)
	assert.ElementsMatch(t, []int64{1, 2}, []int64{plain[0].ID, plain[1].ID},
		"no dedup: nearest two are the same-body pair")

	// With bodyhash dedup, the farther same-body copy (id=2) is dropped and the
	// search expands to the different-body ad (id=3).
	deduped, err := FindNearestPolygon(idx, 0, 51.5, 2, nil, jobsDedupKey)
	require.NoError(t, err)
	require.Len(t, deduped, 2)
	dedupIDs := []int64{deduped[0].ID, deduped[1].ID}
	assert.ElementsMatch(t, []int64{1, 3}, dedupIDs,
		"bodyhash dedup keeps nearest of the same-body pair and fills with the distinct-body ad")
	assert.NotContains(t, dedupIDs, int64(2), "the farther same-body copy must be dropped")
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

// TestJobBufferLevelsExtendReach is the regression guard for the WhatJobs
// rural-reach drop (PR #764 routed jobs through the shared 0.32° ladder, halving
// reach and dropping click volume ~40%). A job ~0.4° away — beyond the shared
// 0.32° ceiling but within the jobs ladder's 0.64° — must be invisible to the
// default ladder yet found via jobBufferLevels, exactly as the jobs Query uses it.
func TestJobBufferLevelsExtendReach(t *testing.T) {
	// jobBufferLevels must strictly extend the shared ladder (same prefix, wider tail).
	require.Greater(t, len(jobBufferLevels), len(bufferLevels),
		"jobs ladder must add reach beyond the shared ceiling")
	for i := range bufferLevels {
		assert.Equal(t, bufferLevels[i], jobBufferLevels[i],
			"jobs ladder must share the inner-level prefix so dense queries behave identically")
	}
	assert.Greater(t, jobBufferLevels[len(jobBufferLevels)-1], 0.32,
		"jobs ladder must reach past the 0.32° postcode-scale ceiling")

	idx, err := CreateIndex(":memory:")
	require.NoError(t, err)
	defer idx.Close()

	// A small job polygon centred ~0.4° east of the query point — ~0.39° from it,
	// i.e. past 0.32° (out of reach for postcode/area lookups) but inside 0.64°.
	job := makeTestItem(t, 7, "Far Job", "Polygon",
		"POLYGON((0.39 51.49, 0.41 51.49, 0.41 51.51, 0.39 51.51, 0.39 51.49))")
	require.NoError(t, InsertItems(idx, []Item{job}, nil))

	// Default ladder (0.32° ceiling) cannot reach it — this is the regression we fix.
	shortReach, err := FindNearestPolygon(idx, 0, 51.5, 1, nil, nil)
	require.NoError(t, err)
	assert.Empty(t, shortReach, "0.32° ceiling must not reach a 0.39°-distant job (proves the bug exists)")

	// Jobs ladder reaches it — restoring the pre-spatial 64km behaviour.
	jobReach, err := findNearestPolygonLevels(idx, 0, 51.5, 1, nil, nil, jobBufferLevels[:])
	require.NoError(t, err)
	require.Len(t, jobReach, 1, "jobs ladder must reach the 0.39°-distant job")
	assert.Equal(t, int64(7), jobReach[0].ID)
}
