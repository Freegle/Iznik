package main

import (
	"database/sql"
	"encoding/base64"
	"fmt"
	"log"
	"runtime"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/peterstace/simplefeatures/geom"
	"spatial-server/cellset"
)

// ReachOverflowDataset serves point-in-RING for the overflow lanes: "which
// posts' overflow rings admit this viewer?".
//
// It exists for the same reason ReachDataset does, one polygon layer further
// out, and the numbers are worse. The rings live as WKT inside
// rippling_reach.overflow_bounds, a JSON column no index can serve, and they
// average 37,000 vertices (measured on prod 2026-08-21). Asking the read
// question of that column means parsing hundreds of them per request: at one
// real viewer point, 836 candidate rings took 4.8s, essentially all of it
// ST_GeomFromText. Narrowing first does not rescue it - 558 of those 836
// genuinely admitted, so the parses are real work, not waste.
//
// The mail side has no such problem (one post, one member, one parse ≈ 6ms),
// which is exactly why the rings looked healthy until they reached browse.
//
// So the rings are rasterised here once at load, as the reaches are: a query
// point classifies in / partial / out in O(1), and only the thin boundary band
// goes back to MySQL for the exact JSON test - a handful of rows by primary
// key. Membership stays identical to the JSON answer; only the cost goes.
type ReachOverflowDataset struct{}

func (d *ReachOverflowDataset) Name() string { return "reachoverflow" }

// Rings are rewritten by the same ripple ticks that move a reach, so this
// tracks ReachDataset's cadence. The full rebuild reads every ring's WKT
// (~4,400 posts × ~0.8MB), which is why it is daily and not hourly.
func (d *ReachOverflowDataset) RebuildInterval() time.Duration { return 24 * time.Hour }
func (d *ReachOverflowDataset) DeltaInterval() time.Duration   { return 2 * time.Minute }

// ringRasterDim is finer than the reach dataset's grid, and deliberately so.
//
// A point landing in the boundary band costs an exact test, and for a ring that
// means parsing ~37k vertices out of JSON - about 6ms, against the sub-ms
// indexed lookup a reach's exact test costs. Halving the cell size halves the
// band's share of the grid, so the dearer fallback fires half as often. The
// price is index size (2 bits/cell: ~9KB per ring at 192, ~62MB for the ~6,700
// live ring items) and load-time CPU, both of which are paid once a day.
const ringRasterDim = 192

// overflowLaneCodes maps each ring's JSON path to the code packed into an
// item's ExtID. THE APIV2 SIDE HOLDS THE SAME TABLE
// (iznik-server-go/rippling/overflowlanes.go) and both have a test asserting it
// verbatim: a silent disagreement here would admit members to the wrong lane's
// ring, which no surface could detect.
//
// Codes are permanent. A retired lane keeps its code rather than letting a
// later lane inherit it, because an index built before the change still carries
// items stamped with it.
//
// The paths themselves are the ones ViewerOverflowPaths can produce, and
// nothing else: a member's band or deprivation fifth is looked up in a fixed
// table there, never interpolated, so this set is closed.
var overflowLaneCodes = map[string]int64{
	"$.rural.dense":  1,
	"$.rural.medium": 2,
	"$.rural.sparse": 3,
	`$.fairness."1"`: 4,
	`$.fairness."2"`: 5,
	`$.fairness."3"`: 6,
	`$.fairness."4"`: 7,
	"$.cluster.w1":   8,
	"$.cluster.w2":   9,
	"$.cluster.w3":   10,
}

// overflowLaneShift is how far the msgid is shifted to make room for the lane
// code. Four bits holds the ten lanes with room to spare, and leaves msgids
// (~1.2e8 today) nowhere near the int64 ceiling.
const overflowLaneShift = 4

// encodeOverflowExtID packs (msgid, lane) into one index id. Code 0 is never
// issued, so a bare msgid can never be mistaken for a lane item.
func encodeOverflowExtID(msgid int64, code int64) int64 {
	return msgid<<overflowLaneShift | code
}

// overflowLaneOrder is the paths in code order, so a load walks lanes
// deterministically (stable logs, reproducible index contents).
func overflowLaneOrder() []string {
	paths := make([]string, 0, len(overflowLaneCodes))
	for path := range overflowLaneCodes {
		paths = append(paths, path)
	}
	// Insertion-order-independent: sort by code, which is what callers reason about.
	for i := 0; i < len(paths); i++ {
		for j := i + 1; j < len(paths); j++ {
			if overflowLaneCodes[paths[j]] < overflowLaneCodes[paths[i]] {
				paths[i], paths[j] = paths[j], paths[i]
			}
		}
	}
	return paths
}

// overflowSelect builds the SELECT list that pulls every lane's ring in one
// pass over the row. Reading the whole overflow_bounds column instead would
// move the same bytes and then re-walk the JSON in Go; asking MySQL for each
// path costs one keyed extraction per lane and returns NULL for the lanes a
// post does not carry (most of them).
//
// Each lane is asked for TWICE: once from overflow_cells (the compact cell-set
// form, plans/2026-08-24-rippling-reach-raster-storage.md) and once from
// overflow_bounds (the ring WKT). Both, not one or the other, because a lane's
// cells are filled in per lane by the backfill, so a partly-converted table is
// a normal state - the cells are preferred when present and the WKT is the
// fallback. Reading both costs one extra keyed extraction per lane and returns
// NULL for the absent one, which is far cheaper than a second query would be.
func overflowSelect() (cols string, args []interface{}) {
	var parts []string
	lanes := overflowLaneOrder()
	for _, path := range lanes {
		parts = append(parts, "JSON_UNQUOTE(JSON_EXTRACT(overflow_cells, ?))")
		args = append(args, path)
	}
	for _, path := range lanes {
		parts = append(parts, "JSON_UNQUOTE(JSON_EXTRACT(overflow_bounds, ?))")
		args = append(args, path)
	}
	return strings.Join(parts, ", "), args
}

// overflowRowScan holds one reach row's rings, one slot per lane in code order
// for each form: cells (base64 cell set, preferred) and rings (WKT, fallback).
type overflowRowScan struct {
	msgid int64
	cells []sql.NullString
	rings []sql.NullString
}

func newOverflowRowScan(laneCount int) overflowRowScan {
	return overflowRowScan{
		cells: make([]sql.NullString, laneCount),
		rings: make([]sql.NullString, laneCount),
	}
}

// scanDest is the destination list matching overflowSelect's column order:
// the caller's own leading columns, then all lanes' cells, then all lanes' WKT.
func (r *overflowRowScan) scanDest(leading ...interface{}) []interface{} {
	dest := make([]interface{}, 0, len(leading)+len(r.cells)+len(r.rings))
	dest = append(dest, leading...)
	for i := range r.cells {
		dest = append(dest, &r.cells[i])
	}
	for i := range r.rings {
		dest = append(dest, &r.rings[i])
	}
	return dest
}

func scanOverflowRow(rows *sql.Rows, laneCount int) (overflowRowScan, error) {
	r := newOverflowRowScan(laneCount)
	return r, rows.Scan(r.scanDest(&r.msgid)...)
}

// ringRasterFor builds one lane's coarse accelerator, and its bounding box.
//
// The CELL SET is preferred over the ring WKT, and the saving is the whole
// point of the raster-storage change: a ring averages 37,000 vertices and
// ~0.8MB of WKT, and turning that into this same 192-cell raster meant a
// full vector parse plus a supercover+scanline fill. A cell set is already a
// membership grid on the 0.0003-degree lattice the ring's own vertices sit on
// (they are traced from a routing-server raster - see
// ripple:shrink-overflow-bounds), so building the raster from it is bit-array
// sampling with no geometry parsed at all.
//
// The coarse raster itself is UNCHANGED, deliberately: 192 cells at 2 bits is
// ~9KB per ring and ~62MB across the ~6,700 live ring items, a measured
// figure this must not quietly inflate. A cell set held in the index instead
// would be megabytes per ring decoded. So the cells replace the PARSE, not
// the accelerator, and the in/partial/out contract every caller relies on is
// the same one BuildRasterFromCellSet and BuildRasterDim both implement.
func ringRasterFor(cells, ring sql.NullString) (ringItem, bool) {
	if cells.Valid && strings.TrimSpace(cells.String) != "" {
		if raw, err := base64.StdEncoding.DecodeString(cells.String); err == nil {
			if cs, derr := cellset.Decode(raw); derr == nil {
				if raster := BuildRasterFromCellSet(cs, ringRasterDim); raster != nil {
					minLng, minLat, maxLng, maxLat := cs.Bounds()
					return ringItem{
						raster: raster,
						minLng: minLng, minLat: minLat,
						maxLng: maxLng, maxLat: maxLat,
						// The covered area, not the bbox: a cell set knows
						// exactly how much ground it covers, so this is the
						// same measure g.Area() gives on the WKT path.
						area: float64(cs.SetCellCount()) * cellset.CellDegrees * cellset.CellDegrees,
					}, true
				}
			}
		}
		// Malformed or unrasterisable cells fall through to the WKT rather
		// than darkening the lane: overflow_bounds is still the authority.
	}

	if !ring.Valid || strings.TrimSpace(ring.String) == "" {
		return ringItem{}, false
	}
	g, err := geom.UnmarshalWKT(ring.String, geom.NoValidate{})
	if err != nil {
		return ringItem{}, false
	}
	raster := BuildRasterDim(g, ringRasterDim)
	if raster == nil {
		return ringItem{}, false
	}
	min, max, ok := g.Envelope().MinMaxXYs()
	if !ok {
		return ringItem{}, false
	}
	return ringItem{
		raster: raster,
		minLng: min.X, minLat: min.Y,
		maxLng: max.X, maxLat: max.Y,
		area: g.Area(),
	}, true
}

// ringItem is one lane's built accelerator and the index fields derived with it.
type ringItem struct {
	raster                         *Raster
	minLng, minLat, maxLng, maxLat float64
	area                           float64
}

// buildOverflowItems rasterises every ring a row carries. A ring that will not
// parse or will not rasterise is skipped: that post's lane goes dark for the
// read surfaces (they show the committed reach instead), which is the same
// degradation a missing index row causes, never a wrong admission.
func buildOverflowItems(r overflowRowScan, lanes []string) []Item {
	var items []Item
	for i := range lanes {
		built, ok := ringRasterFor(r.cells[i], r.rings[i])
		if !ok {
			continue
		}
		items = append(items, Item{
			ExtID:  encodeOverflowExtID(r.msgid, overflowLaneCodes[lanes[i]]),
			MinLng: built.minLng, MaxLng: built.maxLng,
			MinLat: built.minLat, MaxLat: built.maxLat,
			Area:  built.area,
			WKB:   built.raster.Serialize(),
			Extra: map[string]any{"msgid": r.msgid, "lane": lanes[i]},
		})
	}
	return items
}

// Load rasterises every live ring.
//
// has_overflow is the generated, indexed flag for "this row carries rings", so
// the scan touches ~4,400 rows of a 17GB table rather than all 52,000 - the
// distinction that keeps a rebuild off the cluster's back.
func (d *ReachOverflowDataset) Load(mysqlDB *sql.DB, idx *Index) error {
	lanes := overflowLaneOrder()
	cols, args := overflowSelect()

	rows, err := mysqlDB.Query(
		"SELECT msgid, "+cols+" FROM rippling_reach WHERE has_overflow = 1 AND status != 'held'",
		args...)
	if err != nil {
		return fmt.Errorf("reachoverflow load query: %w", err)
	}
	defer rows.Close()

	// Rasterising a 37k-vertex ring is the expensive part, so it goes to a
	// worker pool while this goroutine keeps draining MySQL - the same shape as
	// the reach load, and capped the same way so a rebuild never starves the
	// apiv2 and routing processes sharing these boxes.
	workers := runtime.NumCPU() - 2
	if workers < 1 {
		workers = 1
	}
	in := make(chan overflowRowScan, workers*2)
	out := make(chan Item, workers*2)
	var built, skippedRows int64
	var wg sync.WaitGroup
	for w := 0; w < workers; w++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for r := range in {
				made := buildOverflowItems(r, lanes)
				if len(made) == 0 {
					atomic.AddInt64(&skippedRows, 1)
					continue
				}
				for _, item := range made {
					out <- item
				}
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
			atomic.AddInt64(&built, 1)
		}
		close(collectDone)
	}()

	var scanErr error
	for rows.Next() {
		r, err := scanOverflowRow(rows, len(lanes))
		if err != nil {
			scanErr = err
			break
		}
		in <- r
	}
	close(in)
	<-collectDone
	if scanErr != nil {
		return fmt.Errorf("reachoverflow scan: %w", scanErr)
	}
	if err := rows.Err(); err != nil {
		return err
	}

	log.Printf("reachoverflow load: %d ring items (%d rows carried no usable ring)", built, skippedRows)
	return InsertItems(idx, items, nil)
}

// ApplyDelta rebuilds the ring items of every reach row touched since `since`,
// and drops the items of rows that went held or lost their rings.
//
// The delta deliberately looks at ALL changed rows, not just those still
// carrying rings: a post whose rings were removed is exactly the case that must
// produce deletions, and filtering on has_overflow would hide it.
func (d *ReachOverflowDataset) ApplyDelta(mysqlDB *sql.DB, idx *Index, since time.Time) error {
	lanes := overflowLaneOrder()
	cols, args := overflowSelect()
	args = append(args, since.UTC())

	// has_overflow (generated, indexed) bounds this to rows that actually carry
	// rings: 16 reach rows change in a typical two minutes, 2 of them ringed, so
	// the tick reads a couple of megabytes instead of every changed row's ~0.8MB
	// of ring WKT. A post that LOSES its rings drops out of this filter and is
	// therefore cleaned up by the reconcile below, not here.
	rows, err := mysqlDB.Query(
		"SELECT msgid, status, "+cols+
			" FROM rippling_reach WHERE has_overflow = 1 AND updated_at > ?", args...)
	if err != nil {
		return fmt.Errorf("reachoverflow delta query: %w", err)
	}
	defer rows.Close()

	var touched, upserted int
	for rows.Next() {
		r := newOverflowRowScan(len(lanes))
		var status string
		dest := r.scanDest(&r.msgid, &status)
		if err := rows.Scan(dest...); err != nil {
			log.Printf("reachoverflow delta scan: %v", err)
			continue
		}
		touched++

		var items []Item
		if status != "held" {
			items = buildOverflowItems(r, lanes)
		}

		// Clear this post's lane items first, then insert what it still has. A
		// lane the post no longer carries - rings removed, or the whole reach
		// held - therefore disappears, which an insert-only delta could never
		// express. Deleting an id that is not there is not an error.
		for _, path := range lanes {
			if err := idx.DeleteByExtID(encodeOverflowExtID(r.msgid, overflowLaneCodes[path])); err != nil {
				log.Printf("reachoverflow delta: clear msgid=%d lane=%s: %v", r.msgid, path, err)
			}
		}
		if len(items) > 0 {
			if err := InsertItems(idx, items, nil); err != nil {
				log.Printf("reachoverflow delta: upsert msgid=%d: %v", r.msgid, err)
				continue
			}
			upserted += len(items)
		}
	}
	if err := rows.Err(); err != nil {
		return err
	}
	if touched > 0 {
		log.Printf("reachoverflow delta: %d rows touched, %d ring items written, since=%s",
			touched, upserted, since.Format(time.RFC3339))
	}

	return d.reconcile(mysqlDB, idx)
}

// reconcile diffs the index against the source, for the two reasons the reach
// dataset has one: reach rows are hard-DELETEd when a post completes or is
// purged, and rows changed while this process was down are invisible to an
// updated_at delta that only looks back one interval.
//
// It compares POSTS, not lanes, and asks only the indexed has_overflow column
// for the id list. Both choices are about cost, and both were measured on prod:
// the id list this way is an index-only scan of 4,447 rows in 2.6ms, while
// naming the lanes (JSON_CONTAINS_PATH, ten paths) or adding a status test
// forces the row reads instead - 5.5s with status, 38s with the paths, every
// two minutes, on the write node. Lane-level drift needs no reconcile anyway:
// any change to a post's rings bumps updated_at, and the delta rewrites all of
// that post's lanes together.
//
// Held rows are not excluded here for the same reason. A held post's items are
// removed by the delta the moment it is held; and were one to linger, apiv2
// re-checks status against MySQL before admitting anyone (rippling.liveMsgids),
// so the index is not the thing standing between a held post and a reader.
func (d *ReachOverflowDataset) reconcile(mysqlDB *sql.DB, idx *Index) error {
	rows, err := mysqlDB.Query("SELECT msgid FROM rippling_reach WHERE has_overflow = 1")
	if err != nil {
		return fmt.Errorf("reachoverflow reconcile ids: %w", err)
	}

	source := make(map[int64]struct{})
	for rows.Next() {
		var msgid int64
		if err := rows.Scan(&msgid); err != nil {
			rows.Close()
			return fmt.Errorf("reachoverflow reconcile scan: %w", err)
		}
		source[msgid] = struct{}{}
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return err
	}

	// An empty source is far more likely to be a failed query than a real state
	// (the lanes have been live and populated since 2026-08-17), and acting on
	// it would delete every ring in the index. Refuse, exactly as reach does.
	if len(source) == 0 {
		return fmt.Errorf("reachoverflow reconcile: source returned 0 ringed posts; refusing to reconcile")
	}

	indexed, err := idx.ExtIDs()
	if err != nil {
		return fmt.Errorf("reachoverflow reconcile extids: %w", err)
	}

	var stale int
	for extID := range indexed {
		if _, ok := source[extID>>overflowLaneShift]; ok {
			continue
		}
		if err := idx.DeleteByExtID(extID); err != nil {
			log.Printf("reachoverflow reconcile: delete stale extid=%d: %v", extID, err)
			continue
		}
		stale++
	}
	// Missing posts are NOT fetched one at a time here. Unlike a reach row, a
	// ring costs ~0.8MB to fetch and ~37k vertices to rasterise, so a wide gap
	// would stall the tick; anything the delta missed is picked up by the daily
	// rebuild, and until then those posts show their committed reach - the same
	// degradation as a lane that has not been built yet.
	if stale > 0 {
		log.Printf("reachoverflow reconcile: removed %d stale ring items", stale)
	}
	return nil
}

// Query is not meaningful for rings: they answer containment, not nearness.
func (d *ReachOverflowDataset) Query(_ *Index, _ QueryParams) ([]QueryResult, error) {
	return nil, fmt.Errorf("reachoverflow dataset does not support knn; use /containing")
}

func (d *ReachOverflowDataset) Within(_ *Index, _ QueryParams) ([]int64, error) {
	return nil, fmt.Errorf("reachoverflow dataset does not support within; use /containing")
}

// AdmitsPoints answers the MAIL side's question: given one post and the lanes
// each candidate member is in, which of those members does this post's ring
// admit?
//
// It is the same question the read surfaces ask, asked from the other end. The
// mail has one post and many members; browse has one member and many posts. Both
// have to come out of the same rasters or the surfaces disagree - and the way
// they disagreed in production was the worst way round: the mail invited people
// the site then refused, and the reply gate held their replies until the item
// was gone.
//
// A point in a ring's boundary band is NOT admitted, exactly as on the read
// side. That is what makes the band harmless: everybody declines the same strip.
func (d *ReachOverflowDataset) AdmitsPoints(idx *Index, msgid int64, points []LanePoint) ([]int, error) {
	var admitted []int

	for i, p := range points {
		for _, lane := range p.Lanes {
			code, ok := overflowLaneCodes[lane]
			if !ok {
				return nil, fmt.Errorf("unknown overflow lane %q", lane)
			}

			item, err := idx.GetByExtID(encodeOverflowExtID(msgid, code))
			if err != nil || item == nil || item.WKB == nil {
				continue
			}
			raster, err := DeserializeRaster(item.WKB)
			if err != nil {
				continue
			}
			if raster.Classify(p.Lng, p.Lat) == cellIn {
				admitted = append(admitted, i)
				break
			}
		}
	}

	return admitted, nil
}

// LanePoint is one candidate member: where they are, and which ring lanes they
// are in. The lanes travel with the point because they are per-member - a
// member's density band decides which rural ring can admit them, and only the
// caller knows it.
type LanePoint struct {
	Lng   float64  `json:"lng"`
	Lat   float64  `json:"lat"`
	Lanes []string `json:"lanes"`
}

// ContainingFiltered implements PointContainerFiltered: the same question, but
// with the caller naming the lanes it is in, and answered as PLAIN msgids.
//
// This is the form every surface uses - apiv2's feed, badge, search, message
// page and reply gate, and the batch's mail paths. It exists so that no caller
// needs its own copy of the lane table: the lane a member is in is already a
// path string they hold ("$.rural.sparse"), and sending that is enough. A lane
// this index does not know is an error, not an empty answer, because silently
// returning nothing for a lane the mail is inviting on is precisely the
// surface split this whole mechanism exists to prevent.
func (d *ReachOverflowDataset) ContainingFiltered(idx *Index, lng, lat float64, lanes []string) (in []int64, partial []int64, err error) {
	codes := make(map[int64]struct{}, len(lanes))
	for _, lane := range lanes {
		code, ok := overflowLaneCodes[lane]
		if !ok {
			return nil, nil, fmt.Errorf("unknown overflow lane %q", lane)
		}
		codes[code] = struct{}{}
	}
	if len(codes) == 0 {
		return nil, nil, nil
	}

	rawIn, rawPartial, err := d.Containing(idx, lng, lat)
	if err != nil {
		return nil, nil, err
	}

	keep := func(ids []int64) []int64 {
		seen := make(map[int64]struct{}, len(ids))
		var out []int64
		for _, id := range ids {
			if _, ok := codes[id&((1<<overflowLaneShift)-1)]; !ok {
				continue
			}
			msgid := id >> overflowLaneShift
			if _, dup := seen[msgid]; dup {
				continue
			}
			seen[msgid] = struct{}{}
			out = append(out, msgid)
		}
		return out
	}

	return keep(rawIn), keep(rawPartial), nil
}

// Containing implements PointContainer, returning ENCODED ids: the caller
// decodes each into (msgid, lane) and keeps only the lanes that apply to its
// viewer. Returning them per-lane rather than as bare msgids is what lets one
// index serve every lane while still answering the per-lane question - a post
// admits a sparse-band member and refuses a dense-band one on different rings.
func (d *ReachOverflowDataset) Containing(idx *Index, lng, lat float64) (in []int64, partial []int64, err error) {
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
			// Corrupt blob: hand it to the exact test rather than guessing.
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
