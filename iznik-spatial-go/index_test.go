package main

import (
	"testing"

	"github.com/peterstace/simplefeatures/geom"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// mustGeom parses a WKT string into a geom.Geometry, failing the test on error.
func mustGeom(t *testing.T, wkt string) geom.Geometry {
	t.Helper()
	g, err := geom.UnmarshalWKT(wkt)
	require.NoError(t, err)
	return g
}

func TestDeleteByExtID_RemovesExistingEntry(t *testing.T) {
	idx := setupTestIndex(t)

	results, err := QueryBBox(idx, -0.02, 0.02, 51.48, 51.52)
	require.NoError(t, err)
	var ids []int64
	for _, r := range results {
		ids = append(ids, r.ExtID)
	}
	assert.Contains(t, ids, int64(1), "small area should be present before delete")

	require.NoError(t, idx.DeleteByExtID(1))

	results, err = QueryBBox(idx, -0.02, 0.02, 51.48, 51.52)
	require.NoError(t, err)
	for _, r := range results {
		assert.NotEqual(t, int64(1), r.ExtID, "deleted entry must not appear in query results")
	}
}

func TestDeleteByExtID_NonExistentIsNoop(t *testing.T) {
	idx := setupTestIndex(t)
	require.NoError(t, idx.DeleteByExtID(999), "deleting a non-existent ID must not error")
}

func TestDeleteByExtID_OnlyRemovesTargetEntry(t *testing.T) {
	idx := setupTestIndex(t)

	require.NoError(t, idx.DeleteByExtID(1))

	results, err := QueryBBox(idx, -0.06, 0.06, 51.44, 51.56)
	require.NoError(t, err)
	require.Len(t, results, 1, "only the surviving entry should remain")
	assert.Equal(t, int64(2), results[0].ExtID)
}

func TestDeleteByExtID_IdempotentSecondDelete(t *testing.T) {
	idx := setupTestIndex(t)

	require.NoError(t, idx.DeleteByExtID(1))
	require.NoError(t, idx.DeleteByExtID(1), "second delete of same ID must not error")
}

func TestCountRows_ReturnsCorrectCount(t *testing.T) {
	idx := setupTestIndex(t)

	n, err := idx.CountRows()
	require.NoError(t, err)
	assert.Equal(t, int64(2), n)

	require.NoError(t, idx.DeleteByExtID(1))
	n, err = idx.CountRows()
	require.NoError(t, err)
	assert.Equal(t, int64(1), n)
}

func TestQueryWithin_ReturnsIdsInsidePolygon(t *testing.T) {
	idx, err := CreateIndex(":memory:")
	require.NoError(t, err)
	defer idx.Close()

	// Two points inside the query box, one outside.
	items := []Item{
		{ExtID: 100, MinLng: 0.01, MaxLng: 0.01, MinLat: 51.50, MaxLat: 51.50},
		{ExtID: 101, MinLng: 0.02, MaxLng: 0.02, MinLat: 51.50, MaxLat: 51.50},
		{ExtID: 102, MinLng: 2.00, MaxLng: 2.00, MinLat: 51.50, MaxLat: 51.50},
	}
	require.NoError(t, InsertItems(idx, items, nil))

	box := mustGeom(t, "POLYGON((-0.1 51.4, 0.1 51.4, 0.1 51.6, -0.1 51.6, -0.1 51.4))")

	ids, err := idx.QueryWithin(box)
	require.NoError(t, err)
	require.Len(t, ids, 2)
	assert.Contains(t, ids, int64(100))
	assert.Contains(t, ids, int64(101))
	assert.NotContains(t, ids, int64(102))
}

func TestQueryWithin_PolygonDataset(t *testing.T) {
	// Polygon items: query box intersects one polygon, not the other.
	idx := setupTestIndex(t)

	// Box that covers both polygons' centres — both should intersect.
	box := mustGeom(t, "POLYGON((-0.1 51.4, 0.1 51.4, 0.1 51.6, -0.1 51.6, -0.1 51.4))")
	ids, err := idx.QueryWithin(box)
	require.NoError(t, err)
	assert.Contains(t, ids, int64(1))
	assert.Contains(t, ids, int64(2))
}

func TestQueryWithin_EmptyResult(t *testing.T) {
	idx := setupTestIndex(t)

	// Box far away from all items.
	box := mustGeom(t, "POLYGON((10 10, 11 10, 11 11, 10 11, 10 10))")
	ids, err := idx.QueryWithin(box)
	require.NoError(t, err)
	assert.Empty(t, ids)
}
