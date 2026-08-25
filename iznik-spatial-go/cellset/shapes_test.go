package cellset

import (
	"fmt"
	"math/rand"
	"os"
	"strings"
	"testing"
)

// SHAPE COVERAGE. The hand-drawn fixtures in trace_test.go pin specific
// awkward cases, which is the right way to encode a known bug - but twelve
// small pictures are not the shape space this code actually meets. Real reach
// polygons are ragged isochrones of tens of thousands of vertices, frequently
// multi-part after a union with an origin group's area or a rejection clip,
// and roughly 94% of them are technically invalid geometry (self-touching
// rings from the routing server's own grid fill).
//
// So this file covers shapes two other ways: a property test over many
// GENERATED grids, and explicit classes of WKT the rasteriser has to accept.
//
// The properties asserted for every shape are the ones the whole design rests
// on:
//   1. trace then rasterise covers exactly the same cells (tolerance 0)
//   2. every traced ring is simple - no ring visits a corner twice
//   3. the streaming probe agrees with a full decode at every cell
//   4. encode/decode is byte-identical
//
// Deterministic seeds throughout: a failure has to be reproducible, and a
// randomised test that cannot be replayed is worse than none.

// checkAllProperties asserts the four invariants above for one grid.
func checkAllProperties(t *testing.T, label string, cs *CellSet) {
	t.Helper()

	if cs.SetCellCount() == 0 {
		if _, err := cs.ToMultiPolygonWKT(0); err == nil {
			t.Errorf("%s: an empty grid must refuse to trace", label)
		}
		return
	}

	// 1 + 2: trace, check simplicity, rasterise back, compare coverage.
	for _, r := range cs.TraceBoundary() {
		seen := map[corner]bool{}
		for _, v := range r.verts {
			if seen[v] {
				t.Errorf("%s: ring revisits corner %v", label, v)
				break
			}
			seen[v] = true
		}
	}

	wkt, err := cs.ToMultiPolygonWKT(0)
	if err != nil {
		t.Errorf("%s: trace failed: %v", label, err)
		return
	}
	back, err := FromPolygonWKT(wkt)
	if err != nil {
		t.Errorf("%s: traced WKT would not rasterise: %v", label, err)
		return
	}
	want, got := globalCells(cs), globalCells(back)
	if len(want) != len(got) {
		t.Errorf("%s: coverage changed through trace+rasterise: %d cells -> %d", label, len(want), len(got))
		return
	}
	for cell := range want {
		if !got[cell] {
			t.Errorf("%s: cell %v lost through trace+rasterise", label, cell)
			return
		}
	}

	// 3: the streaming probe is what every gate uses; a full decode is what
	// the clip uses. They must never differ.
	enc := cs.Encode()
	for r := int32(-1); r <= int32(cs.Rows); r++ {
		for c := int32(-1); c <= int32(cs.Cols); c++ {
			lng := (float64(cs.MinCol+c) + 0.5) * CellDegrees
			lat := (float64(cs.MinRow+r) + 0.5) * CellDegrees
			probe, ok := ContainsEncoded(enc, lng, lat)
			if !ok {
				t.Errorf("%s: probe could not answer at (%d,%d)", label, c, r)
				return
			}
			if probe != cs.Contains(lng, lat) {
				t.Errorf("%s: probe and decode disagree at (%d,%d)", label, c, r)
				return
			}
		}
	}

	// 4: and the bytes must survive a round trip, since the clip writes them
	// back after subtracting.
	decoded, err := Decode(enc)
	if err != nil {
		t.Errorf("%s: own bytes would not decode: %v", label, err)
		return
	}
	if string(decoded.Encode()) != string(enc) {
		t.Errorf("%s: encode/decode is not byte-identical", label)
	}
}

// A checkerboard is the worst case for boundary tracing: EVERY interior corner
// is a point where two covered regions meet only diagonally, so the whole grid
// is saddles. Worth its own test rather than being buried in the random set.
func TestShapes_Checkerboard(t *testing.T) {
	for _, n := range []uint32{2, 3, 8, 15} {
		cs := newCellSet(-7, 11, n, n)
		for r := uint32(0); r < n; r++ {
			for c := uint32(0); c < n; c++ {
				if (r+c)%2 == 0 {
					cs.setCell(c, r)
				}
			}
		}
		checkAllProperties(t, fmt.Sprintf("checkerboard %dx%d", n, n), cs)
	}
}

// Structured awkward shapes, at a size where a failure is still readable.
func TestShapes_StructuredAwkwardCases(t *testing.T) {
	build := func(w, h uint32, set func(c, r uint32) bool) *CellSet {
		cs := newCellSet(-1000, 2000, w, h)
		for r := uint32(0); r < h; r++ {
			for c := uint32(0); c < w; c++ {
				if set(c, r) {
					cs.setCell(c, r)
				}
			}
		}
		return cs
	}

	cases := map[string]*CellSet{
		// A one-cell-wide ribbon - a rural reach following a single road.
		"horizontal sliver": build(40, 1, func(c, r uint32) bool { return true }),
		"vertical sliver":   build(1, 40, func(c, r uint32) bool { return true }),
		// A comb: many slivers joined along one edge, so many concave corners.
		"comb": build(11, 6, func(c, r uint32) bool { return r == 0 || c%2 == 0 }),
		// A one-cell-thick ring: shell and hole almost touching everywhere.
		"thin annulus": build(9, 9, func(c, r uint32) bool {
			return c == 0 || r == 0 || c == 8 || r == 8
		}),
		// Concentric rings - nested holes, three deep.
		"concentric": build(13, 13, func(c, r uint32) bool {
			d := min4(c, r, 12-c, 12-r)
			return d%2 == 0
		}),
		// A diagonal staircase, whose every step corner sits on the lattice.
		"diagonal band": build(20, 20, func(c, r uint32) bool {
			return c >= r && c <= r+2
		}),
		// A spiral: one long snake with many turns and a hole that wraps.
		"spiral": build(11, 11, func(c, r uint32) bool {
			return r%4 == 0 || (r%4 == 2 && c > 0) || (r%2 == 1 && ((r%4 == 1 && c == 10) || (r%4 == 3 && c == 1)))
		}),
		// Two blobs joined only at a corner, then again - the chained saddle
		// case, at a larger size than the hand-drawn fixture.
		"corner chain": build(12, 12, func(c, r uint32) bool {
			return (c/3+r/3)%2 == 0
		}),
		// Fully covered, so there is no hole and one square ring.
		"solid": build(7, 7, func(c, r uint32) bool { return true }),
		// A single cell in a large empty grid: bounds much larger than coverage.
		"lone cell in space": build(30, 30, func(c, r uint32) bool { return c == 15 && r == 15 }),
	}
	for name, cs := range cases {
		t.Run(name, func(t *testing.T) { checkAllProperties(t, name, cs) })
	}
}

func min4(a, b, c, d uint32) uint32 {
	m := a
	for _, v := range []uint32{b, c, d} {
		if v < m {
			m = v
		}
	}
	return m
}

// The property test proper: many random grids at several densities. Low
// densities produce scattered single cells and diagonal touches; middling ones
// produce ragged blobs with holes; high ones produce near-solid areas with
// pinholes. Between them they reach far more of the shape space than any set
// of drawn fixtures.
func TestShapes_RandomGridsHoldEveryProperty(t *testing.T) {
	densities := []float64{0.05, 0.2, 0.35, 0.5, 0.65, 0.8, 0.95}
	sizes := []uint32{1, 2, 3, 5, 9, 16, 25}

	for si, size := range sizes {
		for di, density := range densities {
			// Seed from the case, so a failure names the exact grid to replay.
			seed := int64(si*1000 + di)
			rng := rand.New(rand.NewSource(seed))
			for iter := 0; iter < 6; iter++ {
				cs := newCellSet(-500+int32(iter), 900, size, size)
				for r := uint32(0); r < size; r++ {
					for c := uint32(0); c < size; c++ {
						if rng.Float64() < density {
							cs.setCell(c, r)
						}
					}
				}
				checkAllProperties(t,
					fmt.Sprintf("random size=%d density=%.2f seed=%d iter=%d", size, density, seed, iter),
					cs)
				if t.Failed() {
					return // one reproducible failure is enough to act on
				}
			}
		}
	}
}

// Classes of WKT the rasteriser must accept, as distinct from grids it must
// trace. Real stored geometry is not a box: it has interior rings, it is
// frequently multi-part, and it is usually invalid.
func TestShapes_WktClassesTheRasteriserMustAccept(t *testing.T) {
	cases := map[string]struct {
		wkt      string
		wantCell [2]float64 // a point that must be COVERED
		wantHole [2]float64 // a point that must NOT be, or {0,0} to skip
	}{
		"plain box": {
			"POLYGON((0 0, 0.003 0, 0.003 0.003, 0 0.003, 0 0))",
			[2]float64{0.0015, 0.0015}, [2]float64{0, 0},
		},
		"box with an interior ring": {
			// A hole in the middle: even-odd must leave it uncovered.
			"POLYGON((0 0, 0.009 0, 0.009 0.009, 0 0.009, 0 0)," +
				"(0.003 0.003, 0.006 0.003, 0.006 0.006, 0.003 0.006, 0.003 0.003))",
			[2]float64{0.0015, 0.0015}, [2]float64{0.0045, 0.0045},
		},
		"clockwise outer ring": {
			// Ring orientation must not matter to an even-odd fill.
			"POLYGON((0 0, 0 0.003, 0.003 0.003, 0.003 0, 0 0))",
			[2]float64{0.0015, 0.0015}, [2]float64{0, 0},
		},
		"multipolygon, two disjoint parts": {
			"MULTIPOLYGON(((0 0, 0.003 0, 0.003 0.003, 0 0.003, 0 0))," +
				"((0.009 0.009, 0.012 0.009, 0.012 0.012, 0.009 0.012, 0.009 0.009)))",
			[2]float64{0.0105, 0.0105}, [2]float64{0.006, 0.006},
		},
		"multipolygon where one part has a hole": {
			"MULTIPOLYGON(((0 0, 0.009 0, 0.009 0.009, 0 0.009, 0 0)," +
				"(0.003 0.003, 0.006 0.003, 0.006 0.006, 0.003 0.006, 0.003 0.003))," +
				"((0.012 0.012, 0.015 0.012, 0.015 0.015, 0.012 0.015, 0.012 0.012)))",
			[2]float64{0.0135, 0.0135}, [2]float64{0.0045, 0.0045},
		},
		"self-intersecting bowtie": {
			// INVALID geometry, and the common case in production. Must
			// rasterise rather than error; even-odd gives each lobe.
			"POLYGON((0 0, 0.006 0.006, 0.006 0, 0 0.006, 0 0))",
			[2]float64{0.0009, 0.003}, [2]float64{0, 0},
		},
		"duplicate consecutive vertices": {
			"POLYGON((0 0, 0 0, 0.003 0, 0.003 0, 0.003 0.003, 0 0.003, 0 0.003, 0 0))",
			[2]float64{0.0015, 0.0015}, [2]float64{0, 0},
		},
		"negative coordinates across zero": {
			// The UK case: Greenwich is longitude zero.
			"POLYGON((-0.003 51.5, 0.003 51.5, 0.003 51.503, -0.003 51.503, -0.003 51.5))",
			[2]float64{-0.0015, 51.5015}, [2]float64{0, 0},
		},
	}

	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			cs, err := FromPolygonWKT(c.wkt)
			if err != nil {
				t.Fatalf("must rasterise, got: %v", err)
			}
			if c.wantCell != [2]float64{0, 0} {
				if !cs.Contains(c.wantCell[0], c.wantCell[1]) {
					t.Errorf("point %v should be covered", c.wantCell)
				}
			}
			if c.wantHole != [2]float64{0, 0} {
				if cs.Contains(c.wantHole[0], c.wantHole[1]) {
					t.Errorf("point %v should NOT be covered - the interior ring was filled in", c.wantHole)
				}
			}
			// And the full property set, so a shape that rasterises but
			// cannot survive a trace is caught here too.
			checkAllProperties(t, name, cs)
		})
	}
}

// Degenerate input must be refused, not silently turned into an empty grid.
// A caller treats an error as "cannot answer" and retries; it treats a valid
// grid as the truth. So a shape that fills no cell centre has to error, or a
// post ends up storing a reach that covers nobody - and after the drop there
// is no polygon left to contradict it. Note "unclosed ring" is NOT here: an
// unclosed three-point ring still encloses area under the even-odd fill, so
// it produces a real grid and is accepted.
func TestShapes_DegenerateInputIsRefused(t *testing.T) {
	for name, wkt := range map[string]string{
		"empty string":        "",
		"not WKT at all":      "definitely not geometry",
		"a point":             "POINT(0 0)",
		"a line":              "LINESTRING(0 0, 1 1)",
		"too few points":      "POLYGON((0 0, 1 0, 0 0))",
		"zero-area polygon":   "POLYGON((0 0, 0 0, 0 0, 0 0))",
		"empty polygon":       "POLYGON EMPTY",
		"geometry collection": "GEOMETRYCOLLECTION(POINT(0 0))",
	} {
		if _, err := FromPolygonWKT(wkt); err == nil {
			t.Errorf("%s: must be refused, not rasterised", name)
		}
	}
}

// The same properties against REAL production reach polygons, which are the
// shapes that actually matter: tens of thousands of vertices, ragged, and
// usually invalid. Runs only where the samples were captured (one WKT per
// line, tab-separated msgid and geometry) so CI skips rather than fails.
func TestShapes_RealProductionPolygons(t *testing.T) {
	path := os.Getenv("REACH_SAMPLE_TSV")
	if path == "" {
		t.Skip("set REACH_SAMPLE_TSV to a msgid<TAB>WKT file of real reaches to run this")
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		t.Skipf("sample not readable: %v", err)
	}

	n := 0
	for _, line := range strings.Split(string(raw), "\n") {
		parts := strings.SplitN(strings.TrimSpace(line), "\t", 2)
		if len(parts) < 2 || parts[1] == "" {
			continue
		}
		cs, err := FromPolygonWKT(parts[1])
		if err != nil {
			t.Errorf("msgid %s: real polygon would not rasterise: %v", parts[0], err)
			continue
		}
		checkAllProperties(t, "msgid "+parts[0], cs)
		n++
		t.Logf("msgid %s: %d x %d grid, %d cells, WKT %d bytes -> %d bytes",
			parts[0], cs.Cols, cs.Rows, cs.SetCellCount(), len(parts[1]), len(cs.Encode()))
	}
	if n == 0 {
		t.Fatal("sample file contained no usable polygons")
	}
}

// Coordinates lying EXACTLY on a cell boundary are the one case the other
// tests miss: every probe elsewhere uses cell centres, where no rule is
// ambiguous. A lattice index is floor(coord / 0.0003), and 0.0003 is not
// representable in binary, so k*0.0003 for integer k lands a hair above or
// below the true multiple and the floor can fall either side. Three
// implementations do this arithmetic - this one, iznik-server-go's port and
// iznik-batch's - and a half-cell disagreement between any two of them is the
// classic "pixel is a point vs pixel is an area" bug, which is silent because
// each side is internally consistent.
//
// The contract asserted here is not a particular answer at the boundary - it
// is that the answer is DETERMINISTIC and matches simple floor arithmetic, so
// the other implementations can be pinned to the same values.
func TestShapes_ExactCellBoundaryCoordinatesAreDeterministic(t *testing.T) {
	for _, k := range []int{-10001, -1000, -301, -3, -1, 0, 1, 3, 301, 1000, 10001} {
		coord := float64(k) * CellDegrees

		gotCol := colIndex(coord)
		gotRow := rowIndex(coord)
		if gotCol != gotRow {
			t.Errorf("k=%d: colIndex=%d but rowIndex=%d - the two axes must share one rule", k, gotCol, gotRow)
		}

		// Whatever floor() gives for this product is the answer, and it must
		// be stable. Recomputing must not drift.
		if again := colIndex(coord); again != gotCol {
			t.Errorf("k=%d: not deterministic (%d then %d)", k, gotCol, again)
		}

		// And the cell just inside must be the previous index, so the mapping
		// is monotonic across the boundary with no gap or overlap.
		if inside := colIndex(coord - CellDegrees/2); inside != gotCol-1 {
			t.Errorf("k=%d (coord %v): index %d, but half a cell below gives %d, expected %d",
				k, coord, gotCol, inside, gotCol-1)
		}
		if above := colIndex(coord + CellDegrees/2); above != gotCol {
			t.Errorf("k=%d (coord %v): index %d, but half a cell above gives %d, expected the same",
				k, coord, gotCol, above)
		}

		t.Logf("k=%6d  coord=%-24v -> cell index %d", k, coord, gotCol)
	}
}

// The same coordinates, through a real grid and the streaming probe, so the
// boundary rule is pinned end to end rather than only in the index helper.
func TestShapes_BoundaryCoordinatesAgreeBetweenProbeAndDecode(t *testing.T) {
	// A grid covering cell indices 0..9 on both axes.
	cs := newCellSet(0, 0, 10, 10)
	for r := uint32(0); r < 10; r++ {
		for c := uint32(0); c < 10; c++ {
			cs.setCell(c, r)
		}
	}
	enc := cs.Encode()

	// Probe on the exact boundaries, including the two outer edges - the
	// bottom-left corner is inside cell 0, the top-right is one past the last
	// cell and so outside.
	for k := -1; k <= 10; k++ {
		coord := float64(k) * CellDegrees
		probe, ok := ContainsEncoded(enc, coord, coord)
		if !ok {
			t.Fatalf("k=%d: probe could not answer on a cell boundary", k)
		}
		full := cs.Contains(coord, coord)
		if probe != full {
			t.Errorf("k=%d (coord %v): streaming probe says %v, full decode says %v",
				k, coord, probe, full)
		}
		want := k >= 0 && k <= 9
		if probe != want {
			t.Errorf("k=%d (coord %v): got %v, want %v - the boundary rule has moved",
				k, coord, probe, want)
		}
	}
}
