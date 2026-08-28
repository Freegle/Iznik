package main

import (
	"database/sql"
	"fmt"
	"log"
	"runtime"
	"sync"
	"sync/atomic"
	"time"

	"spatial-server/cellset"
)

// ReachDataset serves point-in-reach for the browse feed's unread badge (and
// eventually the feed itself): "which posts' rippling-out reach currently
// covers this viewer?".
//
// Answering that in MySQL costs 95-98% of the badge-count query (measured
// 2026-08-11 on prod: 300-500ms per call, ~215 calls/min at peak ≈ 2 cores of
// mysqld on the write node) because the sandwich-bounds quick-accept barely
// fires — 65-84% of R-tree candidates have no inner bound, so they fall
// through to exact ST_Contains against ~11k-vertex polygon BLOBs.
//
// The index is built from the stored cell grid (rippling_reach.polygon_cells,
// plans/2026-08-24-rippling-reach-raster-storage.md): the item blob is the
// encoded grid itself (~23KB), and a query point is answered EXACTLY by
// walking its run stream — no boundary band, no `partial`, no fallback to the
// geometry. A row with no readable cells is skipped (fail-closed - it has no
// reach anywhere).
//
// Status filtering is the caller's contract: rows with status='held' (reach
// frozen because the origin post went back to Pending) are excluded here,
// matching the browse feed's `rr.status != 'held'`. Other statuses
// (expanding/stopped/done) all count — a stopped reach still covers people.
type ReachDataset struct{}

func (d *ReachDataset) Name() string { return "reach" }

// Reach rows change on ripple ticks (half-hourly sweeps) plus ad-hoc holds
// and clips; a 2-minute delta (keyed on updated_at, which Laravel stamps on
// every write) keeps the badge within its own 60s poll's staleness budget.
func (d *ReachDataset) RebuildInterval() time.Duration { return 24 * time.Hour }
func (d *ReachDataset) DeltaInterval() time.Duration   { return 2 * time.Minute }

// reachSelect builds the row query. `retired` = the row's grid has been
// drained under labels-truth (stored label + union threshold answer
// everything): such rows leave this index entirely - containment for them is
// served by the routing server's label evaluation (the discover arm).
func reachSelect(where string) string {
	return "SELECT rr.msgid, rr.status, rr.polygon_cells, " +
		"(rr.reach_labels IS NOT NULL AND rr.polygon_cells IS NULL) AS retired " +
		"FROM rippling_reach rr " + where
}

type reachRawRow struct {
	msgid   int64
	status  string
	cells   []byte
	retired bool
}

func scanReachRaw(rows *sql.Rows) (reachRawRow, error) {
	var r reachRawRow
	return r, rows.Scan(&r.msgid, &r.status, &r.cells, &r.retired)
}

func (d *ReachDataset) Load(mysqlDB *sql.DB, idx *Index) error {
	// Load ALL non-held statuses; held rows are simply absent (the delta
	// re-adds them if released).
	rows, err := mysqlDB.Query(reachSelect(`WHERE rr.status != 'held'`))
	if err != nil {
		return fmt.Errorf("reach load query: %w", err)
	}
	defer rows.Close()

	// A cells row is a header validation (microseconds). Capped below NumCPU
	// so a rebuild never starves the co-located apiv2/routing processes on
	// the prod db nodes.
	workers := runtime.NumCPU() - 2
	if workers < 1 {
		workers = 1
	}
	in := make(chan reachRawRow, workers*2)
	out := make(chan Item, workers*2)
	var skipped int64
	var wg sync.WaitGroup
	for w := 0; w < workers; w++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for r := range in {
				if r.retired {
					// Drained under labels-truth: not this index's row.
					continue
				}
				item, ok := buildReachItem(r.msgid, r.status, r.cells)
				if !ok {
					atomic.AddInt64(&skipped, 1)
					continue
				}
				out <- item
			}
		}()
	}
	go func() {
		wg.Wait()
		close(out)
	}()

	var items []Item
	collectDone := make(chan struct{})
	go func() {
		for item := range out {
			items = append(items, item)
		}
		close(collectDone)
	}()

	var scanErr error
	for rows.Next() {
		r, err := scanReachRaw(rows)
		if err != nil {
			scanErr = err
			break
		}
		in <- r
	}
	close(in)
	<-collectDone
	if scanErr != nil {
		return fmt.Errorf("reach scan: %w", scanErr)
	}
	if err := rows.Err(); err != nil {
		return err
	}
	log.Printf("reach load: loaded %d items (%d skipped)", len(items), skipped)
	return InsertItems(idx, items, nil)
}

// ApplyDelta upserts reaches modified since `since` and removes newly-held
// ones. Clips and expansions both arrive as plain updates: the item is
// rebuilt from the row's current cells, so there is no drift to reconcile.
func (d *ReachDataset) ApplyDelta(mysqlDB *sql.DB, idx *Index, since time.Time) error {
	rows, err := mysqlDB.Query(reachSelect(`WHERE rr.updated_at > ?`), since.UTC())
	if err != nil {
		return fmt.Errorf("reach delta query: %w", err)
	}
	defer rows.Close()

	var upserted, removed, skipped int
	for rows.Next() {
		r, err := scanReachRaw(rows)
		if err != nil {
			log.Printf("reach scan: %v", err)
			skipped++
			continue
		}
		if r.retired {
			// A writer drained this row's grid (labels-truth): remove it -
			// a skipped upsert would leave the PREVIOUS tick's smaller
			// reach serving stale answers forever.
			if err := idx.DeleteByExtID(r.msgid); err != nil {
				log.Printf("reach delta: remove retired msgid=%d: %v", r.msgid, err)
			}
			removed++
			continue
		}
		item, ok := buildReachItem(r.msgid, r.status, r.cells)
		if !ok {
			skipped++
			continue
		}
		if item.Extra["status"] == "held" {
			if err := idx.DeleteByExtID(item.ExtID); err != nil {
				log.Printf("reach delta: remove held msgid=%d: %v", item.ExtID, err)
			}
			removed++
			continue
		}
		if err := InsertItems(idx, []Item{item}, nil); err != nil {
			log.Printf("reach delta: upsert msgid=%d: %v", item.ExtID, err)
		}
		upserted++
	}
	if err := rows.Err(); err != nil {
		return err
	}
	if upserted+removed+skipped > 0 {
		log.Printf("reach delta: upserted=%d removed=%d skipped=%d since=%s",
			upserted, removed, skipped, since.Format(time.RFC3339))
	}

	// Reconcile against the source's full id list. The updated_at delta above
	// cannot see two kinds of change: hard DELETEs (a reach row is deleted when
	// its post completes/purges — parity checks 2026-08-11 found deleted rows
	// still claiming containment from the index), and rows changed while the
	// server was DOWN (startup adopts an on-disk index with lastSync unset, so
	// the first delta looks back only one interval). The id list is ~52k
	// bigints ≈ 400KB per tick — cheap — and makes the index converge on the
	// source within one delta interval regardless of what was missed.
	return d.reconcile(mysqlDB, idx)
}

// reconcile diffs the index's extids against rippling_reach's live msgids:
// index-only entries are deleted, source-only msgids are fetched and built.
func (d *ReachDataset) reconcile(mysqlDB *sql.DB, idx *Index) error {
	// Retired rows (grid drained under labels-truth) are OUT of the source
	// set, so their stale index entries are deleted like any other.
	rows, err := mysqlDB.Query(`SELECT msgid FROM rippling_reach WHERE status != 'held'
		AND NOT (reach_labels IS NOT NULL AND polygon_cells IS NULL)`)
	if err != nil {
		return fmt.Errorf("reach reconcile ids: %w", err)
	}
	source := make(map[int64]struct{})
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			rows.Close()
			return fmt.Errorf("reach reconcile scan: %w", err)
		}
		source[id] = struct{}{}
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return err
	}
	// An empty source id list is almost certainly a failed query, not an empty
	// table (same reasoning as the rebuild zero-row guard) — deleting the whole
	// index on it would be destructive, so refuse.
	if len(source) == 0 {
		return fmt.Errorf("reach reconcile: source returned 0 ids; refusing to reconcile")
	}

	indexed, err := idx.ExtIDs()
	if err != nil {
		return fmt.Errorf("reach reconcile extids: %w", err)
	}

	var stale, missing int
	for id := range indexed {
		if _, ok := source[id]; !ok {
			if err := idx.DeleteByExtID(id); err != nil {
				log.Printf("reach reconcile: delete stale msgid=%d: %v", id, err)
				continue
			}
			stale++
		}
	}
	for id := range source {
		if _, ok := indexed[id]; ok {
			continue
		}
		row := mysqlDB.QueryRow(reachSelect(`WHERE rr.msgid = ?`), id)
		var r reachRawRow
		if scanErr := row.Scan(&r.msgid, &r.status, &r.cells, &r.retired); scanErr != nil {
			// Row vanished between the id list and this fetch: fine, next tick.
			continue
		}
		item, ok := buildReachItem(r.msgid, r.status, r.cells)
		if !ok || r.status == "held" || r.retired {
			continue
		}
		if err := InsertItems(idx, []Item{item}, nil); err != nil {
			log.Printf("reach reconcile: insert missing msgid=%d: %v", id, err)
			continue
		}
		missing++
	}
	if stale+missing > 0 {
		log.Printf("reach reconcile: removed %d stale, added %d missing", stale, missing)
	}
	return nil
}

// buildReachItem builds one row's index Item from its encoded grid: the item
// blob IS the grid, giving exact answers. Validation walks the whole run
// stream (streaming, no allocation), so a corrupt or absent blob skips the
// row - it has no reach anywhere, and skipping is the fail-closed direction.
func buildReachItem(msgid int64, status string, cells []byte) (Item, bool) {
	if status == "held" {
		// Only reachable from the delta (Load filters held in SQL); the caller
		// removes it. Envelope fields are unused for removal.
		return Item{ExtID: msgid, Extra: map[string]any{"status": status}}, true
	}

	if len(cells) > 0 {
		set, minLng, minLat, maxLng, maxLat, err := cellset.ValidateEncoded(cells)
		if err == nil && set > 0 {
			return Item{
				ExtID:  msgid,
				MinLng: minLng, MaxLng: maxLng,
				MinLat: minLat, MaxLat: maxLat,
				// Planar cell-count area: only comparative uses exist and the
				// legacy path's g.Area() was the same planar degrees².
				Area:  float64(set) * cellset.CellDegrees * cellset.CellDegrees,
				WKB:   cells,
				Extra: map[string]any{"status": status},
			}, true
		}
		if err != nil {
			log.Printf("reach: msgid=%d cells rejected (%v), row skipped", msgid, err)
		}
	}

	return Item{}, false
}

// classifyReachBlob answers one point against one item blob. The encoded-grid
// probe answers exactly. A coarse tri-state raster blob (only possible in an
// on-disk index adopted from before the cells era; rebuilt away within one
// rebuild interval) keeps its boundary band; a blob neither can read
// classifies as partial, which the callers log and fail closed on.
func classifyReachBlob(blob []byte, lng, lat float64) byte {
	if in, ok := cellset.ContainsEncoded(blob, lng, lat); ok {
		if in {
			return cellIn
		}
		return cellOut
	}
	raster, err := DeserializeRaster(blob)
	if err != nil {
		return cellPartial
	}
	return raster.Classify(lng, lat)
}

// Query is not meaningful for reach (it's a containment dataset, not KNN).
func (d *ReachDataset) Query(_ *Index, _ QueryParams) ([]QueryResult, error) {
	return nil, fmt.Errorf("reach dataset does not support knn; use /containing")
}

func (d *ReachDataset) Within(_ *Index, _ QueryParams) ([]int64, error) {
	return nil, fmt.Errorf("reach dataset does not support within; use /containing")
}

// Containing implements PointContainer: all reaches whose stored form says
// the point is definitely inside (in) or cannot be decided locally (partial —
// only legacy coarse-raster rows and unreadable blobs produce these).
func (d *ReachDataset) Containing(idx *Index, lng, lat float64) (in []int64, partial []int64, err error) {
	candidates, err := QueryBBox(idx, lng, lng, lat, lat)
	if err != nil {
		return nil, nil, err
	}
	for _, c := range candidates {
		if c.WKB == nil {
			continue
		}
		switch classifyReachBlob(c.WKB, lng, lat) {
		case cellIn:
			in = append(in, c.ExtID)
		case cellPartial:
			partial = append(partial, c.ExtID)
		}
	}
	return in, partial, nil
}

// ReachPoint is one candidate location for AdmitsPoints.
type ReachPoint struct {
	Lng float64 `json:"lng"`
	Lat float64 `json:"lat"`
}

// AdmitsPoints is the committed-reach question from the MAIL's end: one post,
// many candidate members, which of them does its current reach cover? The
// twin of ReachOverflowDataset.AdmitsPoints, so the digest asks both halves
// of "would the site show this member the post" of the same authority.
//
// known=false means the post has no live entry here (no reach row, held, or
// the index simply has not caught up) — the caller decides what that means;
// the mail fails closed on it. `uncertain` carries the points a legacy
// coarse-raster row cannot decide (boundary band): pre-drop callers may
// exact-test those, post-drop they cannot occur.
func (d *ReachDataset) AdmitsPoints(idx *Index, msgid int64, points []ReachPoint) (admitted []int, uncertain []int, known bool, err error) {
	item, err := idx.GetByExtID(msgid)
	if err != nil {
		return nil, nil, false, err
	}
	if item == nil || item.WKB == nil {
		return nil, nil, false, nil
	}
	for i, p := range points {
		switch classifyReachBlob(item.WKB, p.Lng, p.Lat) {
		case cellIn:
			admitted = append(admitted, i)
		case cellPartial:
			uncertain = append(uncertain, i)
		}
	}
	return admitted, uncertain, true, nil
}

// No DriftChecker: the per-tick reconcile above is strictly stronger — it
// heals deletes and gaps in place within one delta interval, where a drift
// check could only trigger a full (minutes-long) rebuild once the count
// diverged past a threshold.
