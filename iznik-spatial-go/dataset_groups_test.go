package main

import (
	"testing"

	"github.com/peterstace/simplefeatures/geom"

	"spatial-server/cellset"
)

// groupItemOf builds a groups-index Item from WKT the way Load does.
func groupItemOf(t *testing.T, id int64, wkt string) Item {
	t.Helper()
	g, err := geom.UnmarshalWKT(wkt)
	if err != nil {
		t.Fatalf("parse: %v", err)
	}
	env := g.Envelope()
	min, max, ok := env.MinMaxXYs()
	if !ok {
		t.Fatal("degenerate envelope")
	}
	return Item{
		ExtID: id, WKB: g.AsBinary(), Area: g.Area(),
		MinLng: min.X, MaxLng: max.X, MinLat: min.Y, MaxLat: max.Y,
		Extra: map[string]any{"nameshort": "test"},
	}
}

// IntersectingCells: bbox candidates from the R-tree, then cell-for-cell
// intersects/within against each group's rasterised area.
func TestGroupsIntersectingCells(t *testing.T) {
	idx, err := CreateIndex(":memory:")
	if err != nil {
		t.Fatal(err)
	}
	defer idx.Close()

	items := []Item{
		// A group square the reach sits entirely inside.
		groupItemOf(t, 1, "POLYGON((0 0, 0.1 0, 0.1 0.1, 0 0.1, 0 0))"),
		// A group overlapping the reach's east half only.
		groupItemOf(t, 2, "POLYGON((0.02 0, 0.2 0, 0.2 0.1, 0.02 0.1, 0.02 0))"),
		// A group far away.
		groupItemOf(t, 3, "POLYGON((5 5, 6 5, 6 6, 5 6, 5 5))"),
	}
	if err := InsertItems(idx, items, nil); err != nil {
		t.Fatal(err)
	}

	reach, err := cellset.FromPolygonWKT("POLYGON((0.01 0.01, 0.03 0.01, 0.03 0.03, 0.01 0.03, 0.01 0.01))")
	if err != nil {
		t.Fatal(err)
	}

	d := &GroupsDataset{}
	rel, err := d.IntersectingCells(idx, reach)
	if err != nil {
		t.Fatal(err)
	}

	got := map[int64]bool{} // id -> within
	for _, r := range rel {
		got[r.ID] = r.Within
	}
	if len(got) != 2 {
		t.Fatalf("expected groups 1 and 2, got %v", rel)
	}
	within, ok := got[1]
	if !ok || !within {
		t.Fatalf("reach lies entirely inside group 1: %v", rel)
	}
	within, ok = got[2]
	if !ok || within {
		t.Fatalf("group 2 overlaps but does not contain the reach: %v", rel)
	}
	if _, ok := got[3]; ok {
		t.Fatalf("distant group must not appear: %v", rel)
	}

	// Second call answers from the group-raster cache and must agree.
	rel2, err := d.IntersectingCells(idx, reach)
	if err != nil || len(rel2) != len(rel) {
		t.Fatalf("cached second call diverged: %v vs %v (err %v)", rel2, rel, err)
	}
}
