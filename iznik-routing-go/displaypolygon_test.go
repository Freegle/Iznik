package main

import (
	"math"
	"testing"
)

// staircaseRing builds a square whose south edge is drawn as many tiny steps - the shape
// the grid tracer produces, where every cell boundary becomes two vertices.
func staircaseRing(steps int, size, step float64) [][2]float64 {
	var pts [][2]float64
	for i := 0; i < steps; i++ {
		x := size * float64(i) / float64(steps)
		y := 0.0
		if i%2 == 1 {
			y = step
		}
		pts = append(pts, [2]float64{x, y})
	}
	pts = append(pts, [2]float64{size, 0})
	pts = append(pts, [2]float64{size, size})
	pts = append(pts, [2]float64{0, size})
	pts = append(pts, pts[0])
	return pts
}

// Deliberately built with exactly-collinear intermediate points on every edge: those are
// what plain Douglas-Peucker drops even at a tolerance of zero, so a ring without them
// cannot tell a working "0 means off" guard from a missing one.
func collinearSquare(perEdge int) [][2]float64 {
	corners := [][2]float64{{0, 0}, {1, 0}, {1, 1}, {0, 1}}
	var pts [][2]float64
	for i, a := range corners {
		b := corners[(i+1)%len(corners)]
		for s := 0; s < perEdge; s++ {
			f := float64(s) / float64(perEdge)
			pts = append(pts, [2]float64{a[0] + f*(b[0]-a[0]), a[1] + f*(b[1]-a[1])})
		}
	}
	return append(pts, pts[0])
}

func TestSimplifyRing_ZeroOrNegativeToleranceIsANoOp(t *testing.T) {
	ring := collinearSquare(8)
	for _, tol := range []float64{0, -1} {
		out := simplifyRing(ring, tol)
		if len(out) != len(ring) {
			t.Errorf("tolerance %g changed the ring: %d -> %d points", tol, len(ring), len(out))
		}
	}
	// Guard the guard: a positive tolerance on the same ring must still simplify, so the
	// no-op above is the tolerance being honoured rather than the ring being unsimplifiable.
	if out := simplifyRing(ring, 0.1); len(out) >= len(ring) {
		t.Errorf("positive tolerance did not simplify: %d -> %d points", len(ring), len(out))
	}
}

func TestDisplayRing_KeepsRingClosedAndReducesPoints(t *testing.T) {
	ring := staircaseRing(200, 1.0, 0.001)
	out := DisplayRing(ring, 0.01)

	if len(out) < 4 {
		t.Fatalf("display ring has %d points, expected >=4", len(out))
	}
	if out[0] != out[len(out)-1] {
		t.Errorf("display ring not closed: first=%v last=%v", out[0], out[len(out)-1])
	}
	if len(out) >= len(ring) {
		t.Errorf("simplification did not reduce point count: %d -> %d", len(ring), len(out))
	}
}

// The displayed boundary is allowed to depart from the traced one, but only by the
// tolerance we asked for. Anything more and the shading would claim reach we do not have.
func TestDisplayRing_StaysWithinTolerance(t *testing.T) {
	ring := staircaseRing(400, 1.0, 0.002)
	const tol = 0.02
	out := DisplayRing(ring, tol)

	// Rounding to 5dp can move a point by up to half a unit in the last place, on each axis.
	const slack = 1e-5

	for _, p := range ring {
		d := math.MaxFloat64
		for i := 0; i+1 < len(out); i++ {
			if e := perpendicularDistance(p, out[i], out[i+1]); e < d {
				d = e
			}
		}
		if d > tol+slack {
			t.Fatalf("point %v is %g from the display ring, tolerance %g", p, d, tol)
		}
	}
}

// The reason this exists is the wire size, so assert the order of magnitude we rely on.
func TestDisplayRing_CollapsesStaircaseHard(t *testing.T) {
	ring := staircaseRing(2000, 1.0, 0.0005)
	out := DisplayRing(ring, 0.01)
	if len(out) > len(ring)/20 {
		t.Errorf("expected at least a 20x reduction, got %d -> %d points", len(ring), len(out))
	}
}

func TestDisplayRing_ZeroToleranceStillRounds(t *testing.T) {
	ring := [][2]float64{
		{-3.4219791980743413, 55.709556579589844},
		{-3.4189791980743410, 55.709856579589844},
		{-3.4189791980743410, 55.710156579589844},
		{-3.4219791980743413, 55.709556579589844},
	}
	out := DisplayRing(ring, 0)
	if len(out) != len(ring) {
		t.Fatalf("zero tolerance should not drop points: %d -> %d", len(ring), len(out))
	}
	assertRounded(t, out, 5)
}

func TestDisplayRing_DegenerateRingsUnchanged(t *testing.T) {
	for _, ring := range [][][2]float64{
		nil,
		{{0, 0}},
		{{0, 0}, {1, 1}, {0, 0}},
	} {
		out := DisplayRing(ring, 0.5)
		if len(out) != len(ring) {
			t.Errorf("degenerate ring of %d points changed to %d", len(ring), len(out))
		}
	}
}

func TestRoundRing_TrimsPrecisionButKeepsShapeAndClosure(t *testing.T) {
	ring := [][2]float64{
		{-3.4219791980743413, 55.709556579589844},
		{-3.4189791980743410, 55.709856579589844},
		{-3.4189791980743410, 55.710156579589844},
		{-3.4219791980743413, 55.709556579589844},
	}
	out := roundRing(ring, 5)

	if out[0] != out[len(out)-1] {
		t.Errorf("rounded ring not closed: first=%v last=%v", out[0], out[len(out)-1])
	}
	assertRounded(t, out, 5)
	for i := range ring {
		if math.Abs(ring[i][0]-out[i][0]) > 1e-5 || math.Abs(ring[i][1]-out[i][1]) > 1e-5 {
			t.Errorf("rounding moved point %d from %v to %v", i, ring[i], out[i])
		}
	}
}

// Rounding can make neighbouring points identical. Zero-length segments are wasted bytes
// and upset consumers that assume distinct vertices, so they must be dropped - without
// opening the ring.
func TestRoundRing_DropsDuplicatesCreatedByRounding(t *testing.T) {
	ring := [][2]float64{
		{0.0000010, 0.0000010},
		{0.0000012, 0.0000012},
		{0.5, 0.5},
		{1.0, 0.0},
		{0.0000010, 0.0000010},
	}
	out := roundRing(ring, 5)
	for i := 0; i+1 < len(out); i++ {
		if out[i] == out[i+1] {
			t.Errorf("duplicate consecutive point at %d: %v", i, out[i])
		}
	}
	if out[0] != out[len(out)-1] {
		t.Errorf("rounded ring not closed: first=%v last=%v", out[0], out[len(out)-1])
	}
}

// Dropping duplicates must never eat the ring down below a drawable triangle.
func TestRoundRing_KeepsDegenerateRingDrawable(t *testing.T) {
	ring := [][2]float64{
		{0.0000010, 0.0000010},
		{0.0000012, 0.0000011},
		{0.0000011, 0.0000013},
		{0.0000010, 0.0000010},
	}
	out := roundRing(ring, 5)
	if len(out) != len(ring) {
		t.Errorf("a ring that rounds to a single point should be left alone, got %d points", len(out))
	}
}

func assertRounded(t *testing.T, ring [][2]float64, dp int) {
	t.Helper()
	scale := math.Pow(10, float64(dp))
	for _, p := range ring {
		for _, v := range []float64{p[0], p[1]} {
			if math.Abs(v*scale-math.Round(v*scale)) > 1e-6 {
				t.Errorf("coordinate %v not rounded to %ddp", v, dp)
			}
		}
	}
}
