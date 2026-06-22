package main

import (
	"database/sql"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/peterstace/simplefeatures/geom"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func TestRebuildFlow_CheckpointSurvivesRename(t *testing.T) {
	// Regression: rebuild() renames only the .db file, so the WAL must be
	// checkpointed into it first — otherwise a large index reopens as
	// "no items table" (its newest pages stranded in the orphaned -wal).
	dir := t.TempDir()
	tmpPath := filepath.Join(dir, "x.db.building")
	finalPath := filepath.Join(dir, "x.db")

	idx, err := CreateIndex(tmpPath)
	require.NoError(t, err)

	const n = 5000
	items := make([]Item, n)
	for i := 0; i < n; i++ {
		lng := -3 + float64(i%400)/100
		lat := 51 + float64(i%500)/100
		items[i] = Item{ExtID: int64(i + 1), MinLng: lng, MaxLng: lng, MinLat: lat, MaxLat: lat}
	}
	require.NoError(t, InsertItems(idx, items, nil))
	require.NoError(t, idx.Checkpoint())
	require.NoError(t, idx.Close())

	require.NoError(t, os.Rename(tmpPath, finalPath))

	reopened, err := OpenIndex(finalPath)
	require.NoError(t, err, "reopened renamed index must still have its items table")
	defer reopened.Close()

	cnt, err := reopened.CountRows()
	require.NoError(t, err)
	assert.Equal(t, int64(n), cnt)
}

// fakeDataset is a Dataset whose Load inserts a fixed set of points and ignores
// MySQL, so server.rebuild() can be exercised without a live DB.
type fakeDataset struct {
	name  string
	items []Item
}

func (d *fakeDataset) Name() string                                  { return d.name }
func (d *fakeDataset) Load(_ *sql.DB, idx *Index) error              { return InsertItems(idx, d.items, nil) }
func (d *fakeDataset) ApplyDelta(_ *sql.DB, _ *Index, _ time.Time) error { return nil }
func (d *fakeDataset) Query(_ *Index, _ QueryParams) ([]QueryResult, error) { return nil, nil }
func (d *fakeDataset) Within(_ *Index, _ QueryParams) ([]int64, error)     { return nil, nil }
func (d *fakeDataset) RebuildInterval() time.Duration                { return time.Hour }
func (d *fakeDataset) DeltaInterval() time.Duration                  { return time.Minute }

func pointItems(start, n int) []Item {
	items := make([]Item, n)
	for i := 0; i < n; i++ {
		lng := -2 + float64(i)/1000
		lat := 53 + float64(i)/1000
		items[i] = Item{ExtID: int64(start + i), MinLng: lng, MaxLng: lng, MinLat: lat, MaxLat: lat}
	}
	return items
}

// TestRebuild_RemovesOrphanedWAL is a regression test for the messages-index
// corruption seen in production (2026-06-16): rebuild() renames only the .db
// over the old file, so the OLD -wal/-shm are left on disk. SQLite then replays
// that stale WAL onto the freshly built .db on reopen and corrupts it — every
// subsequent delta upsert/delete fails with "database disk image is malformed".
// rebuild() must delete the orphaned sidecars before reopening.
func TestRebuild_RemovesOrphanedWAL(t *testing.T) {
	dir := t.TempDir()
	ds := &fakeDataset{name: "msgs", items: pointItems(1, 3)}
	srv := newServer(nil, dir, []Dataset{ds})

	// First build: opens dir/msgs.db as the live index (WAL mode).
	require.NoError(t, srv.rebuild("msgs"))

	// Dirty the live index's WAL the way real delta upserts do, and leave it
	// un-checkpointed so dir/msgs.db-wal is non-empty when the next rebuild runs.
	state := srv.datasets["msgs"]
	require.NoError(t, InsertItems(state.idx, pointItems(1000, 50), nil))
	walPath := filepath.Join(dir, "msgs.db-wal")
	if fi, err := os.Stat(walPath); err != nil || fi.Size() == 0 {
		t.Fatalf("expected a non-empty orphan-able WAL before rebuild (err=%v)", err)
	}

	// Second build replaces the .db. Without the sidecar cleanup, reopening
	// replays the stale WAL and the index is corrupt / has the wrong rows.
	ds.items = pointItems(1, 7)
	require.NoError(t, srv.rebuild("msgs"), "rebuild must not fail on an orphaned WAL")

	cnt, err := state.idx.CountRows()
	require.NoError(t, err, "reopened index must be readable (no malformed disk image)")
	assert.Equal(t, int64(7), cnt, "index must reflect only the fresh build, not stale WAL pages")

	// The orphaned sidecars from the previous index must be gone (a fresh WAL
	// for the new live index may exist, but it must belong to the new .db).
	for _, suffix := range []string{".building-wal", ".building-shm"} {
		_, err := os.Stat(filepath.Join(dir, "msgs.db"+suffix))
		assert.True(t, os.IsNotExist(err), "temp build sidecar %s must be cleaned up", suffix)
	}
}

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
