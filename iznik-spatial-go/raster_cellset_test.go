package main

import (
	"os"
	"strconv"
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

// staircaseRingWKT builds a closed ring whose boundary is a lattice staircase -
// the shape class production actually stores. Every real reach polygon and every
// overflow ring is a marching-squares tracing of a routing-server raster
// (iznik-routing-go's traceBoundary), so its vertices step along the lattice in
// right angles rather than following a smooth curve. A convex test square shares
// none of that structure: it has four edges, no reflex corners and no boundary
// cell touched twice.
//
// Deterministic, so a failure is reproducible: the staircase's step heights come
// from a fixed integer walk, not a random source.
func staircaseRingWKT(steps int) string {
	const cell = cellset.CellDegrees
	pts := make([][2]float64, 0, 4*steps+2)

	// Out along the bottom, rising in a repeating 1-2-1-3 pattern of cells.
	heights := []int{1, 2, 1, 3}
	y := 0
	for i := 0; i < steps; i++ {
		x := float64(i) * cell
		pts = append(pts, [2]float64{x, float64(y) * cell})
		y += heights[i%len(heights)]
		pts = append(pts, [2]float64{x, float64(y) * cell})
	}
	// Across the top, then back down the far side, then home along the axis.
	top := float64(y+2) * cell
	pts = append(pts, [2]float64{float64(steps) * cell, top})
	pts = append(pts, [2]float64{0, top})
	pts = append(pts, [2]float64{0, 0})

	wkt := "POLYGON(("
	for i, p := range pts {
		if i > 0 {
			wkt += ", "
		}
		// Eight decimals is four orders of magnitude finer than the 0.0003
		// lattice, so formatting never nudges a vertex off it.
		wkt += strconv.FormatFloat(p[0], 'f', 8, 64) + " " + strconv.FormatFloat(p[1], 'f', 8, 64)
	}
	return wkt + "))"
}

// TestBuildRasterFromCellSet_AgreesWithAStaircaseBoundary is the parity proof
// that ALWAYS runs. The polygon-built and cell-set-built accelerators are two
// descriptions of one shape - one via its boundary, one via its membership grid
// - so wherever either is DEFINITE they must agree, and on a lattice staircase
// (the shape production stores) that boundary is maximally awkward: hundreds of
// right-angle corners sitting exactly on cell edges.
//
// The full-scale version of this, over a real ~31,000-vertex production polygon
// probed at 40,401 points, is TestBuildRasterFromCellSet_AgreesWithPolygonBuild
// above; it needs a ~1MB sample that is not checked in. This one needs nothing.
func TestBuildRasterFromCellSet_AgreesWithAStaircaseBoundary(t *testing.T) {
	wkt := staircaseRingWKT(60)

	g, err := geom.UnmarshalWKT(wkt, geom.NoValidate{})
	if err != nil {
		t.Fatalf("parse staircase WKT: %v", err)
	}
	polyRaster := BuildRasterDim(g, rasterMaxDim)
	if polyRaster == nil {
		t.Fatal("nil polygon-built raster")
	}

	cs, err := cellset.FromPolygonWKT(wkt)
	if err != nil {
		t.Fatalf("FromPolygonWKT: %v", err)
	}
	cellRaster := BuildRasterFromCellSet(cs, rasterMaxDim)
	if cellRaster == nil {
		t.Fatal("nil cell-set-built raster")
	}

	minLng, minLat, maxLng, maxLat := cs.Bounds()
	w, h := maxLng-minLng, maxLat-minLat

	const n = 200
	agree, deferred, contradictions := 0, 0, 0
	for i := 0; i <= n; i++ {
		for j := 0; j <= n; j++ {
			x := minLng - 0.1*w + (1.2*w)*float64(i)/n
			y := minLat - 0.1*h + (1.2*h)*float64(j)/n

			a, b := polyRaster.Classify(x, y), cellRaster.Classify(x, y)
			if a == cellPartial || b == cellPartial {
				deferred++

				continue
			}
			if a != b {
				contradictions++
				if contradictions <= 5 {
					t.Errorf("contradiction at (%v,%v): polygon says %d, cells say %d", x, y, a, b)
				}

				continue
			}
			agree++
		}
	}

	if contradictions > 0 {
		t.Fatalf("%d contradictions over %d probes", contradictions, (n+1)*(n+1))
	}
	if agree == 0 {
		t.Fatal("no probe was definite in both - this proves nothing")
	}
	t.Logf("agree=%d deferred=%d contradictions=0 over %d probes", agree, deferred, (n+1)*(n+1))
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
// membership grid.
//
// Needs a real reach polygon, which is ~1MB of WKT and so is not checked in.
// Point REACH_SAMPLE_WKT at one to run it - e.g. pull a live row read-only
// with `SELECT ST_AsText(polygon) FROM rippling_reach WHERE msgid = ...`. It
// skips without one, and the CI-enforced parity check is the smaller
// TestBuildOverflowItems_CellsAndWKTAgreeOnAdmission, which always runs.
func TestBuildRasterFromCellSet_AgreesWithPolygonBuild(t *testing.T) {
	path := os.Getenv("REACH_SAMPLE_WKT")
	if path == "" {
		t.Skip("set REACH_SAMPLE_WKT to a real reach polygon's WKT to run this parity proof")
	}
	wkt, err := os.ReadFile(path)
	if err != nil {
		t.Skipf("REACH_SAMPLE_WKT is set but unreadable (%v)", err)
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
