package ormharness

// Optimizer-hint assertions.
//
// Layer 1 compares both sides through Canonical (canonical.go), which strips
// every /* */ comment. That is right for an ordinary comment - it carries no
// SQL semantics - and wrong for /*+ MAX_EXECUTION_TIME(10000) */, which is a
// MySQL optimizer hint with behaviour attached: a query that overruns it aborts,
// and recommendations/stats.go catches that to set a "degraded" flag rather than
// letting the whole request time out.
//
// So a conversion that silently dropped the hint would still pass
// AssertGoldenSQL, because the golden and the hint-less render are stripped to
// the same text before they are ever compared. AssertHintSurvivesInRawSQL looks
// at the raw rendering instead, before canonicalisation, giving the dropped hint
// somewhere to be caught.
//
// This lives in a normal file rather than beside its tests because the parity
// tests that need it are in package `test`, and Go does not export identifiers
// declared in another package's _test.go files.

import (
	"strings"

	"gorm.io/gorm"
)

// AssertHintSurvivesInRawSQL renders build and fails t unless the RAW rendered
// SQL contains hint verbatim.
//
// Use it ALONGSIDE AssertGoldenSQL, never instead of it: AssertGoldenSQL proves
// the rest of the statement, and this proves the one thing it structurally
// cannot.
func AssertHintSurvivesInRawSQL(t TestingT, build func(tx *gorm.DB) *gorm.DB, hint string) {
	t.Helper()

	sql, err := RenderDryRunSQL(build)
	if err != nil {
		t.Fatalf("ormharness: rendering for hint check: %v", err)
	}
	if !strings.Contains(sql, hint) {
		t.Fatalf("ormharness: expected the optimizer hint %q to survive in the raw rendered SQL.\n"+
			"Canonical strips /* */ comments, so AssertGoldenSQL would have passed this - the hint has "+
			"behaviour attached and its loss is invisible to text parity.\ngot: %s", hint, sql)
	}
}
