package main

import (
	"database/sql"
	"fmt"
	"log"
	"runtime"
	"sync"
	"sync/atomic"
	"time"

	"github.com/peterstace/simplefeatures/geom"
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
// Here each reach polygon is rasterised ONCE at load into a ~2KB tri-state
// grid (raster.go): a query point classifies as in / out / partial in O(1).
// Only the thin partial band (cells a polygon edge passes through) still
// needs the exact polygon — the caller (apiv2) resolves those few against
// rippling_reach in MySQL, keeping correctness exact while removing ~96% of
// the geometry work. The polygons themselves are NOT kept in the index
// (that would be ~9GB; the rasters are ~150MB).
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

func (d *ReachDataset) Load(mysqlDB *sql.DB, idx *Index) error {
	// Load ALL non-held statuses; held rows are simply absent (the delta
	// re-adds them if released). ST_AsWKB gives portable WKB; the polygon
	// column is SRID 3857-tagged but stores plain degrees (site convention).
	rows, err := mysqlDB.Query(`
		SELECT msgid, status, ST_AsWKB(polygon)
		FROM rippling_reach
		WHERE status != 'held'
	`)
	if err != nil {
		return fmt.Errorf("reach load query: %w", err)
	}
	defer rows.Close()

	// Rasterising an ~11k-vertex polygon costs ~14ms (BenchmarkBuildRaster), so
	// ~50k reaches would be ~12 minutes serially. Fan the CPU work out to a
	// worker pool while this goroutine keeps draining MySQL rows; row order is
	// irrelevant to the index. Capped below NumCPU so a rebuild never starves
	// the co-located apiv2/routing processes on the prod db nodes.
	type rawRow struct {
		msgid  int64
		status string
		wkb    []byte
	}
	workers := runtime.NumCPU() - 2
	if workers < 1 {
		workers = 1
	}
	in := make(chan rawRow, workers*2)
	out := make(chan Item, workers*2)
	var skipped int64
	var wg sync.WaitGroup
	for w := 0; w < workers; w++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for r := range in {
				item, ok := buildReachItem(r.msgid, r.status, r.wkb)
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
		var r rawRow
		if err := rows.Scan(&r.msgid, &r.status, &r.wkb); err != nil {
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
// ones. Polygon clips (ST_Difference on completion) and expansions both
// arrive as plain updates: the whole raster is rebuilt from the current
// polygon, so there is no cell-set drift to reconcile.
func (d *ReachDataset) ApplyDelta(mysqlDB *sql.DB, idx *Index, since time.Time) error {
	rows, err := mysqlDB.Query(`
		SELECT msgid, status, ST_AsWKB(polygon)
		FROM rippling_reach
		WHERE updated_at > ?
	`, since.UTC())
	if err != nil {
		return fmt.Errorf("reach delta query: %w", err)
	}
	defer rows.Close()

	var upserted, removed, skipped int
	for rows.Next() {
		item, ok := scanReachRow(rows)
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
	rows, err := mysqlDB.Query(`SELECT msgid FROM rippling_reach WHERE status != 'held'`)
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
		row := mysqlDB.QueryRow(`SELECT msgid, status, ST_AsWKB(polygon) FROM rippling_reach WHERE msgid = ?`, id)
		var msgid int64
		var status string
		var wkbRaw []byte
		if err := row.Scan(&msgid, &status, &wkbRaw); err != nil {
			// Row vanished between the id list and this fetch: fine, next tick.
			continue
		}
		item, ok := buildReachItem(msgid, status, wkbRaw)
		if !ok || status == "held" {
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

// scanReachRow reads one (msgid, status, wkb) row and builds its index Item.
func scanReachRow(rows *sql.Rows) (Item, bool) {
	var msgid int64
	var status string
	var wkbRaw []byte
	if err := rows.Scan(&msgid, &status, &wkbRaw); err != nil {
		log.Printf("reach scan: %v", err)
		return Item{}, false
	}
	return buildReachItem(msgid, status, wkbRaw)
}

// buildReachItem rasterises one reach polygon into an index Item: the
// serialized raster is stored as the item's blob (the WKB column is just
// bytes to the index). ok=false when the geometry is unusable — the row is
// skipped, and the badge's MySQL fallback still covers that post, so a skip
// degrades cost, never correctness.
func buildReachItem(msgid int64, status string, wkbRaw []byte) (Item, bool) {
	if status == "held" {
		// Only reachable from the delta (Load filters held in SQL); the caller
		// removes it. Envelope fields are unused for removal.
		return Item{ExtID: msgid, Extra: map[string]any{"status": status}}, true
	}
	g, err := geom.UnmarshalWKB(stripSRIDPrefix(wkbRaw), geom.NoValidate{})
	if err != nil {
		return Item{}, false
	}
	raster := BuildRaster(g)
	if raster == nil {
		return Item{}, false
	}
	env := g.Envelope()
	min, max, ok := env.MinMaxXYs()
	if !ok {
		return Item{}, false
	}
	return Item{
		ExtID:  msgid,
		MinLng: min.X, MaxLng: max.X,
		MinLat: min.Y, MaxLat: max.Y,
		Area:  g.Area(),
		WKB:   raster.Serialize(),
		Extra: map[string]any{"status": status},
	}, true
}

// Query is not meaningful for reach (it's a containment dataset, not KNN).
func (d *ReachDataset) Query(_ *Index, _ QueryParams) ([]QueryResult, error) {
	return nil, fmt.Errorf("reach dataset does not support knn; use /containing")
}

func (d *ReachDataset) Within(_ *Index, _ QueryParams) ([]int64, error) {
	return nil, fmt.Errorf("reach dataset does not support within; use /containing")
}

// Containing implements PointContainer: all reaches whose raster says the
// point is definitely inside (in) or on the boundary band (partial — caller
// must exact-test these against rippling_reach.polygon).
func (d *ReachDataset) Containing(idx *Index, lng, lat float64) (in []int64, partial []int64, err error) {
	candidates, err := QueryBBox(idx, lng, lng, lat, lat)
	if err != nil {
		return nil, nil, err
	}
	for _, c := range candidates {
		if c.WKB == nil {
			continue
		}
		raster, err := DeserializeRaster(c.WKB)
		if err != nil {
			// Corrupt blob: surface as partial so the exact test decides.
			partial = append(partial, c.ExtID)
			continue
		}
		switch raster.Classify(lng, lat) {
		case cellIn:
			in = append(in, c.ExtID)
		case cellPartial:
			partial = append(partial, c.ExtID)
		}
	}
	return in, partial, nil
}

// No DriftChecker: the per-tick reconcile above is strictly stronger — it
// heals deletes and gaps in place within one delta interval, where a drift
// check could only trigger a full (minutes-long) rebuild once the count
// diverged past a threshold.
