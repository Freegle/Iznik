package main

import (
	"database/sql"
	"encoding/base64"
	"testing"

	"spatial-server/cellset"
)

// ringRow makes one reach row's lane slots, rasterising the named lanes' WKT
// into the base64 cell sets overflow_cells stores.
func ringRow(t *testing.T, msgid int64, rings map[string]string) overflowRowScan {
	t.Helper()
	lanes := overflowLaneOrder()
	r := newOverflowRowScan(len(lanes))
	r.msgid = msgid
	for i, lane := range lanes {
		if wkt, ok := rings[lane]; ok {
			r.cells[i] = sql.NullString{String: ringCellsB64(t, wkt), Valid: true}
		}
	}
	return r
}

// rawRingRow fills the named lanes' cell slots verbatim, for malformed-bytes cases.
func rawRingRow(msgid int64, cells map[string]string) overflowRowScan {
	lanes := overflowLaneOrder()
	r := newOverflowRowScan(len(lanes))
	r.msgid = msgid
	for i, lane := range lanes {
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
	cols, args := overflowSelect()
	lanes := overflowLaneOrder()

	// The scan pairs column i with lane i positionally, so the binds must run
	// through the lane order in order.
	if len(args) != len(lanes) {
		t.Fatalf("select binds %d paths, want %d", len(args), len(lanes))
	}
	for i, lane := range lanes {
		if args[i] != lane {
			t.Errorf("cells bind %d = %v, want %q", i, args[i], lane)
		}
	}
	// One extraction per lane, as text to be decoded here rather than parsed
	// by MySQL - the DB doing that work is what this dataset exists to stop.
	if got := countSubstr(cols, "JSON_UNQUOTE(JSON_EXTRACT(overflow_cells, ?))"); got != len(lanes) {
		t.Errorf("select has %d cell extractions, want %d: %s", got, len(lanes), cols)
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
	items := buildOverflowItems(ringRow(t, 1001, map[string]string{
		"$.rural.sparse": "POLYGON((0 0, 0.01 0, 0.01 0.01, 0 0.01, 0 0))",
		"$.cluster.w1":   "POLYGON((0.02 0.02, 0.03 0.02, 0.03 0.03, 0.02 0.03, 0.02 0.02))",
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
	if sparse.MinLng > 0.001 || sparse.MinLng < -0.001 || sparse.MaxLng < 0.009 || sparse.MaxLng > 0.011 {
		t.Errorf("sparse envelope = [%v,%v], want the ring's own bounds (within a lattice cell)", sparse.MinLng, sparse.MaxLng)
	}
	if wedge := byLane["$.cluster.w1"]; wedge.MinLng < 0.019 || wedge.MinLng > 0.021 {
		t.Errorf("wedge envelope = %v, want its own bounds, not the other lane's", wedge.MinLng)
	}
}

// A lane whose bytes will not decode costs that lane its posts - the read
// surfaces fall back to the committed reach - and must never take the row's
// other lanes with it, nor be admitted on a guess.
func TestBuildOverflowItems_SkipsUndecodableLanes(t *testing.T) {
	good := ringCellsB64(t, "POLYGON((0 0, 4 0, 4 4, 0 4, 0 0))")
	items := buildOverflowItems(rawRingRow(1002, map[string]string{
		"$.rural.sparse": "!!!not base64 at all!!!",
		"$.rural.medium": good,
	}), overflowLaneOrder())

	if len(items) != 1 {
		t.Fatalf("expected the good lane only, got %d items", len(items))
	}
	if lane, _ := items[0].Extra["lane"].(string); lane != "$.rural.medium" {
		t.Errorf("survivor is %q, want the medium lane", lane)
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

// A lane classifies from its cells: interior points are definite, far points
// are definite outs.
func TestBuildOverflowItems_ClassifiesFromCells(t *testing.T) {
	const wkt = "POLYGON((0 0, 0.003 0, 0.003 0.003, 0 0.003, 0 0))"

	items := buildOverflowItems(rawRingRow(1003,
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

// Containment end-to-end at the index level: a point inside one post's sparse
// ring is definite, a point in nobody's ring is nothing, and a point on the
// edge is partial so the caller exact-tests it rather than guessing.
func TestReachOverflowContaining(t *testing.T) {
	idx, err := CreateIndex(":memory:")
	if err != nil {
		t.Fatal(err)
	}
	defer idx.Close()

	items := buildOverflowItems(ringRow(t, 1001, map[string]string{
		"$.rural.sparse": "POLYGON((0 0, 0.01 0, 0.01 0.01, 0 0.01, 0 0))",
	}), overflowLaneOrder())
	items = append(items, buildOverflowItems(ringRow(t, 1002, map[string]string{
		"$.rural.dense": "POLYGON((0 0, 0.01 0, 0.01 0.01, 0 0.01, 0 0))",
	}), overflowLaneOrder())...)
	if err := InsertItems(idx, items, nil); err != nil {
		t.Fatal(err)
	}

	d := &ReachOverflowDataset{}

	in, partial, err := d.Containing(idx, 0.005, 0.005)
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

	// Near the edge but within the covered lattice: still reported. (The
	// coarse ring raster keeps its boundary band, so this may come back as
	// partial rather than definite - either way it must not be lost.)
	in, partial, err = d.Containing(idx, 0.0095, 0.005)
	if err != nil {
		t.Fatal(err)
	}
	if len(in)+len(partial) == 0 {
		t.Fatal("a near-edge point inside the ring must still be reported")
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
