package main

import (
	"database/sql"
	"testing"
	"time"

	"github.com/stretchr/testify/require"
)

// newFakeJobsDB returns an in-memory sqlite DB with a minimal `jobs` table
// standing in for the production MySQL source. The drift queries
// (CAST(MAX(seenat) AS CHAR) and COUNT over jobsLiveFilter) are portable SQL, so
// they run identically here without needing MySQL. MaxOpenConns(1) keeps the
// :memory: DB on a single connection (otherwise each pooled connection is a
// distinct empty database).
func newFakeJobsDB(t *testing.T) *sql.DB {
	t.Helper()
	db, err := sql.Open("sqlite", ":memory:")
	require.NoError(t, err)
	db.SetMaxOpenConns(1)
	t.Cleanup(func() { db.Close() })
	_, err = db.Exec(`CREATE TABLE jobs (
		id       INTEGER PRIMARY KEY,
		seenat   TEXT,
		visible  INTEGER,
		cpc      REAL,
		geometry BLOB
	)`)
	require.NoError(t, err)
	return db
}

// insertJob inserts one row into the fake jobs table. A nil geom inserts NULL.
func insertJob(t *testing.T, db *sql.DB, id int64, seenat string, visible int, cpc float64, geom any) {
	t.Helper()
	_, err := db.Exec(`INSERT INTO jobs (id, seenat, visible, cpc, geometry) VALUES (?,?,?,?,?)`,
		id, seenat, visible, cpc, geom)
	require.NoError(t, err)
}

// indexWith returns an in-memory index seeded with the given external IDs
// (degenerate bounding boxes — only the row count matters for drift).
func indexWith(t *testing.T, ids ...int64) *Index {
	t.Helper()
	idx, err := CreateIndex(":memory:")
	require.NoError(t, err)
	t.Cleanup(func() { idx.Close() })
	items := make([]Item, 0, len(ids))
	for _, id := range ids {
		items = append(items, Item{ExtID: id})
	}
	require.NoError(t, InsertItems(idx, items, nil))
	return idx
}

// A swap that closes and opens the SAME number of postings leaves the row count
// unchanged but stamps every row with a fresh seenat. The seenat trigger catches
// it; a bare count comparison would not. This is the headline case for choosing
// seenat as the primary signal.
func TestJobsDataset_CheckDrift_SeenatAdvanceOnEqualCountSwap(t *testing.T) {
	db := newFakeJobsDB(t)
	for _, id := range []int64{1, 2, 3} {
		insertJob(t, db, id, "2026-06-23 10:00:00", 1, 0.12, []byte("g"))
	}
	d := &JobsDataset{}
	idx := indexWith(t, 1, 2, 3)

	// First call establishes the baseline and must NOT trigger (no spurious
	// rebuild 5 minutes after startup).
	need, _, err := d.CheckDrift(db, idx)
	require.NoError(t, err)
	require.False(t, need, "first call should only baseline, not trigger")

	// Simulate a swap: same three ids stay, but every row gets a fresh seenat.
	_, err = db.Exec(`UPDATE jobs SET seenat = '2026-06-23 13:00:00'`)
	require.NoError(t, err)

	need, reason, err := d.CheckDrift(db, idx)
	require.NoError(t, err)
	require.True(t, need, "seenat advance must trigger even when the row count is unchanged")
	require.Contains(t, reason, "swap detected")

	// After reconciling (as a rebuild would), the same state must not re-trigger.
	require.NoError(t, d.NoteReconciled(db))
	need, _, err = d.CheckDrift(db, idx)
	require.NoError(t, err)
	require.False(t, need, "no further trigger once reconciled")
}

// The count backstop fires when orphans accumulate without a seenat advance —
// the stale-adopted-index case. seenat is held constant so only the count path
// can trigger.
func TestJobsDataset_CheckDrift_CountBackstop(t *testing.T) {
	db := newFakeJobsDB(t)
	for _, id := range []int64{1, 2, 3} {
		insertJob(t, db, id, "2026-06-23 10:00:00", 1, 0.12, []byte("g"))
	}
	d := &JobsDataset{}

	// Index carries the 3 live ids plus enough orphans to exceed the tolerance.
	orphans := make([]int64, 0, driftCountTolerance+10)
	ids := []int64{1, 2, 3}
	for i := int64(0); i < driftCountTolerance+10; i++ {
		ids = append(ids, 1000+i)
		orphans = append(orphans, 1000+i)
	}
	idx := indexWith(t, ids...)

	// First call baselines seenat; the backstop still evaluates this tick, so a
	// stale adopted index is caught immediately.
	need, reason, err := d.CheckDrift(db, idx)
	require.NoError(t, err)
	require.True(t, need, "orphan count over tolerance must trigger via the backstop")
	require.Contains(t, reason, "drift")

	// Just under the tolerance must NOT trigger.
	d2 := &JobsDataset{}
	few := []int64{1, 2, 3}
	for i := int64(0); i < driftCountTolerance-1; i++ {
		few = append(few, 2000+i)
	}
	idxFew := indexWith(t, few...)
	need, _, err = d2.CheckDrift(db, idxFew)
	require.NoError(t, err)
	require.False(t, need, "drift within tolerance must not trigger")
}

// liveCount and the delta filter must agree with loadJobs: non-visible, sub-cpc
// and null-geometry rows are not served and must not count toward the live set
// (otherwise they would register as phantom drift).
func TestJobsDataset_LiveCount_FilterAlignment(t *testing.T) {
	db := newFakeJobsDB(t)
	insertJob(t, db, 1, "2026-06-23 10:00:00", 1, 0.12, []byte("g")) // servable
	insertJob(t, db, 2, "2026-06-23 10:00:00", 1, 0.12, []byte("g")) // servable
	insertJob(t, db, 3, "2026-06-23 10:00:00", 0, 0.12, []byte("g")) // invisible
	insertJob(t, db, 4, "2026-06-23 10:00:00", 1, 0.05, []byte("g")) // sub-cpc
	insertJob(t, db, 5, "2026-06-23 10:00:00", 1, 0.12, nil)         // null geometry

	d := &JobsDataset{}
	n, err := d.liveCount(db)
	require.NoError(t, err)
	require.EqualValues(t, 2, n, "only visible, paying, geolocated rows count")
}

// An empty table yields an empty MAX(seenat); CheckDrift must not panic or
// falsely trigger on it.
func TestJobsDataset_CheckDrift_EmptyTable(t *testing.T) {
	db := newFakeJobsDB(t)
	d := &JobsDataset{}
	idx := indexWith(t) // empty index
	need, _, err := d.CheckDrift(db, idx)
	require.NoError(t, err)
	require.False(t, need)
}

// fakeDriftDataset is a minimal DriftChecker whose Load rebuilds a clean index
// from a fixed live set, so the wiring (checkAndRebuildOnDrift -> rebuild) can be
// exercised without MySQL.
type fakeDriftDataset struct {
	liveIDs []int64
}

func (f *fakeDriftDataset) Name() string { return "fakedrift" }
func (f *fakeDriftDataset) Load(_ *sql.DB, idx *Index) error {
	items := make([]Item, 0, len(f.liveIDs))
	for _, id := range f.liveIDs {
		items = append(items, Item{ExtID: id})
	}
	return InsertItems(idx, items, nil)
}
func (f *fakeDriftDataset) ApplyDelta(_ *sql.DB, _ *Index, _ time.Time) error { return nil }
func (f *fakeDriftDataset) Query(_ *Index, _ QueryParams) ([]QueryResult, error) {
	return nil, nil
}
func (f *fakeDriftDataset) Within(_ *Index, _ QueryParams) ([]int64, error) { return nil, nil }
func (f *fakeDriftDataset) RebuildInterval() time.Duration                  { return time.Hour }
func (f *fakeDriftDataset) DeltaInterval() time.Duration                    { return time.Minute }
func (f *fakeDriftDataset) CheckDrift(_ *sql.DB, idx *Index) (bool, string, error) {
	n, err := idx.CountRows()
	if err != nil {
		return false, "", err
	}
	if int(n) != len(f.liveIDs) {
		return true, "count mismatch", nil
	}
	return false, "", nil
}
func (f *fakeDriftDataset) NoteReconciled(_ *sql.DB) error { return nil }

// allExtIDs returns the external ids currently in the index, sorted ascending.
func allExtIDs(t *testing.T, idx *Index) []int64 {
	t.Helper()
	rows, err := idx.db.Query(`SELECT extid FROM items ORDER BY extid`)
	require.NoError(t, err)
	defer rows.Close()
	var ids []int64
	for rows.Next() {
		var id int64
		require.NoError(t, rows.Scan(&id))
		ids = append(ids, id)
	}
	require.NoError(t, rows.Err())
	return ids
}

// The brief's scenario: an index holds stale ext-ids absent from the source; the
// drift check rebuilds and the orphans are gone.
func TestCheckAndRebuildOnDrift_ClearsOrphans(t *testing.T) {
	dir := t.TempDir()
	ds := &fakeDriftDataset{liveIDs: []int64{1, 2, 3}}
	srv := newServer(nil, dir, []Dataset{ds})
	state := srv.datasets["fakedrift"]

	// Seed a live index with the 3 source ids plus 2 orphans (99, 100) that the
	// source no longer has — the swap-leftover situation.
	idxPath := srv.idxPath("fakedrift")
	seed, err := CreateIndex(idxPath)
	require.NoError(t, err)
	require.NoError(t, InsertItems(seed, []Item{
		{ExtID: 1}, {ExtID: 2}, {ExtID: 3}, {ExtID: 99}, {ExtID: 100},
	}, nil))
	require.NoError(t, seed.Checkpoint())
	require.NoError(t, seed.Close())

	live, err := OpenIndex(idxPath)
	require.NoError(t, err)
	state.idx = live

	rebuilt := srv.checkAndRebuildOnDrift("fakedrift", state)
	require.True(t, rebuilt, "drift (5 indexed vs 3 live) must trigger a rebuild")

	n, err := state.idx.CountRows()
	require.NoError(t, err)
	require.EqualValues(t, 3, n)
	require.Equal(t, []int64{1, 2, 3}, allExtIDs(t, state.idx), "orphans 99,100 must be gone")

	// A second check is now clean (no rebuild).
	require.False(t, srv.checkAndRebuildOnDrift("fakedrift", state))
}

// Datasets that don't implement DriftChecker are a no-op (e.g. locations).
func TestCheckAndRebuildOnDrift_NonDriftDataset(t *testing.T) {
	srv := newServer(nil, t.TempDir(), []Dataset{&LocationsDataset{}})
	state := srv.datasets["locations"]
	require.False(t, srv.checkAndRebuildOnDrift("locations", state))
}
