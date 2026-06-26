package main

import (
	"database/sql"
	"testing"
	"time"

	"github.com/stretchr/testify/require"
)

// countLoadDataset is a stub Dataset whose Load inserts a configurable number of
// rows, letting tests exercise the empty-index guards without a real MySQL.
type countLoadDataset struct {
	name string
	n    int
}

func (d *countLoadDataset) Name() string { return d.name }

func (d *countLoadDataset) Load(_ *sql.DB, idx *Index) error {
	items := make([]Item, d.n)
	for i := range items {
		items[i] = Item{ExtID: int64(i + 1), MinLng: float64(i), MaxLng: float64(i)}
	}
	return InsertItems(idx, items, nil)
}

func (d *countLoadDataset) ApplyDelta(_ *sql.DB, _ *Index, _ time.Time) error      { return nil }
func (d *countLoadDataset) Query(_ *Index, _ QueryParams) ([]QueryResult, error)   { return nil, nil }
func (d *countLoadDataset) Within(_ *Index, _ QueryParams) ([]int64, error)        { return nil, nil }
func (d *countLoadDataset) RebuildInterval() time.Duration                         { return time.Hour }
func (d *countLoadDataset) DeltaInterval() time.Duration                           { return 0 }

// TestRebuildRefusesEmptyIndex verifies that a build producing 0 rows does not
// replace a previously-good live index — the failure mode that let the postcode
// remap write garbage when the locations index loaded empty.
func TestRebuildRefusesEmptyIndex(t *testing.T) {
	dir := t.TempDir()
	ds := &countLoadDataset{name: "stub", n: 1}
	srv := newServer(nil, dir, []Dataset{ds})

	require.NoError(t, srv.rebuild("stub"))
	state, _ := srv.getDataset("stub")
	require.NotNil(t, state.idx)
	prev := state.idx
	n, err := state.idx.CountRows()
	require.NoError(t, err)
	require.EqualValues(t, 1, n)

	// Next build loads 0 rows: rebuild must fail and keep the previous index.
	ds.n = 0
	err = srv.rebuild("stub")
	require.Error(t, err)
	require.Same(t, prev, state.idx, "live index must be unchanged after a 0-row build")
	n, err = state.idx.CountRows()
	require.NoError(t, err)
	require.EqualValues(t, 1, n, "previous good rows must still be served")
}

// TestStartupRebuildsEmptyExistingIndex verifies that an on-disk index which
// opens cleanly but contains 0 rows is discarded and rebuilt, rather than being
// adopted and served as "ready" with no data.
func TestStartupRebuildsEmptyExistingIndex(t *testing.T) {
	dir := t.TempDir()
	ds := &countLoadDataset{name: "stub", n: 5}
	srv := newServer(nil, dir, []Dataset{ds})

	// Leave an empty (0-row) index file at the dataset path.
	empty, err := CreateIndex(srv.idxPath("stub"))
	require.NoError(t, err)
	require.NoError(t, empty.Checkpoint())
	empty.Close()

	srv.startupLoad(false)

	state, _ := srv.getDataset("stub")
	require.NotNil(t, state.idx, "empty existing index must be rebuilt, not left nil")
	n, err := state.idx.CountRows()
	require.NoError(t, err)
	require.EqualValues(t, 5, n, "startup must rebuild rather than adopt the empty index")
}
