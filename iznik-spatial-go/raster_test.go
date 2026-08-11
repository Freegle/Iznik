package main

import (
	"fmt"
	"math"
	"strings"
	"testing"

	"github.com/peterstace/simplefeatures/geom"
)

// mustGeom lives in index_test.go.

// assertNeverWrong probes a dense grid over (and beyond) g's bbox and checks
// the raster's cardinal guarantee: cellIn implies exactly-inside and cellOut
// implies exactly-outside. cellPartial makes no claim. This is the property
// the badge count's correctness rests on — a partial verdict costs an exact
// SQL test, a wrong In/Out verdict would corrupt the count.
func assertNeverWrong(t *testing.T, g geom.Geometry, r *Raster) (in, out, partial int) {
	t.Helper()
	env := g.Envelope()
	min, max, ok := env.MinMaxXYs()
	if !ok {
		t.Fatal("no envelope")
	}
	w, h := max.X-min.X, max.Y-min.Y
	const n = 150
	for i := 0; i <= n; i++ {
		for j := 0; j <= n; j++ {
			// Probe 10% beyond the bbox on every side too.
			lng := min.X - 0.1*w + (1.2*w)*float64(i)/n
			lat := min.Y - 0.1*h + (1.2*h)*float64(j)/n
			pt := geom.NewPoint(geom.Coordinates{XY: geom.XY{X: lng, Y: lat}})
			exact := geom.Intersects(g, pt.AsGeometry())
			switch r.Classify(lng, lat) {
			case cellIn:
				in++
				if !exact {
					t.Fatalf("raster says IN but point (%f,%f) is outside", lng, lat)
				}
			case cellOut:
				out++
				if exact {
					t.Fatalf("raster says OUT but point (%f,%f) is inside", lng, lat)
				}
			default:
				partial++
			}
		}
	}
	return
}

func TestRasterSquare(t *testing.T) {
	g := mustGeom(t, "POLYGON((0 0, 10 0, 10 10, 0 10, 0 0))")
	r := BuildRaster(g)
	if r == nil {
		t.Fatal("nil raster")
	}
	in, _, partial := assertNeverWrong(t, g, r)
	if in == 0 {
		t.Fatal("no definite-in cells for a solid square")
	}
	// The partial band should be a thin boundary strip, not the bulk.
	if partial > in {
		t.Fatalf("partial (%d) exceeds in (%d): boundary band too fat", partial, in)
	}
}

func TestRasterPolygonWithHole(t *testing.T) {
	g := mustGeom(t, "POLYGON((0 0, 20 0, 20 20, 0 20, 0 0), (8 8, 12 8, 12 12, 8 12, 8 8))")
	r := BuildRaster(g)
	if r == nil {
		t.Fatal("nil raster")
	}
	assertNeverWrong(t, g, r)
	// Hole centre must not be IN.
	if r.Classify(10, 10) == cellIn {
		t.Fatal("hole centre classified as inside")
	}
}

func TestRasterMultiPolygon(t *testing.T) {
	g := mustGeom(t, "MULTIPOLYGON(((0 0, 5 0, 5 5, 0 5, 0 0)), ((10 10, 15 10, 15 15, 10 15, 10 10)))")
	r := BuildRaster(g)
	if r == nil {
		t.Fatal("nil raster")
	}
	assertNeverWrong(t, g, r)
	// The gap between the two parts must not be IN.
	if r.Classify(7.5, 7.5) == cellIn {
		t.Fatal("gap between multipolygon parts classified as inside")
	}
}

// TestRasterConcaveGridFill mimics a reach polygon's real shape: a grid-fill
// outline (staircase edges, concavities). Built as a plus-sign with a bite.
func TestRasterConcaveGridFill(t *testing.T) {
	g := mustGeom(t, "POLYGON((2 0, 4 0, 4 2, 6 2, 6 4, 4 4, 4 6, 2 6, 2 4, 0 4, 0 2, 2 2, 2 0))")
	r := BuildRaster(g)
	if r == nil {
		t.Fatal("nil raster")
	}
	assertNeverWrong(t, g, r)
	// Concave notch (0..2 x 0..2 corner) is outside the plus.
	if r.Classify(0.5, 0.5) == cellIn {
		t.Fatal("concave notch classified as inside")
	}
	if r.Classify(3, 3) != cellIn {
		t.Fatal("plus centre not classified as inside")
	}
}

// TestRasterManyVertices approximates the production shape: an ~11k-vertex
// jagged ring (like a traced isochrone). Guards both correctness and that
// BuildRaster stays fast enough for a 50k-polygon load.
func TestRasterManyVertices(t *testing.T) {
	var sb strings.Builder
	sb.WriteString("POLYGON((")
	const n = 11000
	for i := 0; i <= n; i++ {
		frac := float64(i%2)*0.08 + 0.92 // jagged radius alternation
		angle := 2 * math.Pi * float64(i%n) / n
		x := 5 + 5*frac*math.Cos(angle)
		y := 5 + 5*frac*math.Sin(angle)
		if i > 0 {
			sb.WriteString(", ")
		}
		sb.WriteString(fmt.Sprintf("%.6f %.6f", x, y))
	}
	sb.WriteString("))")
	g := mustGeom(t, sb.String())
	r := BuildRaster(g)
	if r == nil {
		t.Fatal("nil raster")
	}
	assertNeverWrong(t, g, r)
}

func TestRasterSerializeRoundTrip(t *testing.T) {
	g := mustGeom(t, "POLYGON((0 0, 10 0, 10 6, 0 6, 0 0))")
	r := BuildRaster(g)
	if r == nil {
		t.Fatal("nil raster")
	}
	b := r.Serialize()
	r2, err := DeserializeRaster(b)
	if err != nil {
		t.Fatalf("deserialize: %v", err)
	}
	if r2.Cols != r.Cols || r2.Rows != r.Rows || r2.MinLng != r.MinLng || r2.MinLat != r.MinLat {
		t.Fatal("round-trip header mismatch")
	}
	for row := 0; row < r.Rows; row++ {
		for col := 0; col < r.Cols; col++ {
			if r.get(col, row) != r2.get(col, row) {
				t.Fatalf("cell (%d,%d) mismatch after round-trip", col, row)
			}
		}
	}
	// Corrupt header rejected.
	if _, err := DeserializeRaster(b[:20]); err == nil {
		t.Fatal("truncated blob accepted")
	}
	// Reach blobs must stay small (index memory budget).
	if len(b) > 4096 {
		t.Fatalf("serialized raster too big: %d bytes", len(b))
	}
}

func TestRasterDegenerateGeometries(t *testing.T) {
	if BuildRaster(mustGeom(t, "POINT(1 1)")) != nil {
		t.Fatal("point should not rasterise")
	}
	if BuildRaster(mustGeom(t, "LINESTRING(0 0, 1 1)")) != nil {
		t.Fatal("line should not rasterise")
	}
	if BuildRaster(mustGeom(t, "POLYGON EMPTY")) != nil {
		t.Fatal("empty polygon should not rasterise")
	}
}

// BenchmarkBuildRaster guards load-time cost: the reach dataset rasterises
// ~52k polygons averaging ~11k vertices at every full rebuild, so per-polygon
// build must stay in the low milliseconds for the rebuild to stay in minutes.
func BenchmarkBuildRaster(b *testing.B) {
	var sb strings.Builder
	sb.WriteString("POLYGON((")
	const n = 11000
	for i := 0; i <= n; i++ {
		frac := float64(i%2)*0.08 + 0.92
		angle := 2 * math.Pi * float64(i%n) / n
		x := 5 + 5*frac*math.Cos(angle)
		y := 5 + 5*frac*math.Sin(angle)
		if i > 0 {
			sb.WriteString(", ")
		}
		sb.WriteString(fmt.Sprintf("%.6f %.6f", x, y))
	}
	sb.WriteString("))")
	g, err := geom.UnmarshalWKT(sb.String())
	if err != nil {
		b.Fatal(err)
	}
	b.ResetTimer()
	for i := 0; i < b.N; i++ {
		if BuildRaster(g) == nil {
			b.Fatal("nil raster")
		}
	}
}

func TestRasterOutsideBBoxIsOut(t *testing.T) {
	g := mustGeom(t, "POLYGON((0 0, 10 0, 10 10, 0 10, 0 0))")
	r := BuildRaster(g)
	if r.Classify(-5, 5) != cellOut || r.Classify(5, 50) != cellOut {
		t.Fatal("points outside the bbox must be OUT")
	}
}
