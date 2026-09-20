package main

import (
	"testing"

	"spatial-server/cellset"
)

// The surviving raster surface: the coarse tri-state prefilter built from a
// fine cell set (reachoverflow's storage form), serialized and read back.
// The WKT-geometry rasterizer this file used to test was deleted with the
// grid-removal endgame - every producer now starts from cell sets.
func TestRasterFromCellSetRoundTrip(t *testing.T) {
	cs, err := cellset.FromPolygonWKT("POLYGON((0 0, 0.02 0, 0.02 0.02, 0 0.02, 0 0))")
	if err != nil || cs.SetCellCount() == 0 {
		t.Fatalf("cell set did not build: %v", err)
	}
	r := BuildRasterFromCellSet(cs, 16)
	if r == nil {
		t.Fatal("raster did not build")
	}

	back, derr := DeserializeRaster(r.Serialize())
	if derr != nil {
		t.Fatalf("round trip: %v", derr)
	}

	// Inside the square: never cellOut. Far outside: cellOut.
	if got := back.Classify(0.01, 0.01); got == cellOut {
		t.Fatalf("centre classified out")
	}
	if got := back.Classify(5, 5); got != cellOut {
		t.Fatalf("far point: got %v want out", got)
	}

	// A nonsense dimension falls back to the default rather than exploding.
	if BuildRasterFromCellSet(cs, -3) == nil {
		t.Fatal("negative maxDim must fall back, not fail")
	}
}
