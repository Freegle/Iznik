package cellset

import (
	"strings"
	"testing"
)

// buildGrid makes a CellSet from a picture: rows are strings of '.' and '#',
// FIRST string is the TOP row (highest latitude), so pictures read naturally.
// The grid is anchored at an arbitrary non-zero origin to catch any code that
// confuses local and global coordinates.
func buildGrid(t *testing.T, pic []string) *CellSet {
	t.Helper()
	rows := uint32(len(pic))
	cols := uint32(len(pic[0]))
	cs := newCellSet(-1234, 5678, cols, rows)
	for i, line := range pic {
		if uint32(len(line)) != cols {
			t.Fatalf("ragged picture row %d", i)
		}
		row := rows - 1 - uint32(i) // picture top = highest row index
		for c := uint32(0); c < cols; c++ {
			if line[c] == '#' {
				cs.setCell(c, row)
			}
		}
	}
	return cs
}

// globalCells returns the set cells in GLOBAL (col,row) coordinates, so two
// CellSets with different stored bounds can be compared for identical
// coverage.
func globalCells(cs *CellSet) map[[2]int32]bool {
	out := map[[2]int32]bool{}
	for r := uint32(0); r < cs.Rows; r++ {
		for c := uint32(0); c < cs.Cols; c++ {
			if cs.getCell(c, r) {
				out[[2]int32{cs.MinCol + int32(c), cs.MinRow + int32(r)}] = true
			}
		}
	}
	return out
}

var traceFixtures = map[string][]string{
	"single cell": {
		"#",
	},
	"rectangle": {
		"####",
		"####",
		"####",
	},
	"L shape": {
		"#..",
		"#..",
		"###",
	},
	"diagonal pinch": {
		"#.",
		".#",
	},
	"donut": {
		"#####",
		"#...#",
		"#...#",
		"#...#",
		"#####",
	},
	"hole with island": {
		"#####",
		"#...#",
		"#.#.#",
		"#...#",
		"#####",
	},
	"two components": {
		"##..##",
		"##..##",
	},
	"island in a lake": {
		"#######",
		"#.....#",
		"#..#..#",
		"#.....#",
		"#######",
	},
	"staircase": {
		"....#",
		"...##",
		"..###",
		".####",
		"#####",
	},
	"pinched hole": {
		"#####",
		"#.#.#",
		"#####",
	},
	// CHAINED diagonal pinches: a covered cell touching two other covered
	// regions at OPPOSITE corners, so one walk meets two saddle points. This
	// is the case a per-visit turn preference gets wrong - the walk can route
	// back through the earlier pinch instead of closing there, producing a
	// figure-eight. Found by an adversarial review.
	"chained pinch": {
		"#####",
		"#.#.#",
		"#..##",
		"#.#.#",
		"#####",
	},
	"chained pinch open": {
		"#..",
		".#.",
		"..#",
	},
}

// The trace's load-bearing property: rasterising the traced boundary (via the
// SAME even-odd centre-sampling rasteriser production uses) reproduces the
// input grid exactly, cell for cell, at tolerance 0.
func TestTraceRoundTripsThroughRasterizer(t *testing.T) {
	for name, pic := range traceFixtures {
		t.Run(name, func(t *testing.T) {
			cs := buildGrid(t, pic)
			wkt, err := cs.ToMultiPolygonWKT(0)
			if err != nil {
				t.Fatalf("trace: %v", err)
			}
			back, err := FromPolygonWKT(wkt)
			if err != nil {
				t.Fatalf("rasterise traced WKT: %v\nwkt=%s", err, wkt)
			}
			want, got := globalCells(cs), globalCells(back)
			if len(want) != len(got) {
				t.Fatalf("cell count changed: %d -> %d\nwkt=%s", len(want), len(got), wkt)
			}
			for cell := range want {
				if !got[cell] {
					t.Fatalf("cell %v lost in roundtrip\nwkt=%s", cell, wkt)
				}
			}
		})
	}
}

// Every traced ring must be simple: the pinch rule splits diagonal touches
// into separate rings rather than emitting a self-touching one.
func TestTraceRingsAreSimple(t *testing.T) {
	for name, pic := range traceFixtures {
		t.Run(name, func(t *testing.T) {
			cs := buildGrid(t, pic)
			for _, r := range cs.TraceBoundary() {
				seen := map[corner]bool{}
				for _, v := range r.verts {
					if seen[v] {
						t.Fatalf("ring revisits vertex %v", v)
					}
					seen[v] = true
				}
			}
		})
	}
}

func TestTraceHoleNesting(t *testing.T) {
	// Donut: one polygon, shell + one hole.
	wkt, err := buildGrid(t, traceFixtures["donut"]).ToMultiPolygonWKT(0)
	if err != nil {
		t.Fatal(err)
	}
	// One shell means exactly one top-level "((" group... count polygons by
	// splitting on ")),((".
	if n := strings.Count(wkt, ")),(("); n != 0 {
		t.Fatalf("donut should be ONE polygon, got %d extra: %s", n, wkt)
	}
	// The donut's central cell is covered, so the hole must not swallow it:
	// re-rasterisation already proves coverage; here just require a hole ring
	// exists (two rings in the polygon).
	inner := strings.Count(wkt, "(")
	if inner < 4 { // MULTIPOLYGON( ( (shell (hole
		t.Fatalf("expected shell+hole rings: %s", wkt)
	}

	// Two components: two polygons.
	wkt2, err := buildGrid(t, traceFixtures["two components"]).ToMultiPolygonWKT(0)
	if err != nil {
		t.Fatal(err)
	}
	if n := strings.Count(wkt2, ")),(("); n != 1 {
		t.Fatalf("two components should be TWO polygons: %s", wkt2)
	}

	// An island inside a hole is its OWN polygon, not a ring of the outer
	// one: outer shell + moat hole, plus the island shell.
	wkt3, err := buildGrid(t, traceFixtures["hole with island"]).ToMultiPolygonWKT(0)
	if err != nil {
		t.Fatal(err)
	}
	if n := strings.Count(wkt3, ")),(("); n != 1 {
		t.Fatalf("island in a hole should be a second polygon: %s", wkt3)
	}
}

func TestTraceEmptyGrid(t *testing.T) {
	cs := newCellSet(0, 0, 4, 4)
	if _, err := cs.ToMultiPolygonWKT(0); err == nil {
		t.Fatal("empty grid must refuse to trace")
	}
}

// A display tolerance must still produce parseable WKT that covers roughly
// the same area (no exactness claim).
func TestTraceSimplifiedStillParses(t *testing.T) {
	cs := buildGrid(t, traceFixtures["staircase"])
	// One cell of tolerance: enough to straighten the staircase hypotenuse
	// into the triangle it approximates. (The production ring tolerance,
	// 0.0015 deg = 5 cells, only makes sense on kilometre-scale shapes.)
	wkt, err := cs.ToMultiPolygonWKT(CellDegrees)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := FromPolygonWKT(wkt); err != nil {
		t.Fatalf("simplified WKT does not parse/rasterise: %v\n%s", err, wkt)
	}
}

// ContainsEncoded must agree with Decode+Contains at every cell in and around
// the grid, and refuse corrupt bytes rather than guessing.
func TestContainsEncodedParity(t *testing.T) {
	for name, pic := range traceFixtures {
		t.Run(name, func(t *testing.T) {
			cs := buildGrid(t, pic)
			b := cs.Encode()
			// Probe every cell centre one cell beyond the bounds on each side.
			for r := int32(-1); r <= int32(cs.Rows); r++ {
				for c := int32(-1); c <= int32(cs.Cols); c++ {
					lng := (float64(cs.MinCol+c) + 0.5) * CellDegrees
					lat := (float64(cs.MinRow+r) + 0.5) * CellDegrees
					got, ok := ContainsEncoded(b, lng, lat)
					if !ok {
						t.Fatalf("probe could not answer at (%d,%d)", c, r)
					}
					want := cs.Contains(lng, lat)
					if got != want {
						t.Fatalf("probe disagrees with decode at (%d,%d): %v vs %v", c, r, got, want)
					}
				}
			}
		})
	}
}

func TestContainsEncodedRefusesCorruptBytes(t *testing.T) {
	cs := buildGrid(t, traceFixtures["rectangle"])
	b := cs.Encode()

	cases := map[string][]byte{
		"empty":        {},
		"short header": b[:10],
		"bad magic":    append([]byte{1, 2, 3, 4}, b[4:]...),
		"truncated":    b[:len(b)-1],
	}
	for name, bad := range cases {
		if _, ok := ContainsEncoded(bad, 0.5*CellDegrees, 0.5*CellDegrees); ok {
			// The probe point must be INSIDE the stored grid for truncation
			// to matter; for the others any point does.
			if name == "truncated" {
				lng := (float64(cs.MinCol) + 0.5) * CellDegrees
				lat := (float64(cs.MinRow+int32(cs.Rows)) - 0.5) * CellDegrees
				if _, ok2 := ContainsEncoded(bad, lng, lat); ok2 {
					t.Fatalf("%s: probe claimed to answer", name)
				}
			} else {
				t.Fatalf("%s: probe claimed to answer", name)
			}
		}
	}
}
