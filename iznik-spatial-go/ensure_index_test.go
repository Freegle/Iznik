package main

import (
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// A dataset whose startup build produced 0 rows leaves state.idx nil (the
// empty-index guard). The admin upsert endpoint is the integration-test seeding
// path and must still work in that state: ensureIndex lazily creates an empty
// index so the seed can populate it. Regression for the SendDigestCommandTest
// Laravel tests that 503'd with "dataset not ready" when seeding via upsert.
func TestEnsureIndex_CreatesIndexForUpsertWhenNoneLoaded(t *testing.T) {
	state := &datasetState{}

	// Precondition: no index loaded → withIndex rejects, mirroring the upsert 503.
	require.ErrorIs(t, state.withIndex(func(*Index) error { return nil }), errIndexNotReady)

	require.NoError(t, state.ensureIndex())
	require.NotNil(t, state.idx)
	defer state.idx.Close()

	// Seed via the same path upsert uses, then confirm the row is queryable.
	items := []Item{{ExtID: 1, MinLng: -0.1, MaxLng: -0.1, MinLat: 51.5, MaxLat: 51.5}}
	require.NoError(t, state.withIndex(func(idx *Index) error { return InsertItems(idx, items, nil) }))

	var n int64
	require.NoError(t, state.withIndex(func(idx *Index) error {
		var e error
		n, e = idx.CountRows()
		return e
	}))
	assert.Equal(t, int64(1), n)

	// ensureIndex is idempotent: a second call keeps the populated index.
	require.NoError(t, state.ensureIndex())
	require.NoError(t, state.withIndex(func(idx *Index) error {
		var e error
		n, e = idx.CountRows()
		return e
	}))
	assert.Equal(t, int64(1), n)
}
