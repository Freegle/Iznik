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
	state.lastSync = time.Now()
	log.Printf("spatial-server: %s rebuilt in %s", name, time.Since(start).Round(time.Millisecond))
	return nil
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
				log.Printf("spatial-server: %s opened existing index from %s (%d rows)", name, idxPath, n)
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
				err := s.withIndex(func(idx *Index) error {
					return s.ds.ApplyDelta(srv.mysqlDB, idx, since)
				})
				switch {
				case err == errIndexNotReady:
					continue
				case err != nil:
					log.Printf("spatial-server: delta %s failed: %v", n, err)
				default:
					s.lastSync = time.Now()
				}
			}
		}(name, state, interval)
	}
}

func (srv *server) idxPath(name string) string {
	return srv.idxDir + "/" + name + ".db"
}
