package main

import (
	"database/sql"
	"errors"
	"fmt"
	"log"
	"os"
	"sync"
	"sync/atomic"
	"time"
)

// datasetState holds the live index and metadata for one dataset.
type datasetState struct {
	mu         sync.RWMutex
	idx        *Index
	lastSync   time.Time
	rebuilding atomic.Bool
	ds         Dataset
}

// errIndexNotReady is returned by withIndex when a dataset has no index yet.
var errIndexNotReady = errors.New("dataset not ready")

// withIndex runs fn while holding the read lock, so the index cannot be swapped
// out and Closed by a concurrent rebuild/delta mid-operation. swapIndex takes
// the write lock before Close()ing the old index, so it blocks until all
// in-flight readers here have returned — no use-after-close, and the old index
// is still Closed (no leak). Returns errIndexNotReady if no index is loaded yet.
func (s *datasetState) withIndex(fn func(idx *Index) error) error {
	s.mu.RLock()
	defer s.mu.RUnlock()
	if s.idx == nil {
		return errIndexNotReady
	}
	return fn(s.idx)
}

func (s *datasetState) swapIndex(newIdx *Index) {
	s.mu.Lock()
	defer s.mu.Unlock()
	old := s.idx
	s.idx = newIdx
	if old != nil {
		// Safe: the write lock is only granted once every withIndex reader has
		// released its RLock, so no request is still using `old`.
		old.Close()
	}
}

// ensureIndex guarantees a live index exists for the admin upsert (integration-
// test seeding) endpoint. When startupLoad/rebuild could not produce a non-empty
// index — e.g. the test database has no source rows — state.idx is nil and
// withIndex would reject the seed with errIndexNotReady. Lazily create an empty
// in-memory index here: upsert populates it immediately, so KNN never sees a
// 0-row index by this path, and the production guards that refuse to SERVE an
// empty index built from MySQL (rebuild/startupLoad) are untouched. In-memory
// keeps it off disk, so it can't be adopted by a later startupLoad and can't
// race a rebuild's file rename.
func (s *datasetState) ensureIndex() error {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.idx != nil {
		return nil
	}
	idx, err := CreateIndex(":memory:")
	if err != nil {
		return err
	}
	s.idx = idx
	return nil
}

// server manages all datasets and the MySQL connection.
type server struct {
	datasets map[string]*datasetState
	mysqlDB  *sql.DB
	idxDir   string
}

func newServer(mysqlDB *sql.DB, idxDir string, datasets []Dataset) *server {
	srv := &server{
		datasets: make(map[string]*datasetState, len(datasets)),
		mysqlDB:  mysqlDB,
		idxDir:   idxDir,
	}
	for _, ds := range datasets {
		srv.datasets[ds.Name()] = &datasetState{ds: ds}
	}
	return srv
}

func (srv *server) getDataset(name string) (*datasetState, bool) {
	state, ok := srv.datasets[name]
	return state, ok
}

// rebuild performs a full rebuild of a single dataset.
func (srv *server) rebuild(name string) error {
	state, ok := srv.datasets[name]
	if !ok {
		return fmt.Errorf("unknown dataset %q", name)
	}
	if !state.rebuilding.CompareAndSwap(false, true) {
		return fmt.Errorf("rebuild of %q already in progress", name)
	}
	defer state.rebuilding.Store(false)

	start := time.Now()
	idxPath := srv.idxPath(name)
	tmpPath := idxPath + ".building"
	os.Remove(tmpPath)

	newIdx, err := CreateIndex(tmpPath)
	if err != nil {
		return fmt.Errorf("%s: create index: %w", name, err)
	}

	if err := state.ds.Load(srv.mysqlDB, newIdx); err != nil {
		newIdx.Close()
		os.Remove(tmpPath)
		return fmt.Errorf("%s: load: %w", name, err)
	}
	// A zero-row build almost always means a transient problem (DB unreachable, a
	// failing geometry query) rather than a genuinely empty dataset — these
	// datasets are never empty in practice. Serving an empty index is silently
	// dangerous: callers like the postcode remap get absurd far-away "nearest"
	// results and write them to the DB. So refuse to swap in an empty index; keep
	// the previous good one live (if any) and surface the failure loudly.
	if n, cErr := newIdx.CountRows(); cErr != nil {
		newIdx.Close()
		os.Remove(tmpPath)
		return fmt.Errorf("%s: count rows: %w", name, cErr)
	} else if n == 0 {
		newIdx.Close()
		os.Remove(tmpPath)
		os.Remove(tmpPath + "-wal")
		os.Remove(tmpPath + "-shm")
		return fmt.Errorf("%s: build produced 0 rows; refusing to replace live index", name)
	}
	// Flush the WAL into the main .db before close+rename — rename moves only the
	// .db file, so anything still in the -wal would be lost (large indexes).
	if err := newIdx.Checkpoint(); err != nil {
		newIdx.Close()
		os.Remove(tmpPath)
		return fmt.Errorf("%s: checkpoint: %w", name, err)
	}
	newIdx.Close()

	if err := os.Rename(tmpPath, idxPath); err != nil {
		os.Remove(tmpPath)
		return fmt.Errorf("%s: rename index: %w", name, err)
	}
	// rename moved only the .db. Any -wal/-shm still on disk belong to the previous
	// (now-replaced) index; if left in place OpenIndex would replay that stale WAL
	// onto the fresh .db and corrupt it. The freshly built .db is already complete
	// (Checkpoint above flushed its WAL), so drop the old sidecars now. The old live
	// index keeps serving reads until swapIndex via its already-open fds (the inodes
	// survive the unlink on Linux), so this is safe to do before the swap.
	os.Remove(idxPath + "-wal")
	os.Remove(idxPath + "-shm")
	// Tidy any leftover WAL sidecars from the temp build path too.
	os.Remove(tmpPath + "-wal")
	os.Remove(tmpPath + "-shm")

	live, err := OpenIndex(idxPath)
	if err != nil {
		return fmt.Errorf("%s: reopen index: %w", name, err)
	}

	state.swapIndex(live)
	// Stamp the sync point at build START, not completion: the Load query's
	// InnoDB snapshot is from when it began, so rows created/updated while the
	// build ran are NOT in the new index — stamping completion time would put
	// them behind the delta horizon forever (a 96s build of the reach dataset
	// permanently missed 65 rows this way, caught by parity checks 2026-08-11).
	// Stamping the start makes the first delta re-fetch anything from during
	// the build; upserts are idempotent so the overlap is harmless. Persisted
	// into the index so a restart resumes from here too.
	state.lastSync = start
	if err := live.SetMetaTime("last_sync", start); err != nil {
		log.Printf("spatial-server: %s persist last_sync failed: %v", name, err)
	}
	// The index now matches the source as of this build. For drift-aware
	// datasets, record that baseline so the next CheckDrift compares against the
	// freshly-built state rather than re-triggering on the change we just healed.
	if dc, ok := state.ds.(DriftChecker); ok {
		if err := dc.NoteReconciled(srv.mysqlDB); err != nil {
			log.Printf("spatial-server: %s note-reconciled failed: %v", name, err)
		}
	}
	log.Printf("spatial-server: %s rebuilt in %s", name, time.Since(start).Round(time.Millisecond))
	return nil
}

// checkAndRebuildOnDrift runs a dataset's optional drift check and, if it
// reports the index has drifted from the source in a way the incremental delta
// cannot self-heal (swap-style hard deletes), triggers a full rebuild. It is a
// no-op for datasets that do not implement DriftChecker. Returns whether a
// rebuild ran. Safe to call on every delta tick.
func (srv *server) checkAndRebuildOnDrift(name string, state *datasetState) bool {
	dc, ok := state.ds.(DriftChecker)
	if !ok {
		return false
	}
	// Skip if a rebuild is already running — rebuild() also guards via its
	// atomic CAS, but checking first avoids a wasted CheckDrift and a noisy
	// "already in progress" error.
	if state.rebuilding.Load() {
		return false
	}

	var need bool
	var reason string
	err := state.withIndex(func(idx *Index) error {
		var e error
		need, reason, e = dc.CheckDrift(srv.mysqlDB, idx)
		return e
	})
	switch {
	case err == errIndexNotReady:
		return false
	case err != nil:
		log.Printf("spatial-server: drift check %s failed: %v", name, err)
		return false
	case !need:
		return false
	}

	log.Printf("spatial-server: drift-triggered rebuild of %s: %s", name, reason)
	if err := srv.rebuild(name); err != nil {
		log.Printf("spatial-server: drift rebuild %s failed: %v", name, err)
		return false
	}
	return true
}

// rebuildAll rebuilds all datasets.
func (srv *server) rebuildAll() {
	for name := range srv.datasets {
		if err := srv.rebuild(name); err != nil {
			log.Printf("spatial-server: rebuild %s failed: %v", name, err)
		}
	}
}

// startupLoad opens existing indexes or builds them from MySQL.
func (srv *server) startupLoad(forceRebuild bool) {
	for name, state := range srv.datasets {
		if forceRebuild {
			log.Printf("spatial-server: force-rebuild %s...", name)
			if err := srv.rebuild(name); err != nil {
				log.Printf("spatial-server: WARNING: forced rebuild of %s failed (%v); starting with no index", name, err)
			}
			continue
		}

		idxPath := srv.idxPath(name)
		if existing, err := OpenIndex(idxPath); err == nil {
			// An on-disk index can open cleanly yet be empty — e.g. a previous
			// build was interrupted or produced 0 rows. Adopting it would report
			// ready with count 0 and silently serve wrong results, so verify it
			// has rows; if not, discard it and rebuild from MySQL.
			if n, cErr := existing.CountRows(); cErr == nil && n > 0 {
				state.idx = existing
				// Resume deltas from the index's own persisted sync point (zero
				// when the index predates the meta table — the old one-interval
				// lookback then applies, unchanged).
				if ls, mErr := existing.GetMetaTime("last_sync"); mErr == nil && !ls.IsZero() {
					state.lastSync = ls
				}
				log.Printf("spatial-server: %s opened existing index from %s (%d rows, last_sync %s)", name, idxPath, n, state.lastSync.Format(time.RFC3339))
			} else {
				existing.Close()
				log.Printf("spatial-server: existing index for %s is empty/unreadable (rows=%d err=%v), rebuilding...", name, n, cErr)
				if err := srv.rebuild(name); err != nil {
					log.Printf("spatial-server: WARNING: rebuild of %s failed (%v); starting with no index", name, err)
				}
			}
		} else {
			log.Printf("spatial-server: no usable index for %s (%v), building...", name, err)
			if err := srv.rebuild(name); err != nil {
				log.Printf("spatial-server: WARNING: initial build of %s failed (%v); starting with no index", name, err)
			}
		}
	}
}

// startScheduler launches background goroutines for nightly rebuilds and
// per-dataset incremental ticks.
func (srv *server) startScheduler() {
	go func() {
		for {
			now := time.Now().UTC()
			next := time.Date(now.Year(), now.Month(), now.Day()+1, 3, 0, 0, 0, time.UTC)
			time.Sleep(time.Until(next))
			log.Printf("spatial-server: nightly rebuild starting...")
			srv.rebuildAll()
		}
	}()

	for name, state := range srv.datasets {
		interval := state.ds.DeltaInterval()
		if interval == 0 {
			// Rebuild-only dataset: schedule periodic full rebuilds instead of deltas.
			go func(n string, rebuildInterval time.Duration) {
				for {
					time.Sleep(rebuildInterval)
					if err := srv.rebuild(n); err != nil {
						log.Printf("spatial-server: periodic rebuild of %s failed: %v", n, err)
					}
				}
			}(name, state.ds.RebuildInterval())
			continue
		}

		go func(n string, s *datasetState, d time.Duration) {
			for {
				time.Sleep(d)
				since := s.lastSync
				if since.IsZero() {
					since = time.Now().Add(-d)
				}
				// Capture the sync point BEFORE the delta query runs — stamping
				// completion time would put rows updated during the query behind
				// the horizon (same reasoning as rebuild's build-start stamp).
				// Persist it into the index so a restart resumes deltas from here
				// instead of "now minus one interval" (an adopted index otherwise
				// silently misses everything that changed while the process was
				// down or since its last delta — caught live on the reach dataset
				// 2026-08-11: a polygon grew at 20:44, the process restarted at
				// 20:53, and the row stayed stale despite ticking deltas).
				tickStart := time.Now()
				err := s.withIndex(func(idx *Index) error {
					if err := s.ds.ApplyDelta(srv.mysqlDB, idx, since); err != nil {
						return err
					}
					return idx.SetMetaTime("last_sync", tickStart)
				})
				switch {
				case err == errIndexNotReady:
					continue
				case err != nil:
					log.Printf("spatial-server: delta %s failed: %v", n, err)
				default:
					s.lastSync = tickStart
				}
				// After applying the delta, check whether the source table has
				// drifted from the index in a way the delta can't self-heal
				// (swap-style hard deletes) and rebuild if so. No-op for datasets
				// that don't implement DriftChecker.
				srv.checkAndRebuildOnDrift(n, s)
			}
		}(name, state, interval)
	}
}

func (srv *server) idxPath(name string) string {
	// The jobs index Extra schema gained a `company` field (for KNN-side dedup of
	// the duplicate WhatJobs postings — Discourse 9363). A pre-company on-disk index
	// would be adopted at startup and dedup on (", title) — collapsing distinct
	// employers — until the next nightly/swap rebuild. Bumping the filename means a
	// deploy can't find the old index and rebuilds once from MySQL with `company`.
	if name == "jobs" {
		return srv.idxDir + "/" + name + ".v2.db"
	}
	return srv.idxDir + "/" + name + ".db"
}
