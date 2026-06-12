package main

import (
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// setupDatasetIndex creates an in-memory index with two polygon items:
// one type="Polygon" and one type="Postcode", both overlapping at centre (0, 51.5).
func setupDatasetIndex(t *testing.T) *Index {
	t.Helper()
	idx, err := CreateIndex(":memory:")
	require.NoError(t, err)
	t.Cleanup(func() { idx.Close() })

	area := makeTestItem(t, 10, "Balham", "Polygon",
		"POLYGON((-0.05 51.45, 0.05 51.45, 0.05 51.55, -0.05 51.55, -0.05 51.45))")
	area.Extra = map[string]any{"name": "Balham", "type": "Polygon"}

	postcode := makeTestItem(t, 11, "SW12", "Postcode",
		"POLYGON((-0.01 51.49, 0.01 51.49, 0.01 51.51, -0.01 51.51, -0.01 51.49))")
	postcode.Extra = map[string]any{"name": "SW12", "type": "Postcode"}

	require.NoError(t, InsertItems(idx, []Item{area, postcode}, nil))
	return idx
}

func TestLocationsDataset_Query_NoTypeFilter(t *testing.T) {
	idx := setupDatasetIndex(t)
	ds := &LocationsDataset{}

	// limit=1 without type filter: should return the smallest matching polygon.
	results, err := ds.Query(idx, QueryParams{Lng: 0, Lat: 51.5, Limit: 1})
	require.NoError(t, err)
	require.Len(t, results, 1)
	assert.Equal(t, int64(11), results[0].ID, "smallest area (postcode) should win")
}

func TestLocationsDataset_Query_PostcodeFilter(t *testing.T) {
	idx := setupDatasetIndex(t)
	ds := &LocationsDataset{}

	results, err := ds.Query(idx, QueryParams{Lng: 0, Lat: 51.5, Limit: 5, TypeFilter: "Postcode"})
	require.NoError(t, err)
	require.Len(t, results, 1)
	assert.Equal(t, int64(11), results[0].ID)
	if extra, ok := results[0].Extra["type"].(string); ok {
		assert.Equal(t, "Postcode", extra)
	}
}

func TestLocationsDataset_Query_AreaFilter(t *testing.T) {
	idx := setupDatasetIndex(t)
	ds := &LocationsDataset{}

	results, err := ds.Query(idx, QueryParams{Lng: 0, Lat: 51.5, Limit: 5, TypeFilter: "Polygon"})
	require.NoError(t, err)
	require.Len(t, results, 1)
	assert.Equal(t, int64(10), results[0].ID)
}

// TestLocationsDataset_Query_PostcodeFilter_Limit1_InsideAreaOnly: the query
// point is inside an area but the nearest postcode is several buffer levels
// away. With limit=1 the type filter must be applied DURING buffer expansion —
// a post-filter would stop at the enclosing area and return nothing.
func TestLocationsDataset_Query_PostcodeFilter_Limit1_InsideAreaOnly(t *testing.T) {
	idx, err := CreateIndex(":memory:")
	require.NoError(t, err)
	t.Cleanup(func() { idx.Close() })

	area := makeTestItem(t, 20, "Balham", "Polygon",
		"POLYGON((-0.05 51.45, 0.05 51.45, 0.05 51.55, -0.05 51.55, -0.05 51.45))")
	// Postcode polygon ~0.008° east of the query point — found at buffer level 7.
	postcode := makeTestItem(t, 21, "SW12 8QN", "Postcode",
		"POLYGON((0.008 51.499, 0.01 51.499, 0.01 51.501, 0.008 51.501, 0.008 51.499))")
	require.NoError(t, InsertItems(idx, []Item{area, postcode}, nil))

	ds := &LocationsDataset{}
	results, err := ds.Query(idx, QueryParams{Lng: 0, Lat: 51.5, Limit: 1, TypeFilter: "Postcode"})
	require.NoError(t, err)
	require.Len(t, results, 1, "postcode filter must expand past the enclosing area")
	assert.Equal(t, int64(21), results[0].ID)
}

// TestLocationsDataset_Query_TypeArea: the pseudo-type "Area" matches any
// non-Postcode location, mirroring the V1 PostGIS remap candidate pool
// (which synced everything except postcodes). Used by PostcodeRemapService.
func TestLocationsDataset_Query_TypeArea(t *testing.T) {
	idx := setupDatasetIndex(t)
	ds := &LocationsDataset{}

	// The postcode (id=11) is smaller and would win without a filter;
	// type=Area must skip it and return the area (id=10).
	results, err := ds.Query(idx, QueryParams{Lng: 0, Lat: 51.5, Limit: 1, TypeFilter: "Area"})
	require.NoError(t, err)
	require.Len(t, results, 1)
	assert.Equal(t, int64(10), results[0].ID, "type=Area must exclude postcodes")
}

// TestLocationsDataset_Query_TypeArea_ExpandsPastPostcode: the query point is
// inside a postcode polygon only; type=Area must keep expanding until it finds
// the non-postcode area further out.
func TestLocationsDataset_Query_TypeArea_ExpandsPastPostcode(t *testing.T) {
	idx, err := CreateIndex(":memory:")
	require.NoError(t, err)
	t.Cleanup(func() { idx.Close() })

	postcode := makeTestItem(t, 30, "SW12 8QN", "Postcode",
		"POLYGON((-0.001 51.499, 0.001 51.499, 0.001 51.501, -0.001 51.501, -0.001 51.499))")
	// Area ~0.008° east — only reached at a later buffer level.
	area := makeTestItem(t, 31, "Balham", "Polygon",
		"POLYGON((0.008 51.49, 0.02 51.49, 0.02 51.51, 0.008 51.51, 0.008 51.49))")
	require.NoError(t, InsertItems(idx, []Item{postcode, area}, nil))

	ds := &LocationsDataset{}
	results, err := ds.Query(idx, QueryParams{Lng: 0, Lat: 51.5, Limit: 1, TypeFilter: "Area"})
	require.NoError(t, err)
	require.Len(t, results, 1, "area filter must expand past the enclosing postcode")
	assert.Equal(t, int64(31), results[0].ID)
}

func TestLocationsDataset_Within(t *testing.T) {
	idx := setupDatasetIndex(t)
	ds := &LocationsDataset{}

	// Polygon covering both items.
	box := mustGeom(t, "POLYGON((-0.1 51.4, 0.1 51.4, 0.1 51.6, -0.1 51.6, -0.1 51.4))")
	ids, err := ds.Within(idx, QueryParams{Polygon: &box})
	require.NoError(t, err)
	assert.Contains(t, ids, int64(10))
	assert.Contains(t, ids, int64(11))
}

func TestMessagesDataset_Query(t *testing.T) {
	idx, err := CreateIndex(":memory:")
	require.NoError(t, err)
	defer idx.Close()

	items := []Item{
		{
			ExtID: 50, MinLng: 0.01, MaxLng: 0.01, MinLat: 51.50, MaxLat: 51.50,
			Extra: map[string]any{"msgtype": "Offer", "groupid": int64(1), "promised": int64(0)},
		},
		{
			ExtID: 51, MinLng: 1.00, MaxLng: 1.00, MinLat: 51.50, MaxLat: 51.50,
			Extra: map[string]any{"msgtype": "Wanted", "groupid": int64(2), "promised": int64(0)},
		},
	}
	require.NoError(t, InsertItems(idx, items, nil))

	ds := &MessagesDataset{}
	results, err := ds.Query(idx, QueryParams{Lng: 0, Lat: 51.5, Limit: 1})
	require.NoError(t, err)
	require.Len(t, results, 1)
	assert.Equal(t, int64(50), results[0].ID, "nearest message should be returned")
	assert.NotNil(t, results[0].Extra)
}

func TestMessagesDataset_Within(t *testing.T) {
	idx, err := CreateIndex(":memory:")
	require.NoError(t, err)
	defer idx.Close()

	items := []Item{
		{ExtID: 60, MinLng: 0.01, MaxLng: 0.01, MinLat: 51.50, MaxLat: 51.50},
		{ExtID: 61, MinLng: 5.00, MaxLng: 5.00, MinLat: 51.50, MaxLat: 51.50},
	}
	require.NoError(t, InsertItems(idx, items, nil))

	ds := &MessagesDataset{}
	box := mustGeom(t, "POLYGON((-0.1 51.4, 0.1 51.4, 0.1 51.6, -0.1 51.6, -0.1 51.4))")
	ids, err := ds.Within(idx, QueryParams{Polygon: &box})
	require.NoError(t, err)
	require.Len(t, ids, 1)
	assert.Contains(t, ids, int64(60))
}

func TestUserApproxLocsDataset_ApplyDeltaNotSupported(t *testing.T) {
	ds := &UserApproxLocsDataset{}
	err := ds.ApplyDelta(nil, nil, zeroTime)
	assert.ErrorIs(t, err, ErrDeltaNotSupported)
}

func TestGroupsDataset_ApplyDeltaNotSupported(t *testing.T) {
	ds := &GroupsDataset{}
	err := ds.ApplyDelta(nil, nil, zeroTime)
	assert.ErrorIs(t, err, ErrDeltaNotSupported)
}

// zeroTime is a zero time.Time value for tests that need a time but don't care about its value.
var zeroTime = time.Time{}
