package main

import (
	"strings"
	"testing"
	"time"

	"github.com/peterstace/simplefeatures/geom"

	"spatial-server/cellset"
)

// wkbOf converts WKT to WKB for buildReachItem (which expects MySQL's WKB).
func wkbOf(t *testing.T, wkt string) []byte {
	t.Helper()
	g, err := geom.UnmarshalWKT(wkt)
	if err != nil {
		t.Fatalf("parse: %v", err)
	}
	return g.AsBinary()
}

// The shared-geometry read (plans/2026-08-23-rippling-reach-polygon-dedup.md)
// must COALESCE the deduped row over the local blob, via a LEFT JOIN keyed on
// polygon_hash — never an INNER JOIN (that would drop every row whose hash is
// still NULL, i.e. every row before the backfill reaches it) and never bare
// `polygon` alone (that would keep serving pre-drain bytes forever once a row
// is drained to the sentinel). This is a text-shape check, the same kind
// TestOverflowSelect_BindsLanesInOrder uses for the dynamically built overflow
// SELECT: this module has no MySQL-backed test harness (dataset_drift_test.go
// stands MySQL's DDL/DML in with sqlite for portable SQL only — ST_AsWKB and a
// spatial JOIN are not portable, so Load/ApplyDelta/reconcile cannot be
// exercised against a fake DB here).
func TestReachGeomExpr_CoalescesSharedOverLocal(t *testing.T) {
	if !strings.Contains(reachGeomExpr, "COALESCE(g.geom, rr.polygon)") {
		t.Fatalf("reachGeomExpr must COALESCE the shared geometry over the local blob: %s", reachGeomExpr)
	}
	if !strings.HasPrefix(reachGeomExpr, "ST_AsWKB(") {
		t.Fatalf("reachGeomExpr must still hand WKB to buildReachItem: %s", reachGeomExpr)
	}
	if !strings.Contains(reachGeomJoin, "LEFT JOIN rippling_reach_geom g") {
		t.Fatalf("reachGeomJoin must be a LEFT JOIN (rows pre-backfill have no hash yet): %s", reachGeomJoin)
	}
	if !strings.Contains(reachGeomJoin, "g.hash = rr.polygon_hash") {
		t.Fatalf("reachGeomJoin must key on rr.polygon_hash: %s", reachGeomJoin)
	}
}

// buildReachItem operates on WKB bytes only — it has no idea whether they came
// from rippling_reach.polygon directly or from rippling_reach_geom via the
// COALESCE above. That is the point of putting the dedup entirely in the SQL
// layer: once reachGeomExpr resolves the right bytes, everything downstream
// (rasterising, envelope, area) is provably unaffected by where they came
// from. This test pins that: a "deduped" row (shared geometry bytes) and a
// "drained" row (the SAME bytes, standing in for what COALESCE returns once
// the local blob has been replaced by the sentinel and only the shared row
// carries the real geometry) both build byte-identical rasters to the
// undeduped case. It does not exercise reachGeomExpr's SQL itself — see the
// text-shape test above for that half of the guarantee.
func TestBuildReachItem_IdenticalAcrossDedupAndDrainedSources(t *testing.T) {
	wkt := "POLYGON((0 0, 10 0, 10 10, 0 10, 0 0))"
	wkb := wkbOf(t, wkt)

	undeduped, ok := buildReachItem(2001, "expanding", nil, wkb)
	if !ok {
		t.Fatal("undeduped item did not build")
	}

	// "Deduped": rr.polygon still holds the blob, but COALESCE would have
	// resolved to the identical shared row's bytes (content-addressed dedup
	// only ever stores an exact byte-for-byte copy — plan's own measurement:
	// polygon = f(origin, tick) byte-for-byte).
	deduped, ok := buildReachItem(2002, "expanding", nil, wkb)
	if !ok {
		t.Fatal("deduped item did not build")
	}

	// "Drained": rr.polygon has been replaced by the sentinel POINT(0 0), so
	// COALESCE resolves to the shared row's bytes instead — again identical to
	// the undeduped case, since it is the same geometry.
	drained, ok := buildReachItem(2003, "expanding", nil, wkb)
	if !ok {
		t.Fatal("drained-source item did not build")
	}

	for _, pair := range [][2]Item{{undeduped, deduped}, {undeduped, drained}} {
		a, b := pair[0], pair[1]
		if a.MinLng != b.MinLng || a.MaxLng != b.MaxLng || a.MinLat != b.MinLat || a.MaxLat != b.MaxLat {
			t.Fatalf("envelope diverged: %+v vs %+v", a, b)
		}
		if a.Area != b.Area {
			t.Fatalf("area diverged: %v vs %v", a.Area, b.Area)
		}
		if string(a.WKB) != string(b.WKB) {
			t.Fatalf("raster diverged for identical source geometry")
		}
	}
}

// TestMetaTimeRoundTrip: the persisted sync point must round-trip, and read
// back as zero (not error) from an index that has never written one — that is
// what startup adoption relies on for pre-meta on-disk indexes.
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

	covering, ok := buildReachItem(1001, "expanding", nil, wkbOf(t, "POLYGON((0 0, 10 0, 10 10, 0 10, 0 0))"))
	if !ok {
		t.Fatal("covering reach did not build")
	}
	elsewhere, ok := buildReachItem(1002, "done", nil, wkbOf(t, "POLYGON((20 20, 30 20, 30 30, 20 30, 20 20))"))
	if !ok {
		t.Fatal("elsewhere reach did not build")
	}
	if err := InsertItems(idx, []Item{covering, elsewhere}, nil); err != nil {
		t.Fatal(err)
	}

	d := &ReachDataset{}

	// Deep inside the first polygon: definite in, and the far one absent.
	in, partial, err := d.Containing(idx, 5, 5)
	if err != nil {
		t.Fatal(err)
	}
	if len(in) != 1 || in[0] != 1001 {
		t.Fatalf("expected in=[1001], got in=%v partial=%v", in, partial)
	}

	// Far outside both: nothing at all.
	in, partial, err = d.Containing(idx, 15, 15)
	if err != nil {
		t.Fatal(err)
	}
	if len(in) != 0 || len(partial) != 0 {
		t.Fatalf("expected nothing at (15,15), got in=%v partial=%v", in, partial)
	}

	// A hair inside the boundary: the raster must not claim definite-in; it
	// must be partial (the caller exact-tests) — never lost entirely.
	in, partial, err = d.Containing(idx, 9.999, 5)
	if err != nil {
		t.Fatal(err)
	}
	found := false
	for _, id := range partial {
		if id == 1001 {
			found = true
		}
	}
	for _, id := range in {
		if id == 1001 {
			found = true
		}
	}
	if !found {
		t.Fatalf("boundary point lost: in=%v partial=%v", in, partial)
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
	heldItem, ok := buildReachItem(1001, "held", nil, nil)
	if !ok || heldItem.Extra["status"] != "held" {
		t.Fatal("held row should build as a removal marker")
	}
	if err := idx.DeleteByExtID(1001); err != nil {
		t.Fatal(err)
	}
	in, partial, err = d.Containing(idx, 5, 5)
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
	item, ok := buildReachItem(3001, "expanding", cellsBlobOf(t, wkt), nil)
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

// Valid cells win over the polygon; corrupt cells fall back to it; and with
// neither usable the row is skipped.
func TestBuildReachItem_CellsPreferredWithFallback(t *testing.T) {
	wkt := "POLYGON((0 0, 0.03 0, 0.03 0.03, 0 0.03, 0 0))"
	cells := cellsBlobOf(t, wkt)
	wkb := wkbOf(t, wkt)

	fromCells, ok := buildReachItem(1, "expanding", cells, wkb)
	if !ok || string(fromCells.WKB) != string(cells) {
		t.Fatal("valid cells must become the item blob verbatim")
	}

	corrupt := append([]byte{}, cells...)
	corrupt = corrupt[:len(corrupt)-1]
	fromFallback, ok := buildReachItem(2, "expanding", corrupt, wkb)
	if !ok {
		t.Fatal("corrupt cells with a good polygon must fall back, not skip")
	}
	if string(fromFallback.WKB) == string(corrupt) {
		t.Fatal("corrupt cells must not be stored as the blob")
	}
	if _, err := DeserializeRaster(fromFallback.WKB); err != nil {
		t.Fatalf("fallback blob should be a coarse raster: %v", err)
	}

	if _, ok := buildReachItem(3, "expanding", corrupt, nil); ok {
		t.Fatal("corrupt cells and no polygon must skip the row")
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
	item, ok := buildReachItem(4001, "expanding", cellsBlobOf(t, wkt), nil)
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

// The SELECT must adapt to which legacy columns survive: dedup-era reads go
// through the COALESCE, polygon-only reads take the blob, and the cells-only
// form (post-drop) must reference no legacy column at all.
func TestReachSelectAdaptsToLegacyForm(t *testing.T) {
	dedup := reachSelect(2, "WHERE rr.status != 'held'")
	if !strings.Contains(dedup, "COALESCE(g.geom, rr.polygon)") || !strings.Contains(dedup, "LEFT JOIN rippling_reach_geom") {
		t.Fatalf("dedup-era select must read through the geom table: %s", dedup)
	}
	plain := reachSelect(1, "WHERE rr.status != 'held'")
	if !strings.Contains(plain, "ST_AsWKB(rr.polygon)") || strings.Contains(plain, "JOIN") {
		t.Fatalf("polygon-only select wrong: %s", plain)
	}
	cellsOnly := reachSelect(0, "WHERE rr.status != 'held'")
	if strings.Contains(cellsOnly, "polygon)") || strings.Contains(cellsOnly, "JOIN") || strings.Contains(cellsOnly, "hash") {
		t.Fatalf("cells-only select must not reference dropped columns: %s", cellsOnly)
	}
	if !strings.Contains(cellsOnly, "rr.polygon_cells") {
		t.Fatalf("cells-only select must still read the cells: %s", cellsOnly)
	}
}
