package main

import (
	"strings"
	"testing"
	"time"

	"spatial-server/cellset"
)

func TestMetaTimeRoundTrip(t *testing.T) {
	idx, err := CreateIndex(":memory:")
	if err != nil {
		t.Fatal(err)
	}
	defer idx.Close()

	got, err := idx.GetMetaTime("last_sync")
	if err != nil || !got.IsZero() {
		t.Fatalf("expected zero time from fresh index, got %v err %v", got, err)
	}

	want := time.Date(2026, 8, 11, 20, 44, 11, 123456789, time.UTC)
	if err := idx.SetMetaTime("last_sync", want); err != nil {
		t.Fatal(err)
	}
	got, err = idx.GetMetaTime("last_sync")
	if err != nil || !got.Equal(want) {
		t.Fatalf("round-trip mismatch: got %v err %v", got, err)
	}

	// Overwrite wins.
	want2 := want.Add(time.Hour)
	if err := idx.SetMetaTime("last_sync", want2); err != nil {
		t.Fatal(err)
	}
	got, _ = idx.GetMetaTime("last_sync")
	if !got.Equal(want2) {
		t.Fatalf("overwrite failed: got %v", got)
	}
}

// TestReachContaining covers the query path end-to-end at the index level:
// definite containment, definite exclusion, boundary-band points reported as
// partial, and held rows never entering the index.
func TestReachContaining(t *testing.T) {
	idx, err := CreateIndex(":memory:")
	if err != nil {
		t.Fatal(err)
	}
	defer idx.Close()

	covering, ok := buildReachItem(1001, "expanding", cellsBlobOf(t, "POLYGON((0 0, 0.01 0, 0.01 0.01, 0 0.01, 0 0))"))
	if !ok {
		t.Fatal("covering reach did not build")
	}
	elsewhere, ok := buildReachItem(1002, "done", cellsBlobOf(t, "POLYGON((0.02 0.02, 0.03 0.02, 0.03 0.03, 0.02 0.03, 0.02 0.02))"))
	if !ok {
		t.Fatal("elsewhere reach did not build")
	}
	if err := InsertItems(idx, []Item{covering, elsewhere}, nil); err != nil {
		t.Fatal(err)
	}

	d := &ReachDataset{}

	// Deep inside the first polygon: definite in, and the far one absent.
	in, partial, err := d.Containing(idx, 0.005, 0.005)
	if err != nil {
		t.Fatal(err)
	}
	if len(in) != 1 || in[0] != 1001 {
		t.Fatalf("expected in=[1001], got in=%v partial=%v", in, partial)
	}

	// Far outside both: nothing at all.
	in, partial, err = d.Containing(idx, 0.015, 0.015)
	if err != nil {
		t.Fatal(err)
	}
	if len(in) != 0 || len(partial) != 0 {
		t.Fatalf("expected nothing at (0.015,0.015), got in=%v partial=%v", in, partial)
	}

	// A point within the covered lattice near the edge: definite in - the
	// grid answers exactly, there is no boundary band and no partial.
	in, partial, err = d.Containing(idx, 0.0095, 0.005)
	if err != nil {
		t.Fatal(err)
	}
	if len(in) != 1 || in[0] != 1001 || len(partial) != 0 {
		t.Fatalf("near-edge covered point must be definite: in=%v partial=%v", in, partial)
	}

	// ExtIDs (the reconcile's index-side view) reflects inserts and deletes.
	ids, err := idx.ExtIDs()
	if err != nil {
		t.Fatal(err)
	}
	if _, ok := ids[1001]; !ok {
		t.Fatal("ExtIDs missing 1001")
	}
	if _, ok := ids[1002]; !ok {
		t.Fatal("ExtIDs missing 1002")
	}

	// A held reach delta-row builds as a removal marker, and removal works.
	heldItem, ok := buildReachItem(1001, "held", nil)
	if !ok || heldItem.Extra["status"] != "held" {
		t.Fatal("held row should build as a removal marker")
	}
	if err := idx.DeleteByExtID(1001); err != nil {
		t.Fatal(err)
	}
	in, partial, err = d.Containing(idx, 0.005, 0.005)
	if err != nil {
		t.Fatal(err)
	}
	if len(in) != 0 && len(partial) != 0 {
		t.Fatalf("held/removed reach still returned: in=%v partial=%v", in, partial)
	}
}

// cellsBlobOf rasterises a WKT into encoded cell bytes via the production
// rasteriser, for building cells-backed reach items in tests.
func cellsBlobOf(t *testing.T, wkt string) []byte {
	t.Helper()
	cs, err := cellset.FromPolygonWKT(wkt)
	if err != nil {
		t.Fatalf("rasterise: %v", err)
	}
	return cs.Encode()
}

// A cells-backed item answers EXACTLY: a point a hair inside the boundary is
// a definite `in`, never `partial` - the whole point of preferring the fine
// grid over the coarse raster.
func TestReachContaining_CellsAnswerExactly(t *testing.T) {
	idx, err := CreateIndex(":memory:")
	if err != nil {
		t.Fatal(err)
	}
	defer idx.Close()

	wkt := "POLYGON((0 0, 0.03 0, 0.03 0.03, 0 0.03, 0 0))" // 100x100 cells
	item, ok := buildReachItem(3001, "expanding", cellsBlobOf(t, wkt))
	if !ok {
		t.Fatal("cells item did not build")
	}
	if err := InsertItems(idx, []Item{item}, nil); err != nil {
		t.Fatal(err)
	}

	d := &ReachDataset{}
	// Just inside the eastern boundary: cell centres inside the polygon are
	// covered, so a point in the last covered cell is a definite in.
	in, partial, err := d.Containing(idx, 0.03-cellset.CellDegrees/2, 0.015)
	if err != nil {
		t.Fatal(err)
	}
	if len(partial) != 0 {
		t.Fatalf("cells-backed item must never answer partial, got %v", partial)
	}
	if len(in) != 1 || in[0] != 3001 {
		t.Fatalf("expected definite in, got in=%v", in)
	}

	// Just outside: definite out, still no partial.
	in, partial, err = d.Containing(idx, 0.03+cellset.CellDegrees/2, 0.015)
	if err != nil {
		t.Fatal(err)
	}
	if len(in) != 0 || len(partial) != 0 {
		t.Fatalf("expected definite out, got in=%v partial=%v", in, partial)
	}
}

// Valid cells become the item blob verbatim; corrupt or absent cells skip the
// row (fail closed - a row nobody can read has no reach anywhere).
func TestBuildReachItem_CellsOrSkip(t *testing.T) {
	wkt := "POLYGON((0 0, 0.03 0, 0.03 0.03, 0 0.03, 0 0))"
	cells := cellsBlobOf(t, wkt)

	fromCells, ok := buildReachItem(1, "expanding", cells)
	if !ok || string(fromCells.WKB) != string(cells) {
		t.Fatal("valid cells must become the item blob verbatim")
	}

	corrupt := append([]byte{}, cells...)
	corrupt = corrupt[:len(corrupt)-1]
	if _, ok := buildReachItem(2, "expanding", corrupt); ok {
		t.Fatal("corrupt cells must skip the row")
	}

	if _, ok := buildReachItem(3, "expanding", nil); ok {
		t.Fatal("no cells must skip the row")
	}
}

// AdmitsPoints: the committed-reach twin of the ring admits call.
func TestReachAdmitsPoints(t *testing.T) {
	idx, err := CreateIndex(":memory:")
	if err != nil {
		t.Fatal(err)
	}
	defer idx.Close()

	wkt := "POLYGON((0 0, 0.03 0, 0.03 0.03, 0 0.03, 0 0))"
	item, ok := buildReachItem(4001, "expanding", cellsBlobOf(t, wkt))
	if !ok {
		t.Fatal(err)
	}
	if err := InsertItems(idx, []Item{item}, nil); err != nil {
		t.Fatal(err)
	}

	d := &ReachDataset{}
	pts := []ReachPoint{
		{Lng: 0.015, Lat: 0.015},  // inside
		{Lng: 0.05, Lat: 0.05},    // outside
		{Lng: 0.001, Lat: 0.001},  // inside, near corner
		{Lng: -0.001, Lat: 0.015}, // outside, west
	}
	admitted, uncertain, known, err := d.AdmitsPoints(idx, 4001, pts)
	if err != nil || !known {
		t.Fatalf("admits failed: err=%v known=%v", err, known)
	}
	if len(uncertain) != 0 {
		t.Fatalf("cells-backed admits must have no uncertain points: %v", uncertain)
	}
	if len(admitted) != 2 || admitted[0] != 0 || admitted[1] != 2 {
		t.Fatalf("expected points 0 and 2 admitted, got %v", admitted)
	}

	// Unknown msgid: not an error, known=false, so the caller fails closed.
	_, _, known, err = d.AdmitsPoints(idx, 999999, pts)
	if err != nil || known {
		t.Fatalf("missing post must answer known=false, got known=%v err=%v", known, err)
	}
}

// The SELECT must reference no dropped legacy column.
func TestReachSelectNamesNoDroppedColumn(t *testing.T) {
	sel := reachSelect("WHERE rr.status != 'held'")
	if strings.Contains(sel, "polygon)") || strings.Contains(sel, "JOIN") || strings.Contains(sel, "hash") {
		t.Fatalf("select must not reference dropped columns: %s", sel)
	}
	if !strings.Contains(sel, "rr.polygon_cells") {
		t.Fatalf("select must read the cells: %s", sel)
	}
	// Labels-truth grid retirement: the select must carry the retired flag,
	// so a drained row is REMOVED (delta) or never loaded - a skipped upsert
	// would leave the previous tick's smaller reach serving stale answers.
	if !strings.Contains(sel, "reach_labels IS NOT NULL AND rr.polygon_cells IS NULL") {
		t.Fatalf("select must carry the retired expression: %s", sel)
	}
}
