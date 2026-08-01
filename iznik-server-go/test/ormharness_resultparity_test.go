package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

// These tests exercise ormharness.AssertResultParity itself (Layer 2 of the
// ORM migration harness, plan section 7.2), rather than any specific
// converted call site. They run against a small dedicated fixture table so
// they do not depend on, or interfere with, fixture data any other test
// relies on.
//
// Because AssertResultParity calls t.Fatalf on a genuine mismatch, the
// "should fail" cases below run it inside a nested t.Run and check that
// sub-test's reported outcome, rather than calling it directly (which would
// fail this test suite, not just demonstrate that the helper works).

// setupResultParityFixture creates (if missing) and (re)seeds a small fixture
// table with one row that has a NULL score/note (Bob), so the NULL-sensitive
// path has something real to exercise. It is idempotent, so every test below
// can call it without needing shared setup or teardown ordering.
func setupResultParityFixture(t *testing.T) *gorm.DB {
	t.Helper()

	db := database.DBConn

	res := db.Exec(`CREATE TABLE IF NOT EXISTS ormharness_resultparity_fixture (
		id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		name VARCHAR(64) NOT NULL,
		score INT NULL,
		note VARCHAR(64) NULL
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)
	if res.Error != nil {
		t.Fatalf("failed to create ormharness_resultparity_fixture: %v", res.Error)
	}

	res = db.Exec(`INSERT INTO ormharness_resultparity_fixture (id, name, score, note) VALUES
		(1, 'Alice', 10, 'a note'),
		(2, 'Bob', NULL, NULL),
		(3, 'Carol', 30, 'another note')
		ON DUPLICATE KEY UPDATE name = VALUES(name), score = VALUES(score), note = VALUES(note)`)
	if res.Error != nil {
		t.Fatalf("failed to seed ormharness_resultparity_fixture: %v", res.Error)
	}

	return db
}

// TestAssertResultParity_IdenticalPasses is the identical-pair case: the raw
// SQL and the GORM replacement select the same columns, the same rows, in
// the same (ORDER BY) order, so AssertResultParity must not fail.
func TestAssertResultParity_IdenticalPasses(t *testing.T) {
	db := setupResultParityFixture(t)

	originalSQL := "SELECT id, name, score FROM ormharness_resultparity_fixture WHERE id IN (?, ?, ?) ORDER BY id"
	args := []any{uint64(1), uint64(2), uint64(3)}
	replacement := func(tx *gorm.DB) *gorm.DB {
		return tx.Table("ormharness_resultparity_fixture").
			Select("id, name, score").
			Where("id IN ?", []uint64{1, 2, 3}).
			Order("id")
	}

	// If this fails, it fails the real test - that is the point: a passing
	// pair must not trip the harness.
	ormharness.AssertResultParity(t, db, originalSQL, args, replacement)
}

// TestAssertResultParity_DifferentDataFails is the genuinely-different-pair
// case: the replacement's score column is off by one for non-NULL rows
// (a stand-in for a real conversion bug, e.g. an accidental extra join or
// wrong predicate). AssertResultParity must fail.
func TestAssertResultParity_DifferentDataFails(t *testing.T) {
	db := setupResultParityFixture(t)

	originalSQL := "SELECT id, name, score FROM ormharness_resultparity_fixture WHERE id IN (?, ?, ?) ORDER BY id"
	args := []any{uint64(1), uint64(2), uint64(3)}
	replacement := func(tx *gorm.DB) *gorm.DB {
		return tx.Table("ormharness_resultparity_fixture").
			Select("id, name, score + 1 AS score"). // deliberately wrong
			Where("id IN ?", []uint64{1, 2, 3}).
			Order("id")
	}

	passed := t.Run("should_fail_on_genuine_difference", func(t *testing.T) {
		ormharness.AssertResultParity(t, db, originalSQL, args, replacement)
	})

	if passed {
		t.Fatal("expected AssertResultParity to fail when the replacement returns different data, but it passed")
	}
}

// TestAssertResultParity_NullVsEmptyStringFails is the NULL-sensitivity
// case: Bob's note is a genuine SQL NULL in the original, but the
// replacement wraps it in COALESCE(note, ''), turning it into an empty
// string. A weaker (e.g. stringified) comparison would call these equal;
// AssertResultParity must not.
func TestAssertResultParity_NullVsEmptyStringFails(t *testing.T) {
	db := setupResultParityFixture(t)

	originalSQL := "SELECT id, name, note FROM ormharness_resultparity_fixture WHERE id IN (?, ?, ?) ORDER BY id"
	args := []any{uint64(1), uint64(2), uint64(3)}
	replacement := func(tx *gorm.DB) *gorm.DB {
		return tx.Table("ormharness_resultparity_fixture").
			Select("id, name, COALESCE(note, '') AS note"). // NULL -> "" is the bug under test
			Where("id IN ?", []uint64{1, 2, 3}).
			Order("id")
	}

	passed := t.Run("should_fail_on_null_vs_empty_string", func(t *testing.T) {
		ormharness.AssertResultParity(t, db, originalSQL, args, replacement)
	})

	if passed {
		t.Fatal("expected AssertResultParity to fail when NULL becomes an empty string, but it passed")
	}
}

// TestAssertResultParity_UnorderedPathPasses exercises the unordered
// comparison path: originalSQL has no ORDER BY, so AssertResultParity must
// sort both result sets into a shared canonical order before comparing.
// The replacement deliberately runs with ORDER BY id DESC (the opposite of
// the table's natural row order) to prove the pass does not depend on the
// two queries happening to agree on physical row order.
func TestAssertResultParity_UnorderedPathPasses(t *testing.T) {
	db := setupResultParityFixture(t)

	originalSQL := "SELECT id, name, score FROM ormharness_resultparity_fixture WHERE id IN (?, ?, ?)" // no ORDER BY
	args := []any{uint64(1), uint64(2), uint64(3)}
	replacement := func(tx *gorm.DB) *gorm.DB {
		return tx.Table("ormharness_resultparity_fixture").
			Select("id, name, score").
			Where("id IN ?", []uint64{1, 2, 3}).
			Order("id DESC") // same rows, deliberately reversed order
	}

	ormharness.AssertResultParity(t, db, originalSQL, args, replacement)
}

// TestAssertResultParity_UnorderedPathCatchesDifference guards against the
// unordered path's own sort-then-compare mechanism silently masking a real
// difference (e.g. if the canonical sort key were too lossy). Same shape as
// the pass case above, but the replacement is genuinely missing a row.
func TestAssertResultParity_UnorderedPathCatchesDifference(t *testing.T) {
	db := setupResultParityFixture(t)

	originalSQL := "SELECT id, name, score FROM ormharness_resultparity_fixture WHERE id IN (?, ?, ?)" // no ORDER BY
	args := []any{uint64(1), uint64(2), uint64(3)}
	replacement := func(tx *gorm.DB) *gorm.DB {
		return tx.Table("ormharness_resultparity_fixture").
			Select("id, name, score").
			Where("id IN ?", []uint64{1, 2}). // missing id 3
			Order("id DESC")
	}

	passed := t.Run("should_fail_on_missing_row_even_when_unordered", func(t *testing.T) {
		ormharness.AssertResultParity(t, db, originalSQL, args, replacement)
	})

	if passed {
		t.Fatal("expected AssertResultParity to fail when the replacement is missing a row, but it passed")
	}
}
