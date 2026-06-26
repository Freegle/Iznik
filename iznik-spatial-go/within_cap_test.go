package main

import (
	"errors"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// The Within cap was raised from 10_000 to 100_000 so dense urban isochrones
// (central London ~50k freeglers within a 30-minute drive) don't trip it.
// These tests pin the headline change down — without them a quiet revert
// would silently re-introduce the old cap.

func TestQueryWithin_DocumentsCapAt100k(t *testing.T) {
	// Catch accidental changes to the constant.  If you're raising it
	// deliberately update this test too.
	assert.Equal(t, 100_000, maxWithinResults,
		"maxWithinResults cap should be 100k — see commit 561d02729")
}

func TestQueryWithin_AllowsResultsAboveOldCap(t *testing.T) {
	// The headline change: dense urban queries that used to trip the old
	// 10k cap should now succeed.  Insert 10001 points and confirm we
	// get them all back without an error.
	idx, err := CreateIndex(":memory:")
	require.NoError(t, err)
	defer idx.Close()

	const N = 10_001
	items := make([]Item, N)
	for i := 0; i < N; i++ {
		// Spread on a tiny lattice so all fit inside the query box.
		lng := -0.05 + float64(i%100)*0.0005
		lat := 51.45 + float64(i/100)*0.0005
		items[i] = Item{
			ExtID:  int64(i + 1),
			MinLng: lng, MaxLng: lng,
			MinLat: lat, MaxLat: lat,
		}
	}
	require.NoError(t, InsertItems(idx, items, nil))

	box := mustGeom(t, "POLYGON((-0.2 51.4, 0.2 51.4, 0.2 51.7, -0.2 51.7, -0.2 51.4))")
	ids, err := idx.QueryWithin(box)
	require.NoError(t, err, "10001 results should fit under the new 100k cap")
	assert.Len(t, ids, N)
}

func TestQueryWithin_RejectsResultsOverCap(t *testing.T) {
	// The cap is enforced inside the result loop using `len > cap`, so
	// triggering it requires maxWithinResults+1 items inside the box.
	// At 100k that's a real but tractable insert (~1-2s on :memory:).
	if testing.Short() {
		t.Skip("inserting 100k+ points; skipped under -short")
	}

	idx, err := CreateIndex(":memory:")
	require.NoError(t, err)
	defer idx.Close()

	const N = maxWithinResults + 1
	items := make([]Item, N)
	for i := 0; i < N; i++ {
		// Tight cluster — bbox-rejection won't help; everything intersects.
		lng := -0.05 + float64(i%500)*0.0002
		lat := 51.45 + float64(i/500)*0.00005
		items[i] = Item{
			ExtID:  int64(i + 1),
			MinLng: lng, MaxLng: lng,
			MinLat: lat, MaxLat: lat,
		}
	}
	require.NoError(t, InsertItems(idx, items, nil))

	box := mustGeom(t, "POLYGON((-0.2 51.4, 0.2 51.4, 0.2 51.7, -0.2 51.7, -0.2 51.4))")
	_, err = idx.QueryWithin(box)
	require.Error(t, err, "queries over the cap must return an error rather than truncate silently")
	assert.True(t, errors.Is(err, ErrTooManyResults),
		"error should be ErrTooManyResults so callers can distinguish overflow from other failures, got %v", err)
}
