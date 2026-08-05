package ormharness

// MAX_EXECUTION_TIME optimizer-hint SELECTs (3 sites, recommendations/stats.go
// Stats): the review found the preflight test written for this category
// structurally blind. AssertGoldenSQL's comparison runs both sides through
// Canonical (canonical.go), which deliberately strips every /* */ comment -
// "dropped entirely: it carries no SQL semantics" is true for an ordinary
// comment, but false for /*+ MAX_EXECUTION_TIME(10000) */, which is a MySQL
// optimizer hint the caller inspects .Error against (a query that overruns it
// aborts, and the caller sets a `degraded` flag rather than timing the whole
// request out - see recommendations/stats.go's own comment). A conversion
// that silently dropped the hint would still pass AssertGoldenSQL, because
// both the golden and the (hint-dropped) render would have it stripped before
// they are ever compared.
//
// TestMaxExecutionTime_CanonicalStripsHint proves the blindness exists.
// AssertHintSurvivesInRawSQL (below) is the fix: it inspects the RAW rendered
// SQL, before Canonical ever runs, so a dropped hint has somewhere to be
// caught. TestMaxExecutionTime_AssertHintSurvives_RejectsDroppedHint proves
// the fix actually rejects the failure Canonical alone would miss.

import (
	"testing"

	"gorm.io/gorm"
)

// TestMaxExecutionTime_CanonicalStripsHint pins the blindness itself: two
// statements differing ONLY in whether the hint is present compare EQUAL
// after Canonical. This is what makes AssertGoldenSQL alone unable to catch
// a conversion that dropped the hint - not a bug in Canonical (dropping
// ordinary comments is correct and desired), but a real gap for this one
// category, where a comment carries behaviour.
func TestMaxExecutionTime_CanonicalStripsHint(t *testing.T) {
	withHint := "SELECT /*+ MAX_EXECUTION_TIME(10000) */ DATE(timestamp) d FROM messages_likes WHERE source = ?"
	withoutHint := "SELECT DATE(timestamp) d FROM messages_likes WHERE source = ?"

	if Canonical(withHint) != Canonical(withoutHint) {
		t.Fatalf("expected Canonical to strip the hint and make these equal (that is the gap this category "+
			"needed a second assertion for), got:\n with hint:    %s\n without hint: %s",
			Canonical(withHint), Canonical(withoutHint))
	}
}

// TestMaxExecutionTime_AssertHintSurvives_RejectsDroppedHint is the negative
// case the review asked for: render the WRONG form (hint silently dropped,
// e.g. from a Select() call someone edited without noticing the comment) and
// confirm AssertHintSurvivesInRawSQL actually catches it. Uses the same
// recordingT + panic/recover pattern golden_test.go's runAssertGoldenSQL
// established, so the expected failure can be observed rather than failing
// this test itself (t.Run cannot express "this subtest must fail").
func TestMaxExecutionTime_AssertHintSurvives_RejectsDroppedHint(t *testing.T) {
	rec := &recordingT{}
	func() {
		defer func() {
			if r := recover(); r != nil && r != abortSentinel {
				panic(r) // a genuine panic, not the controlled unwind
			}
		}()
		AssertHintSurvivesInRawSQL(rec, func(tx *gorm.DB) *gorm.DB {
			var dest []map[string]interface{}
			return tx.Table("messages_likes").
				Select("DATE(timestamp) d, source, COUNT(*) impressions"). // hint dropped
				Where("type = ?", "View").
				Find(&dest)
		}, "/*+ MAX_EXECUTION_TIME(10000) */")
	}()

	if !rec.failed {
		t.Fatal("expected AssertHintSurvivesInRawSQL to fail when the hint is missing from the rendered SQL, " +
			"but it passed - the assertion is not actually checking what it claims to")
	}
}

// TestMaxExecutionTime_AssertHintSurvives_AcceptsPresentHint is the positive
// control: the same shape, hint included via the Select() string (the
// mechanism recommendations/stats.go's three conversions use), passes.
func TestMaxExecutionTime_AssertHintSurvives_AcceptsPresentHint(t *testing.T) {
	AssertHintSurvivesInRawSQL(t, func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages_likes").
			Select("/*+ MAX_EXECUTION_TIME(10000) */ DATE(timestamp) d, source, COUNT(*) impressions").
			Where("type = ?", "View").
			Find(&dest)
	}, "/*+ MAX_EXECUTION_TIME(10000) */")
}
