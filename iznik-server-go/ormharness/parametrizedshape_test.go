package ormharness

// Proves the AssertGoldenParametrizedShape mechanism itself, independent of
// any real site, using the recordingT + panic/recover pattern golden_test.go
// established for testing that an assertion correctly REJECTS a bad case
// (t.Run cannot express "this subtest must fail").

import (
	"strings"
	"testing"
)

// buildUnionAllBranches is the synthetic "production code" under test: n
// branches of "SELECT 1" joined by " UNION ALL ", exactly the shape
// message_list.go's buildMTUnionAllMsgIDQuery and markseen.go's
// insertViewBatch have (a fixed template repeated n times), without pulling
// in either real site's surrounding complexity.
func buildUnionAllBranches(n int) string {
	branches := make([]string, n)
	for i := range branches {
		branches[i] = "SELECT 1"
	}
	return strings.Join(branches, " UNION ALL ")
}

func TestAssertGoldenParametrizedShape_AcceptsCorrectTemplate(t *testing.T) {
	AssertGoldenParametrizedShape(t, "synthetic-union-all",
		buildUnionAllBranches,
		nil,
		[]ParametrizedShapeCase{
			{N: 1, SQL: buildUnionAllBranches(1)},
			{N: 2, SQL: buildUnionAllBranches(2)},
			{N: 5, SQL: buildUnionAllBranches(5)},
		},
	)
}

// TestAssertGoldenParametrizedShape_RejectsWrongTemplate is the negative
// case: the n=5 case's actual SQL is missing a branch (a stand-in for a real
// bug - a builder that appended one branch per group but dropped the last
// one). The assertion must catch this even though n=1 and n=2 both compared
// correctly, which is exactly the "sampling two points looks tested but
// isn't" failure mode this whole capability exists to avoid.
func TestAssertGoldenParametrizedShape_RejectsWrongTemplate(t *testing.T) {
	rec := &recordingT{}
	func() {
		defer func() {
			if r := recover(); r != nil && r != abortSentinel {
				panic(r)
			}
		}()
		AssertGoldenParametrizedShape(rec, "synthetic-union-all-broken",
			buildUnionAllBranches,
			nil,
			[]ParametrizedShapeCase{
				{N: 1, SQL: buildUnionAllBranches(1)},
				{N: 2, SQL: buildUnionAllBranches(2)},
				{N: 5, SQL: buildUnionAllBranches(4)}, // one branch short
			},
		)
	}()
	if !rec.failed {
		t.Fatal("expected AssertGoldenParametrizedShape to reject a case whose SQL doesn't match wantSQL(n), but it passed")
	}
}

func TestAssertGoldenParametrizedShape_RequiresAtLeastThreeCases(t *testing.T) {
	rec := &recordingT{}
	func() {
		defer func() {
			if r := recover(); r != nil && r != abortSentinel {
				panic(r)
			}
		}()
		AssertGoldenParametrizedShape(rec, "synthetic-too-few-cases",
			buildUnionAllBranches,
			nil,
			[]ParametrizedShapeCase{
				{N: 1, SQL: buildUnionAllBranches(1)},
				{N: 2, SQL: buildUnionAllBranches(2)},
			},
		)
	}()
	if !rec.failed {
		t.Fatal("expected AssertGoldenParametrizedShape to refuse fewer than 3 cases, but it passed")
	}
}

func TestAssertGoldenParametrizedShape_RejectsDuplicateN(t *testing.T) {
	rec := &recordingT{}
	func() {
		defer func() {
			if r := recover(); r != nil && r != abortSentinel {
				panic(r)
			}
		}()
		AssertGoldenParametrizedShape(rec, "synthetic-dup-n",
			buildUnionAllBranches,
			nil,
			[]ParametrizedShapeCase{
				{N: 1, SQL: buildUnionAllBranches(1)},
				{N: 1, SQL: buildUnionAllBranches(1)},
				{N: 2, SQL: buildUnionAllBranches(2)},
			},
		)
	}()
	if !rec.failed {
		t.Fatal("expected AssertGoldenParametrizedShape to reject a duplicate n, but it passed")
	}
}

// TestAssertGoldenParametrizedShape_ChecksPlaceholderArgConsistency proves a
// case whose SQL text matches wantSQL(n) exactly, but whose placeholder
// count does not match its own Args count, still fails - text parity alone
// is blind to that (the same reasoning golden.go's assertRenderedSQL applies
// to ordinary sites).
func TestAssertGoldenParametrizedShape_ChecksPlaceholderArgConsistency(t *testing.T) {
	sqlFor := func(n int) string {
		parts := make([]string, n)
		for i := range parts {
			parts[i] = "SELECT ?"
		}
		return strings.Join(parts, " UNION ALL ")
	}

	rec := &recordingT{}
	func() {
		defer func() {
			if r := recover(); r != nil && r != abortSentinel {
				panic(r)
			}
		}()
		AssertGoldenParametrizedShape(rec, "synthetic-arg-mismatch",
			sqlFor,
			nil,
			[]ParametrizedShapeCase{
				{N: 1, SQL: sqlFor(1), Args: []interface{}{1}},
				{N: 2, SQL: sqlFor(2), Args: []interface{}{1, 2}},
				{N: 3, SQL: sqlFor(3), Args: []interface{}{1, 2}}, // one short
			},
		)
	}()
	if !rec.failed {
		t.Fatal("expected AssertGoldenParametrizedShape to reject a placeholder/arg-count mismatch, but it passed")
	}
}

// TestAssertGoldenParametrizedShape_ChecksWantArgCount proves the optional
// wantArgCount hook is actually consulted, not decoration - a case with the
// "right" number of placeholders-vs-args internally, but the wrong count
// relative to what the caller declared n should need, still fails.
func TestAssertGoldenParametrizedShape_ChecksWantArgCount(t *testing.T) {
	sqlFor := func(n int) string {
		parts := make([]string, n)
		for i := range parts {
			parts[i] = "SELECT ?"
		}
		return strings.Join(parts, " UNION ALL ")
	}
	// Declares that each branch needs 2 args, though sqlFor only has 1
	// placeholder per branch - deliberately wrong, to prove this hook bites.
	wantArgCount := func(n int) int { return n * 2 }

	rec := &recordingT{}
	func() {
		defer func() {
			if r := recover(); r != nil && r != abortSentinel {
				panic(r)
			}
		}()
		AssertGoldenParametrizedShape(rec, "synthetic-wantargcount",
			sqlFor,
			wantArgCount,
			[]ParametrizedShapeCase{
				{N: 1, SQL: sqlFor(1), Args: []interface{}{1}},
				{N: 2, SQL: sqlFor(2), Args: []interface{}{1, 2}},
				{N: 3, SQL: sqlFor(3), Args: []interface{}{1, 2, 3}},
			},
		)
	}()
	if !rec.failed {
		t.Fatal("expected AssertGoldenParametrizedShape to reject a wantArgCount mismatch, but it passed")
	}
}
