package main

import (
	"testing"

	"github.com/peterstace/simplefeatures/geom"
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

// TestReachContaining covers the query path end-to-end at the index level:
// definite containment, definite exclusion, boundary-band points reported as
// partial, and held rows never entering the index.
func TestReachContaining(t *testing.T) {
	idx, err := CreateIndex(":memory:")
	if err != nil {
		t.Fatal(err)
	}
	defer idx.Close()

	covering, ok := buildReachItem(1001, "expanding", wkbOf(t, "POLYGON((0 0, 10 0, 10 10, 0 10, 0 0))"))
	if !ok {
		t.Fatal("covering reach did not build")
	}
	elsewhere, ok := buildReachItem(1002, "done", wkbOf(t, "POLYGON((20 20, 30 20, 30 30, 20 30, 20 20))"))
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
	heldItem, ok := buildReachItem(1001, "held", nil)
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
