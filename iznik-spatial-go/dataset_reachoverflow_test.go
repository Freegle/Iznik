package main

import (
	"database/sql"
	"encoding/base64"
	"strings"
	"testing"

	"spatial-server/cellset"
)

// ringRow makes one reach row's lane slots, filling the named lanes with WKT
// and leaving every lane's cell set absent (the pre-conversion state, which the
// WKT fallback must keep serving).
func ringRow(msgid int64, rings map[string]string) overflowRowScan {
	lanes := overflowLaneOrder()
	r := newOverflowRowScan(len(lanes))
	r.msgid = msgid
	for i, lane := range lanes {
		if wkt, ok := rings[lane]; ok {
			r.rings[i] = sql.NullString{String: wkt, Valid: true}
		}
	}
	return r
}

// ringRowWithCells is ringRow plus base64 cell sets for the named lanes - the
// converted state, in which no ring WKT should be parsed at all.
func ringRowWithCells(msgid int64, rings map[string]string, cells map[string]string) overflowRowScan {
	r := ringRow(msgid, rings)
	for i, lane := range overflowLaneOrder() {
		if b64, ok := cells[lane]; ok {
			r.cells[i] = sql.NullString{String: b64, Valid: true}
		}
	}
	return r
}

// The lane table is a CONTRACT with apiv2, which decodes these codes out of the
// ids this dataset returns (iznik-server-go/rippling/overflowlanes.go). A
// disagreement admits members to another band's ring and looks like a perfectly
// ordinary feed, so both sides assert the pairs verbatim.
func TestOverflowLaneCodes_MatchTheAPIsTable(t *testing.T) {
	want := map[string]int64{
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

	if len(overflowLaneCodes) != len(want) {
		t.Fatalf("lane count = %d, want %d - a lane added here must be added to apiv2 too",
			len(overflowLaneCodes), len(want))
	}
	for path, code := range want {
		if got, ok := overflowLaneCodes[path]; !ok || got != code {
			t.Errorf("lane %q = %d (present=%v), want %d", path, got, ok, code)
		}
	}
}

// Lane order drives both the SELECT list and the scan destinations, so a row's
// ring must land in the slot whose code it is stamped with. Get this wrong and
// every post is admitted on the wrong lane.
func TestOverflowLaneOrder_IsByCode(t *testing.T) {
	lanes := overflowLaneOrder()
	if len(lanes) != len(overflowLaneCodes) {
		t.Fatalf("order has %d lanes, table has %d", len(lanes), len(overflowLaneCodes))
	}
	for i, lane := range lanes {
		if overflowLaneCodes[lane] != int64(i+1) {
			t.Fatalf("lane %d is %q with code %d; order must be 1..n by code",
				i, lane, overflowLaneCodes[lane])
		}
	}
}

// The SELECT list must ask for the lanes in that same order, since the scan
// pairs column i with lane i positionally.
func TestOverflowSelect_BindsLanesInOrder(t *testing.T) {
	cols, args := overflowSelectCols(true)
	lanes := overflowLaneOrder()

	// Every lane is asked for twice - cells first, then WKT - and the scan
	// pairs column i with lane i within each block, so the binds must run
	// through the lane order twice in the same order.
	if len(args) != 2*len(lanes) {
		t.Fatalf("select binds %d paths, want %d (cells + WKT per lane)", len(args), 2*len(lanes))
	}
	for i, lane := range lanes {
		if args[i] != lane {
			t.Errorf("cells bind %d = %v, want %q", i, args[i], lane)
		}
		if args[len(lanes)+i] != lane {
			t.Errorf("wkt bind %d = %v, want %q", i, args[len(lanes)+i], lane)
		}
	}
	// One extraction per lane per form, and the ring comes back as text to be
	// parsed here rather than as geometry parsed by MySQL - the DB doing that
	// work is what this dataset exists to stop.
	if got := countSubstr(cols, "JSON_UNQUOTE(JSON_EXTRACT(overflow_cells, ?))"); got != len(lanes) {
		t.Errorf("select has %d cell extractions, want %d: %s", got, len(lanes), cols)
	}
	if got := countSubstr(cols, "JSON_UNQUOTE(JSON_EXTRACT(overflow_bounds, ?))"); got != len(lanes) {
		t.Errorf("select has %d lane extractions, want %d: %s", got, len(lanes), cols)
	}
}

// Post-drop era: overflow_bounds is gone, so its half of the SELECT must be
// literal NULLs, never the column name - naming a dropped column errors on
// EVERY query, which is exactly what froze this dataset's load and delta on
// all four instances the moment the Stage 3 drop ran (2026-08-26). The shape
// is preserved (one value per lane per form) so the scanner stays era-blind.
func TestOverflowSelect_PostDropUsesNullsNotTheDroppedColumn(t *testing.T) {
	cols, args := overflowSelectCols(false)
	lanes := overflowLaneOrder()

	// Binds shrink to the cells half only; the WKT half binds nothing.
	if len(args) != len(lanes) {
		t.Fatalf("select binds %d paths, want %d (cells only post-drop)", len(args), len(lanes))
	}
	if got := countSubstr(cols, "overflow_bounds"); got != 0 {
		t.Errorf("post-drop select still names overflow_bounds %d time(s): %s", got, cols)
	}
	if got := countSubstr(cols, "JSON_UNQUOTE(JSON_EXTRACT(overflow_cells, ?))"); got != len(lanes) {
		t.Errorf("select has %d cell extractions, want %d: %s", got, len(lanes), cols)
	}
	// The scanner pairs columns positionally, so the NULL placeholders must
	// keep the column COUNT identical to the pre-drop era. Asserted by
	// building the exact expected string - counting ", " separators is a trap
	// this test fell into on its first run: every cells extraction contains
	// ", " INSIDE it ("overflow_cells, ?"), so the separator count read 29
	// where the assertion expected 19, and the suite failed on every master
	// build (invisible to PR builds, which do not run the spatial step).
	want := strings.Repeat("JSON_UNQUOTE(JSON_EXTRACT(overflow_cells, ?)), ", len(lanes)) +
		strings.TrimSuffix(strings.Repeat("NULL, ", len(lanes)), ", ")
	if cols != want {
		t.Errorf("post-drop select shape:\n got: %s\nwant: %s", cols, want)
	}
}

func countSubstr(s, sub string) int {
	n := 0
	for i := 0; i+len(sub) <= len(s); i++ {
		if s[i:i+len(sub)] == sub {
			n++
		}
	}
	return n
}

// The reconcile compares POSTS: it shifts a lane's code back off the id to get
// the msgid it belongs to. If that ever stopped being the inverse of the
// stamping, reconcile would decide every item was stale and quietly empty the
// index one tick after a deploy.
func TestEncodeOverflowExtID_ShiftsBackToTheMsgid(t *testing.T) {
	for lane, code := range overflowLaneCodes {
		const msgid = int64(121564088)
		if got := encodeOverflowExtID(msgid, code) >> overflowLaneShift; got != msgid {
			t.Errorf("lane %q: id shifts back to %d, want %d", lane, got, msgid)
		}
	}
}

// A post carrying two lanes becomes two items, each stamped with its own lane,
// and the lanes it does not carry produce nothing.
func TestBuildOverflowItems_OneItemPerLaneCarried(t *testing.T) {
	items := buildOverflowItems(ringRow(1001, map[string]string{
		"$.rural.sparse": "POLYGON((0 0, 10 0, 10 10, 0 10, 0 0))",
		"$.cluster.w1":   "POLYGON((20 20, 30 20, 30 30, 20 30, 20 20))",
	}), overflowLaneOrder())

	if len(items) != 2 {
		t.Fatalf("expected 2 ring items, got %d", len(items))
	}

	byLane := map[string]Item{}
	for _, item := range items {
		lane, _ := item.Extra["lane"].(string)
		byLane[lane] = item
	}
	sparse, ok := byLane["$.rural.sparse"]
	if !ok {
		t.Fatalf("no item for the sparse ring: %v", byLane)
	}
	if want := int64(1001)<<overflowLaneShift | 3; sparse.ExtID != want {
		t.Errorf("sparse item id = %d, want %d (msgid stamped with lane 3)", sparse.ExtID, want)
	}
	if sparse.MinLng != 0 || sparse.MaxLng != 10 {
		t.Errorf("sparse envelope = [%v,%v], want the ring's own bounds", sparse.MinLng, sparse.MaxLng)
	}
	if wedge := byLane["$.cluster.w1"]; wedge.MinLng != 20 {
		t.Errorf("wedge envelope = %v, want its own bounds, not the other lane's", wedge.MinLng)
	}
}

// A ring that will not parse costs that lane its posts - the read surfaces fall
// back to the committed reach - and must never take the row's other lanes with
// it, nor be admitted on a guess.
func TestBuildOverflowItems_SkipsUnparseableRings(t *testing.T) {
	items := buildOverflowItems(ringRow(1002, map[string]string{
		"$.rural.sparse": "NOT WKT AT ALL",
		"$.rural.medium": "POLYGON((0 0, 4 0, 4 4, 0 4, 0 0))",
	}), overflowLaneOrder())

	if len(items) != 1 {
		t.Fatalf("expected the good lane only, got %d items", len(items))
	}
	if lane, _ := items[0].Extra["lane"].(string); lane != "$.rural.medium" {
		t.Errorf("survivor is %q, want the medium ring", lane)
	}
}

// ringCellsB64 rasterises a ring WKT the way the batch does - through the ONE
// encoder - and base64s it the way overflow_cells stores it.
func ringCellsB64(t *testing.T, wkt string) string {
	t.Helper()
	cs, err := cellset.FromPolygonWKT(wkt)
	if err != nil {
		t.Fatal(err)
	}
	return base64.StdEncoding.EncodeToString(cs.Encode())
}

// A lane whose cells are stored classifies from the CELLS, never the WKT: the
// WKT slot is deliberately filled with nonsense that would fail to parse, so
// this only passes if the ring geometry was not consulted at all.
func TestBuildOverflowItems_PrefersCellsOverRingWKT(t *testing.T) {
	const wkt = "POLYGON((0 0, 0.003 0, 0.003 0.003, 0 0.003, 0 0))"

	items := buildOverflowItems(ringRowWithCells(1003,
		map[string]string{"$.rural.sparse": "NOT WKT AT ALL"},
		map[string]string{"$.rural.sparse": ringCellsB64(t, wkt)},
	), overflowLaneOrder())

	if len(items) != 1 {
		t.Fatalf("expected the lane to build from its cells, got %d items", len(items))
	}
	raster, err := DeserializeRaster(items[0].WKB)
	if err != nil {
		t.Fatal(err)
	}
	if got := raster.Classify(0.0015, 0.0015); got != cellIn {
		t.Errorf("interior point classified %d, want cellIn (%d)", got, cellIn)
	}
	if got := raster.Classify(5, 5); got != cellOut {
		t.Errorf("far-outside point classified %d, want cellOut (%d)", got, cellOut)
	}
}

// Malformed cells must not darken a lane that still has its ring: the WKT is
// still the authority, so a bad blob falls back rather than losing the lane.
func TestBuildOverflowItems_FallsBackToWKTWhenCellsAreMalformed(t *testing.T) {
	items := buildOverflowItems(ringRowWithCells(1004,
		map[string]string{"$.rural.sparse": "POLYGON((0 0, 4 0, 4 4, 0 4, 0 0))"},
		map[string]string{"$.rural.sparse": "!!!not base64 at all!!!"},
	), overflowLaneOrder())

	if len(items) != 1 {
		t.Fatalf("expected the ring WKT to carry the lane, got %d items", len(items))
	}
	if items[0].MinLng != 0 || items[0].MaxLng != 4 {
		t.Errorf("envelope = [%v,%v], want the ring's own bounds", items[0].MinLng, items[0].MaxLng)
	}
}

// The two forms must agree about who is admitted, since a partly-converted
// table serves some lanes from cells and others from WKT at the same time. The
// cells' bounds are lattice-aligned so the envelopes differ by under a cell;
// what must match is the classification of real points.
func TestBuildOverflowItems_CellsAndWKTAgreeOnAdmission(t *testing.T) {
	const wkt = "POLYGON((0 0, 0.03 0, 0.03 0.03, 0 0.03, 0 0))"

	fromWKT := buildOverflowItems(ringRow(1005,
		map[string]string{"$.rural.sparse": wkt}), overflowLaneOrder())
	fromCells := buildOverflowItems(ringRowWithCells(1006,
		map[string]string{"$.rural.sparse": wkt},
		map[string]string{"$.rural.sparse": ringCellsB64(t, wkt)}), overflowLaneOrder())

	if len(fromWKT) != 1 || len(fromCells) != 1 {
		t.Fatalf("expected one item each, got %d and %d", len(fromWKT), len(fromCells))
	}
	rWKT, err := DeserializeRaster(fromWKT[0].WKB)
	if err != nil {
		t.Fatal(err)
	}
	rCells, err := DeserializeRaster(fromCells[0].WKB)
	if err != nil {
		t.Fatal(err)
	}

	// Well clear of the boundary band on both sides, where the two forms must
	// not merely be close but identical - these are the points that decide
	// whether a member is admitted.
	for _, p := range [][2]float64{
		{0.015, 0.015}, {0.005, 0.005}, {0.025, 0.025}, // inside
		{-1, -1}, {5, 5}, {0.015, 1}, // outside
	} {
		a, b := rWKT.Classify(p[0], p[1]), rCells.Classify(p[0], p[1])
		if a != b {
			t.Errorf("point (%v,%v): WKT says %d, cells say %d", p[0], p[1], a, b)
		}
	}
}

// Containment end-to-end at the index level: a point inside one post's sparse
// ring is definite, a point in nobody's ring is nothing, and a point on the
// edge is partial so the caller exact-tests it rather than guessing.
func TestReachOverflowContaining(t *testing.T) {
	idx, err := CreateIndex(":memory:")
	if err != nil {
		t.Fatal(err)
	}
	defer idx.Close()

	items := buildOverflowItems(ringRow(1001, map[string]string{
		"$.rural.sparse": "POLYGON((0 0, 10 0, 10 10, 0 10, 0 0))",
	}), overflowLaneOrder())
	items = append(items, buildOverflowItems(ringRow(1002, map[string]string{
		"$.rural.dense": "POLYGON((0 0, 10 0, 10 10, 0 10, 0 0))",
	}), overflowLaneOrder())...)
	if err := InsertItems(idx, items, nil); err != nil {
		t.Fatal(err)
	}

	d := &ReachOverflowDataset{}

	in, partial, err := d.Containing(idx, 5, 5)
	if err != nil {
		t.Fatal(err)
	}
	if len(in) != 2 {
		t.Fatalf("both rings cover (5,5): in=%v partial=%v", in, partial)
	}
	// Both posts are returned, each on ITS OWN lane - which is the whole point
	// of stamping the id: a sparse-band viewer takes 1001 and leaves 1002.
	want := map[int64]bool{
		int64(1001)<<overflowLaneShift | 3: true,
		int64(1002)<<overflowLaneShift | 1: true,
	}
	for _, id := range in {
		if !want[id] {
			t.Errorf("unexpected id %d; want %v", id, want)
		}
	}

	in, partial, err = d.Containing(idx, 50, 50)
	if err != nil {
		t.Fatal(err)
	}
	if len(in) != 0 || len(partial) != 0 {
		t.Fatalf("nothing covers (50,50): in=%v partial=%v", in, partial)
	}

	// On the edge: reported, and reported as uncertain rather than lost.
	in, partial, err = d.Containing(idx, 9.999, 5)
	if err != nil {
		t.Fatal(err)
	}
	if len(in)+len(partial) == 0 {
		t.Fatal("a point on the ring's edge must still be reported, for the exact test to decide")
	}
}

// The dataset answers containment, not nearness: a KNN or within query against
// it is a caller mistake and must say so rather than returning an empty list
// that reads as "no rings".
func TestReachOverflowRejectsKnnAndWithin(t *testing.T) {
	d := &ReachOverflowDataset{}
	if _, err := d.Query(nil, QueryParams{}); err == nil {
		t.Error("knn against the ring dataset must error")
	}
	if _, err := d.Within(nil, QueryParams{}); err == nil {
		t.Error("within against the ring dataset must error")
	}
}
