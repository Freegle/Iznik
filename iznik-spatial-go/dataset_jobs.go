package main

import (
	"database/sql"
	"fmt"
	"log"
	"sync"
	"time"

	"github.com/peterstace/simplefeatures/geom"
)

// minJobsCPC is the minimum cost-per-click for a job to be servable. Kept in
// step with the WhatJobs sync (WhatJobsService::MINIMUM_CPC) and jobsLiveFilter.
const minJobsCPC = 0.10

// jobsLiveFilter selects the rows the server actually serves: visible, paying,
// and geolocated. Single-sourced so the full load (loadJobs) and the drift
// live-count query stay identical; ApplyDelta applies the same predicate in Go
// so the incremental set can never diverge from the full-load set (which would
// otherwise make the index/table count comparison report phantom drift).
const jobsLiveFilter = "visible = 1 AND cpc >= 0.10 AND geometry IS NOT NULL"

// driftCountTolerance is how far the index row count may drift from the live
// table before the backstop forces a rebuild. The seenat trigger already
// rebuilds on every swap, so in steady state the drift is ~0; this backstop only
// matters when the seenat baseline was masked — e.g. the server adopted a stale
// on-disk index at startup (no rebuild ran to set the baseline) and orphans
// accumulated. Small but non-zero to absorb the brief race between the COUNT and
// CountRows reads around a concurrent swap.
const driftCountTolerance = 100

// JobsDataset implements Dataset (and DriftChecker) for the jobs (polygon) table.
type JobsDataset struct {
	mu sync.Mutex
	// lastMaxSeen is CAST(MAX(seenat) AS CHAR) as of the last reconcile (build or
	// drift-rebuild). A WhatJobs swap stamps seenat=now on every row and is the
	// only writer of seenat, so MAX(seenat) advancing past this is an exact "a
	// swap happened" signal. Stored as the raw textual timestamp (not time.Time)
	// so it scans cleanly on both the MySQL driver and the sqlite test fake; the
	// fixed "YYYY-MM-DD HH:MM:SS" format orders lexically == chronologically.
	lastMaxSeen string
	baselined   bool
}

func (d *JobsDataset) Name() string { return "jobs" }

func (d *JobsDataset) RebuildInterval() time.Duration { return 24 * time.Hour }
func (d *JobsDataset) DeltaInterval() time.Duration   { return 5 * time.Minute }

func (d *JobsDataset) Load(mysqlDB *sql.DB, idx *Index) error {
	return loadJobs(mysqlDB, idx, "")
}

func (d *JobsDataset) ApplyDelta(mysqlDB *sql.DB, idx *Index, since time.Time) error {
	rows, err := mysqlDB.Query(`
		SELECT id, ST_AsWKB(geometry) AS wkb, COALESCE(title, '') AS title, COALESCE(city, '') AS city, cpc, visible
		FROM jobs
		WHERE seenat > ?
	`, since.UTC())
	if err != nil {
		return fmt.Errorf("jobs delta query: %w", err)
	}
	defer rows.Close()

	var upserted, removed int
	for rows.Next() {
		var id int64
		var wkbRaw []byte
		var title, city string
		var cpc float64
		var visible int
		if err := rows.Scan(&id, &wkbRaw, &title, &city, &cpc, &visible); err != nil {
			return fmt.Errorf("scan: %w", err)
		}
		// Remove any row that no longer meets jobsLiveFilter, not just visible=0.
		// loadJobs (the full rebuild) excludes sub-cpc and null-geometry rows, so
		// the delta must drop them too — otherwise a row that drops below cpc or
		// loses its geometry while staying visible would linger in the index as a
		// phantom and inflate the index/table drift count. ST_AsWKB(NULL geometry)
		// scans as an empty/nil blob, so len(wkbRaw)==0 detects the null-geom case.
		if visible == 0 || cpc < minJobsCPC || len(wkbRaw) == 0 {
			if err := idx.DeleteByExtID(id); err != nil {
				log.Printf("jobs delta: remove non-servable id=%d: %v", id, err)
			}
			removed++
			continue
		}
		wkb := stripSRIDPrefix(wkbRaw)
		g, err := geom.UnmarshalWKB(wkb, geom.NoValidate{})
		if err != nil {
			continue
		}
		env := g.Envelope()
		min, max, ok := env.MinMaxXYs()
		if !ok {
			continue
		}
		item := Item{
			ExtID:  id,
			WKB:    wkb,
			Area:   g.Area(),
			MinLng: min.X,
			MaxLng: max.X,
			MinLat: min.Y,
			MaxLat: max.Y,
			Extra:  map[string]any{"title": title, "city": city, "cpc": cpc},
		}
		if err := InsertItems(idx, []Item{item}, nil); err != nil {
			log.Printf("jobs delta: upsert id=%d: %v", id, err)
		}
		upserted++
	}
	if err := rows.Err(); err != nil {
		return err
	}
	log.Printf("jobs delta: upserted=%d removed=%d since=%s", upserted, removed, since.Format(time.RFC3339))
	return nil
}

func (d *JobsDataset) Query(idx *Index, params QueryParams) ([]QueryResult, error) {
	if params.Limit <= 0 {
		params.Limit = 10
	}
	return FindNearestPolygon(idx, params.Lng, params.Lat, params.Limit, nil)
}

func (d *JobsDataset) Within(idx *Index, params QueryParams) ([]int64, error) {
	if params.Polygon == nil {
		return nil, fmt.Errorf("polygon parameter is required for Within queries")
	}
	ids, err := idx.QueryWithin(*params.Polygon)
	if err != nil {
		return nil, err
	}
	if len(ids) > maxWithinResults {
		return nil, ErrTooManyResults
	}
	return ids, nil
}

func loadJobs(mysqlDB *sql.DB, idx *Index, extraWhere string) error {
	query := `
		SELECT id, ST_AsWKB(geometry) AS wkb, COALESCE(title, '') AS title, COALESCE(city, '') AS city, cpc
		FROM jobs
		WHERE ` + jobsLiveFilter + `
	` + extraWhere

	rows, err := mysqlDB.Query(query)
	if err != nil {
		return fmt.Errorf("jobs load query: %w", err)
	}
	defer rows.Close()

	var items []Item
	var skipped int
	for rows.Next() {
		var id int64
		var wkbRaw []byte
		var title, city string
		var cpc float64
		if err := rows.Scan(&id, &wkbRaw, &title, &city, &cpc); err != nil {
			return fmt.Errorf("scan: %w", err)
		}
		wkb := stripSRIDPrefix(wkbRaw)
		g, err := geom.UnmarshalWKB(wkb, geom.NoValidate{})
		if err != nil {
			skipped++
			continue
		}
		env := g.Envelope()
		min, max, ok := env.MinMaxXYs()
		if !ok {
			skipped++
			continue
		}
		items = append(items, Item{
			ExtID:  id,
			WKB:    wkb,
			Area:   g.Area(),
			MinLng: min.X,
			MaxLng: max.X,
			MinLat: min.Y,
			MaxLat: max.Y,
			Extra:  map[string]any{"title": title, "city": city, "cpc": cpc},
		})
	}
	if err := rows.Err(); err != nil {
		return err
	}
	log.Printf("jobs load: loaded %d items (%d skipped)", len(items), skipped)
	return InsertItems(idx, items, nil)
}

// jobsMaxSeen returns CAST(MAX(seenat) AS CHAR) — a stable textual timestamp.
// Casting to CHAR avoids driver-specific time handling: the MySQL driver
// (parseTime=true) returns a time.Time for a DATETIME column but the textual
// CHAR result is a plain string, and the sqlite test fake returns the same
// text. The empty string is returned for an empty table (MAX over no rows).
func (d *JobsDataset) jobsMaxSeen(db *sql.DB) (string, error) {
	var s sql.NullString
	if err := db.QueryRow(`SELECT CAST(MAX(seenat) AS CHAR) FROM jobs`).Scan(&s); err != nil {
		return "", err
	}
	return s.String, nil
}

// liveCount returns the number of rows the server would serve (the loadJobs
// set). Compared against the index row count to detect orphan drift.
func (d *JobsDataset) liveCount(db *sql.DB) (int64, error) {
	var n int64
	err := db.QueryRow(`SELECT COUNT(*) FROM jobs WHERE ` + jobsLiveFilter).Scan(&n)
	return n, err
}

// NoteReconciled records the source's current MAX(seenat) as the baseline the
// next CheckDrift compares against. Called after every successful (re)build so
// startup/nightly/drift rebuilds all reset the baseline uniformly.
func (d *JobsDataset) NoteReconciled(db *sql.DB) error {
	max, err := d.jobsMaxSeen(db)
	if err != nil {
		return err
	}
	d.mu.Lock()
	d.lastMaxSeen = max
	d.baselined = true
	d.mu.Unlock()
	return nil
}

// CheckDrift reports whether the jobs index has drifted from the live table
// enough to warrant a full rebuild. See DriftChecker.
//
// Primary signal — seenat advance: a WhatJobs swap stamps seenat=now on every
// row and is the only writer of seenat, so MAX(seenat) advancing past the last
// reconciled baseline means a swap happened (which hard-deleted closed postings,
// leaving orphans the add/update-only delta can't remove). This is exact and
// fires exactly once per swap; it even catches a swap that closes and opens
// equal numbers of postings (identical row count, all-new seenat) — the case a
// bare count comparison would miss.
//
// Backstop — count drift: abs(indexCount - liveCount) over the tolerance. Covers
// the case the seenat baseline was masked (a stale on-disk index adopted at
// startup without a rebuild), where orphans are present but seenat didn't move.
//
// On the very first call after an adopted index (no rebuild set a baseline) the
// seenat baseline is established here and the seenat trigger is suppressed for
// that tick; the count backstop still runs, so a stale adopted index is caught
// immediately.
func (d *JobsDataset) CheckDrift(db *sql.DB, idx *Index) (bool, string, error) {
	max, err := d.jobsMaxSeen(db)
	if err != nil {
		return false, "", fmt.Errorf("jobs drift: max seenat: %w", err)
	}
	live, err := d.liveCount(db)
	if err != nil {
		return false, "", fmt.Errorf("jobs drift: live count: %w", err)
	}
	indexCount, err := idx.CountRows()
	if err != nil {
		return false, "", fmt.Errorf("jobs drift: index count: %w", err)
	}

	d.mu.Lock()
	baselined := d.baselined
	last := d.lastMaxSeen
	if !d.baselined {
		// Lazy baseline for an adopted index — record the current max and treat
		// this tick's table state as the reference (no seenat trigger), leaving
		// the count backstop below to catch a stale adopted index.
		d.lastMaxSeen = max
		d.baselined = true
	}
	d.mu.Unlock()

	// Note: a seenat trigger deliberately does NOT advance the baseline here —
	// only a successful rebuild (via NoteReconciled) does. So if a triggered
	// rebuild fails, the next tick re-fires and retries.
	swapped := baselined && max != "" && max > last

	drift := indexCount - live
	if drift < 0 {
		drift = -drift
	}
	countDrift := drift > driftCountTolerance

	switch {
	case swapped:
		return true, fmt.Sprintf("swap detected: seenat advanced %q -> %q (index=%d live=%d)",
			last, max, indexCount, live), nil
	case countDrift:
		return true, fmt.Sprintf("index/table drift %d > tolerance %d (index=%d live=%d, seenat=%q)",
			drift, driftCountTolerance, indexCount, live, max), nil
	default:
		return false, "", nil
	}
}
