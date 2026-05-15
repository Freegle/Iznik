package main

import (
	"database/sql"
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

func (s *datasetState) getIndex() *Index {
	s.mu.RLock()
	defer s.mu.RUnlock()
	return s.idx
}

func (s *datasetState) swapIndex(newIdx *Index) {
	s.mu.Lock()
	defer s.mu.Unlock()
	old := s.idx
	s.idx = newIdx
	if old != nil {
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
	newIdx.Close()

	if err := os.Rename(tmpPath, idxPath); err != nil {
		os.Remove(tmpPath)
		return fmt.Errorf("%s: rename index: %w", name, err)
	}

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
			state.idx = existing
			log.Printf("spatial-server: %s opened existing index from %s", name, idxPath)
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
				idx := s.getIndex()
				if idx == nil {
					continue
				}
				since := s.lastSync
				if since.IsZero() {
					since = time.Now().Add(-d)
				}
				if err := s.ds.ApplyDelta(srv.mysqlDB, idx, since); err != nil {
					log.Printf("spatial-server: delta %s failed: %v", n, err)
				} else {
					s.lastSync = time.Now()
				}
			}
		}(name, state, interval)
	}
}

func (srv *server) idxPath(name string) string {
	return srv.idxDir + "/" + name + ".db"
}
