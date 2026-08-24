package main

import (
	"os"
	"testing"

	"github.com/peterstace/simplefeatures/geom"
	"spatial-server/cellset"
)

// assertCellSetNeverWrong is assertNeverWrong's counterpart for a raster
// built from a CellSet: the SAME cardinal guarantee (cellIn/cellOut must
// never be wrong; cellPartial makes no claim), checked against the CellSet's
// own Contains — the fine-grained ground truth once there is no polygon to
// fall back to.
func assertCellSetNeverWrong(t *testing.T, cs *cellset.CellSet, r *Raster) (in, out, partial int) {
	t.Helper()
	minLng, minLat, maxLng, maxLat := cs.Bounds()
	w, h := maxLng-minLng, maxLat-minLat
	const n = 150
	for i := 0; i <= n; i++ {
		for j := 0; j <= n; j++ {
			x := minLng - 0.1*w + (1.2*w)*float64(i)/n
			y := minLat - 0.1*h + (1.2*h)*float64(j)/n
			switch r.Classify(x, y) {
			case cellIn:
				in++
				if !cs.Contains(x, y) {
					t.Fatalf("cellIn at (%v,%v) but CellSet says outside", x, y)
				}
			case cellOut:
				out++
				if cs.Contains(x, y) {
					t.Fatalf("cellOut at (%v,%v) but CellSet says inside", x, y)
				}
			case cellPartial:
				partial++
			}
		}
	}
	return
}

func TestBuildRasterFromCellSet_NeverWrong(t *testing.T) {
	cs, err := cellset.FromPolygonWKT("POLYGON((0 0, 0.03 0, 0.03 0.03, 0 0.03, 0 0))")
	if err != nil {
		t.Fatal(err)
	}
	r := BuildRasterFromCellSet(cs, rasterMaxDim)
	if r == nil {
		t.Fatal("nil raster")
	}
	in, out, partial := assertCellSetNeverWrong(t, cs, r)
	if in == 0 {
		t.Error("expected some definite-in cells for a filled square")
	}
	if out == 0 {
		t.Error("expected some definite-out cells outside the square")
	}
	t.Logf("in=%d out=%d partial=%d", in, out, partial)
}

func TestBuildRasterFromCellSet_NilOnEmptyCellSet(t *testing.T) {
	// A CellSet with zero area (degenerate bounds) - BuildRasterFromCellSet
	// must degrade to nil, matching BuildRasterDim's "no raster; always use
	// the exact answer" contract for unusable input, not panic.
	r := BuildRasterFromCellSet(&cellset.CellSet{}, rasterMaxDim)
	if r != nil {
		t.Error("expected nil for a degenerate (zero-sized) CellSet")
	}
}

// TestBuildRasterFromCellSet_AgreesWithPolygonBuild is the parity proof: on
// a real production reach polygon (not synthetic), the WKT/edge-based
// BuildRasterDim and the CellSet-based BuildRasterFromCellSet must reach the
// same DEFINITE verdict everywhere either one is definite - they are two
// ways of describing the same shape, one via its boundary, one via its
// membership grid. Skipped where the live sample artefact is not present.
func TestBuildRasterFromCellSet_AgreesWithPolygonBuild(t *testing.T) {
	path := "/tmp/claude-1000/-home-edward-FreegleDockerWSL/6cc0d137-5be2-47c2-a290-7c87f043dcd2/scratchpad/sample-polygon.wkt"
	wkt, err := os.ReadFile(path)
	if err != nil {
		t.Skipf("live sample not present (%v)", err)
	}

	g, err := geom.UnmarshalWKT(string(wkt), geom.NoValidate{})
	if err != nil {
		t.Fatalf("parse WKT: %v", err)
	}
	polyRaster := BuildRasterDim(g, rasterMaxDim)
	if polyRaster == nil {
		t.Fatal("nil polygon-built raster")
	}

	cs, err := cellset.FromPolygonWKT(string(wkt))
	if err != nil {
		t.Fatalf("FromPolygonWKT: %v", err)
	}
	cellRaster := BuildRasterFromCellSet(cs, rasterMaxDim)
	if cellRaster == nil {
		t.Fatal("nil cellset-built raster")
	}

	minLng, minLat, maxLng, maxLat := cs.Bounds()
	w, h := maxLng-minLng, maxLat-minLat
	const n = 200
	var agree, disagreeDefinite, eitherPartial int
	for i := 0; i <= n; i++ {
		for j := 0; j <= n; j++ {
			x := minLng + w*float64(i)/n
			y := minLat + h*float64(j)/n
			a, b := polyRaster.Classify(x, y), cellRaster.Classify(x, y)
			if a == cellPartial || b == cellPartial {
				eitherPartial++
				continue
			}
			if a == b {
				agree++
			} else {
				disagreeDefinite++
				if disagreeDefinite <= 5 {
					t.Logf("disagreement at (%.6f,%.6f): polygon-raster=%d cellset-raster=%d", x, y, a, b)
				}
			}
		}
	}
	t.Logf("agree=%d disagreeDefinite=%d eitherPartial=%d (of %d probes)",
		agree, disagreeDefinite, eitherPartial, (n+1)*(n+1))

	// Zero tolerance for a definite-vs-definite disagreement: that would mean
	// one of the two builds is classifying a point wrongly, not a rounding
	// difference at a boundary (boundary disagreements show up as partial).
	if disagreeDefinite != 0 {
		t.Errorf("%d probe points got a DIFFERENT definite verdict between the two build methods", disagreeDefinite)
	}
}
