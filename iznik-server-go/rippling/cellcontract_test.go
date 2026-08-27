package rippling

import (
	"encoding/base64"
	"strings"
	"testing"
)

// The cell-grid contract on the Go side: the SQL the builders emit names only
// surviving columns, and the byte probes every gate depends on fail closed.

// droppedNames are the identifiers that must not appear in any SQL the
// cells-only branches build. Checked with word boundaries because
// polygon_cells and max_polygon_cells legitimately contain "polygon".
var droppedNames = []struct {
	name  string
	check func(string) bool
}{
	{"polygon", func(s string) bool { return mentionsBare(s, "polygon") }},
	{"max_polygon", func(s string) bool { return mentionsBare(s, "max_polygon") }},
	{"polygon_hash", func(s string) bool { return strings.Contains(s, "polygon_hash") }},
	{"overflow_bounds", func(s string) bool { return strings.Contains(s, "overflow_bounds") }},
	{"rippling_reach_geom", func(s string) bool { return strings.Contains(s, "rippling_reach_geom") }},
}

// mentionsBare reports whether s names col as a bare column rather than as a
// prefix of col+"_cells".
func mentionsBare(s, col string) bool {
	for i := 0; ; {
		j := strings.Index(s[i:], col)
		if j < 0 {
			return false
		}
		at := i + j
		rest := s[at+len(col):]
		if !strings.HasPrefix(rest, "_cells") {
			// "max_polygon" contains "polygon", so when looking for the bare
			// "polygon" skip a hit that is really the tail of max_polygon.
			if col == "polygon" && at >= 4 && s[at-4:at] == "max_" {
				i = at + len(col)
				continue
			}
			return true
		}
		i = at + len(col)
	}
}

func assertNoDroppedColumns(t *testing.T, what, sql string) {
	t.Helper()
	for _, d := range droppedNames {
		if d.check(sql) {
			t.Errorf("%s still names the dropped %q post-drop:\n%s", what, d.name, sql)
		}
	}
}

// ReachOuterOnlyWhere is the degraded path's conjunct - the outer-bound
// superset the caller then refines with a cells probe. It must reference only
// columns that survive, and consume exactly the binds it says it does.
func TestReachOuterOnlyWhere_SurvivesTheDrop(t *testing.T) {
	where, args := ReachOuterOnlyWhere(-0.1, 51.5, 3857)
	assertNoDroppedColumns(t, "ReachOuterOnlyWhere", where)
	if !strings.Contains(where, "rr.outer_bound") {
		t.Fatalf("the degraded conjunct must drive from outer_bound: %s", where)
	}
	if got, want := strings.Count(where, "?"), len(args); got != want {
		t.Fatalf("placeholder/bind mismatch: %d placeholders, %d args", got, want)
	}
}

// The single-point gate helpers must be usable without a database at all:
// the answer comes from bytes, so the only SQL is a keyed blob fetch. Here
// the probe is exercised directly against real encoded bytes to pin the
// fail-closed contract the gate depends on.
func TestCellSetContains_FailClosedContract(t *testing.T) {
	cases := map[string][]byte{
		"nil":          nil,
		"empty":        {},
		"short header": {1, 2, 3},
		"garbage":      []byte("definitely not a cell set at all"),
	}
	for name, b := range cases {
		if in, ok := CellSetContains(b, -0.1, 51.5); ok {
			t.Errorf("%s: probe claimed it could answer (returned in=%v); a gate must be able to tell "+
				"'cannot say' from 'outside' or it will admit replies on unreadable bytes", name, in)
		}
	}
}

// GOLDEN_NEGATIVE_OFFSETS is a grid whose MinCol AND MinRow are both NEGATIVE,
// produced by the real rasteriser (iznik-spatial-go's POST /v1/reach/rasterize)
// for POLYGON((-0.0009 -0.0006, 0.0006 -0.0006, 0.0006 0.0006, -0.0009 0.0006,
// -0.0009 -0.0006)) - a box straddling both lng 0 and lat 0.
//
// This case is worth pinning separately because the UK is at NEGATIVE
// longitude and Greenwich is lng 0, so real reaches straddle the meridian
// routinely - and the header stores MinCol/MinRow as int32 written through
// uint32, which is exactly where a sign bug hides. The pre-existing golden
// vector has MinCol=MinRow=0 and so never exercised it.
//
// Header: MinCol=-3 MinRow=-2 Cols=6 Rows=5, 20 covered cells.
const goldenNegativeOffsets = "Q0NTMf3////+////BgAAAAUAAAAABQEFAQUBBQc="

func TestCellSet_NegativeOffsetsAgreeWithPHP(t *testing.T) {
	raw, err := base64.StdEncoding.DecodeString(goldenNegativeOffsets)
	if err != nil {
		t.Fatal(err)
	}

	cs, err := DecodeCellSet(raw)
	if err != nil {
		t.Fatalf("golden vector with negative offsets would not decode: %v", err)
	}
	if got := cs.SetCellCount(); got != 20 {
		t.Errorf("covered cells: got %d, want 20 - the header's signed offsets are being read wrongly", got)
	}

	// The same four probes the PHP side answers. A sign bug shows up here as an
	// inverted or shifted answer rather than as a decode failure.
	cases := []struct {
		lng, lat float64
		want     bool
	}{
		{-0.00075, -0.00045, true}, // inside, both coords negative
		{0.00045, 0.00045, true},   // inside, both coords positive
		{-0.00105, 0.0, false},     // outside to the west, beyond MinCol
		{0.00075, 0.0, false},      // outside to the east, beyond MinCol+Cols
	}
	for _, c := range cases {
		// The streaming probe: what every gate uses.
		got, ok := CellSetContains(raw, c.lng, c.lat)
		if !ok {
			t.Errorf("probe could not answer at %.5f,%.5f", c.lng, c.lat)
			continue
		}
		if got != c.want {
			t.Errorf("probe at %.5f,%.5f: got %v, want %v (PHP answers %v for the same bytes)",
				c.lng, c.lat, got, c.want, c.want)
		}
		// And the decoded form, which the clip uses: the two must never differ.
		if full := cs.Contains(c.lng, c.lat); full != got {
			t.Errorf("at %.5f,%.5f the streaming probe says %v but a full decode says %v - "+
				"the two read paths have diverged on a negative-offset grid", c.lng, c.lat, got, full)
		}
	}

	// Re-encoding must reproduce the bytes exactly, including the negative
	// offsets in the header: this is what lets the clip write a grid back.
	if out := base64.StdEncoding.EncodeToString(cs.Encode()); out != goldenNegativeOffsets {
		t.Errorf("re-encode is not byte-identical for negative offsets:\n got %s\nwant %s",
			out, goldenNegativeOffsets)
	}
}
