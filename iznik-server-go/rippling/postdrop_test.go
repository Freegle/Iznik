package rippling

import (
	"strings"
	"testing"
)

// The CELLS-ONLY era on the Go side: what the query builders emit once
// polygon, max_polygon and overflow_bounds have been dropped
// (plans/2026-08-24-rippling-reach-raster-storage.md Stage 3).
//
// These are shape tests, deliberately. The answers are covered by the
// integration suite in test/, but the failure that actually matters here is a
// query naming a column that no longer exists - which is a runtime error on a
// schema this suite does not have, and therefore invisible to any assertion
// about answers. Reading the emitted SQL is the only way to catch it before
// the drop rather than after.

func boolPtr(b bool) *bool { return &b }

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

// The era guards are what make every legacy branch dead code after the drop,
// so the override that lets both eras be tested has to work in both
// directions and restore cleanly.
func TestLegacyGeomOverride(t *testing.T) {
	defer SetLegacyGeomForTest(nil, nil)

	SetLegacyGeomForTest(boolPtr(false), boolPtr(false))
	if LegacyPolygonReady(nil) {
		t.Fatal("override to false must report the post-drop era")
	}
	if LegacyOverflowReady(nil) {
		t.Fatal("override to false must report the post-drop era for rings too")
	}

	SetLegacyGeomForTest(boolPtr(true), boolPtr(true))
	if !LegacyPolygonReady(nil) {
		t.Fatal("override to true must report the legacy era")
	}
	if !LegacyOverflowReady(nil) {
		t.Fatal("override to true must report the legacy era for rings too")
	}

	// Restoring must fall back to the real schema check rather than sticking
	// on the last override - a test that leaked its era would silently change
	// every later test's meaning.
	SetLegacyGeomForTest(nil, nil)
	if LegacyPolygonReady(nil) {
		t.Fatal("with no db and no override the guard must answer false, not the stale override")
	}
}

// The single-point gate helpers must be usable without a database at all in
// the cells-only era: the answer comes from bytes, so the only SQL is a keyed
// blob fetch. Here the probe is exercised directly against real encoded bytes
// to pin the fail-closed contract the gate depends on.
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
