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
// The index prefers the stored cell grid (rippling_reach.polygon_cells,
// plans/2026-08-24-rippling-reach-raster-storage.md): the item blob is the
// encoded grid itself (~23KB), and a query point is answered EXACTLY by
// walking its run stream — no boundary band, no `partial`, no fallback to the
// geometry. Rows the backfill has not reached yet fall back per row to the
// legacy path: parse the polygon WKB and rasterise it into the ~2KB tri-state
// coarse grid (raster.go), whose boundary band still classifies as `partial`
// for the caller to exact-test. Once the polygon column is dropped the legacy
// path is unreachable and `partial` is empty by construction.
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

// mysqlColumnExists reports whether a column is present — re-asked on every
// Load/ApplyDelta (one information_schema row, microseconds against a
// 2-minute cadence) so the operator dropping the legacy geometry mid-flight
// is adopted within one delta interval rather than at the next restart.
func mysqlColumnExists(db *sql.DB, table, column string) bool {
	var n int
	err := db.QueryRow(`SELECT COUNT(*) FROM information_schema.columns
		WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?`,
		table, column).Scan(&n)
	return err == nil && n > 0
}

// reachGeomExpr / reachGeomJoin: how the LEGACY polygon is read while it
// still exists. The dedup change (#1402) may have drained a row's own blob to
// a sentinel POINT with the real bytes in rippling_reach_geom, so the legacy
// read COALESCEs the shared row over the local blob via a LEFT JOIN keyed on
// polygon_hash — never an INNER JOIN (rows before the dedup backfill have no
// hash) and never bare `polygon` (a drained row's blob is only the sentinel).
// Both go away with the columns; the cells column needs none of it.
const reachGeomExpr = `ST_AsWKB(COALESCE(g.geom, rr.polygon))`
const reachGeomJoin = ` LEFT JOIN rippling_reach_geom g ON g.hash = rr.polygon_hash`

// reachLegacyForms describes which legacy geometry columns are still present:
// 2 = polygon + hash/geom-table (dedup era), 1 = polygon only, 0 = cells only.
func reachLegacyForm(db *sql.DB) int {
	if !mysqlColumnExists(db, "rippling_reach", "polygon") {
		return 0
	}
	if mysqlColumnExists(db, "rippling_reach", "polygon_hash") {
		return 2
	}
	return 1
}

// reachSelect builds the row query: the cells column always, the legacy
// polygon only while it still exists, read through the dedup COALESCE while
// THAT still exists.
func reachSelect(legacyForm int, where string) string {
	cols := "rr.msgid, rr.status, rr.polygon_cells"
	join := ""
	switch legacyForm {
	case 2:
		cols += ", " + reachGeomExpr
		join = reachGeomJoin
	case 1:
		cols += ", ST_AsWKB(rr.polygon)"
	}
	return "SELECT " + cols + " FROM rippling_reach rr" + join + " " + where
}

type reachRawRow struct {
	msgid  int64
	status string
	cells  []byte
	wkb    []byte
}

func scanReachRaw(rows *sql.Rows, hasPolygon bool) (reachRawRow, error) {
	var r reachRawRow
	if hasPolygon {
		return r, rows.Scan(&r.msgid, &r.status, &r.cells, &r.wkb)
	}
	return r, rows.Scan(&r.msgid, &r.status, &r.cells)
}

func (d *ReachDataset) Load(mysqlDB *sql.DB, idx *Index) error {
	legacyForm := reachLegacyForm(mysqlDB)
	// Load ALL non-held statuses; held rows are simply absent (the delta
	// re-adds them if released).
	rows, err := mysqlDB.Query(reachSelect(legacyForm, `WHERE rr.status != 'held'`))
	if err != nil {
		return fmt.Errorf("reach load query: %w", err)
	}
	defer rows.Close()

	// A cells row is a header validation (microseconds); only legacy WKB rows
	// pay the ~14ms rasterise (BenchmarkBuildRaster), so the worker fan-out
	// mainly serves the pre-backfill state. Capped below NumCPU so a rebuild
	// never starves the co-located apiv2/routing processes on the prod db
	// nodes.
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
				item, ok := buildReachItem(r.msgid, r.status, r.cells, r.wkb)
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
		r, err := scanReachRaw(rows, legacyForm > 0)
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
// rebuilt from the row's current cells (or legacy polygon), so there is no
// drift to reconcile.
func (d *ReachDataset) ApplyDelta(mysqlDB *sql.DB, idx *Index, since time.Time) error {
	legacyForm := reachLegacyForm(mysqlDB)
	rows, err := mysqlDB.Query(reachSelect(legacyForm, `WHERE rr.updated_at > ?`), since.UTC())
	if err != nil {
		return fmt.Errorf("reach delta query: %w", err)
	}
	defer rows.Close()

	var upserted, removed, skipped int
	for rows.Next() {
		r, err := scanReachRaw(rows, legacyForm > 0)
		if err != nil {
			log.Printf("reach scan: %v", err)
			skipped++
			continue
		}
		item, ok := buildReachItem(r.msgid, r.status, r.cells, r.wkb)
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
	return d.reconcile(mysqlDB, idx, legacyForm)
}

// reconcile diffs the index's extids against rippling_reach's live msgids:
// index-only entries are deleted, source-only msgids are fetched and built.
func (d *ReachDataset) reconcile(mysqlDB *sql.DB, idx *Index, legacyForm int) error {
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
		row := mysqlDB.QueryRow(reachSelect(legacyForm, `WHERE rr.msgid = ?`), id)
		var r reachRawRow
		var scanErr error
		if legacyForm > 0 {
			scanErr = row.Scan(&r.msgid, &r.status, &r.cells, &r.wkb)
		} else {
			scanErr = row.Scan(&r.msgid, &r.status, &r.cells)
		}
		if scanErr != nil {
			// Row vanished between the id list and this fetch: fine, next tick.
			continue
		}
		item, ok := buildReachItem(r.msgid, r.status, r.cells, r.wkb)
		if !ok || r.status == "held" {
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

// buildReachItem builds one row's index Item. Preference order:
//
//  1. Valid cells: the item blob IS the encoded grid, giving exact answers.
//     Validation walks the whole run stream (streaming, no allocation), so a
//     corrupt blob is rejected here and the row falls to the next form.
//  2. Legacy polygon WKB: rasterised into the coarse tri-state raster,
//     exactly the pre-cells behaviour, including `partial`.
//  3. Neither usable: the row is skipped. Pre-drop that degrades cost only
//     (the badge's MySQL fallback still covers the post); post-drop a row
//     with no readable cells has no reach anywhere, and skipping is the
//     fail-closed direction.
func buildReachItem(msgid int64, status string, cells []byte, wkbRaw []byte) (Item, bool) {
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
			log.Printf("reach: msgid=%d cells rejected (%v), trying legacy polygon", msgid, err)
		}
	}

	if len(wkbRaw) == 0 {
		return Item{}, false
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

// classifyReachBlob answers one point against one item blob, whichever form
// it holds. The encoded-grid probe answers exactly; the legacy coarse raster
// keeps its boundary band. A blob neither can read classifies as partial:
// pre-drop the caller's exact test decides, post-drop the caller's own probe
// of the same stored bytes fails the same way and fails closed there.
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
