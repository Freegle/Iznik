package ormharness

// Pure unit tests for the Layer 4 pieces that do not need a live database:
// EXPLAIN-plan normalisation and the table-diff formatter/comparison
// helpers. Everything that actually runs SQL (ExplainTree, AssertPlanParity,
// RunUpsertParity, DiffTables) needs a seeded database and is tested in
// iznik-server-go/test instead.

import (
	"strings"
	"testing"
)

func TestNormalizeExplainPlan_IgnoresDriftingEstimates(t *testing.T) {
	old := "-> Filter: (t.foo = 1)  (cost=1.25 rows=1)\n" +
		"    -> Index lookup on t using PRIMARY (id=1)  (cost=1.25 rows=1)\n"
	newer := "-> Filter: (t.foo = 1)  (cost=98.70 rows=500)\n" +
		"    -> Index lookup on t using PRIMARY (id=1)  (cost=98.70 rows=500)\n"

	normOld := NormalizeExplainPlan(old)
	normNew := NormalizeExplainPlan(newer)

	if normOld != normNew {
		t.Errorf("expected plans differing only in cost/row estimates to normalise identically:\n%s\n---\n%s", normOld, normNew)
	}
	if strings.Contains(normOld, "1.25") || strings.Contains(normOld, "98.70") {
		t.Errorf("expected numeric estimates to be replaced, got %q", normOld)
	}
}

func TestNormalizeExplainPlan_PreservesStructuralDifferences(t *testing.T) {
	usesPrimary := "-> Index lookup on t using PRIMARY (id=1)  (cost=1.25 rows=1)\n"
	usesSecondary := "-> Index lookup on t using idx_foo (foo=1)  (cost=1.25 rows=1)\n"

	if NormalizeExplainPlan(usesPrimary) == NormalizeExplainPlan(usesSecondary) {
		t.Error("expected plans using different indexes to remain distinct after normalisation - that is a real plan-shape difference, not drift")
	}
}

func TestNormalizeExplainPlan_ActualTimeAndLoops(t *testing.T) {
	// ANALYZE-style output carries "actual time=X..Y rows=Z loops=W" as well
	// as cost/rows; all of it is drift that must be normalised away.
	a := "-> Filter: (t.foo = 1)  (cost=1.25 rows=1) (actual time=0.05..0.12 rows=1 loops=1)\n"
	b := "-> Filter: (t.foo = 1)  (cost=1.30 rows=2) (actual time=0.09..0.20 rows=2 loops=3)\n"

	if NormalizeExplainPlan(a) != NormalizeExplainPlan(b) {
		t.Errorf("expected actual-time/loops drift to be normalised away:\n%s\n---\n%s", NormalizeExplainPlan(a), NormalizeExplainPlan(b))
	}
}

func TestPlanParityReport_Diff(t *testing.T) {
	equal := &PlanParityReport{Equal: true, NormalizedOldPlan: "same", NormalizedNewPlan: "same"}
	if got := equal.Diff(); got != "" {
		t.Errorf("expected empty diff for an equal report, got %q", got)
	}

	unequal := &PlanParityReport{Equal: false, NormalizedOldPlan: "a", NormalizedNewPlan: "b"}
	got := unequal.Diff()
	if !strings.Contains(got, "a") || !strings.Contains(got, "b") {
		t.Errorf("expected diff to contain both plans, got %q", got)
	}
}

func TestReplayRowKeyAndIndexByKey(t *testing.T) {
	pk := []string{"id"}
	rows := []map[string]any{
		{"id": float64(1), "name": "a"},
		{"id": float64(2), "name": "b"},
	}

	index := replayIndexByKey(rows, pk)
	if len(index) != 2 {
		t.Fatalf("expected 2 entries, got %d", len(index))
	}

	key1 := replayRowKey(map[string]any{"id": float64(1)}, pk)
	row, ok := index[key1]
	if !ok || row["name"] != "a" {
		t.Errorf("expected to find row for id=1, got %+v (ok=%v)", row, ok)
	}
}

func TestReplayRowKey_CompositeKeyOrderMatters(t *testing.T) {
	pk := []string{"a", "b"}
	k1 := replayRowKey(map[string]any{"a": 1, "b": 2}, pk)
	k2 := replayRowKey(map[string]any{"a": 2, "b": 1}, pk)
	if k1 == k2 {
		t.Error("expected (a=1,b=2) and (a=2,b=1) to produce different composite keys")
	}
}

func TestReplayRowsEqual(t *testing.T) {
	a := map[string]any{"id": float64(1), "name": "x"}
	b := map[string]any{"id": float64(1), "name": "x"}
	c := map[string]any{"id": float64(1), "name": "y"}

	if !replayRowsEqual(a, b) {
		t.Error("expected identical rows to compare equal")
	}
	if replayRowsEqual(a, c) {
		t.Error("expected rows differing in one field to compare unequal")
	}
}

func TestReplayRowsEqual_NullDistinctFromEmptyString(t *testing.T) {
	withNull := map[string]any{"id": float64(1), "extra": nil}
	withEmpty := map[string]any{"id": float64(1), "extra": ""}

	if replayRowsEqual(withNull, withEmpty) {
		t.Error("expected NULL and empty string to compare unequal, matching ormshadow.ShadowRowDigest's NULL-distinctness")
	}
}

func TestReplayKeyValues(t *testing.T) {
	row := map[string]any{"id": float64(5), "other": "ignored"}
	key := replayKeyValues(row, []string{"id"})
	if len(key) != 1 || key["id"] != float64(5) {
		t.Errorf("expected key to contain only pk columns, got %+v", key)
	}
}

func TestFormatTableDiffs_NoDifferences(t *testing.T) {
	if got := FormatTableDiffs(nil); got != "no differences" {
		t.Errorf(`expected "no differences", got %q`, got)
	}
}

func TestFormatTableDiffs_ReadableOutput(t *testing.T) {
	diffs := []TableDiff{
		{
			Table: "users",
			Key:   map[string]any{"id": 1},
			InA:   map[string]any{"id": 1, "name": "old"},
			InB:   map[string]any{"id": 1, "name": "new"},
		},
		{
			Table: "users",
			Key:   map[string]any{"id": 2},
			InA:   nil,
			InB:   map[string]any{"id": 2, "name": "only-in-b"},
		},
	}

	out := FormatTableDiffs(diffs)

	for _, want := range []string{"users", "old write path", "new write path", "only in copy B"} {
		if !strings.Contains(out, want) {
			t.Errorf("expected formatted output to contain %q, got:\n%s", want, out)
		}
	}
}
